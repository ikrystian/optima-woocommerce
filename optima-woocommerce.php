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

class WC_Optima_Integration
{
    private $api_url;
    private $username;
    private $password;
    private $access_token;
    private $token_expiry;
    private $options;

    public function __construct()
    {
        // Initialize options
        $this->options = get_option('wc_optima_settings', [
            'api_url' => '',
            'username' => '',
            'password' => ''
        ]);

        // Set API credentials from options
        $this->api_url = $this->options['api_url'];
        $this->username = $this->options['username'];
        $this->password = $this->options['password'];

        // Add hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wc_optima_daily_sync', array($this, 'sync_products'));

        // Add product meta data display in admin
        add_filter('woocommerce_product_data_tabs', array($this, 'add_optima_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'display_optima_meta_data_panel'));

        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Add hook for customer creation in Optima
        add_action('woocommerce_checkout_order_processed', array($this, 'process_customer_for_optima'), 10, 3);
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles()
    {
        wp_enqueue_style(
            'wc-optima-admin-styles',
            plugins_url('admin-styles.css', __FILE__)
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        register_setting('wc_optima_settings_group', 'wc_optima_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings($input)
    {
        $sanitized = array();

        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw(trim($input['api_url']));
        }

        if (isset($input['username'])) {
            $sanitized['username'] = sanitize_text_field($input['username']);
        }

        if (isset($input['password'])) {
            // Only update password if it's changed (not empty)
            if (!empty($input['password'])) {
                $sanitized['password'] = $input['password']; // We don't sanitize passwords to preserve special characters
            } else {
                // Keep the old password if not changed
                $current_options = get_option('wc_optima_settings', array());
                $sanitized['password'] = isset($current_options['password']) ? $current_options['password'] : '';
            }
        }

        // If credentials changed, clear the token
        $current_options = get_option('wc_optima_settings', array());
        if (
            $sanitized['api_url'] !== ($current_options['api_url'] ?? '') ||
            $sanitized['username'] !== ($current_options['username'] ?? '') ||
            $sanitized['password'] !== ($current_options['password'] ?? '')
        ) {
            delete_option('wc_optima_token_data');
        }

        return $sanitized;
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

    /**
     * Add admin menu page
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Optima Integration',
            'Optima Integration',
            'manage_woocommerce',
            'wc-optima-integration',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin page content
     */
    public function admin_page()
    {
        // Check if we're on the settings tab or the main tab
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sync';

        // Check if manual sync was requested
        if ($active_tab === 'sync' && isset($_POST['wc_optima_manual_sync']) && wp_verify_nonce($_POST['wc_optima_sync_nonce'], 'wc_optima_manual_sync')) {
            $this->sync_products();
            echo '<div class="notice notice-success"><p>Synchronization with Optima completed.</p></div>';
        }

        // Admin page HTML
?>
        <div class="wrap">
            <h1>WooCommerce Optima Integration</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=wc-optima-integration&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">Synchronization</a>
                <a href="?page=wc-optima-integration&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            </h2>

            <?php if ($active_tab === 'sync'): ?>

                <p>This plugin synchronizes products and inventory between Optima API and WooCommerce once daily at 04:30 (4:30 AM).</p>

                <?php if (empty($this->api_url) || empty($this->username) || empty($this->password)): ?>
                    <div class="notice notice-warning">
                        <p>Please configure API settings before running synchronization. Go to the <a href="?page=wc-optima-integration&tab=settings">Settings tab</a>.</p>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <?php wp_nonce_field('wc_optima_manual_sync', 'wc_optima_sync_nonce'); ?>
                        <input type="submit" name="wc_optima_manual_sync" class="button button-primary" value="Run Manual Sync">
                    </form>
                <?php endif; ?>

                <h2>Last Synchronization</h2>
                <p>Last sync: <?php echo get_option('wc_optima_last_sync', 'Never'); ?></p>
                <p>Products added: <?php echo get_option('wc_optima_products_added', '0'); ?></p>
                <p>Products updated: <?php echo get_option('wc_optima_products_updated', '0'); ?></p>
                <p>Categories created: <?php echo get_option('wc_optima_categories_created', '0'); ?></p>


                <?php
                echo '<h3>Debug Cron Information</h3>';
                $timestamp = wp_next_scheduled('wc_optima_daily_sync');
                if ($timestamp) {
                    echo '<p>Next sync scheduled at: ' . date('Y-m-d H:i:s', $timestamp) . '</p>';
                } else {
                    echo '<p style="color: red;">No sync scheduled! Attempting to schedule now...</p>';
                    // Try to schedule it now
                    $current_time = time();
                    $current_date = date('Y-m-d', $current_time);
                    $target_time = strtotime($current_date . ' 04:30:00');

                    // If it's already past 4:30 AM today, schedule for tomorrow
                    if ($current_time > $target_time) {
                        $target_time = strtotime('+1 day', $target_time);
                    }

                    wp_schedule_event($target_time, 'daily_at_0430', 'wc_optima_daily_sync');
                    // Check again
                    $new_timestamp = wp_next_scheduled('wc_optima_daily_sync');
                    echo '<p>' . ($new_timestamp ? 'Successfully scheduled for: ' . date('Y-m-d H:i:s', $new_timestamp) : 'Still unable to schedule!') . '</p>';
                }

                // Show all scheduled cron events
                echo '<h4>All Scheduled Events:</h4>';
                $cron_array = _get_cron_array();
                echo '<pre>';
                print_r($cron_array);
                echo '</pre>';

                // Add this to your admin page, in the sync tab
                echo '<form method="post">';
                wp_nonce_field('wc_optima_force_reschedule', 'wc_optima_reschedule_nonce');
                echo '<input type="submit" name="wc_optima_force_reschedule" class="button" value="Force Reschedule Cron Job">';
                echo '</form>';

                // And at the beginning of your admin_page method, add:
                if (isset($_POST['wc_optima_force_reschedule']) && wp_verify_nonce($_POST['wc_optima_reschedule_nonce'], 'wc_optima_force_reschedule')) {
                    // Clear existing schedule
                    $timestamp = wp_next_scheduled('wc_optima_daily_sync');
                    if ($timestamp) {
                        wp_unschedule_event($timestamp, 'wc_optima_daily_sync');
                    }

                    // Reschedule
                    $current_time = time();
                    $current_date = date('Y-m-d', $current_time);
                    $target_time = strtotime($current_date . ' 04:30:00');

                    // If it's already past 4:30 AM today, schedule for tomorrow
                    if ($current_time > $target_time) {
                        $target_time = strtotime('+1 day', $target_time);
                    }

                    wp_schedule_event($target_time, 'daily_at_0430', 'wc_optima_daily_sync');
                    echo '<div class="notice notice-success"><p>Cron job has been rescheduled.</p></div>';
                }
                ?>



            <?php elseif ($active_tab === 'settings'): ?>

                <form method="post" action="options.php">
                    <?php
                    settings_fields('wc_optima_settings_group');
                    $options = get_option('wc_optima_settings', [
                        'api_url' => '',
                        'username' => '',
                        'password' => ''
                    ]);
                    ?>

                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">API URL</th>
                            <td>
                                <input type="url" name="wc_optima_settings[api_url]" value="<?php echo esc_attr($options['api_url']); ?>" class="regular-text" placeholder="http://example.com/api" />
                                <p class="description">Enter the full URL to the Optima API (e.g., http://194.150.196.122:8603/api)</p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">Username</th>
                            <td>
                                <input type="text" name="wc_optima_settings[username]" value="<?php echo esc_attr($options['username']); ?>" class="regular-text" placeholder="Username" />
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">Password</th>
                            <td>
                                <input type="password" name="wc_optima_settings[password]" value="" class="regular-text" placeholder="Enter new password" />
                                <?php if (!empty($options['password'])): ?>
                                    <p class="description">Password is set. Leave blank to keep current password.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Settings'); ?>
                </form>

            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Get access token from Optima API
     */
    private function get_access_token()
    {
        // Check if credentials are set
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            error_log('WC Optima Integration: API credentials not configured.');
            return false;
        }

        // Check if we have a valid cached token
        $token_data = get_option('wc_optima_token_data', null);

        if ($token_data && isset($token_data['expires_at']) && $token_data['expires_at'] > time()) {
            $this->access_token = $token_data['access_token'];
            return $this->access_token;
        }

        // No valid token, request a new one
        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available through autoloading, let's use WordPress HTTP API
                return $this->get_token_with_wp_http();
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'form_params' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'grant_type' => 'password'
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ];

            $response = $client->request('POST', $this->api_url . '/Token', $options);
            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['access_token'])) {
                $this->access_token = $result['access_token'];

                // Save token with expiry information
                $token_data = [
                    'access_token' => $result['access_token'],
                    'expires_at' => time() + $result['expires_in'] - 300 // 5 minutes buffer
                ];

                update_option('wc_optima_token_data', $token_data);

                return $this->access_token;
            }
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting access token - ' . $e->getMessage());
            // Fall back to WordPress HTTP API if Guzzle fails
            return $this->get_token_with_wp_http();
        }

        return false;
    }

    /**
     * Get token using WordPress HTTP API as a fallback
     */
    private function get_token_with_wp_http()
    {
        // Check if credentials are set
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            error_log('WC Optima Integration: API credentials not configured.');
            return false;
        }

        $response = wp_remote_post($this->api_url . '/Token', [
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'username' => $this->username,
                'password' => $this->password,
                'grant_type' => 'password'
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['access_token'])) {
            $this->access_token = $result['access_token'];

            // Save token with expiry information
            $token_data = [
                'access_token' => $result['access_token'],
                'expires_at' => time() + $result['expires_in'] - 300 // 5 minutes buffer
            ];

            update_option('wc_optima_token_data', $token_data);

            return $this->access_token;
        }

        return false;
    }

    /**
     * Get products from Optima API
     */
    private function get_optima_products()
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_products_with_wp_http($token);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Items', $options);
            $products = json_decode($response->getBody()->getContents(), true);

            return $products;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting products - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_products_with_wp_http($token);
        }

        return false;
    }

