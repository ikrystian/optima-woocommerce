<?php

/**
 * Order completed handler class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling order completion and invoice creation in Optima
 */
class WC_Optima_Order_Completed
{
    /**
     * API instance
     *
     * @var WC_Optima_API
     */
    private $api;

    /**
     * Invoice handler instance
     *
     * @var WC_Optima_Invoice
     */
    private $invoice;

    /**
     * Constructor
     *
     * @param WC_Optima_API $api API instance
     * @param WC_Optima_Invoice $invoice Invoice handler instance
     */
    public function __construct($api, $invoice)
    {
        $this->api = $api;
        $this->invoice = $invoice;

        // Hook into order status completed
        add_action('woocommerce_order_status_completed', array($this, 'process_completed_order'), 10, 1);

        // Add invoice link to order admin page
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_invoice_link_in_order'), 10, 1);
    }

    /**
     * Process completed order
     *
     * When an order is marked as completed:
     * 1. Create an invoice in Optima
     * 2. Add a link to the invoice in the order
     * 3. Send the invoice to the customer by email
     *
     * @param int $order_id Order ID
     */
    public function process_completed_order($order_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono zamówienia - %s', 'optima-woocommerce'), $order_id));
            return;
        }

        // Check if invoice already created for this order
        $invoice_id = get_post_meta($order_id, 'optima_invoice_id', true);

        if (!empty($invoice_id)) {
            error_log(sprintf(__('Integracja WC Optima: Faktura już istnieje dla zamówienia %s', 'optima-woocommerce'), $order_id));

            // If invoice exists but wasn't sent, send it now
            $invoice_sent = get_post_meta($order_id, 'optima_invoice_sent', true);
            if (empty($invoice_sent)) {
                $this->send_invoice_to_customer($order_id, $invoice_id);
            }

            return;
        }

        // Check if RO document exists for this order
        $ro_document_id = get_post_meta($order_id, 'optima_ro_document_id', true);

        if (empty($ro_document_id)) {
            error_log(sprintf(__('Integracja WC Optima: Brak dokumentu RO dla zamówienia %s', 'optima-woocommerce'), $order_id));

            // If no RO document exists, we need to create one first
            $this->create_ro_document_for_order($order_id);

            // Get the newly created RO document ID
            $ro_document_id = get_post_meta($order_id, 'optima_ro_document_id', true);

            if (empty($ro_document_id)) {
                // If still no RO document, we can't proceed
                $order->add_order_note(
                    __('Nie udało się utworzyć faktury w Optima - brak dokumentu RO.', 'optima-woocommerce')
                );
                return;
            }
        }

        // Create invoice in Optima by converting the RO document
        $invoice_id = $this->create_invoice_from_ro($order_id, $ro_document_id);

        if (!$invoice_id) {
            $order->add_order_note(
                __('Nie udało się utworzyć faktury w Optima.', 'optima-woocommerce')
            );
            return;
        }

