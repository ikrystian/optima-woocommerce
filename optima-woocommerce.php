<?php

/**
 * Plugin Name: Intergacja Optimy z WooCommerce
 * Description: Synchronizes products and inventory between Optima API and WooCommerce
 * Version: 1.0
 * Author: Krystian Kuźmiński
 * Author URI: https://bpcoders.pl
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin file constant
define('OPTIMA_WC_PLUGIN_FILE', __FILE__);

// Register custom cron schedule
add_filter('cron_schedules', function ($schedules) {
    $schedules['daily_at_0430'] = array(
        'interval' => 86400, // 24 hours in seconds
        'display' => __('Once daily at 04:30 (4:30 AM)')
    );
    return $schedules;
});

// Include Guzzle HTTP client via Composer autoloader if available
if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
} else {
    // If Composer is not available, we'll need to handle Guzzle differently
    // Let's provide an admin notice
    add_action('admin_notices', function () {
        echo '<div class="error"><p>WooCommerce Optima Integration requires the Guzzle HTTP library. Please install it using Composer or contact your developer.</p></div>';
    });
}

// Hook for daily sync debug logging
add_action('wc_optima_daily_sync', function () {
    error_log('WC Optima sync cron executed at: ' . date('Y-m-d H:i:s'));
});

/**
 * Initialize the plugin
 */
function wc_optima_init()
{
    // Check if WooCommerce is active before initializing
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Include the main class files
    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-optima-integration.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-optima-filters.php';

    // Initialize the plugin
    new WC_Optima_Integration();

    /**
     * Skip products with zero stock and zero price during import
     * 
     * @param bool $should_sync Whether the product should be synchronized
     * @param array $product The product data from Optima API
     * @param string $sku The product SKU
     * @return bool
     */
    function wc_optima_skip_zero_stock_zero_price($should_sync, $product, $sku)
    {
        // Get stock data
        $stock_quantity = 0;

        // Check if there's stock data available in the API response
        if (isset($product['stocks']) && is_array($product['stocks'])) {
            foreach ($product['stocks'] as $warehouse) {
                if (isset($warehouse['quantity'])) {
                    $stock_quantity += floatval($warehouse['quantity']);
                }
            }
        }

        // Get price (use the same logic as in the sync class)
        $price = 0;
        if (isset($product['prices']) && is_array($product['prices'])) {
            // Look for retail price (type 2)
            foreach ($product['prices'] as $price_data) {
                if (isset($price_data['type']) && $price_data['type'] == 2 && isset($price_data['value'])) {
                    $price = floatval($price_data['value']);
                    break;
                }
            }

            // Fallback to first price if retail not found
            if ($price == 0 && count($product['prices']) > 0 && isset($product['prices'][0]['value'])) {
                $price = floatval($product['prices'][0]['value']);
            }
        }

        // Skip if both stock and price are zero
        if ($stock_quantity == 0 && $price == 0) {
            return false;
        }

        return $should_sync;
    }
    add_filter('wc_optima_should_sync_product', 'wc_optima_skip_zero_stock_zero_price', 10, 3);

    /**
     * Generate random prefix for order number
     */
    function wc_optima_generate_random_order_number($order_id)
    {
        $prefix = rand(1000, 9999);
        return $prefix . $order_id;
    }
    add_filter('woocommerce_order_number', 'wc_optima_generate_random_order_number', 10, 1);
}

// Initialize the plugin on plugins loaded
add_action('plugins_loaded', 'wc_optima_init');
