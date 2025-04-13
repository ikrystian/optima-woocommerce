<?php

/**
 * Order actions handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling order actions related to Optima
 */
class WC_Optima_Order_Actions
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Add invoice link to order details
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_invoice_link_to_order_details'), 10, 1);

        // Add invoice link to order emails
        add_action('woocommerce_email_after_order_table', array($this, 'add_invoice_link_to_order_email'), 10, 4);

        // Add invoice link to admin order page
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'add_invoice_link_to_admin_order'), 10, 1);

        // Add email invoice button to admin order actions
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'add_email_invoice_button'), 10, 1);

        // Add scripts for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add invoice link to order details page
     *
     * @param WC_Order $order Order object
     */
    public function add_invoice_link_to_order_details($order)
    {
        $this->display_invoice_link($order);
    }

    /**
     * Add invoice link to order emails
     *
     * @param WC_Order $order Order object
     * @param bool $sent_to_admin Whether the email is sent to admin
     * @param bool $plain_text Whether the email is plain text
     * @param WC_Email $email Email object
     */
    public function add_invoice_link_to_order_email($order, $sent_to_admin, $plain_text, $email)
    {
        // Only add to completed order emails
        if ($email->id == 'customer_completed_order' || $email->id == 'customer_invoice') {
            $this->display_invoice_link($order, $plain_text);
        }
    }

    /**
     * Add invoice link to admin order page
     *
     * @param WC_Order $order Order object
     */
    public function add_invoice_link_to_admin_order($order)
    {
        $this->display_invoice_link($order, false, true);
    }

    /**
     * Display invoice link
     *
     * @param WC_Order $order Order object
     * @param bool $plain_text Whether to display as plain text
     * @param bool $is_admin Whether displayed in admin
     */
    private function display_invoice_link($order, $plain_text = false, $is_admin = false)
    {
        $order_id = $order->get_id();
        $invoice_pdf_url = get_post_meta($order_id, 'optima_invoice_pdf_url', true);
        $invoice_number = get_post_meta($order_id, 'optima_invoice_number', true);

        if (empty($invoice_pdf_url)) {
            return;
        }

        if (empty($invoice_number)) {
            $invoice_number = get_post_meta($order_id, 'optima_ro_document_id', true);
            if (empty($invoice_number)) {
                $invoice_number = __('Faktura VAT', 'optima-woocommerce');
            }
        }

        if ($plain_text) {
            echo "\n\n" . sprintf(__('Faktura VAT: %s - %s', 'optima-woocommerce'), $invoice_number, $invoice_pdf_url) . "\n\n";
        } else {
            echo '<div class="wc-optima-invoice">';

            if ($is_admin) {
                echo '<h3>' . __('Faktura Optima', 'optima-woocommerce') . '</h3>';
            } else {
                echo '<h2>' . __('Faktura VAT', 'optima-woocommerce') . '</h2>';
            }

            echo '<p>' . sprintf(
                __('Faktura VAT %s: <a href="%s" target="_blank">%s</a>', 'optima-woocommerce'),
                $invoice_number,
                esc_url($invoice_pdf_url),
                __('Pobierz fakturę', 'optima-woocommerce')
            ) . '</p>';

            echo '</div>';
        }
    }

    /**
     * Add email invoice button to order actions
     *
     * @param WC_Order|int $order Order object or order ID
     */
    public function add_email_invoice_button($order)
    {
        // Check if $order is an order ID or an order object
        if (is_numeric($order)) {
            $order_id = $order;
        } else {
            $order_id = $order->get_id();
        }

        $invoice_pdf_url = get_post_meta($order_id, 'optima_invoice_pdf_url', true);

        // Only show button if invoice PDF exists
        if (empty($invoice_pdf_url)) {
            return;
        }

        // Add button
        echo '<div class="optima-invoice-actions">';
        echo '<button type="button" class="button send-invoice-email" data-order-id="' . esc_attr($order_id) . '" data-nonce="' . wp_create_nonce('wc_optima_send_invoice_email') . '">' . __('Wyślij fakturę VAT na email', 'optima-woocommerce') . '</button>';
        echo '<span class="invoice-email-result"></span>';
        echo '</div>';
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on order edit page
        if ('post.php' !== $hook || !isset($_GET['post']) || 'shop_order' !== get_post_type($_GET['post'])) {
            return;
        }

        // Register and enqueue script
        wp_register_script('wc-optima-admin', plugin_dir_url(OPTIMA_WC_PLUGIN_FILE) . 'assets/js/admin.js', array('jquery'), '1.0.0', true);

        // Localize script
        wp_localize_script('wc-optima-admin', 'wc_optima_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'sending_text' => __('Wysyłanie...', 'optima-woocommerce'),
            'success_text' => __('Faktura wysłana!', 'optima-woocommerce'),
            'error_text' => __('Błąd wysyłania!', 'optima-woocommerce')
        ));

        wp_enqueue_script('wc-optima-admin');

        // Add inline styles
        wp_add_inline_style('woocommerce_admin_styles', '
            .optima-invoice-actions {
                margin-top: 10px;
                padding: 10px;
                background-color: #f8f8f8;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .invoice-email-result {
                margin-left: 10px;
                display: inline-block;
                vertical-align: middle;
            }
            .invoice-email-result.success {
                color: green;
            }
            .invoice-email-result.error {
                color: red;
            }
        ');
    }
}
