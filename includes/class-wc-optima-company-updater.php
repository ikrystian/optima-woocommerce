<?php

/**
 * Company data updater class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling company data updates
 */
class WC_Optima_Company_Updater
{
    /**
     * Plugin options
     *
     * @var array
     */
    private $options;

    /**
     * GUS API instance
     *
     * @var WC_Optima_GUS_API
     */
    private $gus_api;

    /**
     * Constructor
     *
     * @param array $options Plugin options
     * @param WC_Optima_GUS_API $gus_api GUS API instance
     */
    public function __construct($options, $gus_api)
    {
        $this->options = $options;
        $this->gus_api = $gus_api;

        // Register hooks
        add_action('wp_login', [$this, 'check_company_data_update'], 10, 2);
        add_action('optima_update_company_data', [$this, 'update_company_data']);
        add_action('optima_bulk_update_company_data', [$this, 'bulk_update_company_data']);

        // Register cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Register activation hook
        register_activation_hook(OPTIMA_WC_PLUGIN_FILE, [$this, 'schedule_updates']);

        // Add action for manual update
        add_action('wp_ajax_wc_optima_update_company_data', [$this, 'ajax_update_company_data']);
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules)
    {
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display' => __('Raz w tygodniu', 'optima-woocommerce')
        ];

        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Raz w miesiącu', 'optima-woocommerce')
        ];

        $schedules['quarterly'] = [
            'interval' => 91 * DAY_IN_SECONDS,
            'display' => __('Raz na kwartał', 'optima-woocommerce')
        ];

        return $schedules;
    }

    /**
     * Schedule company data updates
     */
    public function schedule_updates()
    {
        // Clear any existing scheduled events
        $this->clear_scheduled_updates();

        // Only schedule if auto-update is enabled
        if (isset($this->options['gus_auto_update']) && $this->options['gus_auto_update'] === 'yes') {
            $frequency = isset($this->options['gus_update_frequency']) ? $this->options['gus_update_frequency'] : 'monthly';

            if (!wp_next_scheduled('optima_bulk_update_company_data')) {
                wp_schedule_event(time(), $frequency, 'optima_bulk_update_company_data');
            }
        }
    }

    /**
     * Clear scheduled updates
     */
    public function clear_scheduled_updates()
    {
        $timestamp = wp_next_scheduled('optima_bulk_update_company_data');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'optima_bulk_update_company_data');
        }
    }

    /**
     * Check if company data needs to be updated on user login
     *
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function check_company_data_update($user_login, $user)
    {
        // Only proceed if auto-update is enabled
        if (!isset($this->options['gus_auto_update']) || $this->options['gus_auto_update'] !== 'yes') {
            return;
        }

        $customer_type = get_user_meta($user->ID, '_optima_customer_type', true);

        // Only update B2B customers
        if ($customer_type !== 'b2b') {
            return;
        }

        $last_update = get_user_meta($user->ID, '_optima_company_data_last_update', true);
        $frequency = isset($this->options['gus_update_frequency']) ? $this->options['gus_update_frequency'] : 'monthly';

        $update_needed = false;

        if (!$last_update) {
            $update_needed = true;
        } else {
            $last_update_time = strtotime($last_update);

            switch ($frequency) {
                case 'daily':
                    $update_needed = $last_update_time < strtotime('-1 day');
                    break;
                case 'weekly':
                    $update_needed = $last_update_time < strtotime('-1 week');
                    break;
                case 'quarterly':
                    $update_needed = $last_update_time < strtotime('-3 months');
                    break;
                case 'monthly':
                default:
                    $update_needed = $last_update_time < strtotime('-1 month');
                    break;
            }
        }

        if ($update_needed) {
            // Schedule immediate update
            wp_schedule_single_event(time(), 'optima_update_company_data', [$user->ID]);
        }
    }

    /**
     * Update company data for a user
     *
     * @param int $user_id User ID
     * @return bool True on success, false on failure
     */
    public function update_company_data($user_id)
    {
        $nip = get_user_meta($user_id, '_optima_nip', true);

        if (!$nip) {
            return false;
        }

        $company_data = $this->gus_api->get_company_by_nip($nip);

        if ($company_data && is_array($company_data) && !empty($company_data)) {
            $company = $company_data[0];

            // Update company data
            update_user_meta($user_id, '_optima_company_name', $company['Nazwa'] ?? '');
            update_user_meta($user_id, 'billing_company', $company['Nazwa'] ?? '');

            // Update address data
            $address = $company['Ulica'] ?? '';
            if (!empty($company['NrNieruchomosci'])) {
                $address .= ' ' . $company['NrNieruchomosci'];
            }
            if (!empty($company['NrLokalu'])) {
                $address .= '/' . $company['NrLokalu'];
            }

            update_user_meta($user_id, 'billing_address_1', $address);
            update_user_meta($user_id, 'billing_postcode', $company['KodPocztowy'] ?? '');
            update_user_meta($user_id, 'billing_city', $company['Miejscowosc'] ?? '');

            // Update REGON if available
            if (!empty($company['Regon'])) {
                update_user_meta($user_id, '_optima_regon', $company['Regon']);
            }

            // Update last update timestamp
            update_user_meta($user_id, '_optima_company_data_last_update', current_time('mysql'));

            // Log the update
            $this->log_update($user_id, true);

            return true;
        }

        // Log the failed update
        $this->log_update($user_id, false);

        return false;
    }

    /**
     * Log company data update
     *
     * @param int $user_id User ID
     * @param bool $success Whether the update was successful
     */
    private function log_update($user_id, $success)
    {
        $log = get_option('wc_optima_company_updates_log', []);

        $log[] = [
            'time' => current_time('mysql'),
            'user_id' => $user_id,
            'success' => $success
        ];

        // Keep only the last 100 log entries
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }

        update_option('wc_optima_company_updates_log', $log);
    }

    /**
     * AJAX handler for manual company data update
     */
    public function ajax_update_company_data()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_update_company_data')) {
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa', 'optima-woocommerce'));
            return;
        }

        // Check if user ID is provided
        if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
            wp_send_json_error(__('ID użytkownika jest wymagane', 'optima-woocommerce'));
            return;
        }

        $user_id = intval($_POST['user_id']);

        // Check if user exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error(__('Nie znaleziono użytkownika', 'optima-woocommerce'));
            return;
        }

        // Update company data
        $result = $this->update_company_data($user_id);

        if ($result) {
            wp_send_json_success([
                'message' => __('Dane firmy zostały pomyślnie zaktualizowane.', 'optima-woocommerce'),
                'last_update' => get_user_meta($user_id, '_optima_company_data_last_update', true)
            ]);
        } else {
            wp_send_json_error(__('Nie udało się zaktualizować danych firmy. Sprawdź, czy numer NIP jest prawidłowy.', 'optima-woocommerce'));
        }
    }

    /**
     * Bulk update company data for all B2B users
     */
    public function bulk_update_company_data()
    {
        // Get all B2B users
        $users = get_users([
            'meta_key' => '_optima_customer_type',
            'meta_value' => 'b2b'
        ]);

        $success_count = 0;
        $failure_count = 0;

        foreach ($users as $user) {
            $result = $this->update_company_data($user->ID);

            if ($result) {
                $success_count++;
            } else {
                $failure_count++;
            }

            // Add a small delay to avoid overwhelming the API
            usleep(500000); // 0.5 seconds
        }

        // Log the bulk update
        update_option('wc_optima_last_bulk_update', [
            'time' => current_time('mysql'),
            'success_count' => $success_count,
            'failure_count' => $failure_count,
            'total_count' => count($users)
        ]);
    }
}
