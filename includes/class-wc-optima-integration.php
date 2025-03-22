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
        self::$api = new WC_Optima_API($this->options);

        // Initialize Admin handler
        $this->admin = new WC_Optima_Admin($this->options);

        // Initialize Product Sync handler
        $this->product_sync = new WC_Optima_Product_Sync(self::$api);

        // Initialize Customer handler
        $this->customer = new WC_Optima_Customer(self::$api);
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
        error_log(message: 'WC Optima Integration: Plugin activated, scheduled event at ' . date('Y-m-d H:i:s', $target_time));
    }

    /**
     * Plugin deactivation: remove scheduled sync
     */
    public function deactivate_plugin()
    {
        wp_clear_scheduled_hook('wc_optima_daily_sync');
    }
}
