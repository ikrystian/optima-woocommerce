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
                    'description' => sprintf(__('Produkty zaimportowane z grupy Optima: %s', 'optima-woocommerce'), $category_name),
                    'slug' => sanitize_title($category_name)
                ]
            );

            if (is_wp_error($term_data)) {
                error_log(__('Integracja WC Optima: Błąd podczas tworzenia kategorii - ', 'optima-woocommerce') . $term_data->get_error_message());
                return 0;
            }

            // Increment the counter for created categories
            $categories_created = get_option('wc_optima_categories_created', 0);
            update_option('wc_optima_categories_created', $categories_created + 1);

            return $term_data['term_id'];
        }
    }

    /**
     * Get WooCommerce product ID by Optima ID stored in meta field
     *
     * @param string $optima_id The Optima product ID.
     * @return int|null Product ID if found, null otherwise.
     */
    private function get_product_id_by_optima_id($optima_id)
    {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_optima_id' AND meta_value = %s",
            $optima_id
        ));

        if ($product_id) {
            // Verify it's a product post type
            $post_type = get_post_type($product_id);
            if ($post_type === 'product' || $post_type === 'product_variation') {
                return (int) $product_id;
            }
        }

        return null;
    }


    /**
     * Get existing product SKUs from WooCommerce
     *
     * @return array Associative array of product SKUs to product IDs
     * @deprecated Use get_product_id_by_optima_id instead for primary lookup.
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
            error_log(__('Integracja WC Optima: WooCommerce nie jest aktywny', 'optima-woocommerce'));
            return;
        }

        // Get products from Optima API with pagination
        // Starting with offset 0 and using the default limit of 100
        $optima_products = $this->api->get_optima_products(0);

        if (!$optima_products) {
            error_log(__('Integracja WC Optima: Nie pobrano produktów z Optima', 'optima-woocommerce'));
            return;
        }

        // Get stock information from Optima API
        $optima_stocks = $this->api->get_optima_stock();
        $stock_data = $this->process_stock_data($optima_stocks);

        $products_added = 0;
        $products_updated = 0;
        $skipped_products = 0;
        $deleted_products = 0;

        // Keep track of processed Optima IDs to avoid duplicates within this batch
        $processed_optima_ids = [];

        // Process each product from Optima
        foreach ($optima_products as $product) {
            // --- Basic Data Extraction ---
            $optima_id = isset($product['id']) ? trim($product['id']) : null;
            $optima_code = isset($product['code']) ? trim($product['code']) : null; // Needed for stock lookup
            $name = isset($product['name']) ? trim($product['name']) : null;
            $description = isset($product['description']) ? $product['description'] : '';

            // --- Validation ---
            // Skip products without Optima ID or name
            if (empty($optima_id)) {
                error_log(__('Integracja WC Optima: Pomijanie produktu bez ID Optima: ', 'optima-woocommerce') . json_encode($product));
                $skipped_products++;
                continue;
            }
            if (empty($name)) {
                error_log(sprintf(__('Integracja WC Optima: Pomijanie produktu (ID: %s) bez nazwy.', 'optima-woocommerce'), $optima_id));
                $skipped_products++;
                continue;
            }

            // Skip products with duplicate Optima IDs within this batch
            if (in_array($optima_id, $processed_optima_ids)) {
                error_log(sprintf(__('Integracja WC Optima: Pomijanie produktu z duplikatem ID Optima w tej paczce: %s', 'optima-woocommerce'), $optima_id));
                $skipped_products++;
                continue;
            }
            $processed_optima_ids[] = $optima_id;

            // Set the target SKU to be the Optima ID
            $sku = $optima_id;

            // Apply a filter to allow 3rd party plugins to skip certain products
            // Return false from this filter to skip the product
            if (!apply_filters('wc_optima_should_sync_product', true, $product, $optima_id)) {
                error_log(sprintf(__('Integracja WC Optima: Pomijanie produktu (ID: %s) przez filtr.', 'optima-woocommerce'), $optima_id));
                $skipped_products++;
                continue;
            }

            // --- Data Preparation ---
            // Get product category from defaultGroup
            $category_id = 0;
            if (isset($product['defaultGroup']) && !empty($product['defaultGroup'])) {
                $category_id = $this->get_or_create_category($product['defaultGroup']);
            }

            // Get retail price from prices array
            $price = $this->get_retail_price(isset($product['prices']) ? $product['prices'] : []);

            // Get stock quantity using Optima Code from stock data or default to 0
            $stock_quantity = 0;
            if (!empty($optima_code) && isset($stock_data[$optima_code])) {
                $stock_quantity = $stock_data[$optima_code]['available']; // Use available quantity
            } else {
                error_log(sprintf(__('Integracja WC Optima: Brak danych o stanie magazynowym dla produktu (ID: %s, Kod: %s). Ustawiono stan na 0.', 'optima-woocommerce'), $optima_id, $optima_code));
            }

            // --- Check Price/Stock and Skip/Delete ---
            $existing_product_id = $this->get_product_id_by_optima_id($optima_id);

            if ($price === null || $price <= 0 || $stock_quantity <= 0) {
                $reason = $price === null || $price <= 0 ? 'nieprawidłowa cena' : 'brak na stanie';

                // If product exists in WooCommerce, remove it
                if ($existing_product_id) {
                    wp_delete_post($existing_product_id, true); // true to bypass trash and delete permanently
                    $deleted_products++;
                    error_log(sprintf(__('Integracja WC Optima: Usunięto produkt (ID Optima: %s, ID WC: %d) - Powód: %s (Cena: %s, Stan: %s)', 'optima-woocommerce'), $optima_id, $existing_product_id, $reason, $price, $stock_quantity));
                } else {
                    // Product doesn't exist and meets skip criteria, just log and skip
                    error_log(sprintf(__('Integracja WC Optima: Pominięto tworzenie produktu (ID Optima: %s) - Powód: %s (Cena: %s, Stan: %s)', 'optima-woocommerce'), $optima_id, $reason, $price, $stock_quantity));
                }
                $skipped_products++;
                continue; // Skip to the next product
            }

            // --- Data Preparation (Continued) ---
            // Get dimensions (height, width, length)
            $height = isset($product['height']) ? floatval($product['height']) : 0;
            $width = isset($product['width']) ? floatval($product['width']) : 0;
            $length = isset($product['length']) ? floatval($product['length']) : 0;

            // Format all prices for storage as custom fields
            $formatted_prices = $this->format_prices_for_storage(isset($product['prices']) ? $product['prices'] : []);

            // Additional product metadata for WooCommerce custom fields
            $meta_data = [
                '_optima_id' => $optima_id, // Ensure Optima ID is always stored
                '_optima_code' => $optima_code, // Store the Optima code as well
                '_optima_type' => isset($product['type']) ? $product['type'] : '',
                '_optima_vat_rate' => isset($product['vatRate']) ? $product['vatRate'] : '',
                '_optima_unit' => isset($product['unit']) ? $product['unit'] : '',
                '_optima_barcode' => isset($product['barcode']) ? $product['barcode'] : '',
                '_optima_catalog_number' => isset($product['catalogNumber']) ? $product['catalogNumber'] : '',
                '_optima_default_group' => isset($product['defaultGroup']) ? $product['defaultGroup'] : '',
                '_optima_sales_category' => isset($product['salesCategory']) ? $product['salesCategory'] : '',
                '_optima_stock_data' => (!empty($optima_code) && isset($stock_data[$optima_code])) ? json_encode($stock_data[$optima_code]) : ''
            ];

            // Add all prices as custom meta fields
            if (!empty($formatted_prices)) {
                foreach ($formatted_prices as $price_key => $price_value) {
                    $meta_data['_' . $price_key] = $price_value;
                }
            }

            // --- Product Create or Update ---
            if ($existing_product_id) {
                // Product exists, update it
                $product_id = $existing_product_id;
                $wc_product = wc_get_product($product_id);

                if ($wc_product) {
                    $update_needed = false;

                    // // Update Name & Description if changed - SKIPPED as per requirement
                    // if ($wc_product->get_name() !== $name) {
                    //     $wc_product->set_name($name);
                    //     $update_needed = true;
                    // }
                    // if ($wc_product->get_description() !== $description) {
                    //     $wc_product->set_description($description);
                    //     $update_needed = true;
                    // }

                    // Update Price
                    if (floatval($wc_product->get_regular_price()) !== floatval($price)) {
                        $wc_product->set_regular_price($price);
                        $update_needed = true;
                    }

                    // Update Stock
                    if ($wc_product->get_manage_stock() !== true) {
                        $wc_product->set_manage_stock(true);
                        $update_needed = true;
                    }
                    if (floatval($wc_product->get_stock_quantity()) !== floatval($stock_quantity)) {
                        $wc_product->set_stock_quantity($stock_quantity);
                        $update_needed = true;
                    }
                    $new_stock_status = $stock_quantity > 0 ? 'instock' : 'outofstock';
                    if ($wc_product->get_stock_status() !== $new_stock_status) {
                        $wc_product->set_stock_status($new_stock_status);
                        $update_needed = true;
                    }

                    // Update SKU (set to Optima ID)
                    if ($wc_product->get_sku() !== $sku) {
                        try {
                            $wc_product->set_sku($sku);
                            $update_needed = true;
                        } catch (\WC_Data_Exception $e) {
                            // Log SKU conflict if it occurs
                            error_log(sprintf(__('Integracja WC Optima: Konflikt SKU podczas aktualizacji produktu (ID Optima: %s, ID WC: %d). Próbowano ustawić SKU "%s". Błąd: %s', 'optima-woocommerce'), $optima_id, $product_id, $sku, $e->getMessage()));
                            // Optionally: Decide how to handle conflict (e.g., skip SKU update, generate unique SKU)
                            // For now, we log and continue without updating SKU if conflict occurs.
                        }
                    }

                    // Update Dimensions
                    if ($height > 0 && floatval($wc_product->get_height()) !== floatval($height)) {
                        $wc_product->set_height($height);
                        $update_needed = true;
                    }
                    if ($width > 0 && floatval($wc_product->get_width()) !== floatval($width)) {
                        $wc_product->set_width($width);
                        $update_needed = true;
                    }
                    if ($length > 0 && floatval($wc_product->get_length()) !== floatval($length)) {
                        $wc_product->set_length($length);
                        $update_needed = true;
                    }

                    // // Update Category - SKIPPED as per requirement
                    // if ($category_id > 0 && !has_term($category_id, 'product_cat', $product_id)) {
                    //     $wc_product->set_category_ids([$category_id]); // Overwrites existing categories
                    //     $update_needed = true;
                    // }

                    // Update meta data
                    foreach ($meta_data as $meta_key => $meta_value) {
                        if ($wc_product->get_meta($meta_key) !== $meta_value) {
                            $wc_product->update_meta_data($meta_key, $meta_value);
                            $update_needed = true;
                        }
                    }

                    // Save the product only if changes were made
                    if ($update_needed) {
                        $wc_product->save();
                        $products_updated++;
                    } else {
                        // If no update was needed, consider it skipped for update count purposes
                        // but not an error. Logically it's processed.
                    }
                } else {
                    error_log(sprintf(__('Integracja WC Optima: Nie można pobrać obiektu produktu WC dla istniejącego produktu (ID Optima: %s, ID WC: %d)', 'optima-woocommerce'), $optima_id, $product_id));
                    $skipped_products++;
                }
            } else {
                // Product doesn't exist, create it
                $new_product_post = [
                    'post_title' => $name,
                    'post_content' => $description,
                    'post_status' => 'publish', // Or 'draft' if preferred initially
                    'post_type' => 'product',
                ];

                $product_id = wp_insert_post($new_product_post, true); // true to return WP_Error on failure

                if (is_wp_error($product_id)) {
                    error_log(sprintf(__('Integracja WC Optima: Błąd podczas tworzenia nowego produktu (ID Optima: %s) - %s', 'optima-woocommerce'), $optima_id, $product_id->get_error_message()));
                    $skipped_products++;
                } else {
                    $wc_product = wc_get_product($product_id);

                    if ($wc_product) {
                        // Set product type (simple by default)
                        wp_set_object_terms($product_id, 'simple', 'product_type');

                        // Set SKU (Optima ID) - handle potential conflicts
                        try {
                            $wc_product->set_sku($sku);
                        } catch (\WC_Data_Exception $e) {
                            error_log(sprintf(__('Integracja WC Optima: Konflikt SKU podczas tworzenia produktu (ID Optima: %s). Próbowano ustawić SKU "%s". Błąd: %s', 'optima-woocommerce'), $optima_id, $sku, $e->getMessage()));
                            // Decide how to handle conflict (e.g., skip, generate unique)
                            // For now, log and potentially leave SKU blank or try a unique one.
                            // Let's try appending timestamp for uniqueness
                            $unique_sku = $sku . '-' . time();
                            try {
                                $wc_product->set_sku($unique_sku);
                                // Store original intended SKU (Optima ID) as meta
                                $wc_product->update_meta_data('_optima_intended_sku', $sku);
                                error_log(sprintf(__('Integracja WC Optima: Wygenerowano unikalne SKU "%s" dla produktu (ID Optima: %s) z powodu konfliktu.', 'optima-woocommerce'), $unique_sku, $optima_id));
                            } catch (\WC_Data_Exception $e2) {
                                error_log(sprintf(__('Integracja WC Optima: Nie udało się ustawić nawet unikalnego SKU "%s" dla produktu (ID Optima: %s). Błąd: %s', 'optima-woocommerce'), $unique_sku, $optima_id, $e2->getMessage()));
                            }
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

                        // Add meta data (including _optima_id)
                        foreach ($meta_data as $meta_key => $meta_value) {
                            $wc_product->update_meta_data($meta_key, $meta_value);
                        }

                        // Save the product
                        $wc_product->save();
                        $products_added++;
                    } else {
                        error_log(sprintf(__('Integracja WC Optima: Nie można pobrać obiektu produktu WC po utworzeniu (ID Optima: %s, ID WC: %d)', 'optima-woocommerce'), $optima_id, $product_id));
                        $skipped_products++;
                        // Consider deleting the post if the WC_Product object is invalid
                        // wp_delete_post($product_id, true);
                    }
                }
            }
        } // End foreach loop

        // Update sync statistics
        update_option('wc_optima_last_sync', current_time('mysql'));
        update_option('wc_optima_products_added', $products_added);
        update_option('wc_optima_products_updated', $products_updated);
        update_option('wc_optima_products_skipped', $skipped_products);
        update_option('wc_optima_products_deleted', $deleted_products); // Add deleted count

        error_log(sprintf(
            __('Integracja WC Optima: Synchronizacja zakończona. Dodano: %d, Zaktualizowano: %d, Pominięto: %d, Usunięto: %d', 'optima-woocommerce'),
            $products_added,
            $products_updated,
            $skipped_products,
            $deleted_products
        ));
    }
}
