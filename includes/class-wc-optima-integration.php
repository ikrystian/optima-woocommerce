<?php

/**
 * Main WC_Optima_Integration class
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main class for Optima WooCommerce integration
 */
class WC_Optima_Integration
{
    /**
     * Instance of the API handler
     *
     * @var WC_Optima_API
     */
    private $api;

    /**
     * Instance of the admin handler
     *
     * @var WC_Optima_Admin
     */
    private $admin;

    /**
     * Instance of the product sync handler
     *
     * @var WC_Optima_Product_Sync
     */
    private $product_sync;

    /**
     * Instance of the customer handler
     *
     * @var WC_Optima_Customer
     */
    private $customer;

    /**
     * Plugin options
     *
     * @var array
     */
    private $options;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize options
        $this->options = get_option('wc_optima_settings', [
            'api_url' => '',
            'username' => '',
            'password' => ''
        ]);

        // Load dependencies
        $this->load_dependencies();

        // Initialize components
        $this->init_components();

        // Register activation and deactivation hooks
        register_activation_hook(OPTIMA_WC_PLUGIN_FILE, array($this, 'activate_plugin'));
        register_deactivation_hook(OPTIMA_WC_PLUGIN_FILE, array($this, 'deactivate_plugin'));

        // Add hook for customer creation in Optima
        add_action('woocommerce_checkout_order_processed', array($this->customer, 'process_customer_for_optima'), 10, 3);

        // Add hooks for product reservation in cart
        add_action('woocommerce_add_to_cart', array($this, 'reserve_product_in_cart'), 10, 6);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_product_can_be_added'), 10, 5);

        // Add a cart item expiration check
        add_action('woocommerce_cart_loaded_from_session', array($this, 'check_cart_items_expiration'));

        // Add stock verification before checkout
        add_filter('woocommerce_checkout_process', array($this, 'verify_stock_before_checkout'));

