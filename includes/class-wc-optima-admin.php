<?php

/**
 * Admin functionality for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling admin functionality
 */
class WC_Optima_Admin
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Add product meta data display in admin
        add_filter('woocommerce_product_data_tabs', array($this, 'add_optima_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'display_optima_meta_data_panel'));

        // Add AJAX handlers for customer operations
        add_action('wp_ajax_wc_optima_fetch_customers', array($this, 'ajax_fetch_customers'));
        add_action('wp_ajax_wc_optima_create_sample_customer', array($this, 'ajax_create_sample_customer'));

        // Add Optima customer ID to user profile
        add_action('show_user_profile', array($this, 'display_optima_customer_id_field'));
        add_action('edit_user_profile', array($this, 'display_optima_customer_id_field'));

        // Add Optima customer ID to order view
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_optima_customer_id_in_order'));
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_styles()
    {
        wp_enqueue_style(
            'wc-optima-admin-styles',
            plugins_url('admin-styles.css', OPTIMA_WC_PLUGIN_FILE)
        );

        // Only load scripts on our admin page
        $screen = get_current_screen();
        if ($screen && $screen->id === 'woocommerce_page_wc-optima-integration') {
            wp_enqueue_script(
                'wc-optima-admin-scripts',
                plugins_url('admin-scripts.js', OPTIMA_WC_PLUGIN_FILE),
                array('jquery'),
                '1.0.0',
                true
            );

            // Add the ajax url to the script
            wp_localize_script('wc-optima-admin-scripts', 'wc_optima_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_optima_fetch_customers')
            ));
        }
    }

    /**
     * AJAX handler for fetching customers
     */
    public function ajax_fetch_customers()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_fetch_customers')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Get customers with limit
        $customers = $api->get_optima_customers();

        if (!$customers) {
            wp_send_json_error('Failed to fetch customers from Optima API');
            return;
        }

        // Send success response
        wp_send_json_success($customers);
    }

    /**
     * AJAX handler for creating a sample customer
     */
    public function ajax_create_sample_customer()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_fetch_customers')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Create sample customer data
        $customer_data = [
            'code' => 'WC_' . substr(date('Ymd_His'), 0, 15),
            'name1' => 'Test Account',
            'name2' => 'Test Account',
            'name3' => '',
            'vatNumber' => '6616681238',
            'country' => 'Poland',
            'city' => 'Warsaw',
            'street' => 'Sample Street',
            'additionalAdress' => 'Apt 123',
            'postCode' => '00-001',
            'houseNumber' => '10',
            'flatNumber' => '5',
            'phone1' => '+48 123 456 789',
            'phone2' => '',
            'inactive' => 0,
            'defaultPrice' => 0,
            'regon' => '123456789',
            'email' => 'sample.customer' . rand(1000, 9999) . '@example.com',
            'paymentMethod' => 'gotówka',
            'dateOfPayment' => 0,
            'maxPaymentDelay' => 0,
            'description' => 'Sample customer created from WooCommerce',
            'countryCode' => 'PL'
        ];

        // Create customer in Optima
        $result = $api->create_optima_customer($customer_data);

        if (!$result) {
            wp_send_json_error('Failed to create sample customer in Optima API');
            return;
        }

        // Send success response
        wp_send_json_success($result);
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
            // Get the product sync instance and run sync
            do_action('wc_optima_daily_sync');
            echo '<div class="notice notice-success"><p>Synchronization with Optima completed.</p></div>';
        }

        // Admin page HTML
?>
        <div class="wrap">
            <h1>WooCommerce Optima Integration</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=wc-optima-integration&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">Synchronization</a>
                <a href="?page=wc-optima-integration&tab=customers" class="nav-tab <?php echo $active_tab === 'customers' ? 'nav-tab-active' : ''; ?>">Customers</a>
                <a href="?page=wc-optima-integration&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            </h2>

            <?php if ($active_tab === 'sync'): ?>

                <p>This plugin synchronizes products and inventory between Optima API and WooCommerce once daily at 04:30 (4:30 AM).</p>

                <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
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



            <?php elseif ($active_tab === 'customers'): ?>

                <div class="optima-customers-section">
                    <h2>Optima Customers</h2>

                    <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                        <div class="notice notice-warning">
                            <p>Please configure API settings before fetching customers. Go to the <a href="?page=wc-optima-integration&tab=settings">Settings tab</a>.</p>
                        </div>
                    <?php else: ?>
                        <div class="optima-customer-actions">
                            <button id="wc-optima-create-customer" class="button button-secondary">Add Sample Customer</button>
                            <button id="wc-optima-fetch-customers" class="button button-primary">Get Latest 50 Customers</button>
                        </div>

                        <div id="wc-optima-customers-loading" style="display: none;">
                            <p><span class="spinner is-active" style="float: none;"></span> Loading customers...</p>
                        </div>

                        <div id="wc-optima-customers-results" style="margin-top: 20px;">
                            <!-- Results will be displayed here -->
                        </div>
                    <?php endif; ?>
                </div>

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
     * Display Optima customer ID field in user profile
     *
     * @param WP_User $user The user object being edited
     */
    public function display_optima_customer_id_field($user)
    {
        // Get the Optima customer ID
        $optima_customer_id = get_user_meta($user->ID, '_optima_customer_id', true);

        // Only display if we have an Optima customer ID
        if (empty($optima_customer_id)) {
            return;
        }

    ?>
        <h3><?php _e('Optima Integration', 'wc-optima-integration'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="optima_customer_id"><?php _e('Optima Customer ID', 'wc-optima-integration'); ?></label></th>
                <td>
                    <input type="text" name="optima_customer_id" id="optima_customer_id" value="<?php echo esc_attr($optima_customer_id); ?>" class="regular-text" readonly />
                    <p class="description"><?php _e('This is the customer ID in the Optima system.', 'wc-optima-integration'); ?></p>
                </td>
            </tr>
        </table>
    <?php
    }

    /**
     * Display Optima customer ID in order view
     *
     * @param WC_Order $order The order object
     */
    public function display_optima_customer_id_in_order($order)
    {
        // Get the Optima customer ID from order meta
        $optima_customer_id = $order->get_meta('_optima_customer_id', true);

        // Only display if we have an Optima customer ID
        if (empty($optima_customer_id)) {
            return;
        }

    ?>
        <div class="order_data_column">
            <h4><?php _e('Optima Integration', 'wc-optima-integration'); ?></h4>
            <p>
                <strong><?php _e('Optima Customer ID:', 'wc-optima-integration'); ?></strong>
                <?php echo esc_html($optima_customer_id); ?>
            </p>
        </div>
<?php
    }
}
