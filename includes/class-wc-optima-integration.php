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
     * Instance of the invoice handler
     *
     * @var WC_Optima_Invoice
     */
    private $invoice;

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

        // Ensure order status is set to "pending" after checkout
        add_action('woocommerce_checkout_order_processed', function ($order_id, $posted_data, $order) {
            if ($order->get_status() !== 'pending') {
                $order->update_status('pending', __('Status ustawiony na "oczekuje na oplacenie" przez integrację Optima', 'optima-woocommerce'));
            }
        }, 20, 3);

        // Ensure order status is set to "processing" after payment
        add_action('woocommerce_payment_complete', function ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_status() !== 'processing') {
                $order->update_status('processing', __('Status ustawiony na "w trakcie realizacji" przez integrację Optima', 'optima-woocommerce'));
            }
        }, 20, 1);

        // Add hooks for RO document creation
        add_action('woocommerce_payment_complete', array($this, 'create_ro_document_for_order'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'create_ro_document_for_order'), 10, 1);

        // Add hook for updating document type after payment
        add_action('woocommerce_payment_complete', array($this, 'update_document_type_after_payment'), 20, 1);
        add_action('woocommerce_order_status_completed', array($this, 'update_document_type_after_payment'), 20, 1);
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

        // Load Invoice class
        require_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'includes/class-wc-optima-invoice.php';

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

        // Initialize Invoice handler
        $this->invoice = new WC_Optima_Invoice(self::$api);

        // Initialize AJAX handler
        $this->ajax = new WC_Optima_AJAX($this->gus_api);

        // Initialize Registration handlers
        $this->b2c_registration = new WC_Optima_B2C_Registration($this->options, $this->gus_api);
        $this->b2b_registration = new WC_Optima_B2B_Registration($this->options, $this->gus_api);

        // Initialize Company Updater
        $this->company_updater = new WC_Optima_Company_Updater($this->options, $this->gus_api);

        // Initialize Account handler
        $this->account = new WC_Optima_Account($this->options);

        // Initialize Order Completed handler
        $this->order_completed = new WC_Optima_Order_Completed(self::$api, $this->invoice);
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
     * Get invoice instance
     *
     * @return WC_Optima_Invoice|null Invoice instance or null if not initialized
     */
    public static function get_invoice_instance()
    {
        $instance = new self();
        return $instance->invoice;
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
     * Plugin activation: schedule daily sync
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
     * When an order is paid or processed, we create an RO document in Optima
     *
     * @param int $order_id Order ID
     */
    public function create_ro_document_for_order($order_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

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

        $order_data = [
            'type' => 302, // RO document type
            'foreignNumber' => 'WC_' . $order->get_order_number(),
            'calculatedOn' => 1, // 1 = gross, 2 = net
            'paymentMethod' => 'przelew', // Fixed payment method that is known to work with Optima
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

        // Add customer data if available
        $customer_id = get_post_meta($order_id, 'optima_customer_id', true);
        if (!empty($customer_id)) {
            $order_data['payerId'] = $customer_id;
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
            $unit_price = $item->get_total() / $quantity;
            $unit_price_with_tax = $item->get_total_tax() > 0 ? ($item->get_total() + $item->get_total_tax()) / $quantity : $unit_price * (1 + ($optima_vat_rate / 100));

            // Always use productId approach when available
            if (!empty($optima_id)) {
                $element = [
                    'productId' => $optima_id,
                    'quantity' => $quantity,
                    'price' => round($unit_price, 2),
                    'vatRate' => floatval($optima_vat_rate),
                    'discount' => 0,
                    'description' => $item->get_name()
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

            $order->add_order_note(
                sprintf(
                    __('Nie udało się utworzyć dokumentu RO Optima. Błąd %s: %s', 'optima-woocommerce'),
                    $status_code,
                    $detailed_error
                )
            );

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
     * Update document type after payment
     *
     * When an order is paid, update the document type in Optima to invoice type and mark as paid
     *
     * @param int $order_id Order ID
     */
    public function update_document_type_after_payment($order_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono zamówienia - %s', 'optima-woocommerce'), $order_id));
            return;
        }

        // Check if RO document exists for this order
        $ro_document_id = get_post_meta($order_id, 'optima_ro_document_id', true);

        if (empty($ro_document_id)) {
            error_log(sprintf(__('Integracja WC Optima: Brak dokumentu RO dla zamówienia %s', 'optima-woocommerce'), $order_id));
            return;
        }

        // Check if document type is already set to invoice
        $document_type = get_post_meta($order_id, 'optima_document_type', true);

        if ($document_type === 'invoice') {
            error_log(sprintf(__('Integracja WC Optima: Dokument dla zamówienia %s jest już fakturą', 'optima-woocommerce'), $order_id));
            return;
        }

        // Get the document from Optima
        $document = self::$api->get_ro_document_by_id($ro_document_id);

        if (!$document) {
            error_log(sprintf(__('Integracja WC Optima: Nie udało się pobrać dokumentu RO %s dla zamówienia %s', 'optima-woocommerce'), $ro_document_id, $order_id));
            return;
        }

        // Prepare document data for update
        $update_data = [
            'type' => 301, // Invoice document type (301 is the type for invoice)
            'status' => 2,  // Status 2 means paid
            'documentPaymentDate' => date('Y-m-d\TH:i:s'), // Current date as payment date
        ];

        // Update the document in Optima
        $result = self::$api->update_document($ro_document_id, $update_data);

        if ($result && !isset($result['error'])) {
            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Zaktualizowano dokument w Optima: %s. Zmieniono typ na fakturę i oznaczono jako opłacony.', 'optima-woocommerce'),
                    $ro_document_id
                )
            );

            // Store the updated document type in the order meta
            update_post_meta($order_id, 'optima_document_type', 'invoice');
            update_post_meta($order_id, 'optima_document_status', 'paid');
        } elseif (is_array($result) && isset($result['error']) && $result['error'] === true) {
            // Handle specific error response
            $error_message = isset($result['message']) ? $result['message'] : __('Nieznany błąd', 'optima-woocommerce');
            $status_code = isset($result['status_code']) ? $result['status_code'] : '';

            // Add a note to the order with detailed error information
            $detailed_error = $error_message;

            // Add more details if available
            if (isset($result['optima_request']) && is_array($result['optima_request'])) {
                $detailed_error .= "\n\nDane wysłane do Optima:";
                $detailed_error .= "\n" . json_encode($result['optima_request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

            $order->add_order_note(
                sprintf(
                    __('Nie udało się zaktualizować dokumentu w Optima. Błąd %s: %s', 'optima-woocommerce'),
                    $status_code,
                    $detailed_error
                )
            );

            error_log(sprintf(__('Integracja WC Optima: Błąd podczas aktualizacji dokumentu dla zamówienia %s: %s', 'optima-woocommerce'), $order_id, $error_message));
        } else {
            // Generic failure
            $order->add_order_note(
                __('Nie udało się zaktualizować dokumentu w Optima.', 'optima-woocommerce')
            );
            error_log(sprintf(__('Integracja WC Optima: Nie udało się zaktualizować dokumentu dla zamówienia %s', 'optima-woocommerce'), $order_id));
        }
    }
}
