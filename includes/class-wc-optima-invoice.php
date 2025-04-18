<?php

/**
 * Invoice handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling invoices from Optima
 */
class WC_Optima_Invoice
{
    /**
     * API instance
     *
     * @var WC_Optima_API
     */
    private $api;

    /**
     * Constructor
     *
     * @param WC_Optima_API $api API instance
     */
    public function __construct($api)
    {
        $this->api = $api;

        // Add AJAX handlers for invoices
        add_action('wp_ajax_wc_optima_fetch_invoices', array($this, 'ajax_fetch_invoices'));
        add_action('wp_ajax_wc_optima_search_invoice', array($this, 'ajax_search_invoice'));
        add_action('wp_ajax_wc_optima_get_invoice_pdf', array($this, 'ajax_get_invoice_pdf'));
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

        // Get invoices with limit
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

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Prepare search parameters
        $search_params = array();

        if (isset($_POST['invoice_number']) && !empty($_POST['invoice_number'])) {
            $search_params['invoice_number'] = sanitize_text_field($_POST['invoice_number']);
        }

        if (isset($_POST['date_from']) && !empty($_POST['date_from'])) {
            $search_params['date_from'] = sanitize_text_field($_POST['date_from']);
        }

        if (isset($_POST['date_to']) && !empty($_POST['date_to'])) {
            $search_params['date_to'] = sanitize_text_field($_POST['date_to']);
        }

        if (isset($_POST['customer_id']) && !empty($_POST['customer_id'])) {
            $search_params['customer_id'] = intval($_POST['customer_id']);
        }

        // Check if search parameters are provided from POST search_params
        if (isset($_POST['search_params']) && is_array($_POST['search_params'])) {
            foreach ($_POST['search_params'] as $key => $value) {
                if (!empty($value)) {
                    $search_params[$key] = sanitize_text_field($value);
                }
            }
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
     * AJAX handler for generating invoice PDF
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

        // Get API instance
        $api = WC_Optima_Integration::get_api_instance();
        if (!$api) {
            wp_send_json_error('API not initialized');
            return;
        }

        // Get invoice data
        $invoice = $api->get_optima_invoice_by_id($invoice_id);

        if (!$invoice) {
            wp_send_json_error('Invoice not found with ID: ' . $invoice_id);
            return;
        }

        // Get customer data if available
        $customer = null;
        if (isset($invoice['customerId']) && !empty($invoice['customerId'])) {
            $customer = $api->get_optima_customer_by_id($invoice['customerId']);
        }

        // Check if we should return a URL
        $return_url = isset($_POST['return_url']) && $_POST['return_url'] === 'true';

        // Generate PDF
        $pdf_result = $this->generate_invoice_pdf($invoice, $customer, $return_url);

        if (!$pdf_result) {
            wp_send_json_error('Failed to generate PDF for invoice');
            return;
        }

        // Send success response
        if ($return_url) {
            // If we're returning a URL, send it directly
            wp_send_json_success(array(
                'pdf_url' => $pdf_result,
                'filename' => 'invoice-' . $invoice['invoiceNumber'] . '.pdf'
            ));
        } else {
            // Otherwise, send the PDF data
            wp_send_json_success(array(
                'pdf_data' => base64_encode($pdf_result),
                'filename' => 'invoice-' . $invoice['invoiceNumber'] . '.pdf'
            ));
        }
    }

    /**
     * Generate PDF for invoice
     *
     * @param array $invoice Invoice data
     * @param array|null $customer Customer data
     * @param bool $return_url Whether to return a URL to the PDF instead of the PDF content
     * @return string|false PDF content, URL to PDF, or false on failure
     */
    public function generate_invoice_pdf($invoice, $customer = null, $return_url = false)
    {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // If TCPDF is not available, include it from a CDN or local file
            // For simplicity, we'll use a simple HTML to PDF conversion
            return $this->generate_simple_invoice_pdf($invoice, $customer);
        }

        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Optima WooCommerce');
        $pdf->SetAuthor('Optima WooCommerce');
        $pdf->SetTitle('Invoice ' . $invoice['invoiceNumber']);
        $pdf->SetSubject('Invoice ' . $invoice['invoiceNumber']);
        $pdf->SetKeywords('Invoice, Optima, WooCommerce');

        // Set default header data
        $pdf->SetHeaderData('', 0, 'Invoice ' . $invoice['invoiceNumber'], '');

        // Set header and footer fonts
        $pdf->setHeaderFont(array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont(array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // Set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // Set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Invoice content
        $html = '<h1>Invoice ' . $invoice['invoiceNumber'] . '</h1>';
        $html .= '<p><strong>Issue Date:</strong> ' . date('Y-m-d', strtotime($invoice['issueDate'])) . '</p>';
        $html .= '<p><strong>Due Date:</strong> ' . date('Y-m-d', strtotime($invoice['dueDate'])) . '</p>';

        // Customer information
        if ($customer) {
            $html .= '<h2>Customer Information</h2>';
            $html .= '<p><strong>Name:</strong> ' . $customer['name1'] . '</p>';
            $html .= '<p><strong>Address:</strong> ' . $customer['street'] . ', ' . $customer['city'] . ', ' . $customer['postCode'] . '</p>';
            $html .= '<p><strong>VAT Number:</strong> ' . $customer['vatNumber'] . '</p>';
        }

        // Invoice details
        $html .= '<h2>Invoice Details</h2>';
        $html .= '<table border="1" cellpadding="5">';
        $html .= '<tr><th>Description</th><th>Net Value</th><th>Gross Value</th><th>Currency</th></tr>';
        $html .= '<tr>';
        $html .= '<td>Invoice ' . $invoice['invoiceNumber'] . '</td>';
        $html .= '<td>' . $invoice['netValue'] . '</td>';
        $html .= '<td>' . $invoice['grossValue'] . '</td>';
        $html .= '<td>' . $invoice['currency'] . '</td>';
        $html .= '</tr>';
        $html .= '</table>';

        // Payment information
        $html .= '<h2>Payment Information</h2>';
        $html .= '<p><strong>Payment Method:</strong> ' . $invoice['paymentMethodName'] . '</p>';
        $html .= '<p><strong>Document Type:</strong> ' . $invoice['documentTypeName'] . '</p>';

        // Output the HTML content
        $pdf->writeHTML($html, true, false, true, false, '');

        // If we need to return a URL, save the PDF to a file and return the URL
        if ($return_url) {
            // Create uploads directory if it doesn't exist
            $upload_dir = wp_upload_dir();
            $invoice_dir = $upload_dir['basedir'] . '/optima-invoices';

            if (!file_exists($invoice_dir)) {
                wp_mkdir_p($invoice_dir);
            }

            // Create an index.php file to prevent directory listing
            if (!file_exists($invoice_dir . '/index.php')) {
                file_put_contents($invoice_dir . '/index.php', '<?php // Silence is golden');
            }

            // Generate a unique filename
            $filename = 'invoice-' . $invoice['invoiceNumber'] . '-' . time() . '.pdf';
            $filepath = $invoice_dir . '/' . $filename;

            // Save the PDF to the file
            $pdf->Output($filepath, 'F');

            // Return the URL to the PDF
            return $upload_dir['baseurl'] . '/optima-invoices/' . $filename;
        }

        // Otherwise, return the PDF content
        return $pdf->Output('invoice.pdf', 'S');
    }

    /**
     * Generate a simple HTML invoice PDF
     *
     * @param array $invoice Invoice data
     * @param array|null $customer Customer data
     * @return string|false PDF content or false on failure
     */
    private function generate_simple_invoice_pdf($invoice, $customer = null)
    {
        // Create a simple HTML invoice
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Invoice ' . $invoice['invoiceNumber'] . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #333; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Invoice ' . $invoice['invoiceNumber'] . '</h1>
            <p><strong>Issue Date:</strong> ' . date('Y-m-d', strtotime($invoice['issueDate'])) . '</p>
            <p><strong>Due Date:</strong> ' . date('Y-m-d', strtotime($invoice['dueDate'])) . '</p>';

        // Customer information
        if ($customer) {
            $html .= '<h2>Customer Information</h2>
            <p><strong>Name:</strong> ' . $customer['name1'] . '</p>
            <p><strong>Address:</strong> ' . $customer['street'] . ', ' . $customer['city'] . ', ' . $customer['postCode'] . '</p>
            <p><strong>VAT Number:</strong> ' . $customer['vatNumber'] . '</p>';
        }

        // Invoice details
        $html .= '<h2>Invoice Details</h2>
            <table>
                <tr>
                    <th>Description</th>
                    <th>Net Value</th>
                    <th>Gross Value</th>
                    <th>Currency</th>
                </tr>
                <tr>
                    <td>Invoice ' . $invoice['invoiceNumber'] . '</td>
                    <td>' . $invoice['netValue'] . '</td>
                    <td>' . $invoice['grossValue'] . '</td>
                    <td>' . $invoice['currency'] . '</td>
                </tr>
            </table>

            <h2>Payment Information</h2>
            <p><strong>Payment Method:</strong> ' . $invoice['paymentMethodName'] . '</p>
            <p><strong>Document Type:</strong> ' . $invoice['documentTypeName'] . '</p>
        </body>
        </html>';

        // For simplicity, we'll just return the HTML as a string
        // In a real-world scenario, you would use a library like DOMPDF or mPDF to convert HTML to PDF
        return $html;
    }
}
