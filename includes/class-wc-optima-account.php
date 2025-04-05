<?php

/**
 * Account handling class for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling account functionality
 */
class WC_Optima_Account
{
    /**
     * Plugin options
     *
     * @var array
     */
    private $options;

    /**
     * Constructor
     *
     * @param array $options Plugin options
     */
    public function __construct($options)
    {
        $this->options = $options;

        // Add hooks
        add_action('woocommerce_account_dashboard', [$this, 'display_company_data']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts()
    {
        if (is_account_page()) {
            wp_enqueue_script('jquery');
        }
    }

    /**
     * Display company data in account dashboard
     */
    public function display_company_data()
    {
        // Get user data
        $user_id = get_current_user_id();
        $customer_type = get_user_meta($user_id, '_optima_customer_type', true);

        // Only show for B2B customers
        if ($customer_type !== 'b2b') {
            return;
        }

        // Load template
        $template_path = plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'templates/myaccount/company-data.php';
        
        if (file_exists($template_path)) {
            include $template_path;
        }
    }
}
