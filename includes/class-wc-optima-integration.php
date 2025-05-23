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
    private static $api;

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
     * Instance of the GUS API handler
     *
     * @var WC_Optima_GUS_API
     */
    private $gus_api;

    /**
     * Instance of the AJAX handler
     *
     * @var WC_Optima_AJAX
     */
    private $ajax;

    /**
     * Instance of the B2C registration handler
     *
     * @var WC_Optima_B2C_Registration
     */
    private $b2c_registration;

    /**
     * Instance of the B2B registration handler
     *
     * @var WC_Optima_B2B_Registration
     */
    private $b2b_registration;

    /**
     * Instance of the company updater
     *
     * @var WC_Optima_Company_Updater
     */
    private $company_updater;

    /**
     * Instance of the account handler
     *
     * @var WC_Optima_Account
     */
    private $account;

    /**
     * Instance of the order completed handler
     *
     * @var WC_Optima_Order_Completed
     */
    private $order_completed;

    /**
     * Instance of the product fields handler
     *
     * @var WC_Optima_Product_Fields
     */
    private $product_fields;


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
        // Process customer *before* RO creation
        add_action('woocommerce_checkout_order_processed', array($this->customer, 'process_customer_for_optima'), 5, 3);

        // Ensure order status is set to "pending" after checkout
        add_action('woocommerce_checkout_order_processed', function ($order_id, $posted_data, $order) {
            if ($order->get_status() !== 'pending') {
                $order->update_status('pending', __('Status ustawiony na "oczekuje na oplacenie" przez integrację Optima', 'optima-woocommerce'));
            }
        }, 20, 3); // Keep this at 20

        // Ensure order status is set to "processing" after payment
        add_action('woocommerce_payment_complete', function ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_status() !== 'processing') {
                $order->update_status('processing', __('Status ustawiony na "w trakcie realizacji" przez integrację Optima', 'optima-woocommerce'));
            }
        }, 20, 1); // Keep this at 20

        // Add hook for RO document creation *after* customer processing
        add_action('woocommerce_checkout_order_processed', array($this, 'create_ro_document_for_order'), 25, 3); // Run after customer processing and status update
    }

    /**
     * Load dependencies
     */
    private function load_dependencies()
    {
        // Load Logs class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-logs.php';

        // Load API class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-api.php';

        // Load Admin class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-admin.php';

        // Load Product Sync class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-product-sync.php';

        // Load Customer class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-customer.php';

        // Load GUS API class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-gus-api.php';

        // Load AJAX class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-ajax.php';

        // Load Registration base class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-registration.php';

        // Load B2C Registration class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-b2c-registration.php';

        // Load B2B Registration class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-b2b-registration.php';

        // Load Company Updater class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-company-updater.php';

        // Load Account class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-account.php';

        // Load Order Completed class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-order-completed.php';

        // Load Product Fields class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-product-fields.php';
    }

    /**
     * Initialize components
     */
    private function init_components()
    {
        // Initialize API handler
        self::$api = new WC_Optima_API($this->options);

        // Initialize GUS API handler
        $this->gus_api = new WC_Optima_GUS_API($this->options);

        // Initialize Admin handler
        $this->admin = new WC_Optima_Admin($this->options);

        // Initialize Product Sync handler
        $this->product_sync = new WC_Optima_Product_Sync(self::$api);

        // Initialize Customer handler
        $this->customer = new WC_Optima_Customer(self::$api);

        // Initialize AJAX handler
        $this->ajax = new WC_Optima_AJAX($this->gus_api, self::$api);

        // Initialize Registration handlers
        $this->b2c_registration = new WC_Optima_B2C_Registration($this->options, $this->gus_api);
        $this->b2b_registration = new WC_Optima_B2B_Registration($this->options, $this->gus_api);

        // Initialize Company Updater
        $this->company_updater = new WC_Optima_Company_Updater($this->options, $this->gus_api);

        // Initialize Account handler
        $this->account = new WC_Optima_Account($this->options);

        // Initialize Order Completed handler
        $this->order_completed = new WC_Optima_Order_Completed(self::$api);

        // Initialize Product Fields handler
        $this->product_fields = new WC_Optima_Product_Fields(self::$api);
    }

    /**
     * Get API instance
     *
     * @return WC_Optima_API|null API instance or null if not initialized
     */
    public static function get_api_instance()
    {
        return self::$api;
    }

    /**
     * Get AJAX instance
     *
     * @return WC_Optima_AJAX|null AJAX instance or null if not initialized
     */
    public function get_ajax_instance()
    {
        return $this->ajax;
    }

    /**
     * Magic getter for accessing protected properties
     *
     * @param string $name Property name
     * @return mixed Property value or null if not found
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        return null;
    }

    /**
     * Plugin activation: schedule daily sync and create database tables
     */
    public function activate_plugin()
    {
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

        // Create logs table
        $logs = new WC_Optima_Logs();
        $logs->create_table();

        // Log for debugging
        error_log(sprintf(__('Integracja WC Optima: Plugin aktywowany, zaplanowano zdarzenie na %s', 'optima-woocommerce'), date('Y-m-d H:i:s', $target_time)));
    }

    /**
     * Plugin deactivation: remove scheduled sync
     */
    public function deactivate_plugin()
    {
        wp_clear_scheduled_hook('wc_optima_daily_sync');
    }

    /**
     * Create RO document for order
     *
     * When a new order is processed during checkout, we create an RO document in Optima
     * This now runs *after* customer processing on the same hook.
     *
     * @param int $order_id Order ID
     * @param array $posted_data Posted checkout data (unused but required by hook)
     * @param WC_Order $order Order object
     */
    public function create_ro_document_for_order($order_id, $posted_data, $order)
    {
        // Order object is passed directly now

        if (!$order) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono zamówienia - %s', 'optima-woocommerce'), $order_id));
            return;
        }

        // Check if RO document already created for this order
        $ro_document_id = get_post_meta($order_id, 'optima_ro_document_id', true);

        if (!empty($ro_document_id)) {
            error_log(sprintf(__('Integracja WC Optima: Dokument RO już istnieje dla zamówienia %s', 'optima-woocommerce'), $order_id));
            return;
        }

        // Prepare order data for Optima
        // Extract billing data
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $company = $order->get_billing_company();
        $vat_number = $order->get_meta('_billing_vat', true);
        $country = $order->get_billing_country();
        $city = $order->get_billing_city();
        $address_1 = $order->get_billing_address_1();
        $address_2 = $order->get_billing_address_2();
        $postcode = $order->get_billing_postcode();
        $phone = $order->get_billing_phone();
        $email = $order->get_billing_email();

        // Determine if company or private person
        $is_company = !empty($company) && !empty($vat_number);

        // Build payer/recipient arrays according to Optima API spec
        $customer_code = 'WC_' . date('Ymd') . '_' . $order->get_id();
        if ($is_company) {
            $payer_recipient = [
                'code' => $customer_code,
                'name1' => $company,
                'vatNumber' => $vat_number,
                'country' => $country,
                'city' => $city,
                'street' => $address_1,
                'postCode' => $postcode,
                'phone' => $phone,
                'email' => $email
            ];
        } else {
            $payer_recipient = [
                'code' => $customer_code,
                'name1' => trim($first_name . ' ' . $last_name),
                'vatNumber' => '',
                'country' => $country,
                'city' => $city,
                'street' => $address_1,
                'postCode' => $postcode,
                'phone' => $phone,
                'email' => $email
            ];
        }

        // Get the payment method from the order
        $payment_method = $order->get_payment_method();
        $optima_payment_method = $this->map_wc_payment_method_to_optima($payment_method);

        $order_data = [
            'type' => 308, // RO document type
            'foreignNumber' => $order->get_order_number(),
            'calculatedOn' => 1, // 1 = gross, 2 = net
            'paymentMethod' => $optima_payment_method, // Use mapped payment method from WooCommerce
            'currency' => $order->get_currency(),
            'elements' => [],
            'description' => sprintf(__('Zamówienie #%s z WooCommerce', 'optima-woocommerce'), $order->get_order_number()),
            'status' => 1,
            'sourceWarehouseId' => 1, // Default warehouse ID
            'documentSaleDate' => $order->get_date_created()->date('Y-m-d\TH:i:s'),
            'documentIssueDate' => date('Y-m-d\TH:i:s'),
            'documentPaymentDate' => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d\TH:i:s') : date('Y-m-d\TH:i:s', strtotime('+7 days')),
            'symbol' => 'RO',
            'series' => 'WC',
            'payer' => $payer_recipient,
            'recipient' => $payer_recipient
        ];

        // Add customer data if available (should be set by process_customer_for_optima now)
        $optima_customer_id = $order->get_meta('_optima_customer_id', true);
        if (!empty($optima_customer_id)) {
            $order_data['payerId'] = $optima_customer_id;
        } else {
            // Log if customer ID is missing, as it should have been set
            error_log(sprintf(__('Integracja WC Optima: Brak ID klienta Optima podczas tworzenia RO dla zamówienia %s. Klient mógł nie zostać poprawnie przetworzony.', 'optima-woocommerce'), $order_id));
            // Optionally, decide if RO creation should proceed without a linked customer ID
            // For now, we proceed but without linking the payer explicitly by ID
        }

        // Add products to order data
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $optima_id = get_post_meta($product_id, '_optima_id', true);
            $optima_vat_rate = get_post_meta($product_id, '_optima_vat_rate', true);

            if (empty($optima_vat_rate)) {
                $optima_vat_rate = 23; // Default VAT rate if not set
            }

            $quantity = $item->get_quantity();
            $unit_price_gross = $item->get_total() / $quantity;
            $vat_rate = floatval($optima_vat_rate);

            // Calculate net price from gross price
            $unit_price_net = round($unit_price_gross / (1 + ($vat_rate / 100)), 2);

            // Calculate total values
            $total_net_value = round($unit_price_net * $quantity, 2);
            $total_gross_value = round($unit_price_gross * $quantity, 2);

            // Always use productId approach when available
            if (!empty($optima_id)) {
                // Get Optima code from meta or fall back to SKU or generate a code based on product ID
                $optima_code = get_post_meta($product_id, '_optima_code', true);
                if (empty($optima_code)) {
                    // Fall back to SKU if _optima_code is not available
                    $optima_code = $product->get_sku();
                    if (empty($optima_code)) {
                        $optima_code = 'WC_PROD_' . $product_id;
                    }
                }

                $element = [
                    'productId' => $optima_id,
                    'code' => $optima_code, // Use the Optima code field
                    'quantity' => $quantity,
                    'price' => round($unit_price_gross, 2), // This is the gross price
                    'vatRate' => $vat_rate,
                    'discount' => 0,
                    'description' => $item->get_name(),
                    'unitNetPrice' => $unit_price_net,
                    'unitGrossPrice' => $unit_price_gross,
                    'totalNetValue' => $total_net_value,
                    'totalGrossValue' => $total_gross_value
                ];
            } else {
                // Skip products without Optima ID
                error_log(sprintf(
                    __('Integracja WC Optima: Pomijanie produktu bez ID Optima: %s (SKU: %s)', 'optima-woocommerce'),
                    $item->get_name(),
                    $product->get_sku() ? $product->get_sku() : 'brak'
                ));
                continue;
            }

            $order_data['elements'][] = $element;
        }

        if (empty($order_data['elements'])) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono produktów z ID Optima w zamówieniu %s', 'optima-woocommerce'), $order_id));
            return;
        }

        // Create RO document in Optima
        $result = self::$api->create_ro_document($order_data);

        if ($result && isset($result['id'])) {
            // Store the RO document ID in the order meta
            update_post_meta($order_id, 'optima_ro_document_id', $result['id']);

            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Utworzono dokument RO Optima: %s (%s)', 'optima-woocommerce'),
                    $result['id'],
                    $result['fullNumber'] ?? ''
                )
            );
        } elseif (is_array($result) && isset($result['error']) && $result['error'] === true) {
            // Handle specific error response
            $error_message = isset($result['message']) ? $result['message'] : __('Nieznany błąd', 'optima-woocommerce');
            $status_code = isset($result['status_code']) ? $result['status_code'] : '';

            // Add a note to the order with detailed error information
            $detailed_error = $error_message;

            // Add more details if available
            if (isset($result['optima_request']) && is_array($result['optima_request'])) {
                $detailed_error .= "\n\nDane wysłane do Optima:";

                // Add information about elements (products) if available
                if (isset($result['optima_request']['elements']) && is_array($result['optima_request']['elements'])) {
                    $detailed_error .= "\nProdukty:";
                    foreach ($result['optima_request']['elements'] as $idx => $element) {
                        $product_info = '';
                        if (isset($element['productId'])) {
                            $product_info .= " ID: " . $element['productId'];
                        }
                        if (isset($element['code'])) {
                            $product_info .= " Kod: " . $element['code'];
                        }
                        if (isset($element['description'])) {
                            $product_info .= " Nazwa: " . $element['description'];
                        }
                        $detailed_error .= "\n- Produkt " . ($idx + 1) . ":" . $product_info;
                    }
                }
            }

            // Add response details if available
            if (isset($result['optima_response'])) {
                $response_data = json_decode($result['optima_response'], true);
                if (is_array($response_data) && isset($response_data['ModelState'])) {
                    $detailed_error .= "\n\nBłędy walidacji:";
                    foreach ($response_data['ModelState'] as $field => $errors) {
                        if (is_array($errors)) {
                            foreach ($errors as $error) {
                                $detailed_error .= "\n- " . $field . ": " . $error;
                            }
                        } else {
                            $detailed_error .= "\n- " . $field . ": " . $errors;
                        }
                    }
                }
            }

            // Create a more detailed error message with timestamp and request information
            $error_timestamp = current_time('Y-m-d H:i:s');
            $enhanced_error = sprintf(
                __('Nie udało się utworzyć dokumentu RO Optima. [%s] Błąd %s:', 'optima-woocommerce'),
                $error_timestamp,
                $status_code
            );

            // Add the detailed error message
            $enhanced_error .= "\n\n" . $detailed_error;

            // Add request URL and method if available
            if (isset($result['request_url'])) {
                $enhanced_error .= "\n\nURL: " . $result['request_url'];
            }

            if (isset($result['request_method'])) {
                $enhanced_error .= "\nMetoda: " . $result['request_method'];
            }

            // Add request headers if available (sanitized)
            if (isset($result['request_headers']) && is_array($result['request_headers'])) {
                $enhanced_error .= "\n\nNagłówki zapytania:";
                foreach ($result['request_headers'] as $header => $value) {
                    // Skip sensitive headers like Authorization
                    if (strtolower($header) !== 'authorization') {
                        $enhanced_error .= "\n- " . $header . ": " . $value;
                    }
                }
            }

            // Log the error to the error log as well
            error_log(sprintf(
                'Optima API Error [%s]: %s - %s',
                $error_timestamp,
                $status_code,
                $error_message
            ));

            $order->add_order_note($enhanced_error);

            // Check if this is a database version error
            if (strpos($error_message, 'Wersja bazy danych jest starsza') !== false) {
                // Add a more specific note about database version mismatch
                $order->add_order_note(
                    __('Wykryto niezgodność wersji bazy danych Optima. Proszę skontaktować się z administratorem systemu Optima.', 'optima-woocommerce')
                );
            }

            error_log(sprintf(__('Integracja WC Optima: Błąd podczas tworzenia dokumentu RO dla zamówienia %s: %s', 'optima-woocommerce'), $order_id, $error_message));
        } else {
            // Generic failure
            $order->add_order_note(
                __('Nie udało się utworzyć dokumentu RO Optima.', 'optima-woocommerce')
            );
            error_log(sprintf(__('Integracja WC Optima: Nie udało się utworzyć dokumentu RO dla zamówienia %s', 'optima-woocommerce'), $order_id));
        }
    }

    /**
     * Map WooCommerce payment method to Optima payment method
     *
     * @param string $wc_payment_method WooCommerce payment method ID
     * @return string Optima payment method
     */
    private function map_wc_payment_method_to_optima($wc_payment_method)
    {
        // Default payment method if mapping fails
        $default_payment_method = 'przelew';

        // Map common WooCommerce payment gateways to Optima payment methods
        $payment_method_map = [
            // Cash-based methods
            'cod' => 'gotówka',                  // Cash on delivery
            'cash' => 'gotówka',                 // Cash payment
            'cheque' => 'gotówka',               // Check payment

            // Bank transfer methods
            'bacs' => 'przelew',                 // Direct bank transfer
            'bank_transfer' => 'przelew',        // Bank transfer
            'przelewy24' => 'przelew',           // Przelewy24
            'dotpay' => 'przelew',               // Dotpay
            'paypal' => 'przelew',               // PayPal
            'stripe' => 'przelew',               // Stripe
            'payu' => 'przelew',                 // PayU
            'tpay' => 'przelew',                 // Tpay

            // Credit card methods
            'credit_card' => 'karta',            // Credit card
            'stripe_cc' => 'karta',              // Stripe Credit Card
            'paypal_cc' => 'karta',              // PayPal Credit Card

            // Other methods
            'other' => 'przelew',                // Other payment methods
        ];

        // Return the mapped payment method or the default if not found
        return isset($payment_method_map[$wc_payment_method])
            ? $payment_method_map[$wc_payment_method]
            : $default_payment_method;
    }
}
