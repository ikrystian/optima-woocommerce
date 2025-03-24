<?php

/**
 * Product synchronization class for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling product synchronization between Optima and WooCommerce
 */
class WC_Optima_Product_Sync
{
    /**
     * API handler instance
     *
     * @var WC_Optima_API
     */
    private $api;

    /**
     * Constructor
     *
     * @param WC_Optima_API $api API handler instance
     */
    public function __construct($api)
    {
        $this->api = $api;

        // Add hook for daily sync
        add_action('wc_optima_daily_sync', array($this, 'sync_products'));
    }

    /**
     * Process stock data and create a lookup array
     * 
     * @param array $stocks Stock data from Optima API
     * @return array Processed stock data
     */
    private function process_stock_data($stocks)
    {
        $stock_lookup = [];

        if (!is_array($stocks)) {
            return $stock_lookup;
        }

        // Process the stock data according to the format from the API
        foreach ($stocks as $item_id => $warehouses) {
            if (!is_array($warehouses) || empty($warehouses)) {
                continue;
            }

            // Use the first warehouse entry (or could sum up all warehouses if needed)
            $warehouse_data = $warehouses[0];

            if (isset($warehouse_data['itemCode'])) {
                // Use item code as the key for lookup since we use code as SKU
                $stock_lookup[$warehouse_data['itemCode']] = [
                    'quantity' => isset($warehouse_data['quantity']) ? floatval($warehouse_data['quantity']) : 0,
                    'available' => isset($warehouse_data['quantity']) ? floatval($warehouse_data['quantity']) : 0,
                    'unit' => isset($warehouse_data['unit']) ? $warehouse_data['unit'] : '',
                    'warehouse_id' => isset($warehouse_data['warehouseId']) ? $warehouse_data['warehouseId'] : 0
                ];
            }
        }

        return $stock_lookup;
    }

    /**
     * Format prices from Optima for storage
     * 
     * @param array $prices Prices from Optima API
     * @return array Formatted prices
     */
    private function format_prices_for_storage($prices)
    {
        if (!is_array($prices)) {
            return '';
        }

        $formatted_prices = [];

        foreach ($prices as $price) {
            if (isset($price['number']) && isset($price['name']) && isset($price['value'])) {
                $key = 'price_' . sanitize_title($price['name']);
                $formatted_prices[$key] = floatval($price['value']);
            }
        }

        return $formatted_prices;
    }

    /**
     * Get retail price from product prices array
     * 
     * @param array $prices Prices from Optima API
     * @return float Retail price
     */
    private function get_retail_price($prices)
    {
        if (!is_array($prices)) {
            return 0;
        }

        // Look for the retail price (typically labeled as "detaliczna")
        foreach ($prices as $price) {
            if (isset($price['type']) && $price['type'] == 2) {
                return isset($price['value']) ? floatval($price['value']) : 0;
            }
        }

        // Fallback to the first price if retail price not found
        if (count($prices) > 0 && isset($prices[0]['value'])) {
            return floatval($prices[0]['value']);
        }

        return 0;
    }

    /**
     * Get or create category by name
     * This function will get an existing category by name or create a new one if it doesn't exist
     * 
     * @param string $category_name Category name
     * @return int Category ID
     */
    private function get_or_create_category($category_name)
    {
        // Check if the category exists
        $term = get_term_by('name', $category_name, 'product_cat');

        if ($term) {
            // Return existing term ID
            return $term->term_id;
        } else {
            // Create a new category
            $term_data = wp_insert_term(
                $category_name,
                'product_cat',
                [
                    'description' => sprintf(__('Products imported from Optima group: %s', 'wc-optima-integration'), $category_name),
                    'slug' => sanitize_title($category_name)
                ]
            );

            if (is_wp_error($term_data)) {
                error_log('WC Optima Integration: Error creating category - ' . $term_data->get_error_message());
                return 0;
            }

            // Increment the counter for created categories
            $categories_created = get_option('wc_optima_categories_created', 0);
            update_option('wc_optima_categories_created', $categories_created + 1);

            return $term_data['term_id'];
        }
    }

    /**
     * Get existing product SKUs from WooCommerce
     * 
     * @return array Associative array of product SKUs to product IDs
     */
    private function get_existing_product_skus()
    {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status != 'trash'
            AND pm.meta_value != ''",
            ARRAY_A
        );

