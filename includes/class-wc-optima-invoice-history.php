<?php

/**
 * Invoice History functionality for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling invoice history functionality
 */
class WC_Optima_Invoice_History
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

        // Register AJAX handlers for front-end
        add_action('wp_ajax_optima_search_invoices', array($this, 'ajax_search_invoices'));
        add_action('wp_ajax_optima_download_invoice', array($this, 'ajax_download_invoice'));
    }

    /**
     * AJAX handler for searching invoices
     */
    public function ajax_search_invoices()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'optima_invoice_history')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get current user
        $current_user = wp_get_current_user();
        if (!$current_user->ID) {
            wp_send_json_error('User not logged in');
            return;
        }

        // Get customer ID from user meta
        $customer_id = get_user_meta($current_user->ID, 'optima_customer_id', true);
        if (empty($customer_id)) {
            wp_send_json_error('No Optima customer ID found for this user');
            return;
        }

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Prepare search parameters
        $search_params = array(
            'customer_id' => $customer_id
        );

        // Add additional search parameters if provided
        if (isset($_POST['filters']) && is_array($_POST['filters'])) {
            $filters = $_POST['filters'];

            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $search_params['date_from'] = sanitize_text_field($filters['date_from']);
            }

            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $search_params['date_to'] = sanitize_text_field($filters['date_to']);
            }

            if (isset($filters['invoice_number']) && !empty($filters['invoice_number'])) {
                $search_params['invoice_number'] = sanitize_text_field($filters['invoice_number']);
            }

            if (isset($filters['document_type']) && !empty($filters['document_type'])) {
                $search_params['document_type'] = sanitize_text_field($filters['document_type']);
            }
        }

        // Search invoices
        $invoices = $api->search_optima_invoices($search_params);

        if (!$invoices) {
            wp_send_json_error('No invoices found matching your criteria');
            return;
        }

        // Send success response
        wp_send_json_success($invoices);
    }

    /**
     * AJAX handler for downloading invoice
     */
    public function ajax_download_invoice()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'optima_invoice_history')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get current user
        $current_user = wp_get_current_user();
        if (!$current_user->ID) {
            wp_send_json_error('User not logged in');
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

        // Create a nonce for the invoice PDF request
        $nonce = wp_create_nonce('wc_optima_fetch_invoices');

        // Generate the direct download URL
        $download_url = admin_url('admin-ajax.php?action=wc_optima_get_invoice_pdf&invoice_id=' . $invoice_id . '&nonce=' . $nonce . '&direct_download=true');

        // Send success response with download URL
        wp_send_json_success(array(
            'download_url' => $download_url,
            'invoice_id' => $invoice_id
        ));
    }
}
