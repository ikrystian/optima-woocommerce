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

        // Add AJAX handler for RO documents
        add_action('wp_ajax_wc_optima_fetch_ro_documents', array($this, 'ajax_fetch_ro_documents'));
        add_action('wp_ajax_wc_optima_search_ro_document', array($this, 'ajax_search_ro_document'));

        // Add AJAX handlers for invoices
        add_action('wp_ajax_wc_optima_fetch_invoices', array($this, 'ajax_fetch_invoices'));
        add_action('wp_ajax_wc_optima_search_invoice', array($this, 'ajax_search_invoice'));
        add_action('wp_ajax_wc_optima_get_invoice_pdf', array($this, 'ajax_get_invoice_pdf'));

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
                plugins_url('../admin-scripts.js', __FILE__), // Corrected path
                array('jquery'),
                '1.0.0',
                true
            );

            // Add the ajax url and translated strings to the script
            wp_localize_script('wc-optima-admin-scripts', 'wc_optima_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_optima_fetch_customers'),
                'ro_nonce' => wp_create_nonce('wc_optima_fetch_ro_documents'),
                'invoice_nonce' => wp_create_nonce('wc_optima_fetch_invoices'),
                'ro_nonce' => wp_create_nonce('wc_optima_fetch_ro_documents'),
                'search_nonce' => wp_create_nonce('wc_optima_search_ro_document'), // Add nonce for search
                'loading_customers' => __('Ładowanie klientów...', 'optima-woocommerce'),
                'loading_documents' => __('Ładowanie dokumentów RO...', 'optima-woocommerce'),
                'error_fetching_customers' => __('Błąd podczas pobierania klientów.', 'optima-woocommerce'),
                'error_creating_customer' => __('Błąd podczas tworzenia przykładowego klienta.', 'optima-woocommerce'),
                'error_fetching_documents' => __('Błąd podczas pobierania dokumentów RO.', 'optima-woocommerce'),
                'error_searching_document' => __('Błąd podczas wyszukiwania dokumentu.', 'optima-woocommerce'),
                'customer_created_success' => __('Przykładowy klient został pomyślnie utworzony.', 'optima-woocommerce'),
                'no_customers_found' => __('Nie znaleziono klientów.', 'optima-woocommerce'),
                'no_documents_found' => __('Nie znaleziono dokumentów RO.', 'optima-woocommerce'),
                'document_not_found' => __('Nie znaleziono dokumentu o podanym ID.', 'optima-woocommerce'),
                'error_prefix' => __('Błąd:', 'optima-woocommerce'),
                'generic_error' => __('Wystąpił nieoczekiwany błąd.', 'optima-woocommerce'),
                'showing_customers' => __('Wyświetlanie %d klientów', 'optima-woocommerce'), // %d will be replaced by the number
                'showing_documents' => __('Wyświetlanie %d dokumentów RO', 'optima-woocommerce'), // %d will be replaced by the number
                'items_suffix' => __('elementy', 'optima-woocommerce'), // For "X items"
                'enter_doc_id_alert' => __('Proszę wprowadzić ID dokumentu do wyszukania', 'optima-woocommerce'),
                // Table Headers
                'th_id' => __('ID', 'optima-woocommerce'),
                'th_code' => __('Kod', 'optima-woocommerce'),
                'th_name' => __('Nazwa', 'optima-woocommerce'),
                'th_email' => __('Email', 'optima-woocommerce'),
                'th_phone' => __('Telefon', 'optima-woocommerce'),
                'th_city' => __('Miasto', 'optima-woocommerce'),
                'th_type' => __('Typ', 'optima-woocommerce'),
                'th_number' => __('Numer', 'optima-woocommerce'),
                'th_foreign_number' => __('Numer obcy', 'optima-woocommerce'),
                'th_payment_method' => __('Metoda płatności', 'optima-woocommerce'),
                'th_currency' => __('Waluta', 'optima-woocommerce'),
                'th_status' => __('Status', 'optima-woocommerce'),
                'th_sale_date' => __('Data sprzedaży', 'optima-woocommerce'),
                'th_amount_to_pay' => __('Do zapłaty', 'optima-woocommerce'),
                'th_category' => __('Kategoria', 'optima-woocommerce'),
                'th_payer' => __('Płatnik', 'optima-woocommerce'),
                'th_recipient' => __('Odbiorca', 'optima-woocommerce'),
                'th_elements' => __('Elementy', 'optima-woocommerce'),
                'th_reservation_date' => __('Data rezerwacji', 'optima-woocommerce')
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
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa', 'optima-woocommerce'));
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error(__('API nie zostało zainicjowane', 'optima-woocommerce'));
            return;
        }

        // Get customers with limit
        $customers = $api->get_optima_customers();

        if ($customers === false) { // Check for false explicitly, as an empty array is valid
            wp_send_json_error(__('Nie udało się pobrać klientów z API Optima', 'optima-woocommerce'));
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
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa', 'optima-woocommerce'));
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error(__('API nie zostało zainicjowane', 'optima-woocommerce'));
            return;
        }

        // Create sample customer data
        $customer_data = [
            'code' => 'WC_' . substr(date('Ymd_His'), 0, 15),
            'name1' => __('Konto Testowe', 'optima-woocommerce'),
            'name2' => __('Konto Testowe', 'optima-woocommerce'),
            'name3' => '',
            'vatNumber' => '6616681238', // Use a valid test NIP if possible, or make it clear it's fake
            'country' => __('Polska', 'optima-woocommerce'),
            'city' => __('Warszawa', 'optima-woocommerce'),
            'street' => __('Przykładowa Ulica', 'optima-woocommerce'),
            'additionalAdress' => __('Lok. 123', 'optima-woocommerce'),
            'postCode' => '00-001',
            'houseNumber' => '10',
            'flatNumber' => '5',
            'phone1' => '+48 123 456 789',
            'phone2' => '',
            'inactive' => 0,
            'defaultPrice' => 0,
            'regon' => '123456789',
            'email' => 'przykładowy.klient' . rand(1000, 9999) . '@example.com',
            'paymentMethod' => 'gotówka',
            'dateOfPayment' => 0,
            'maxPaymentDelay' => 0,
            'description' => __('Przykładowy klient utworzony z WooCommerce', 'optima-woocommerce'),
            'countryCode' => 'PL'
        ];

        // Create customer in Optima
        $result = $api->create_optima_customer($customer_data);

        if (!$result) {
            wp_send_json_error(__('Nie udało się utworzyć przykładowego klienta w API Optima', 'optima-woocommerce'));
            return;
        }

        // Send success response
        wp_send_json_success($result);
    }
    /**
     * AJAX handler for searching RO document by ID
     */
    public function ajax_search_ro_document()
    {
        // Check nonce - Use a specific nonce for this action
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_search_ro_document')) {
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa', 'optima-woocommerce'));
            return;
        }

        // Check if document ID is provided
        if (!isset($_POST['document_id']) || empty($_POST['document_id'])) {
            wp_send_json_error(__('ID dokumentu jest wymagane', 'optima-woocommerce'));
            return;
        }

        $document_id = sanitize_text_field($_POST['document_id']);

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error(__('API nie zostało zainicjowane', 'optima-woocommerce'));
            return;
        }

        // Get specific document by ID
        $document = $api->get_ro_document_by_id($document_id);

        if (!$document) {
            wp_send_json_error(sprintf(__('Nie znaleziono dokumentu o ID: %s', 'optima-woocommerce'), $document_id));
            return;
        }

        // Send success response
        wp_send_json_success($document);
    }
    /**
     * AJAX handler for fetching RO documents
     */
    public function ajax_fetch_ro_documents()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_fetch_ro_documents')) {
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa', 'optima-woocommerce'));
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error(__('API nie zostało zainicjowane', 'optima-woocommerce'));
            return;
        }

        // Get RO documents with limit
        $documents = $api->get_ro_documents();

        if ($documents === false) { // Check for false explicitly
            wp_send_json_error(__('Nie udało się pobrać dokumentów RO z API Optima', 'optima-woocommerce'));
            return;
        }

        // Send success response
        wp_send_json_success($documents);
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
        $sanitized = [];

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
                $current_options = get_option('wc_optima_settings', []);
                $sanitized['password'] = $current_options['password'] ?? '';
            }
        }

        // GUS API settings
        if (isset($input['gus_api_key'])) {
            $sanitized['gus_api_key'] = sanitize_text_field($input['gus_api_key']);
        }

        $sanitized['gus_production_mode'] = isset($input['gus_production_mode']) && $input['gus_production_mode'] === 'yes' ? 'yes' : 'no';
        $sanitized['gus_debug_mode'] = isset($input['gus_debug_mode']) && $input['gus_debug_mode'] === 'yes' ? 'yes' : 'no';
        $sanitized['gus_auto_update'] = isset($input['gus_auto_update']) && $input['gus_auto_update'] === 'yes' ? 'yes' : 'no';

        // Validate update frequency
        $valid_frequencies = ['daily', 'weekly', 'monthly', 'quarterly'];
        $sanitized['gus_update_frequency'] = in_array($input['gus_update_frequency'], $valid_frequencies) ? $input['gus_update_frequency'] : 'monthly';

        // If credentials changed, clear the token
        $current_options = get_option('wc_optima_settings', []);
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
            __('Integracja Optima', 'optima-woocommerce'), // Page title
            __('Integracja Optima', 'optima-woocommerce'), // Menu title
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
            echo '<div class="notice notice-success"><p>' . __('Synchronizacja z Optima zakończona.', 'optima-woocommerce') . '</p></div>';
        }

        // Handle force reschedule
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
            echo '<div class="notice notice-success"><p>' . __('Zadanie Cron zostało ponownie zaplanowane.', 'optima-woocommerce') . '</p></div>';
        }

        // Admin page HTML
