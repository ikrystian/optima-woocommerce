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
        // Get nonce from either POST or GET
        $nonce = '';
        if (isset($_POST['nonce'])) {
            $nonce = $_POST['nonce'];
        } elseif (isset($_GET['nonce'])) {
            $nonce = $_GET['nonce'];
        }

        // Check nonce
        if (empty($nonce) || !wp_verify_nonce($nonce, 'wc_optima_fetch_invoices')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get invoice ID from either POST or GET
        $invoice_id = 0;
        if (isset($_POST['invoice_id']) && !empty($_POST['invoice_id'])) {
            $invoice_id = intval($_POST['invoice_id']);
        } elseif (isset($_GET['invoice_id']) && !empty($_GET['invoice_id'])) {
            $invoice_id = intval($_GET['invoice_id']);
        }

        // Check if invoice ID is provided
        if ($invoice_id <= 0) {
            wp_send_json_error('Invoice ID is required');
            return;
        }

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

        // Check if this is a direct download request
        $direct_download = isset($_GET['direct_download']) && $_GET['direct_download'] === 'true';

        if ($direct_download) {
            // For direct download, we'll output the file directly
            $return_url = false;
            $pdf_result = $this->generate_invoice_pdf($invoice, $customer, $return_url);

            if (!$pdf_result) {
                wp_die('Failed to generate invoice document');
                return;
            }

            // Check if the result is HTML
            $is_html = (strpos($pdf_result, '<!DOCTYPE html>') === 0 || strpos($pdf_result, '<html>') !== false);

            // Set appropriate headers based on content type
            if ($is_html) {
                // For HTML content
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Disposition: attachment; filename="invoice-' . $invoice['invoiceNumber'] . '.html"');
            } else {
                // For PDF content
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="invoice-' . $invoice['invoiceNumber'] . '.pdf"');
            }

            // Output the content and exit
            echo $pdf_result;
            exit;
        }

        // For AJAX requests, we'll return a direct download URL
        // Generate the direct download URL
        $download_url = admin_url('admin-ajax.php?action=wc_optima_get_invoice_pdf&invoice_id=' . $invoice_id . '&nonce=' . $nonce . '&direct_download=true');

        // Send success response with download URL
        wp_send_json_success(array(
            'download_url' => $download_url,
            'filename' => 'invoice-' . $invoice['invoiceNumber'] . '.pdf'
        ));
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
            // If TCPDF is not available, use a simple HTML version
            return $this->generate_simple_invoice_pdf($invoice, $customer, $return_url);
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

        // Format dates
        $formatDate = function ($dateString) {
            return $dateString ? date('Y-m-d', strtotime($dateString)) : '';
        };

        // Format boolean values
        $formatBoolean = function ($value) {
            return $value ? 'Yes' : 'No';
        };

        // Invoice content
        $html = '<h1>Invoice ' . $invoice['invoiceNumber'] . '</h1>';

        // Basic invoice information
        $html .= '<h2>Invoice Information</h2>';
        $html .= '<p><strong>Invoice Number:</strong> ' . $invoice['invoiceNumber'] . '</p>';
        $html .= '<p><strong>Issue Date:</strong> ' . $formatDate($invoice['issueDate']) . '</p>';
        $html .= '<p><strong>Due Date:</strong> ' . $formatDate($invoice['dueDate']) . '</p>';
        $html .= '<p><strong>Sale Date:</strong> ' . $formatDate($invoice['saleDate']) . '</p>';
        $html .= '<p><strong>Payment Date:</strong> ' . $formatDate($invoice['paymentDate']) . '</p>';
        $html .= '<p><strong>Foreign Number:</strong> ' . (isset($invoice['foreignNumber']) ? $invoice['foreignNumber'] : '') . '</p>';
        $html .= '<p><strong>Description:</strong> ' . (isset($invoice['description']) ? $invoice['description'] : '') . '</p>';

        // Status information
        $html .= '<h2>Status Information</h2>';
        $html .= '<p><strong>Status:</strong> ' . (isset($invoice['status']) ? $invoice['status'] : '') . '</p>';
        $html .= '<p><strong>Paid:</strong> ' . $formatBoolean(isset($invoice['paid']) ? $invoice['paid'] : false) . '</p>';
        $html .= '<p><strong>Canceled:</strong> ' . $formatBoolean(isset($invoice['canceled']) ? $invoice['canceled'] : false) . '</p>';

        // Customer information
        $html .= '<h2>Customer Information</h2>';
        $html .= '<p><strong>Customer ID:</strong> ' . (isset($invoice['customerId']) ? $invoice['customerId'] : '') . '</p>';

        if ($customer) {
            $html .= '<p><strong>Customer Name:</strong> ' . $customer['name1'] . '</p>';
            $html .= '<p><strong>Address:</strong> ' . $customer['street'] . ', ' . $customer['city'] . ', ' . $customer['postCode'] . '</p>';
            $html .= '<p><strong>VAT Number (NIP):</strong> ' . (isset($customer['vatNumber']) ? $customer['vatNumber'] : '') . '</p>';
        } else {
            $html .= '<p><strong>Customer Name:</strong> ' . (isset($invoice['customerName']) ? $invoice['customerName'] : '') . '</p>';
            $html .= '<p><strong>Customer NIP:</strong> ' . (isset($invoice['customerNip']) ? $invoice['customerNip'] : '') . '</p>';
        }

        // Document and payment information
        $html .= '<h2>Document Information</h2>';
        $html .= '<p><strong>Document Type:</strong> ' . (isset($invoice['documentTypeName']) ? $invoice['documentTypeName'] : '') . '</p>';
        $html .= '<p><strong>Document Type ID:</strong> ' . (isset($invoice['documentTypeId']) ? $invoice['documentTypeId'] : '') . '</p>';
        $html .= '<p><strong>Payment Method:</strong> ' . (isset($invoice['paymentMethodName']) ? $invoice['paymentMethodName'] : '') . '</p>';
        $html .= '<p><strong>Payment Method ID:</strong> ' . (isset($invoice['paymentMethodId']) ? $invoice['paymentMethodId'] : '') . '</p>';
        $html .= '<p><strong>VAT Registration Country:</strong> ' . (isset($invoice['vatRegistrationCountry']) ? $invoice['vatRegistrationCountry'] : '') . '</p>';

        // Financial details
        $html .= '<h2>Financial Details</h2>';
        $html .= '<table border="1" cellpadding="5">';
        $html .= '<tr><th>Net Value</th><th>Gross Value</th><th>Currency</th><th>Discount</th></tr>';
        $html .= '<tr>';
        $html .= '<td>' . (isset($invoice['netValue']) ? $invoice['netValue'] : '0.00') . '</td>';
        $html .= '<td>' . (isset($invoice['grossValue']) ? $invoice['grossValue'] : '0.00') . '</td>';
        $html .= '<td>' . (isset($invoice['currency']) ? $invoice['currency'] : '') . '</td>';
        $html .= '<td>' . (isset($invoice['discount']) ? $invoice['discount'] . '%' : '0%') . '</td>';
        $html .= '</tr>';
        $html .= '</table>';

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
     * @param bool $return_url Whether to return a URL to the HTML file
     * @return string|false HTML content, URL to HTML file, or false on failure
     */
    private function generate_simple_invoice_pdf($invoice, $customer = null, $return_url = false)
    {
        // Helper functions for formatting
        $formatDate = function ($dateString) {
            return $dateString ? date('Y-m-d', strtotime($dateString)) : '';
        };

        $formatBoolean = function ($value) {
            return $value ? 'Yes' : 'No';
        };

        // Create a simple HTML invoice
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Invoice ' . $invoice['invoiceNumber'] . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #333; }
                h2 { color: #555; margin-top: 20px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .section { margin-bottom: 20px; }
                @media print {
                    body { font-size: 12pt; }
                    h1 { font-size: 18pt; }
                    h2 { font-size: 14pt; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="text-align: right; margin-bottom: 20px;">
                <button onclick="window.print()">Print Invoice</button>
            </div>
            <h1>Invoice ' . $invoice['invoiceNumber'] . '</h1>

            <div class="section">
                <h2>Invoice Information</h2>
                <p><strong>Invoice Number:</strong> ' . $invoice['invoiceNumber'] . '</p>
                <p><strong>Issue Date:</strong> ' . $formatDate($invoice['issueDate']) . '</p>
                <p><strong>Due Date:</strong> ' . $formatDate($invoice['dueDate']) . '</p>
                <p><strong>Sale Date:</strong> ' . $formatDate(isset($invoice['saleDate']) ? $invoice['saleDate'] : '') . '</p>
                <p><strong>Payment Date:</strong> ' . $formatDate(isset($invoice['paymentDate']) ? $invoice['paymentDate'] : '') . '</p>
                <p><strong>Foreign Number:</strong> ' . (isset($invoice['foreignNumber']) ? $invoice['foreignNumber'] : '') . '</p>
                <p><strong>Description:</strong> ' . (isset($invoice['description']) ? $invoice['description'] : '') . '</p>
            </div>

            <div class="section">
                <h2>Status Information</h2>
                <p><strong>Status:</strong> ' . (isset($invoice['status']) ? $invoice['status'] : '') . '</p>
                <p><strong>Paid:</strong> ' . $formatBoolean(isset($invoice['paid']) ? $invoice['paid'] : false) . '</p>
                <p><strong>Canceled:</strong> ' . $formatBoolean(isset($invoice['canceled']) ? $invoice['canceled'] : false) . '</p>
            </div>';

        // Customer information
        $html .= '<div class="section">
            <h2>Customer Information</h2>
            <p><strong>Customer ID:</strong> ' . (isset($invoice['customerId']) ? $invoice['customerId'] : '') . '</p>';

        if ($customer) {
            $html .= '<p><strong>Customer Name:</strong> ' . $customer['name1'] . '</p>
            <p><strong>Address:</strong> ' . $customer['street'] . ', ' . $customer['city'] . ', ' . $customer['postCode'] . '</p>
            <p><strong>VAT Number (NIP):</strong> ' . (isset($customer['vatNumber']) ? $customer['vatNumber'] : '') . '</p>';
        } else {
            $html .= '<p><strong>Customer Name:</strong> ' . (isset($invoice['customerName']) ? $invoice['customerName'] : '') . '</p>
            <p><strong>Customer NIP:</strong> ' . (isset($invoice['customerNip']) ? $invoice['customerNip'] : '') . '</p>';
        }

        $html .= '</div>

            <div class="section">
                <h2>Document Information</h2>
                <p><strong>Document Type:</strong> ' . (isset($invoice['documentTypeName']) ? $invoice['documentTypeName'] : '') . '</p>
                <p><strong>Document Type ID:</strong> ' . (isset($invoice['documentTypeId']) ? $invoice['documentTypeId'] : '') . '</p>
                <p><strong>Payment Method:</strong> ' . (isset($invoice['paymentMethodName']) ? $invoice['paymentMethodName'] : '') . '</p>
                <p><strong>Payment Method ID:</strong> ' . (isset($invoice['paymentMethodId']) ? $invoice['paymentMethodId'] : '') . '</p>
                <p><strong>VAT Registration Country:</strong> ' . (isset($invoice['vatRegistrationCountry']) ? $invoice['vatRegistrationCountry'] : '') . '</p>
            </div>

            <div class="section">
                <h2>Financial Details</h2>
                <table>
                    <tr>
                        <th>Net Value</th>
                        <th>Gross Value</th>
                        <th>Currency</th>
                        <th>Discount</th>
                    </tr>
                    <tr>
                        <td>' . (isset($invoice['netValue']) ? $invoice['netValue'] : '0.00') . '</td>
                        <td>' . (isset($invoice['grossValue']) ? $invoice['grossValue'] : '0.00') . '</td>
                        <td>' . (isset($invoice['currency']) ? $invoice['currency'] : '') . '</td>
                        <td>' . (isset($invoice['discount']) ? $invoice['discount'] . '%' : '0%') . '</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>';

        // If we need to return a URL, save the HTML to a file and return the URL
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
            $filename = 'invoice-' . $invoice['invoiceNumber'] . '-' . time() . '.html';
            $filepath = $invoice_dir . '/' . $filename;

            // Save the HTML to the file
            file_put_contents($filepath, $html);

            // Return the URL to the HTML file
            return $upload_dir['baseurl'] . '/optima-invoices/' . $filename;
        }

        // Otherwise, return the HTML content
        return $html;
    }
}