        // Add hooks for RO document creation
        add_action('woocommerce_payment_complete', array($this, 'create_ro_document_for_order'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'create_ro_document_for_order'), 10, 1);
    }

    /**
     * Load dependencies
     */
    private function load_dependencies()
    {
        // Load API class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-api.php';

        // Load Admin class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-admin.php';

        // Load Product Sync class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-product-sync.php';

        // Load Customer class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-customer.php';
    }

    /**
     * Initialize components
     */
    private function init_components()
    {
        // Initialize API handler
        $this->api = new WC_Optima_API($this->options);

        // Initialize Admin handler
        $this->admin = new WC_Optima_Admin($this->options);

        // Initialize Product Sync handler
        $this->product_sync = new WC_Optima_Product_Sync($this->api);

        // Initialize Customer handler
        $this->customer = new WC_Optima_Customer($this->api);
    }

    /**
     * Plugin activation: schedule daily sync
     */
    public function activate_plugin()
    
        // Clear any existing scheduled events
        $timestamp = wp_next_scheduled('wc_optima_daily_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_optima_daily_sync');
        }

        // Schedule the event to run daily at 4:30 AM
        $current_time = time();
        $current_date = date('Y-m-d', $current_time);
        $target_time = strtotime($current_date . ' 04:30:00');

        // If it's already past 4:30 AM today, schedule for tomorrow
        if ($current_time > $target_time) {
            $target_time = strtotime('+1 day', $target_time);
        }

        wp_schedule_event($target_time, 'daily_at_0430', 'wc_optima_daily_sync');

        // Log for debugging
        error_log(message: 'WC Optima Integration: Plugin activated, scheduled event at ' . date('Y-m-d H:i:s', $target_time));
    }

    /**
     * Plugin deactivation: remove scheduled sync
     */
    public function deactivate_plugin()
    {
        wp_clear_scheduled_hook('wc_optima_daily_sync');
    }

    /**
     * Reserve product in cart
     * 
     * When a product is added to the cart, we reserve it in Optima for 15 minutes
     * 
     * @param string $cart_item_key Cart item key
     * @param int $product_id Product ID
     * @param int $quantity Quantity
     * @param int $variation_id Variation ID
     * @param array $variation Variation data
     * @param array $cart_item_data Extra cart item data
     */
    public function reserve_product_in_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        // Get the Optima product ID (stored as meta in WooCommerce product)
        $optima_product_id = get_post_meta($variation_id ? $variation_id : $product_id, 'optima_product_id', true);

        if (empty($optima_product_id)) {
            error_log('WC Optima Integration: No Optima product ID found for product ' . $product_id);
            return;
        }

        // Reserve the product in Optima
        $reserved = $this->api->reserve_product($optima_product_id, $quantity);

        if ($reserved) {
            // Store reservation info in cart item data for expiration check
            $cart = WC()->cart->get_cart();
            if (isset($cart[$cart_item_key])) {
                $cart[$cart_item_key]['optima_reservation'] = [
                    'product_id' => $optima_product_id,
                    'quantity' => $quantity,
                    'timestamp' => time(),
                    'expires_at' => time() + (15 * 60) // 15 minutes in seconds
                ];
                WC()->cart->set_session();
            }
        } else {
            error_log('WC Optima Integration: Failed to reserve product ' . $optima_product_id);
        }
    }

    /**
     * Validate product can be added to cart
     * 
     * Before a product is added to the cart, we check if it's available in Optima
     * 
     * @param bool $passed Validation status
     * @param int $product_id Product ID
     * @param int $quantity Quantity
     * @param int $variation_id Variation ID
     * @param array $variation Variation data
     * @return bool True if product can be added to cart, false otherwise
     */
    public function validate_product_can_be_added($passed, $product_id, $quantity, $variation_id = 0, $variation = [])
    {
        if (!$passed) {
            return false;
        }

        // Get the Optima product ID (stored as meta in WooCommerce product)
        $optima_product_id = get_post_meta($variation_id ? $variation_id : $product_id, 'optima_product_id', true);

        if (empty($optima_product_id)) {
            error_log('WC Optima Integration: No Optima product ID found for product ' . $product_id);
            return $passed;
        }

        // Check if product is available in Optima
        $products = [
            [
                'product_id' => $optima_product_id,
                'quantity' => $quantity
            ]
        ];

        $result = $this->api->verify_stock_availability($products);

        if (!$result || !isset($result['success']) || !$result['success']) {
            // Product is not available or error occurred
            wc_add_notice(__('Sorry, this product is currently unavailable.', 'optima-woocommerce'), 'error');
            return false;
        }

        // Check if individual product is available
        if (isset($result['products']) && is_array($result['products'])) {
            foreach ($result['products'] as $product) {
                if (isset($product['product_id']) && $product['product_id'] === $optima_product_id) {
                    if (!isset($product['available']) || !$product['available']) {
                        wc_add_notice(
                            isset($product['message'])
                                ? $product['message']
                                : __('Sorry, this product is currently unavailable.', 'optima-woocommerce'),
                            'error'
                        );
                        return false;
                    }
                }
            }
        }

        return $passed;
    }

    /**
     * Check cart items expiration
     * 
     * When the cart is loaded from session, we check if any reserved products have expired
     */
    public function check_cart_items_expiration()
    {
        $cart = WC()->cart;
        $items = $cart->get_cart();
        $current_time = time();
        $removed_items = false;

        foreach ($items as $cart_item_key => $cart_item) {
            if (isset($cart_item['optima_reservation']) && isset($cart_item['optima_reservation']['expires_at'])) {
                // Check if reservation has expired
                if ($current_time >= $cart_item['optima_reservation']['expires_at']) {
                    // Remove the item from cart
                    $cart->remove_cart_item($cart_item_key);
                    $removed_items = true;
                }
            }
        }

        if ($removed_items) {
            wc_add_notice(
                __('Some items in your cart have expired and were removed.', 'optima-woocommerce'),
                'notice'
            );
            $cart->set_session();
        }
    }

    /**
     * Verify stock before checkout
     * 
     * Before proceeding to checkout, we verify all products are still available in Optima
     */
    public function verify_stock_before_checkout()
    {
        $cart = WC()->cart;
        $items = $cart->get_cart();
        $products_to_verify = [];

        // Collect all products that need verification
        foreach ($items as $cart_item) {
            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
            $optima_product_id = get_post_meta($product_id, 'optima_product_id', true);

            if ($optima_product_id) {
                $products_to_verify[] = [
                    'product_id' => $optima_product_id,
                    'quantity' => $cart_item['quantity']
                ];
            }
        }

        if (empty($products_to_verify)) {
            return;
        }

        // Verify stock availability in Optima
        $result = $this->api->verify_stock_availability($products_to_verify);

        if (!$result || !isset($result['success']) || !$result['success']) {
            // Error occurred during verification
            wc_add_notice(
                isset($result['message'])
                    ? $result['message']
                    : __('Unable to verify product availability. Please try again.', 'optima-woocommerce'),
                'error'
            );
            throw new Exception(__('Stock verification failed.', 'optima-woocommerce'));
        }

        // Check individual products
        if (isset($result['products']) && is_array($result['products'])) {
            foreach ($result['products'] as $product) {
                if (isset($product['available']) && !$product['available']) {
                    // Product is no longer available
                    wc_add_notice(
                        isset($product['message'])
                            ? $product['message']
                            : __('One or more products in your cart are no longer available.', 'optima-woocommerce'),
                        'error'
                    );
                    throw new Exception(__('Product unavailable.', 'optima-woocommerce'));
                }
            }
        }
    }

    /**
     * Create RO document for order
     * 
     * When an order is paid or processed, we create an RO document in Optima
     * 
     * @param int $order_id Order ID
     */
    public function create_ro_document_for_order($order_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('WC Optima Integration: Order not found - ' . $order_id);
            return;
        }

        // Check if RO document already created for this order
        $ro_document_id = get_post_meta($order_id, 'optima_ro_document_id', true);

        if (!empty($ro_document_id)) {
            error_log('WC Optima Integration: RO document already exists for order ' . $order_id);
            return;
        }

        // Prepare order data for Optima
        $order_data = [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'customer_id' => get_post_meta($order_id, 'optima_customer_id', true),
            'date' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'products' => []
        ];

        // Add products to order data
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $optima_product_id = get_post_meta($product_id, 'optima_product_id', true);

            if (!empty($optima_product_id)) {
                $order_data['products'][] = [
                    'product_id' => $optima_product_id,
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_total()
                ];
            }
        }

        if (empty($order_data['products'])) {
            error_log('WC Optima Integration: No products with Optima ID found in order ' . $order_id);
            return;
        }

        // Create RO document in Optima
        $result = $this->api->create_ro_document($order_data);

        if ($result && isset($result['document_id'])) {
            // Store the RO document ID in the order meta
            update_post_meta($order_id, 'optima_ro_document_id', $result['document_id']);

            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Optima RO document created: %s', 'optima-woocommerce'),
                    $result['document_id']
                )
            );
        } else {
            // Add a note to the order about the failure
            $order->add_order_note(
                __('Failed to create Optima RO document.', 'optima-woocommerce')
            );
            error_log('WC Optima Integration: Failed to create RO document for order ' . $order_id);
        }
    }
}