?>
        <div class="wrap">
            <h1><?php _e('Integracja WooCommerce z Optima', 'optima-woocommerce'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=wc-optima-integration&tab=invoices" class="nav-tab <?php echo $active_tab === 'invoices' ? 'nav-tab-active' : ''; ?>">Invoices</a>
                <a href="?page=wc-optima-integration&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>"><?php _e('Synchronizacja', 'optima-woocommerce'); ?></a>
                <a href="?page=wc-optima-integration&tab=customers" class="nav-tab <?php echo $active_tab === 'customers' ? 'nav-tab-active' : ''; ?>"><?php _e('Klienci', 'optima-woocommerce'); ?></a>
                <a href="?page=wc-optima-integration&tab=rco" class="nav-tab <?php echo $active_tab === 'rco' ? 'nav-tab-active' : ''; ?>"><?php _e('Dokumenty RO', 'optima-woocommerce'); ?></a>
                <a href="?page=wc-optima-integration&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>"><?php _e('Test Faktury', 'optima-woocommerce'); ?></a>
                <a href="?page=wc-optima-integration&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php _e('Ustawienia', 'optima-woocommerce'); ?></a>
            </h2>

            <?php if ($active_tab === 'sync'): ?>

                <p><?php _e('Ten plugin synchronizuje produkty i stany magazynowe między API Optima a WooCommerce raz dziennie o 04:30.', 'optima-woocommerce'); ?></p>

                <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                    <div class="notice notice-warning">
                        <p><?php printf(__('Proszę skonfigurować ustawienia API przed uruchomieniem synchronizacji. Przejdź do zakładki <a href="%s">Ustawienia</a>.', 'optima-woocommerce'), '?page=wc-optima-integration&tab=settings'); ?></p>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <?php wp_nonce_field('wc_optima_manual_sync', 'wc_optima_sync_nonce'); ?>
                        <input type="submit" name="wc_optima_manual_sync" class="button button-primary" value="<?php _e('Uruchom ręczną synchronizację', 'optima-woocommerce'); ?>">
                    </form>
                <?php endif; ?>

                <h2><?php _e('Ostatnia synchronizacja', 'optima-woocommerce'); ?></h2>
                <p><?php _e('Ostatnia synchronizacja:', 'optima-woocommerce'); ?> <?php echo get_option('wc_optima_last_sync', __('Nigdy', 'optima-woocommerce')); ?></p>
                <p><?php _e('Dodane produkty:', 'optima-woocommerce'); ?> <?php echo get_option('wc_optima_products_added', '0'); ?></p>
                <p><?php _e('Zaktualizowane produkty:', 'optima-woocommerce'); ?> <?php echo get_option('wc_optima_products_updated', '0'); ?></p>
                <p><?php _e('Utworzone kategorie:', 'optima-woocommerce'); ?> <?php echo get_option('wc_optima_categories_created', '0'); ?></p>


                <?php
                echo '<h3>' . __('Informacje debugowania Cron', 'optima-woocommerce') . '</h3>';
                $timestamp = wp_next_scheduled('wc_optima_daily_sync');
                if ($timestamp) {
                    echo '<p>' . __('Następna synchronizacja zaplanowana na:', 'optima-woocommerce') . ' ' . date('Y-m-d H:i:s', $timestamp) . '</p>';
                } else {
                    echo '<p style="color: red;">' . __('Brak zaplanowanej synchronizacji! Próba zaplanowania teraz...', 'optima-woocommerce') . '</p>';
                    // Try to schedule it now (code remains the same)
                    $current_time = time();
                    $current_date = date('Y-m-d', $current_time);
                    $target_time = strtotime($current_date . ' 04:30:00');
                    if ($current_time > $target_time) {
                        $target_time = strtotime('+1 day', $target_time);
                    }
                    wp_schedule_event($target_time, 'daily_at_0430', 'wc_optima_daily_sync');
                    $new_timestamp = wp_next_scheduled('wc_optima_daily_sync');
                    echo '<p>' . ($new_timestamp ? __('Pomyślnie zaplanowano na:', 'optima-woocommerce') . ' ' . date('Y-m-d H:i:s', $new_timestamp) : __('Nadal nie można zaplanować!', 'optima-woocommerce')) . '</p>';
                }

                // Show all scheduled cron events
                echo '<h4>' . __('Wszystkie zaplanowane zdarzenia:', 'optima-woocommerce') . '</h4>';
                $cron_array = _get_cron_array();
                echo '<pre>';
                print_r($cron_array);
                echo '</pre>';

                // Force reschedule button
                echo '<form method="post">';
                wp_nonce_field('wc_optima_force_reschedule', 'wc_optima_reschedule_nonce');
                echo '<input type="submit" name="wc_optima_force_reschedule" class="button" value="' . __('Wymuś ponowne zaplanowanie zadania Cron', 'optima-woocommerce') . '">';
                echo '</form>';
                ?>

            <?php elseif ($active_tab === 'customers'): ?>

                <div class="optima-customers-section">
                    <h2><?php _e('Klienci Optima', 'optima-woocommerce'); ?></h2>

                    <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                        <div class="notice notice-warning">
                            <p><?php printf(__('Proszę skonfigurować ustawienia API przed pobraniem klientów. Przejdź do zakładki <a href="%s">Ustawienia</a>.', 'optima-woocommerce'), '?page=wc-optima-integration&tab=settings'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="optima-customer-actions">
                            <button id="wc-optima-create-customer" class="button button-secondary"><?php _e('Dodaj przykładowego klienta', 'optima-woocommerce'); ?></button>
                            <button id="wc-optima-fetch-customers" class="button button-primary"><?php _e('Pobierz 50 ostatnich klientów', 'optima-woocommerce'); ?></button>
                        </div>

                        <div id="wc-optima-customers-loading" style="display: none;">
                            <p><span class="spinner is-active" style="float: none;"></span> <?php _e('Ładowanie klientów...', 'optima-woocommerce'); ?></p>
                        </div>

                        <div id="wc-optima-customers-results" style="margin-top: 20px;">
                            <!-- Results will be displayed here -->
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'rco'): ?>

                <div class="optima-rco-section">
                    <h2><?php _e('Dokumenty RO Optima', 'optima-woocommerce'); ?></h2>

                    <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                        <div class="notice notice-warning">
                            <p><?php printf(__('Proszę skonfigurować ustawienia API przed pobraniem dokumentów RO. Przejdź do zakładki <a href="%s">Ustawienia</a>.', 'optima-woocommerce'), '?page=wc-optima-integration&tab=settings'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="optima-search-document">
                            <label for="wc-optima-document-id"><?php _e('ID Dokumentu:', 'optima-woocommerce'); ?></label>
                            <input type="text" id="wc-optima-document-id" name="document_id" placeholder="<?php _e('Wprowadź ID dokumentu', 'optima-woocommerce'); ?>">
                            <button id="wc-optima-search-ro-document" class="button"><?php _e('Wyszukaj dokument', 'optima-woocommerce'); ?></button>
                        </div>

                        <div class="optima-rco-actions">
                            <button id="wc-optima-fetch-ro-documents" class="button button-primary"><?php _e('Pobierz ostatnie dokumenty RO', 'optima-woocommerce'); ?></button>
                        </div>

                        <div id="wc-optima-ro-documents-loading" style="display: none;">
                            <p><span class="spinner is-active" style="float: none;"></span> <?php _e('Ładowanie dokumentów RO...', 'optima-woocommerce'); ?></p>
                        </div>

                        <div id="wc-optima-ro-documents-results" style="margin-top: 20px;">
                            <!-- Results will be displayed here -->
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'invoices'): ?>

                <div class="optima-invoices-section">
                    <h2>Optima Invoices</h2>

                    <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                        <div class="notice notice-warning">
                            <p>Please configure API settings before fetching invoices. Go to the <a href="?page=wc-optima-integration&tab=settings">Settings tab</a>.</p>
                        </div>
                    <?php else: ?>
                        <div class="optima-search-invoice">
                            <h3>Search Invoices</h3>
                            <div class="search-form">
                                <div class="search-field">
                                    <label for="wc-optima-invoice-number">Invoice Number:</label>
                                    <input type="text" id="wc-optima-invoice-number" name="invoice_number" placeholder="Enter invoice number">
                                </div>
                                <div class="search-field">
                                    <label for="wc-optima-date-from">Date From:</label>
                                    <input type="date" id="wc-optima-date-from" name="date_from">
                                </div>
                                <div class="search-field">
                                    <label for="wc-optima-date-to">Date To:</label>
                                    <input type="date" id="wc-optima-date-to" name="date_to">
                                </div>
                                <div class="search-field">
                                    <label for="wc-optima-customer-id">Customer ID:</label>
                                    <input type="text" id="wc-optima-customer-id" name="customer_id" placeholder="Enter customer ID">
                                </div>
                                <div class="search-actions">
                                    <button id="wc-optima-search-invoice" class="button">Search Invoices</button>
                                    <button id="wc-optima-fetch-invoices" class="button button-primary">Get Latest Invoices</button>
                                </div>
                            </div>
                        </div>

                        <div id="wc-optima-invoices-loading" style="display: none;">
                            <p><span class="spinner is-active" style="float: none;"></span> Loading invoices...</p>
                        </div>

                        <div id="wc-optima-invoices-results" style="margin-top: 20px;">
                            <!-- Results will be displayed here -->
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'test'): ?>

                <div class="optima-test-section">
                    <h2><?php _e('Test Faktury dla Zamówień Zrealizowanych', 'optima-woocommerce'); ?></h2>

                    <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                        <div class="notice notice-warning">
                            <p><?php printf(__('Proszę skonfigurować ustawienia API przed testowaniem. Przejdź do zakładki <a href="%s">Ustawienia</a>.', 'optima-woocommerce'), '?page=wc-optima-integration&tab=settings'); ?></p>
                        </div>
                    <?php else: ?>
                        <p><?php _e('Ten formularz pozwala na testowanie funkcjonalności tworzenia faktury w Optima po zmianie statusu zamówienia na "zrealizowane".', 'optima-woocommerce'); ?></p>

                        <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                            <input type="hidden" name="page" value="wc-optima-integration">
                            <input type="hidden" name="tab" value="test">
                            <input type="hidden" name="test_order_completed" value="1">

                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="order_id"><?php _e('ID Zamówienia', 'optima-woocommerce'); ?></label></th>
                                    <td>
                                        <input type="number" name="order_id" id="order_id" class="regular-text" min="1" required>
                                        <p class="description"><?php _e('Wprowadź ID zamówienia, dla którego chcesz przetestować tworzenie faktury.', 'optima-woocommerce'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Testuj Tworzenie Faktury', 'optima-woocommerce'); ?>">
                            </p>
                        </form>

                        <?php
                        // Check if this is a test request
                        if (isset($_GET['test_order_completed']) && isset($_GET['order_id'])) {
                            $order_id = intval($_GET['order_id']);

                            // Include the test script
                            include_once plugin_dir_path(OPTIMA_WC_PLUGIN_FILE) . 'test-order-completed.php';
                        }
                        ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'invoice-history'): ?>

                <div class="optima-invoice-history-section">
                    <h2>Invoice History</h2>

                    <?php if (empty($this->options['api_url']) || empty($this->options['username']) || empty($this->options['password'])): ?>
                        <div class="notice notice-warning">
                            <p>Please configure API settings before using invoice history. Go to the <a href="?page=wc-optima-integration&tab=settings">Settings tab</a>.</p>
                        </div>
                    <?php else: ?>
                        <p>Use the shortcode <code>[optima_invoice_history]</code> on any page to display the invoice history for logged-in customers.</p>

                        <h3>Shortcode Usage</h3>
                        <p>The shortcode will display a form where customers can search for their invoices and download them as PDFs.</p>

                        <h3>Requirements</h3>
                        <ul>
                            <li>Users must be logged in to view their invoice history.</li>
                            <li>Users must have an Optima customer ID associated with their account.</li>
                        </ul>

                        <h3>How to Associate Optima Customer IDs with Users</h3>
                        <p>When a customer places an order, their Optima customer ID is automatically saved to their user account if available. You can also manually set the Optima customer ID in the user's profile page.</p>

                        <h3>Preview</h3>
                        <div class="optima-invoice-history-preview">
                            <img src="<?php echo plugins_url('assets/images/invoice-history-preview.png', OPTIMA_WC_PLUGIN_FILE); ?>" alt="Invoice History Preview" style="max-width: 100%; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
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
                        'password' => '',
                        'gus_api_key' => '',
                        'gus_production_mode' => 'no',
                        'gus_debug_mode' => 'no',
                        'gus_auto_update' => 'no',
                        'gus_update_frequency' => 'monthly'
                    ]);
                    ?>

                    <h3><?php _e('Ustawienia API Optima', 'optima-woocommerce'); ?></h3>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Adres URL API', 'optima-woocommerce'); ?></th>
                            <td>
                                <input type="url" name="wc_optima_settings[api_url]" value="<?php echo esc_attr($options['api_url']); ?>" class="regular-text" placeholder="http://example.com/api" />
                                <p class="description"><?php _e('Wprowadź pełny adres URL do API Optima (np. http://194.150.196.122:8603/api)', 'optima-woocommerce'); ?></p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row"><?php _e('Nazwa użytkownika', 'optima-woocommerce'); ?></th>
                            <td>
                                <input type="text" name="wc_optima_settings[username]" value="<?php echo esc_attr($options['username']); ?>" class="regular-text" placeholder="<?php _e('Nazwa użytkownika', 'optima-woocommerce'); ?>" />
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row"><?php _e('Hasło', 'optima-woocommerce'); ?></th>
                            <td>
                                <input type="password" name="wc_optima_settings[password]" value="" class="regular-text" placeholder="<?php _e('Wprowadź nowe hasło', 'optima-woocommerce'); ?>" />
                                <?php if (!empty($options['password'])): ?>
                                    <p class="description"><?php _e('Hasło jest ustawione. Pozostaw puste, aby zachować obecne hasło.', 'optima-woocommerce'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <h3><?php _e('Ustawienia API GUS', 'optima-woocommerce'); ?></h3>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Klucz API', 'optima-woocommerce'); ?></th>
                            <td>
                                <input type="text" name="wc_optima_settings[gus_api_key]" value="<?php echo esc_attr($options['gus_api_key']); ?>" class="regular-text" placeholder="<?php _e('Wprowadź klucz API GUS', 'optima-woocommerce'); ?>" />
                                <p class="description"><?php _e('Wprowadź swój klucz API GUS (np. b9ba14aaeada4be388ef)', 'optima-woocommerce'); ?></p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row"><?php _e('Tryb produkcyjny', 'optima-woocommerce'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_optima_settings[gus_production_mode]" value="yes" <?php checked('yes', $options['gus_production_mode']); ?> />
                                    <?php _e('Włącz tryb produkcyjny', 'optima-woocommerce'); ?>
                                </label>
                                <p class="description"><?php _e('Gdy wyłączone, używane będzie środowisko testowe z testowym kluczem API.', 'optima-woocommerce'); ?></p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row"><?php _e('Tryb debugowania', 'optima-woocommerce'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_optima_settings[gus_debug_mode]" value="yes" <?php checked('yes', $options['gus_debug_mode']); ?> />
                                    <?php _e('Włącz tryb debugowania', 'optima-woocommerce'); ?>
                                </label>
                                <p class="description"><?php _e('Gdy włączone, szczegółowe informacje o żądaniach API będą logowane.', 'optima-woocommerce'); ?></p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row"><?php _e('Automatyczna aktualizacja danych firmy', 'optima-woocommerce'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_optima_settings[gus_auto_update]" value="yes" <?php checked('yes', isset($options['gus_auto_update']) ? $options['gus_auto_update'] : 'no'); ?> />
                                    <?php _e('Włącz automatyczną aktualizację danych firmy', 'optima-woocommerce'); ?>
                                </label>
                                <p class="description"><?php _e('Gdy włączone, dane firmy będą automatycznie aktualizowane zgodnie z poniższą częstotliwością.', 'optima-woocommerce'); ?></p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row"><?php _e('Częstotliwość aktualizacji', 'optima-woocommerce'); ?></th>
                            <td>
                                <select name="wc_optima_settings[gus_update_frequency]">
                                    <option value="daily" <?php selected('daily', isset($options['gus_update_frequency']) ? $options['gus_update_frequency'] : 'monthly'); ?>><?php _e('Codziennie', 'optima-woocommerce'); ?></option>
                                    <option value="weekly" <?php selected('weekly', isset($options['gus_update_frequency']) ? $options['gus_update_frequency'] : 'monthly'); ?>><?php _e('Tygodniowo', 'optima-woocommerce'); ?></option>
                                    <option value="monthly" <?php selected('monthly', isset($options['gus_update_frequency']) ? $options['gus_update_frequency'] : 'monthly'); ?>><?php _e('Miesięcznie', 'optima-woocommerce'); ?></option>
                                    <option value="quarterly" <?php selected('quarterly', isset($options['gus_update_frequency']) ? $options['gus_update_frequency'] : 'monthly'); ?>><?php _e('Kwartalnie', 'optima-woocommerce'); ?></option>
                                </select>
                                <p class="description"><?php _e('Jak często dane firmy powinny być automatycznie aktualizowane.', 'optima-woocommerce'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Zapisz ustawienia', 'optima-woocommerce')); ?>
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
            'label'    => __('Optima', 'optima-woocommerce'), // Keep 'Optima' as it's a brand name, but use the correct text domain
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
                    <label><?php _e('ID Optima', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_id); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Numer katalogowy', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_catalog_number); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Kod kreskowy', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_barcode); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Typ', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_type); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Stawka VAT', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_vat_rate); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Jednostka', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_unit); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Grupa domyślna', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_default_group); ?></span>
                </p>
                <p class="form-field">
                    <label><?php _e('Kategoria sprzedaży', 'optima-woocommerce'); ?></label>
                    <span><?php echo esc_html($optima_sales_category); ?></span>
                </p>
                <?php if (!empty($optima_stock_data)) :
                    $stock_data = json_decode($optima_stock_data, true);
                ?>
                    <p class="form-field">
                        <label><?php _e('Ilość w magazynie', 'optima-woocommerce'); ?></label>
                        <span><?php echo isset($stock_data['quantity']) ? esc_html($stock_data['quantity']) : '0'; ?></span>
                    </p>
                    <p class="form-field">
                        <label><?php _e('Dostępne', 'optima-woocommerce'); ?></label>
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
        <h3><?php _e('Integracja Optima', 'optima-woocommerce'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="optima_customer_id"><?php _e('ID Klienta Optima', 'optima-woocommerce'); ?></label></th>
                <td>
                    <input type="text" name="optima_customer_id" id="optima_customer_id" value="<?php echo esc_attr($optima_customer_id); ?>" class="regular-text" readonly />
                    <p class="description"><?php _e('To jest ID klienta w systemie Optima.', 'optima-woocommerce'); ?></p>
                </td>
            </tr>
        </table>
    <?php
    }

    /**
     * AJAX handler for fetching invoices
     */
    public function ajax_fetch_invoices()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_fetch_invoices')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Get invoices
        $invoices = $api->get_optima_invoices();

        if (!$invoices) {
            wp_send_json_error('Failed to fetch invoices from Optima API');
            return;
        }

        // Send success response
        wp_send_json_success($invoices);
    }

    /**
     * AJAX handler for searching invoices
     */
    public function ajax_search_invoice()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_fetch_invoices')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Check if search parameters are provided
        if (!isset($_POST['search_params']) || empty($_POST['search_params'])) {
            wp_send_json_error('Search parameters are required');
            return;
        }

        $search_params = $_POST['search_params'];

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Search invoices
        $invoices = $api->search_optima_invoices($search_params);

        if (!$invoices) {
            wp_send_json_error('No invoices found matching the search criteria');
            return;
        }

        // Send success response
        wp_send_json_success($invoices);
    }

    /**
     * AJAX handler for getting invoice PDF
     */
    public function ajax_get_invoice_pdf()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_fetch_invoices')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Check if invoice ID is provided
        if (!isset($_POST['invoice_id']) || empty($_POST['invoice_id'])) {
            wp_send_json_error('Invoice ID is required');
            return;
        }

        $invoice_id = intval($_POST['invoice_id']);

        // Get the invoice handler
        $invoice_handler = WC_Optima_Integration::get_invoice_instance();
        if (!$invoice_handler) {
            wp_send_json_error('Invoice handler not initialized');
            return;
        }

        // Use the invoice handler's AJAX method to generate the PDF
        // This will handle getting the invoice data, customer data, and generating the PDF
        $invoice_handler->ajax_get_invoice_pdf();

        // The ajax_get_invoice_pdf method in the invoice handler will send the JSON response,
        // so we don't need to do anything else here
        exit;
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
            <h4><?php _e('Integracja Optima', 'optima-woocommerce'); ?></h4>
            <p>
                <strong><?php _e('ID Klienta Optima:', 'optima-woocommerce'); ?></strong>
                <?php echo esc_html($optima_customer_id); ?>
            </p>
        </div>
<?php
    }
}