        // Send invoice to customer
        $this->send_invoice_to_customer($order_id, $invoice_id);
    }

    /**
     * Create RO document for order
     *
     * This is a simplified version of the create_ro_document_for_order method in WC_Optima_Integration
     *
     * @param int $order_id Order ID
     * @return bool True if successful, false otherwise
     */
    private function create_ro_document_for_order($order_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono zamówienia - %s', 'optima-woocommerce'), $order_id));
            return false;
        }

        // Prepare order data for Optima
        // Extract billing data
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $company = $order->get_billing_company();
        $vat_number = $order->get_meta('_billing_vat', true);
        $country = $order->get_billing_country();
        $city = $order->get_billing_city();
        $address_1 = $order->get_billing_address_1();
        $address_2 = $order->get_billing_address_2();
        $postcode = $order->get_billing_postcode();
        $phone = $order->get_billing_phone();
        $email = $order->get_billing_email();

        // Determine if company or private person
        $is_company = !empty($company) && !empty($vat_number);

        // Build payer/recipient arrays according to Optima API spec
        $customer_code = 'WC_' . date('Ymd') . '_' . $order->get_id();
        if ($is_company) {
            $payer_recipient = [
                'code' => $customer_code,
                'name1' => $company,
                'vatNumber' => $vat_number,
                'country' => $country,
                'city' => $city,
                'street' => $address_1,
                'postCode' => $postcode,
                'phone' => $phone,
                'email' => $email
            ];
        } else {
            $payer_recipient = [
                'code' => $customer_code,
                'name1' => trim($first_name . ' ' . $last_name),
                'vatNumber' => '',
                'country' => $country,
                'city' => $city,
                'street' => $address_1,
                'postCode' => $postcode,
                'phone' => $phone,
                'email' => $email
            ];
        }

        $order_data = [
            'type' => 301, // Document type according to API sample (301 instead of 302)
            'foreignNumber' => 'WC_' . $order->get_order_number(),
            'calculatedOn' => 2, // 2 = net (changed from 1 = gross to match sample)
            'paymentMethod' => 'gotówka', // Changed to match sample
            'currency' => $order->get_currency(),
            'elements' => [],
            'description' => sprintf(__('Zamówienie #%s z WooCommerce', 'optima-woocommerce'), $order->get_order_number()),
            'status' => 1,
            'sourceWarehouseId' => 1, // Default warehouse ID
            'documentSaleDate' => $order->get_date_created()->date('Y-m-d\TH:i:s'),
            'documentIssueDate' => date('Y-m-d\TH:i:s'),
            'documentPaymentDate' => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d\TH:i:s') : date('Y-m-d\TH:i:s', strtotime('+7 days')),
            'symbol' => 'FA', // Changed to match sample
            'series' => '-', // Changed to match sample
            'payer' => $payer_recipient,
            'recipient' => $payer_recipient,
            // Additional fields from sample
            'attributes' => [],
            'amountPaid' => 0,
            'amountToPay' => 0,
            'checkIfExists' => false,
            'state' => 0,
            'category' => '',
            'internalReservation' => 0,
            'transactionType' => 0,
            'activeVatVies' => 0
        ];

        // Add customer data if available
        $customer_id = get_post_meta($order_id, 'optima_customer_id', true);
        if (!empty($customer_id)) {
            $order_data['payerId'] = $customer_id;
        }

        // Add products to order data
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $optima_id = get_post_meta($product_id, '_optima_id', true);
            $optima_vat_rate = get_post_meta($product_id, '_optima_vat_rate', true);

            if (empty($optima_vat_rate)) {
                $optima_vat_rate = 23; // Default VAT rate if not set
            }

            // Skip products without Optima ID
            if (empty($optima_id)) {
                error_log(sprintf(
                    __('Integracja WC Optima: Produkt bez ID Optima w zamówieniu %s: %s (ID: %s)', 'optima-woocommerce'),
                    $order_id,
                    $item->get_name(),
                    $product_id
                ));
                continue;
            }

            // Validate required product data
            $validation_errors = [];

            if (empty($optima_vat_rate)) {
                $validation_errors[] = sprintf(
                    __('Brak stawki VAT dla produktu %s (ID: %s)', 'optima-woocommerce'),
                    $item->get_name(),
                    $product_id
                );
            }

            if (!empty($validation_errors)) {
                error_log(sprintf(
                    __('Integracja WC Optima: Błędy walidacji produktu w zamówieniu %s: %s', 'optima-woocommerce'),
                    $order_id,
                    implode(', ', $validation_errors)
                ));
            }

            // Get Optima code from meta or fall back to SKU or generate a code based on product ID
            $optima_code = get_post_meta($product_id, '_optima_code', true);
            if (empty($optima_code)) {
                // Fall back to SKU if _optima_code is not available
                $optima_code = $product->get_sku();
                if (empty($optima_code)) {
                    $optima_code = 'WC_PROD_' . $product_id;
                }
            }

            // Get additional product data
            $unit = get_post_meta($product_id, '_optima_unit', true);
            $pkwiu = get_post_meta($product_id, '_optima_pkwiu', true);

            // Calculate price correctly
            $price = $item->get_total() / $item->get_quantity(); // Net price per unit

            // Create element with all required fields for Optima according to sample
            $element = [
                'itemId' => $optima_id, // Changed from productId to itemId
                'code' => $optima_code, // Use the Optima code field
                'name' => null, // Added from sample
                'manufacturerCode' => '', // Added from sample
                'unitNetPrice' => $price, // Added from sample
                'unitGrossPrice' => $price * (1 + ($optima_vat_rate / 100)), // Added from sample
                'totalNetValue' => $price * $item->get_quantity(), // Added from sample
                'totalGrossValue' => $price * $item->get_quantity() * (1 + ($optima_vat_rate / 100)), // Added from sample
                'quantity' => $item->get_quantity(),
                'vatRate' => $optima_vat_rate,
                'setCustomValue' => true, // Added from sample
                'currentQuantity' => 0, // Added from sample
                'unit' => !empty($unit) ? strtoupper($unit) : 'SZT', // Default to 'SZT' if not set (uppercase)
                'purchaseAmount' => 0, // Added from sample
                'attributes' => [], // Added from sample
                'category' => 'MAT. PODSTAWOWE', // Added from sample
                'description' => $item->get_name(), // Keep description
                'discount' => 0, // Keep discount
                'warehouseId' => 1 // Keep warehouse ID
            ];

            // Add optional fields if available
            if (!empty($pkwiu)) {
                $element['pkwiu'] = $pkwiu;
            }

            // Add currency if different from default
            if ($order->get_currency() !== 'PLN') {
                $element['currency'] = $order->get_currency();
            }

            $order_data['elements'][] = $element;
        }

        if (empty($order_data['elements'])) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono produktów z ID Optima w zamówieniu %s', 'optima-woocommerce'), $order_id));
            return false;
        }

        // Create RO document in Optima
        $result = $this->api->create_ro_document($order_data);

        if ($result && isset($result['id'])) {
            // Store the RO document ID in the order meta
            update_post_meta($order_id, 'optima_ro_document_id', $result['id']);

            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Utworzono dokument RO Optima: %s (%s)', 'optima-woocommerce'),
                    $result['id'],
                    $result['fullNumber'] ?? ''
                )
            );

            return true;
        } else {
            // Handle error
            $error_message = '';
            if (is_array($result) && isset($result['message'])) {
                $error_message = $result['message'];
            } elseif (is_array($result) && isset($result['error']) && $result['error'] === true) {
                $error_message = isset($result['message']) ? $result['message'] : __('Nieznany błąd', 'optima-woocommerce');
            }

            // Add a note to the order with error information
            $order->add_order_note(
                sprintf(
                    __('Nie udało się utworzyć dokumentu RO Optima: %s', 'optima-woocommerce'),
                    $error_message
                )
            );

            // Check if this is a database version error
            if (strpos($error_message, 'Wersja bazy danych jest starsza') !== false) {
                // Add a more specific note about database version mismatch
                $order->add_order_note(
                    __('Wykryto niezgodność wersji bazy danych Optima. Proszę skontaktować się z administratorem systemu Optima.', 'optima-woocommerce')
                );
            }

            error_log(sprintf(__('Integracja WC Optima: Błąd podczas tworzenia dokumentu RO dla zamówienia %s: %s', 'optima-woocommerce'), $order_id, $error_message));
            return false;
        }
    }

    /**
     * Create invoice from RO document
     *
     * @param int $order_id Order ID
     * @param string $ro_document_id RO document ID
     * @return string|false Invoice ID if successful, false otherwise
     */
    private function create_invoice_from_ro($order_id, $ro_document_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono zamówienia - %s', 'optima-woocommerce'), $order_id));
            return false;
        }

        // Get the document from Optima
        $document = $this->api->get_ro_document_by_id($ro_document_id);

        if (!$document) {
            error_log(sprintf(__('Integracja WC Optima: Nie udało się pobrać dokumentu RO %s dla zamówienia %s', 'optima-woocommerce'), $ro_document_id, $order_id));
            return false;
        }

        // Get payment method mapping
        $payment_method = $order->get_payment_method();
        $payment_method_name = $order->get_payment_method_title();
        $optima_payment_method = $this->get_optima_payment_method($payment_method);

        // Get shipping information
        $shipping_method = $order->get_shipping_method();
        $shipping_total = $order->get_shipping_total();
        $shipping_tax = $order->get_shipping_tax();

        // Get order dates
        $order_date = $order->get_date_created()->date('Y-m-d\TH:i:s');
        $payment_date = $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d\TH:i:s') : date('Y-m-d\TH:i:s', strtotime('+7 days'));
        $issue_date = date('Y-m-d\TH:i:s');

        // Get order totals
        $order_total = $order->get_total();
        $order_tax = $order->get_total_tax();
        $order_discount = $order->get_total_discount();

        // Get customer information
        $customer_id = $order->get_customer_id();
        $customer_note = $order->get_customer_note();
        $billing_company = $order->get_billing_company();
        $billing_first_name = $order->get_billing_first_name();
        $billing_last_name = $order->get_billing_last_name();
        $billing_address_1 = $order->get_billing_address_1();
        $billing_address_2 = $order->get_billing_address_2();
        $billing_city = $order->get_billing_city();
        $billing_postcode = $order->get_billing_postcode();
        $billing_country = $order->get_billing_country();
        $billing_state = $order->get_billing_state();
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();
        $vat_number = $order->get_meta('_billing_vat', true);

        // Prepare customer name
        $customer_name = !empty($billing_company) ? $billing_company : trim($billing_first_name . ' ' . $billing_last_name);

        // Prepare full address
        $address_parts = array_filter([
            $billing_address_1,
            $billing_address_2,
            $billing_postcode . ' ' . $billing_city,
            $billing_state,
            WC()->countries->countries[$billing_country] ?? $billing_country
        ]);
        $full_address = implode(', ', $address_parts);

        // Create a comprehensive invoice data structure based on the RO document and sample
        $invoice_data = [
            // Document type and identification
            'type' => 301, // Invoice document type (changed to 301 to match sample)
            'documentTypeId' => 301, // Invoice document type ID (changed to match type)
            'documentTypeName' => 'Faktura VAT', // Default document type name
            'status' => 1,  // Status 1 means active/normal
            'foreignNumber' => 'WC_' . $order->get_order_number(),
            'symbol' => 'FA', // Invoice symbol (changed to match sample)
            'series' => '-', // Series (changed to match sample)
            'number' => null, // Added from sample

            // Calculation and financial information
            'calculatedOn' => 2, // 2 = net (changed to match sample)
            'currency' => $order->get_currency(),
            'exchangeRate' => $order->get_currency() === 'PLN' ? 1 : null, // Set exchange rate if needed
            'discount' => isset($document['discount']) ? $document['discount'] : $order_discount,
            'paid' => $order->is_paid(),
            'canceled' => false,

            // Payment information
            'paymentMethod' => isset($document['paymentMethod']) ? $document['paymentMethod'] : 'gotówka', // Changed to match sample
            'paymentMethodId' => isset($document['paymentMethodId']) ? $document['paymentMethodId'] : null,
            'paymentMethodName' => isset($document['paymentMethodName']) ? $document['paymentMethodName'] : null,

            // Dates
            'documentIssueDate' => $issue_date,
            'documentSaleDate' => $order_date,
            'documentPaymentDate' => $payment_date,
            'issueDate' => $issue_date,
            'saleDate' => $order_date,
            'paymentDate' => $payment_date,
            'documentReservationDate' => '0001-01-01T00:00:00', // Added from sample
            'documentCorrectionDate' => '0001-01-01T00:00:00', // Added from sample
            'documentReceptionDate' => '0001-01-01T00:00:00', // Added from sample
            'documentReceiptDate' => '0001-01-01T00:00:00', // Added from sample
            'documentDeliveryDate' => '0001-01-01T00:00:00', // Added from sample
            'documentPurchaseDate' => '0001-01-01T00:00:00', // Added from sample
            'documentReleaseDate' => '0001-01-01T00:00:00', // Added from sample

            // Warehouse information
            'sourceWarehouseId' => isset($document['sourceWarehouseId']) ? $document['sourceWarehouseId'] : 1,
            'targetWarehouseId' => 0, // Added from sample

            // Description and notes
            'description' => sprintf(
                __('Faktura do zamówienia #%s z WooCommerce (RO: %s)', 'optima-woocommerce'),
                $order->get_order_number(),
                $ro_document_id
            ),
            'notes' => !empty($customer_note) ? $customer_note : null,

            // Customer information
            'customerName' => $customer_name,
            'customerNip' => !empty($vat_number) ? $vat_number : null,
            'customerAddress' => $full_address,
            'customerEmail' => $billing_email,
            'customerPhone' => $billing_phone,

            // VAT Registration Country
            'vatRegistrationCountry' => isset($document['vatRegistrationCountry']) ? $document['vatRegistrationCountry'] : ($billing_country ?: 'PL'),

            // Totals
            'totalNet' => $order->get_total() - $order_tax,
            'totalGross' => $order->get_total(),
            'totalVat' => $order_tax,

            // Reference to original document
            'originalDocumentId' => $ro_document_id,
            'originalDocumentNumber' => isset($document['fullNumber']) ? $document['fullNumber'] : null,

            // Additional fields from sample
            'amountPaid' => 0,
            'amountToPay' => 0,
            'checkIfExists' => false,
            'state' => 0,
            'category' => '',
            'internalReservation' => 0,
            'transactionType' => 0,
            'activeVatVies' => 0,
            'attributes' => [],

            // Empty elements array to be filled with products
            'elements' => [],
        ];

        // Customer information - copy from RO document
        if (isset($document['payer']) && is_array($document['payer'])) {
            $invoice_data['payer'] = $document['payer'];

            // Extract customer name and NIP from payer data
            if (isset($document['payer']['name1'])) {
                $invoice_data['customerName'] = $document['payer']['name1'];
            }

            if (isset($document['payer']['vatNumber'])) {
                $invoice_data['customerNip'] = $document['payer']['vatNumber'];
            }

            // Add defaultPayer based on payer data (from sample)
            $invoice_data['defaultPayer'] = [
                'type' => 3,
                'code' => isset($document['payer']['code']) ? $document['payer']['code'] : 'KA',
                'name1' => isset($document['payer']['name1']) ? $document['payer']['name1'] : 'Klient',
                'name2' => '',
                'name3' => '',
                'vatNumber' => isset($document['payer']['vatNumber']) ? $document['payer']['vatNumber'] : '',
                'country' => isset($document['payer']['country']) ? $document['payer']['country'] : '',
                'city' => isset($document['payer']['city']) ? $document['payer']['city'] : '',
                'street' => isset($document['payer']['street']) ? $document['payer']['street'] : '',
                'postCode' => isset($document['payer']['postCode']) ? $document['payer']['postCode'] : '',
                'houseNumber' => '',
                'flatNumber' => '',
                'phone1' => isset($document['payer']['phone']) ? $document['payer']['phone'] : '',
                'phone2' => '',
                'inactive' => 0,
                'defaultPrice' => 0,
                'regon' => '',
                'email' => isset($document['payer']['email']) ? $document['payer']['email'] : '',
                'paymentMethod' => 'gotówka',
                'dateOfPayment' => 0,
                'maxPaymentDelay' => 0,
                'created' => date('Y-m-d\TH:i:s', strtotime('-1 year')),
                'updated' => date('Y-m-d\TH:i:s'),
                'description' => '',
                'countryCode' => '',
                'countryIso' => '',
                'group' => '',
                'purchaseCategory' => ''
            ];
        } elseif (isset($document['payerId'])) {
            // If we have a payer ID but not payer details, create a minimal payer object
            $invoice_data['payer'] = [
                'code' => $document['payerId']
            ];

            // Add minimal defaultPayer
            $invoice_data['defaultPayer'] = [
                'type' => 3,
                'code' => 'KA',
                'name1' => 'Klient',
                'vatNumber' => '',
                'paymentMethod' => 'gotówka'
            ];
        }

        // If customer name or NIP is not set from payer data, try to get it from the order
        if (!isset($invoice_data['customerName'])) {
            $company = $order->get_billing_company();
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();

            if (!empty($company)) {
                $invoice_data['customerName'] = $company;
            } else {
                $invoice_data['customerName'] = trim($first_name . ' ' . $last_name);
            }
        }

        if (!isset($invoice_data['customerNip'])) {
            $vat_number = $order->get_meta('_billing_vat', true);
            if (!empty($vat_number)) {
                $invoice_data['customerNip'] = $vat_number;
            }
        }

        // Copy and enhance elements (products) from RO document
        if (isset($document['elements']) && is_array($document['elements'])) {
            $invoice_data['elements'] = [];

            // Process each product from the RO document
            foreach ($document['elements'] as $element) {
                // Calculate totals for the element
                $quantity = isset($element['quantity']) ? $element['quantity'] : 0;
                $price = isset($element['price']) ? $element['price'] : 0;
                $vat_rate = isset($element['vatRate']) ? $element['vatRate'] : 23;
                $discount = isset($element['discount']) ? $element['discount'] : 0;

                // Calculate net and gross totals
                $discount_multiplier = (100 - $discount) / 100;
                $total_net = $quantity * $price * $discount_multiplier;
                $total_vat = $total_net * ($vat_rate / 100);
                $total_gross = $total_net + $total_vat;

                // Create a comprehensive element with all fields for invoice according to sample
                $invoice_element = [
                    // Product identification according to sample
                    'id' => isset($element['id']) ? $element['id'] : null,
                    'itemId' => isset($element['itemId']) ? $element['itemId'] : (isset($element['productId']) ? $element['productId'] : null),
                    'code' => isset($element['code']) ? $element['code'] : '',
                    'name' => isset($element['name']) ? $element['name'] : null,
                    'manufacturerCode' => isset($element['manufacturerCode']) ? $element['manufacturerCode'] : '',

                    // Pricing according to sample
                    'unitNetPrice' => $price,
                    'unitGrossPrice' => $price * (1 + ($vat_rate / 100)),
                    'totalNetValue' => $total_net,
                    'totalGrossValue' => $total_gross,
                    'quantity' => $quantity,
                    'vatRate' => $vat_rate,
                    'setCustomValue' => isset($element['setCustomValue']) ? $element['setCustomValue'] : true,
                    'currentQuantity' => isset($element['currentQuantity']) ? $element['currentQuantity'] : 0,

                    // Product details according to sample
                    'unit' => isset($element['unit']) ? strtoupper($element['unit']) : 'SZT', // Default to 'SZT' if not set (uppercase)
                    'purchaseAmount' => isset($element['purchaseAmount']) ? $element['purchaseAmount'] : 0,
                    'attributes' => isset($element['attributes']) ? $element['attributes'] : [],
                    'category' => isset($element['category']) ? $element['category'] : 'MAT. PODSTAWOWE',

                    // Keep additional fields from original implementation
                    'description' => isset($element['description']) ? $element['description'] : '',
                    'discount' => $discount,
                    'discountAmount' => $quantity * $price * ($discount / 100),
                    'totalVat' => $total_vat,
                    'warehouseId' => isset($element['warehouseId']) ? $element['warehouseId'] : 1,
                    'pkwiu' => isset($element['pkwiu']) ? $element['pkwiu'] : null,
                    'currency' => isset($element['currency']) ? $element['currency'] : null,
                    'exchangeRate' => isset($element['exchangeRate']) ? $element['exchangeRate'] : null,
                    'position' => isset($element['position']) ? $element['position'] : null,
                    'isService' => isset($element['isService']) ? $element['isService'] : false,
                    'isExported' => isset($element['isExported']) ? $element['isExported'] : false
                ];

                // Add the element to the invoice
                $invoice_data['elements'][] = $invoice_element;
            }

            // If no elements were added, log a warning
            if (empty($invoice_data['elements'])) {
                error_log(sprintf(__('Integracja WC Optima: Brak produktów w dokumencie RO %s dla zamówienia %s', 'optima-woocommerce'), $ro_document_id, $order_id));
            }
        } else {
            $invoice_data['elements'] = [];
            error_log(sprintf(__('Integracja WC Optima: Brak elementów w dokumencie RO %s dla zamówienia %s', 'optima-woocommerce'), $ro_document_id, $order_id));
        }

        // Copy customer ID if available
        if (isset($document['payerId'])) {
            $invoice_data['payerId'] = $document['payerId'];
        }

        // Validate invoice data before sending to API
        $validation_errors = $this->validate_invoice_data($invoice_data, $order_id);

        if (!empty($validation_errors)) {
            // Add validation errors to order note
            $order->add_order_note(
                sprintf(
                    __('Nie udało się utworzyć faktury w Optima. Błędy walidacji: %s', 'optima-woocommerce'),
                    implode(', ', $validation_errors)
                )
            );

            error_log(sprintf(
                __('Integracja WC Optima: Błędy walidacji faktury dla zamówienia %s: %s', 'optima-woocommerce'),
                $order_id,
                implode(', ', $validation_errors)
            ));

            return false;
        }

        // Log invoice data for debugging
        error_log(sprintf(
            __('Integracja WC Optima: Wysyłanie danych faktury dla zamówienia %s: %s', 'optima-woocommerce'),
            $order_id,
            json_encode($invoice_data, JSON_PRETTY_PRINT)
        ));

        // Create the invoice in Optima
        $result = $this->api->create_invoice($invoice_data);

        if ($result && isset($result['id'])) {
            // Store the invoice ID in the order meta
            update_post_meta($order_id, 'optima_invoice_id', $result['id']);

            // Store the invoice number if available
            if (isset($result['invoiceNumber'])) {
                update_post_meta($order_id, 'optima_invoice_number', $result['invoiceNumber']);
            } elseif (isset($result['fullNumber'])) {
                update_post_meta($order_id, 'optima_invoice_number', $result['fullNumber']);
            }

            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Utworzono fakturę w Optima: %s (%s)', 'optima-woocommerce'),
                    $result['id'],
                    $result['fullNumber'] ?? ''
                )
            );

            // Log success
            error_log(sprintf(
                __('Integracja WC Optima: Pomyślnie utworzono fakturę %s dla zamówienia %s', 'optima-woocommerce'),
                $result['id'],
                $order_id
            ));

            return $result['id'];
        } elseif (is_array($result) && isset($result['error']) && $result['error'] === true) {
            // Handle specific error response
            $error_message = isset($result['message']) ? $result['message'] : __('Nieznany błąd', 'optima-woocommerce');
            $status_code = isset($result['status_code']) ? $result['status_code'] : '';

            // Add a note to the order with detailed error information
            $order->add_order_note(
                sprintf(
                    __('Nie udało się utworzyć faktury w Optima. Błąd %s: %s', 'optima-woocommerce'),
                    $status_code,
                    $error_message
                )
            );

            // Log detailed error information
            error_log(sprintf(
                __('Integracja WC Optima: Błąd podczas tworzenia faktury dla zamówienia %s: %s (Kod: %s)', 'optima-woocommerce'),
                $order_id,
                $error_message,
                $status_code
            ));

            // Log API response if available
            if (isset($result['optima_response'])) {
                error_log(sprintf(
                    __('Integracja WC Optima: Odpowiedź API dla zamówienia %s: %s', 'optima-woocommerce'),
                    $order_id,
                    $result['optima_response']
                ));
            }
        } else {
            // Generic failure
            $order->add_order_note(
                __('Nie udało się utworzyć faktury w Optima.', 'optima-woocommerce')
            );

            // Log generic error
            error_log(sprintf(
                __('Integracja WC Optima: Nie udało się utworzyć faktury dla zamówienia %s. Nieznany błąd.', 'optima-woocommerce'),
                $order_id
            ));

            // Log result for debugging
            if ($result) {
                error_log(sprintf(
                    __('Integracja WC Optima: Odpowiedź API dla zamówienia %s: %s', 'optima-woocommerce'),
                    $order_id,
                    json_encode($result)
                ));
            }
        }

        return false;
    }

    /**
     * Send invoice to customer by email
     *
     * @param int $order_id Order ID
     * @param string $invoice_id Invoice ID
     * @return bool True if successful, false otherwise
     */
    private function send_invoice_to_customer($order_id, $invoice_id)
    {
        // Get the order
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono zamówienia - %s', 'optima-woocommerce'), $order_id));
            return false;
        }

        // Get invoice data
        $invoice = $this->api->get_optima_invoice_by_id($invoice_id);

        if (!$invoice) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono faktury %s dla zamówienia %s', 'optima-woocommerce'), $invoice_id, $order_id));
            return false;
        }

        // Get customer data if available
        $customer = null;
        if (isset($invoice['customerId']) && !empty($invoice['customerId'])) {
            $customer = $this->api->get_optima_customer_by_id($invoice['customerId']);
        }

        // Generate PDF with URL
        $pdf_url = $this->invoice->generate_invoice_pdf($invoice, $customer, true);

        if (!$pdf_url) {
            error_log(sprintf(__('Integracja WC Optima: Nie udało się wygenerować PDF faktury %s dla zamówienia %s', 'optima-woocommerce'), $invoice_id, $order_id));
            return false;
        }

        // Get customer email
        $customer_email = $order->get_billing_email();
        if (empty($customer_email)) {
            error_log(sprintf(__('Integracja WC Optima: Brak adresu email klienta dla zamówienia %s', 'optima-woocommerce'), $order_id));
            return false;
        }

        // Get invoice number
        $invoice_number = get_post_meta($order_id, 'optima_invoice_number', true);
        if (empty($invoice_number) && isset($invoice['invoiceNumber'])) {
            $invoice_number = $invoice['invoiceNumber'];
        } elseif (empty($invoice_number) && isset($invoice['fullNumber'])) {
            $invoice_number = $invoice['fullNumber'];
        } elseif (empty($invoice_number)) {
            $invoice_number = $invoice_id;
        }

        // Prepare email
        $subject = sprintf(__('Faktura nr %s do zamówienia %s', 'optima-woocommerce'), $invoice_number, $order->get_order_number());

        // Email content
        $message = sprintf(
            __('Szanowny Kliencie,

Dziękujemy za złożenie zamówienia w naszym sklepie. W załączniku znajdziesz fakturę nr %s do zamówienia %s.

Pozdrawiamy,
Zespół %s', 'optima-woocommerce'),
            $invoice_number,
            $order->get_order_number(),
            get_bloginfo('name')
        );

        // Get PDF file path from URL
        $upload_dir = wp_upload_dir();
        $pdf_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $pdf_url);

        // Check if file exists
        if (!file_exists($pdf_path)) {
            error_log(sprintf(__('Integracja WC Optima: Nie znaleziono pliku PDF faktury %s dla zamówienia %s', 'optima-woocommerce'), $invoice_id, $order_id));
            return false;
        }

        // Prepare attachments
        $attachments = array($pdf_path);

        // Send email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $mail_sent = wp_mail($customer_email, $subject, nl2br($message), $headers, $attachments);

        if ($mail_sent) {
            // Mark invoice as sent
            update_post_meta($order_id, 'optima_invoice_sent', 'yes');

            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Faktura nr %s została wysłana do klienta na adres %s', 'optima-woocommerce'),
                    $invoice_number,
                    $customer_email
                )
            );

            return true;
        } else {
            // Add a note to the order
            $order->add_order_note(
                sprintf(
                    __('Nie udało się wysłać faktury nr %s do klienta na adres %s', 'optima-woocommerce'),
                    $invoice_number,
                    $customer_email
                )
            );

            error_log(sprintf(__('Integracja WC Optima: Nie udało się wysłać faktury %s dla zamówienia %s na adres %s', 'optima-woocommerce'), $invoice_id, $order_id, $customer_email));
            return false;
        }
    }

    /**
     * Validate invoice data before sending to API
     *
     * @param array $invoice_data Invoice data to validate
     * @param int $order_id Order ID for logging purposes
     * @return array Array of validation errors, empty if no errors
     */
    private function validate_invoice_data($invoice_data, $order_id)
    {
        $errors = [];

        // Check required fields
        $required_fields = [
            'type' => __('Typ dokumentu', 'optima-woocommerce'),
            'status' => __('Status', 'optima-woocommerce'),
            'foreignNumber' => __('Numer obcy', 'optima-woocommerce'),
            'calculatedOn' => __('Sposób kalkulacji', 'optima-woocommerce'),
            'documentIssueDate' => __('Data wystawienia', 'optima-woocommerce'),
            'saleDate' => __('Data sprzedaży', 'optima-woocommerce'),
            'paymentDate' => __('Data płatności', 'optima-woocommerce')
        ];

        foreach ($required_fields as $field => $label) {
            if (!isset($invoice_data[$field]) || (is_string($invoice_data[$field]) && empty($invoice_data[$field]))) {
                $errors[] = sprintf(__('Brak wymaganego pola: %s', 'optima-woocommerce'), $label);
            }
        }

        // Check if elements array exists and is not empty
        if (!isset($invoice_data['elements']) || !is_array($invoice_data['elements']) || empty($invoice_data['elements'])) {
            $errors[] = __('Brak produktów w fakturze', 'optima-woocommerce');
        } else {
            // Validate each element
            foreach ($invoice_data['elements'] as $index => $element) {
                $element_errors = $this->validate_invoice_element($element, $index);
                $errors = array_merge($errors, $element_errors);
            }
        }

        // Check if payer exists
        if (!isset($invoice_data['payer']) || !is_array($invoice_data['payer'])) {
            $errors[] = __('Brak danych płatnika', 'optima-woocommerce');
        } else {
            // Validate payer
            if (!isset($invoice_data['payer']['code']) || empty($invoice_data['payer']['code'])) {
                $errors[] = __('Brak kodu płatnika', 'optima-woocommerce');
            }
        }

        return $errors;
    }

    /**
     * Validate invoice element (product)
     *
     * @param array $element Element data to validate
     * @param int $index Element index for error messages
     * @return array Array of validation errors, empty if no errors
     */
    private function validate_invoice_element($element, $index)
    {
        $errors = [];

        // Check required fields
        $required_fields = [
            'productId' => __('ID produktu', 'optima-woocommerce'),
            'quantity' => __('Ilość', 'optima-woocommerce'),
            'price' => __('Cena', 'optima-woocommerce'),
            'vatRate' => __('Stawka VAT', 'optima-woocommerce')
        ];

        foreach ($required_fields as $field => $label) {
            if (!isset($element[$field]) || (is_string($element[$field]) && empty($element[$field]))) {
                $errors[] = sprintf(__('Produkt #%d: Brak wymaganego pola: %s', 'optima-woocommerce'), $index + 1, $label);
            }
        }

        // Validate numeric fields
        if (isset($element['quantity']) && (!is_numeric($element['quantity']) || $element['quantity'] <= 0)) {
            $errors[] = sprintf(__('Produkt #%d: Nieprawidłowa ilość', 'optima-woocommerce'), $index + 1);
        }

        if (isset($element['price']) && (!is_numeric($element['price']) || $element['price'] < 0)) {
            $errors[] = sprintf(__('Produkt #%d: Nieprawidłowa cena', 'optima-woocommerce'), $index + 1);
        }

        if (isset($element['vatRate']) && (!is_numeric($element['vatRate']) || $element['vatRate'] < 0 || $element['vatRate'] > 100)) {
            $errors[] = sprintf(__('Produkt #%d: Nieprawidłowa stawka VAT', 'optima-woocommerce'), $index + 1);
        }

        return $errors;
    }

    /**
     * Display invoice link in order admin page
     *
     * @param WC_Order $order The order object
     */
    public function display_invoice_link_in_order($order)
    {
        // Get the invoice ID from order meta
        $invoice_id = $order->get_meta('optima_invoice_id', true);

        // Only display if we have an invoice ID
        if (empty($invoice_id)) {
            return;
        }

        // Get invoice number
        $invoice_number = $order->get_meta('optima_invoice_number', true);
        if (empty($invoice_number)) {
            $invoice_number = $invoice_id;
        }

        // Create a nonce for security
        $nonce = wp_create_nonce('wc_optima_fetch_invoices');

        // Generate the URL for the invoice PDF
        $invoice_url = admin_url('admin-ajax.php?action=wc_optima_get_invoice_pdf&invoice_id=' . $invoice_id . '&nonce=' . $nonce . '&return_url=true');

        // Display the invoice link
        echo '<p class="form-field form-field-wide">';
        echo '<strong>' . __('Faktura Optima:', 'optima-woocommerce') . '</strong> ';
        echo '<a href="' . esc_url($invoice_url) . '" target="_blank">' . esc_html($invoice_number) . '</a>';
        echo '</p>';
    }
}
