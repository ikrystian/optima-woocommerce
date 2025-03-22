<?php

/**
 * Customer handling class for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling customer data between Optima and WooCommerce
 */
class WC_Optima_Customer
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
    }

    /**
     * Process customer for Optima when a new order is placed
     * 
     * @param int $order_id The WooCommerce order ID
     * @param array $posted_data The posted data from checkout
     * @param WC_Order $order The WooCommerce order object
     */
    public function process_customer_for_optima($order_id, $posted_data, $order)
    {
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
            $new_customer = $this->api->create_optima_customer($customer_data);

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
     * Check if a customer exists in Optima
     * 
     * @param string $email Customer email
     * @param string $vat_number Customer VAT number (optional)
     * @return array|false Customer data if found, false otherwise
     */
    private function check_customer_exists_in_optima($email, $vat_number = '')
    {
        $customers = $this->api->get_optima_customers();

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
     * Map WooCommerce customer data to Optima format
     * 
     * @param int $customer_id WooCommerce customer ID
     * @param WC_Order $order WooCommerce order object
     * @return array Customer data in Optima format
     */
    private function map_wc_customer_to_optima($customer_id, $order)
    {
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
            'code' => $customer_code . '_WC_' . substr(date('Ymd_His'), 0, 15),
            'name1' => $name1,
            'name2' => !empty($company) ? trim($first_name . ' ' . $last_name) : '',
            'name3' => '',
            'vatNumber' => '2572958886',
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
