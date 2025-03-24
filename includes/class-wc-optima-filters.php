<?php

/**
 * Filters and hooks documentation for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for documenting available filters and hooks in the Optima WooCommerce integration
 */
class WC_Optima_Filters
{

    /**
     * Initialize the class
     */
    public function __construct()
    {
        // This is primarily a documentation class
    }

    /**
     * List of available filters
     * 
     * These filters can be used by third-party plugins to modify the behavior of the integration
     * 
     * Example usage for wc_optima_should_sync_product:
     * 
     * add_filter('wc_optima_should_sync_product', 'my_filter_optima_products', 10, 3);
     * function my_filter_optima_products($should_sync, $product, $sku) {
     *     // Skip products with SKU starting with "TEST-"
     *     if (strpos($sku, 'TEST-') === 0) {
     *         return false;
     *     }
     *     
     *     // Skip products with missing name
     *     if (empty($product['name'])) {
     *         return false;
     *     }
     *     
     *     return $should_sync;
     * }
     * 
     * Available filters:
     * 
     * 1. wc_optima_should_sync_product
     *    Description: Controls whether a specific product should be synchronized
     *    Parameters:
     *    - $should_sync (bool) Default true. Set to false to skip synchronization
     *    - $product (array) The product data from Optima API
     *    - $sku (string) The product SKU
     *    Return: boolean. True to sync the product, false to skip it
     */
    public function get_filters_documentation()
    {
        return array(
            'wc_optima_should_sync_product' => array(
                'description' => 'Controls whether a specific product should be synchronized',
                'parameters' => array(
                    'should_sync' => 'Default true. Set to false to skip synchronization',
                    'product' => 'The product data from Optima API',
                    'sku' => 'The product SKU'
                ),
                'return' => 'boolean. True to sync the product, false to skip it'
            ),
            // Add more filters here as they are created
        );
    }
}