    /**
     * Get products using WordPress HTTP API as a fallback
     */
    private function get_products_with_wp_http($token)
    {
        $response = wp_remote_get($this->api_url . '/Items', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $products = json_decode($body, true);

        return $products;
    }

    /**
     * Get product stock quantities from Optima API
     */
    private function get_optima_stock()
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_stock_with_wp_http($token);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Stocks', $options);
            $stocks = json_decode($response->getBody()->getContents(), true);

            return $stocks;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting stock quantities - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_stock_with_wp_http($token);
        }

        return false;
    }

    /**
     * Get stock using WordPress HTTP API as a fallback
     */
    private function get_stock_with_wp_http($token)
    {
        $response = wp_remote_get($this->api_url . '/Stocks', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $stocks = json_decode($body, true);

        return $stocks;
    }

    /**
     * Process stock data and create a lookup array
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
                    'reservation' => isset($warehouse_data['reservation']) ? floatval($warehouse_data['reservation']) : 0,
                    'available' => isset($warehouse_data['quantity']) ?
                        (floatval($warehouse_data['quantity']) -
                            (isset($warehouse_data['reservation']) ? floatval($warehouse_data['reservation']) : 0)) : 0,
                    'unit' => isset($warehouse_data['unit']) ? $warehouse_data['unit'] : '',
                    'warehouse_id' => isset($warehouse_data['warehouseId']) ? $warehouse_data['warehouseId'] : 0
                ];
            }
        }

        return $stock_lookup;
    }

    /**
     * Format prices from Optima for storage
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

        // Check if API credentials are configured
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            error_log('WC Optima Integration: API credentials not configured.');
            return;
        }

        // Get products from Optima API
        $optima_products = $this->get_optima_products();

        if (!$optima_products) {
            error_log('WC Optima Integration: No products retrieved from Optima');
            return;
        }

        // Get stock information from Optima API
        $optima_stocks = $this->get_optima_stock();
        $stock_data = $this->process_stock_data($optima_stocks);

        $products_added = 0;
        $products_updated = 0;

        // Get existing WooCommerce product IDs
        $existing_products = $this->get_existing_product_skus();

        // Process each product from Optima
        foreach ($optima_products as $product) {
            // Map Optima fields to WooCommerce fields based on the provided JSON structure
            $sku = isset($product['code']) ? $product['code'] : null;
            $name = isset($product['name']) ? $product['name'] : null;
            $description = isset($product['description']) ? $product['description'] : '';

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
                '_optima_id' => isset($product['id']) ? $product['id']
                : '',
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
                    $wc_product->set_name($name);
                    $wc_product->set_description($description);
                    $wc_product->set_regular_price($price);
                    $wc_product->set_stock_quantity($stock_quantity);
                    $wc_product->set_manage_stock(true);
                    $wc_product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
                    
                    // Set dimensions if available
                    if ($height > 0) $wc_product->set_height($height);
                    if ($width > 0) $wc_product->set_width($width);
                    if ($length > 0) $wc_product->set_length($length);
                    
                    // Set category if available
                    if ($category_id > 0) {
                        $wc_product->set_category_ids([$category_id]);
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
                    
                    // Set SKU
                    $wc_product->set_sku($sku);
                    
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
        
        error_log(sprintf('WC Optima Integration: Sync completed. Added: %d, Updated: %d', $products_added, $products_updated));
    }

    /**
     * Add a new tab to the product data metabox
     */
    public function add_optima_product_data_tab($tabs)
    {
        $tabs['optima'] = array(
            'label'    => __('Optima', 'wc-optima-integration'),
            'target'   => 'optima_product_data',
            'class'    => array(),
            'priority' => 90
        );
        return $tabs;
    }

    /**
     * Display Optima meta data in the product data panel
     */
    public function display_optima_meta_data_panel()
    {
        global $post;

        // Get the product ID
        $product_id = $post->ID;

        // Get all Optima meta data
        $optima_id = get_post_meta($product_id, '_optima_id', true);
        $optima_type = get_post_meta($product_id, '_optima_type', true);
        $optima_vat_rate = get_post_meta($product_id, '_optima_vat_rate', true);
        $optima_unit = get_post_meta($product_id, '_optima_unit', true);
        $optima_barcode = get_post_meta($product_id, '_optima_barcode', true);
        $optima_catalog_number = get_post_meta($product_id, '_optima_catalog_number', true);
        $optima_default_group = get_post_meta($product_id, '_optima_default_group', true);
        $optima_sales_category = get_post_meta($product_id, '_optima_sales_category', true);
        $optima_stock_data = get_post_meta($product_id, '_optima_stock_data', true);

        // Only display the panel if we have Optima data
        if (empty($optima_id) && empty($optima_catalog_number) && empty($optima_barcode)) {
            return;
        }

        // Start the Optima data panel
        ?>
        <div id="optima_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label><?php _e('Optima ID', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_id); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Catalog Number', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_catalog_number); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Barcode', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_barcode); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Type', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_type); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('VAT Rate', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_vat_rate); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Unit', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_unit); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Default Group', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_default_group); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Sales Category', 'wc-optima-integration'); ?></label>
                    <span><?php echo esc_html($optima_sales_category); ?></span>
                </p>
                <?php if (!empty($optima_stock_data)) : 
                    $stock_data = json_decode($optima_stock_data, true);
                ?>
                <p class="form-field">
                    <label><?php _e('Stock Quantity', 'wc-optima-integration'); ?></label>
                    <span><?php echo isset($stock_data['quantity']) ? esc_html($stock_data['quantity']) : '0'; ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Reserved', 'wc-optima-integration'); ?></label>
                    <span><?php echo isset($stock_data['reservation']) ? esc_html($stock_data['reservation']) : '0'; ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Available', 'wc-optima-integration'); ?></label>
                    <span><?php echo isset($stock_data['available']) ? esc_html($stock_data['available']) : '0'; ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Process customer for Optima when a new order is placed
     * 
     * @param int $order_id The WooCommerce order ID
     * @param array $posted_data The posted data from checkout
     * @param WC_Order $order The WooCommerce order object
     */
    public function process_customer_for_optima($order_id, $posted_data, $order) {
        // Check if API credentials are configured
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            error_log('WC Optima Integration: API credentials not configured.');
            return;
        }

        // Get customer ID from order
        $customer_id = $order->get_customer_id();
        
        // Check if we already have an Optima customer ID for this user
        $optima_customer_id = '';
        
        if ($customer_id > 0) {
            // Registered user - check user meta
            $optima_customer_id = get_user_meta($customer_id, '_optima_customer_id', true);
        } else {
            // Guest order - check if we can find by email
            $customer_email = $order->get_billing_email();
            
            // Check if this email already has an Optima customer ID in any previous orders
            $existing_orders = wc_get_orders(array(
                'billing_email' => $customer_email,
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'exclude' => array($order_id), // Exclude current order
            ));
            
            if (!empty($existing_orders)) {
                $existing_order = reset($existing_orders);
                $optima_customer_id = $existing_order->get_meta('_optima_customer_id', true);
            }
        }
        
        // If we already have an Optima customer ID, update the order and return
        if (!empty($optima_customer_id)) {
            $order->update_meta_data('_optima_customer_id', $optima_customer_id);
            $order->save();
            return;
        }
        
        // Check if customer exists in Optima by email
        $customer_email = $order->get_billing_email();
        $customer_vat = $order->get_meta('_billing_vat', true); // Assuming VAT is stored in this meta field
        
        $optima_customer = $this->check_customer_exists_in_optima($customer_email, $customer_vat);
        
        if ($optima_customer) {
            // Customer exists in Optima, save the ID
            $optima_customer_id = $optima_customer['id'];
        } else {
            // Customer doesn't exist, create a new one
            $customer_data = $this->map_wc_customer_to_optima($customer_id, $order);
            $new_customer = $this->create_optima_customer($customer_data);
            
            if ($new_customer && isset($new_customer['id'])) {
                $optima_customer_id = $new_customer['id'];
            } else {
                error_log('WC Optima Integration: Failed to create customer in Optima');
                return;
            }
        }
        
        // Save Optima customer ID to order meta
        $order->update_meta_data('_optima_customer_id', $optima_customer_id);
        $order->save();
        
        // If this is a registered user, also save to user meta
        if ($customer_id > 0) {
            update_user_meta($customer_id, '_optima_customer_id', $optima_customer_id);
        }
        
        error_log(sprintf('WC Optima Integration: Customer ID %s assigned to order %s', $optima_customer_id, $order_id));
    }

    /**
     * Get customers from Optima API
     * 
     * @return array|false Array of customers or false on failure
     */
    private function get_optima_customers() {
        $token = $this->get_access_token();
        
        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }
        
        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_customers_with_wp_http($token);
            }
            
            $client = new \GuzzleHttp\Client();
            
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];
            
            $response = $client->request('GET', $this->api_url . '/Customers', $options);
            $customers = json_decode($response->getBody()->getContents(), true);
            
            return $customers;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting customers - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_customers_with_wp_http($token);
        }
        
        return false;
    }

    /**
     * Get customers using WordPress HTTP API as a fallback
     * 
     * @param string $token The access token
     * @return array|false Array of customers or false on failure
     */
    private function get_customers_with_wp_http($token) {
        $response = wp_remote_get($this->api_url . '/Customers', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $customers = json_decode($body, true);
        
        return $customers;
    }

    /**
     * Check if a customer exists in Optima
     * 
     * @param string $email Customer email
     * @param string $vat_number Customer VAT number (optional)
     * @return array|false Customer data if found, false otherwise
     */
    private function check_customer_exists_in_optima($email, $vat_number = '') {
        $customers = $this->get_optima_customers();
        
        if (!$customers || !is_array($customers)) {
            return false;
        }
        
        // First try to find by email (most reliable)
        if (!empty($email)) {
            foreach ($customers as $customer) {
                if (isset($customer['email']) && strtolower($customer['email']) === strtolower($email)) {
                    return $customer;
                }
            }
        }
        
        // If not found by email and VAT number is provided, try to find by VAT
        if (!empty($vat_number)) {
            foreach ($customers as $customer) {
                if (isset($customer['vatNumber']) && $customer['vatNumber'] === $vat_number) {
                    return $customer;
                }
            }
        }
        
        return false;
    }

    /**
     * Create a new customer in Optima
     * 
     * @param array $customer_data Customer data in Optima format
     * @return array|false New customer data if created, false on failure
     */
    private function create_optima_customer($customer_data) {
        $token = $this->get_access_token();
        
        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }
        
        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->create_customer_with_wp_http($token, $customer_data);
            }
            
            $client = new \GuzzleHttp\Client();
            
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($customer_data)
            ];
            
            $response = $client->request('POST', $this->api_url . '/Customers', $options);
            $result = json_decode($response->getBody()->getContents(), true);
            
            return $result;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error creating customer - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->create_customer_with_wp_http($token, $customer_data);
        }
        
        return false;
    }

    /**
     * Create customer using WordPress HTTP API as a fallback
     * 
     * @param string $token The access token
     * @param array $customer_data Customer data in Optima format
     * @return array|false New customer data if created, false on failure
     */
    private function create_customer_with_wp_http($token, $customer_data) {
        $response = wp_remote_post($this->api_url . '/Customers', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($customer_data)
        ]);
        
        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        return $result;
    }

    /**
     * Map WooCommerce customer data to Optima format
     * 
     * @param int $customer_id WooCommerce customer ID
     * @param WC_Order $order WooCommerce order object
     * @return array Customer data in Optima format
     */
    private function map_wc_customer_to_optima($customer_id, $order) {
        // Generate a unique customer code
        $customer_code = 'WC_' . date('Ymd') . '_' . $order->get_id();
        
        // Get customer name
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $company = $order->get_billing_company();
        
        // Determine name1 (company name or full name)
        $name1 = !empty($company) ? $company : trim($first_name . ' ' . $last_name);
        
        // Get VAT number (assuming it's stored in a custom field)
        $vat_number = $order->get_meta('_billing_vat', true);
        
        // Get address details
        $country = $order->get_billing_country();
        $city = $order->get_billing_city();
        $address_1 = $order->get_billing_address_1();
        $address_2 = $order->get_billing_address_2();
        $postcode = $order->get_billing_postcode();
        
        // Get contact details
        $phone = $order->get_billing_phone();
        $email = $order->get_billing_email();
        
        // Map country code to ISO code
        $country_iso = $country;
        $country_name = WC()->countries->countries[$country] ?? $country;
        
        // Prepare customer data for Optima
        $customer_data = [
            'code' => $customer_code,
            'name1' => $name1,
            'name2' => !empty($company) ? trim($first_name . ' ' . $last_name) : '',
            'name3' => '',
            'vatNumber' => $vat_number,
            'country' => $country_name,
            'city' => $city,
            'street' => $address_1,
            'additionalAdress' => $address_2,
            'postCode' => $postcode,
            'houseNumber' => '', // Not directly available in WooCommerce
            'flatNumber' => '', // Not directly available in WooCommerce
            'phone1' => $phone,
            'phone2' => '',
            'inactive' => 0,
            'defaultPrice' => 0,
            'regon' => '', // Not directly available in WooCommerce
            'email' => $email,
            'paymentMethod' => 'gotówka', // Default payment method
            'dateOfPayment' => 0,
            'maxPaymentDelay' => 0,
            'description' => 'Customer created from WooCommerce order #' . $order->get_id(),
            'countryCode' => $country_iso
        ];
        
        return $customer_data;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    // Check if WooCommerce is active before initializing
    if (class_exists('WooCommerce')) {
        new WC_Optima_Integration();
    }
});


add_action('wc_optima_daily_sync', function () {
    error_log('WC Optima sync cron executed at: ' . date('Y-m-d H:i:s'));
});