        $skus = array();
        if ($results) {
            foreach ($results as $result) {
                $skus[$result['sku']] = $result['ID'];
            }
        }

        return $skus;
    }

    /**
     * Main function to synchronize products
     */
    public function sync_products()
    {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            error_log('WC Optima Integration: WooCommerce is not active');
            return;
        }

        // Get products from Optima API with pagination
        // Starting with offset 0 and using the default limit of 100
        $optima_products = $this->api->get_optima_products(0);

        if (!$optima_products) {
            error_log('WC Optima Integration: No products retrieved from Optima');
            return;
        }

        // Get stock information from Optima API
        $optima_stocks = $this->api->get_optima_stock();
        $stock_data = $this->process_stock_data($optima_stocks);

        $products_added = 0;
        $products_updated = 0;

        // Get existing WooCommerce product IDs
        $existing_products = $this->get_existing_product_skus();

        // Keep track of processed SKUs to avoid duplicates
        $processed_skus = [];
        $skipped_products = 0;

        // Process each product from Optima
        foreach ($optima_products as $product) {
            // Map Optima fields to WooCommerce fields based on the provided JSON structure
            $sku = isset($product['code']) ? $product['code'] : null;
            $name = isset($product['name']) ? $product['name'] : null;
            $description = isset($product['description']) ? $product['description'] : '';

            // Generate a unique identifier for products without SKU
            if (empty($sku) && isset($product['id'])) {
                // Use Optima ID as fallback identifier
                $sku = 'optima-' . $product['id'];
                error_log('WC Optima Integration: Using Optima ID as SKU for product: ' . $product['id']);
            } elseif (empty($sku)) {
                // Skip products with no SKU and no ID
                error_log('WC Optima Integration: Skipping product with no identifiers: ' . json_encode($product));
                $skipped_products++;
                continue;
            }

            // Skip products with duplicate SKUs (first occurrence is processed, others skipped)
            if (in_array($sku, $processed_skus)) {
                error_log('WC Optima Integration: Skipping product with duplicate SKU: ' . $sku);
                $skipped_products++;
                continue;
            }

            // Apply a filter to allow 3rd party plugins to skip certain products
            // Return false from this filter to skip the product
            if (!apply_filters('wc_optima_should_sync_product', true, $product, $sku)) {
                error_log('WC Optima Integration: Skipping product by filter: ' . $sku);
                $skipped_products++;
                continue;
            }

            // Add this SKU to the processed list
            $processed_skus[] = $sku;

            // Get product category from defaultGroup
            $category_id = 0;
            if (isset($product['defaultGroup']) && !empty($product['defaultGroup'])) {
                $category_id = $this->get_or_create_category($product['defaultGroup']);
            }

            // Get retail price from prices array
            $price = $this->get_retail_price(isset($product['prices']) ? $product['prices'] : []);

            // Get stock quantity from stock data or default to 0
            $stock_quantity = 0;
            if (isset($stock_data[$sku])) {
                $stock_quantity = $stock_data[$sku]['available']; // Use available quantity (total minus reservations)
            }

            // Get dimensions (height, width, length)
            $height = isset($product['height']) ? floatval($product['height']) : 0;
            $width = isset($product['width']) ? floatval($product['width']) : 0;
            $length = isset($product['length']) ? floatval($product['length']) : 0;

            // Format all prices for storage as custom fields
            $formatted_prices = $this->format_prices_for_storage(isset($product['prices']) ? $product['prices'] : []);

            // Additional product metadata for WooCommerce custom fields
            $meta_data = [
                '_optima_id' => isset($product['id']) ? $product['id'] : '',
                '_optima_type' => isset($product['type']) ? $product['type'] : '',
                '_optima_vat_rate' => isset($product['vatRate']) ? $product['vatRate'] : '',
                '_optima_unit' => isset($product['unit']) ? $product['unit'] : '',
                '_optima_barcode' => isset($product['barcode']) ? $product['barcode'] : '',
                '_optima_catalog_number' => isset($product['catalogNumber']) ? $product['catalogNumber'] : '',
                '_optima_default_group' => isset($product['defaultGroup']) ? $product['defaultGroup'] : '',
                '_optima_sales_category' => isset($product['salesCategory']) ? $product['salesCategory'] : '',
                '_optima_stock_data' => isset($stock_data[$sku]) ? json_encode($stock_data[$sku]) : ''
            ];

            // Add all prices as custom meta fields
            if (!empty($formatted_prices)) {
                foreach ($formatted_prices as $price_key => $price_value) {
                    $meta_data['_' . $price_key] = $price_value;
                }
            }

            // Check if product exists in WooCommerce by SKU
            if (isset($existing_products[$sku])) {
                // Product exists, update it
                $product_id = $existing_products[$sku];

                // Update product data
                $wc_product = wc_get_product($product_id);

                if ($wc_product) {
                    // Update basic product data
                    $wc_product->set_regular_price($price);
                    $wc_product->set_stock_quantity($stock_quantity);
                    $wc_product->set_manage_stock(true);
                    $wc_product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

                    // Try to update the SKU in case it has changed or there are conflicts
                    try {
                        // Only try to update if the current SKU doesn't match
                        if ($wc_product->get_sku() !== $sku) {
                            $wc_product->set_sku($sku);
                        }
                    } catch (\WC_Data_Exception $e) {
                        error_log('WC Optima Integration: SKU error for product ' . $name . ' with SKU ' . $sku . ' - ' . $e->getMessage());

                        // Store original SKU as meta for reference if not already stored
                        if (!$wc_product->get_meta('_optima_original_sku')) {
                            $wc_product->update_meta_data('_optima_original_sku', $sku);
                        }
                    }

                    // Update meta data
                    foreach ($meta_data as $meta_key => $meta_value) {
                        $wc_product->update_meta_data($meta_key, $meta_value);
                    }

                    // Save the product
                    $wc_product->save();

                    $products_updated++;
                }
            } else {
                // Product doesn't exist, create it
                $new_product = [
                    'post_title' => $name,
                    'post_content' => $description,
                    'post_status' => 'publish',
                    'post_type' => 'product',
                ];

                $product_id = wp_insert_post($new_product);

                if (!is_wp_error($product_id)) {
                    // Set product data
                    $wc_product = wc_get_product($product_id);

                    // Set product type (simple by default)
                    wp_set_object_terms($product_id, 'simple', 'product_type');

                    // Try to set SKU - handle the case where it might be a duplicate
                    try {
                        $wc_product->set_sku($sku);
                    } catch (\WC_Data_Exception $e) {
                        error_log('WC Optima Integration: SKU error for product ' . $name . ' with SKU ' . $sku . ' - ' . $e->getMessage());

                        // Generate a unique SKU by appending a timestamp
                        $unique_sku = $sku . '-' . time();
                        $wc_product->set_sku($unique_sku);

                        // Store original SKU as meta for reference
                        $wc_product->update_meta_data('_optima_original_sku', $sku);

                        error_log('WC Optima Integration: Generated unique SKU: ' . $unique_sku);
                    }

                    // Set price
                    $wc_product->set_regular_price($price);

                    // Set stock
                    $wc_product->set_manage_stock(true);
                    $wc_product->set_stock_quantity($stock_quantity);
                    $wc_product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

                    // Set dimensions if available
                    if ($height > 0) $wc_product->set_height($height);
                    if ($width > 0) $wc_product->set_width($width);
                    if ($length > 0) $wc_product->set_length($length);

                    // Set category if available
                    if ($category_id > 0) {
                        $wc_product->set_category_ids([$category_id]);
                    }

                    // Add meta data
                    foreach ($meta_data as $meta_key => $meta_value) {
                        $wc_product->update_meta_data($meta_key, $meta_value);
                    }

                    // Save the product
                    $wc_product->save();

                    $products_added++;
                }
            }
        }

        // Update sync statistics
        update_option('wc_optima_last_sync', current_time('mysql'));
        update_option('wc_optima_products_added', $products_added);
        update_option('wc_optima_products_updated', $products_updated);
        update_option('wc_optima_products_skipped', $skipped_products);

        error_log(sprintf(
            'WC Optima Integration: Sync completed. Added: %d, Updated: %d, Skipped: %d',
            $products_added,
            $products_updated,
            $skipped_products
        ));
    }
}
