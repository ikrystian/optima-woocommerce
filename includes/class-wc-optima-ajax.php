<?php

/**
 * AJAX handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling AJAX requests
 */
class WC_Optima_AJAX
{
    /**
     * GUS API instance
     *
     * @var WC_Optima_GUS_API
     */
    private $gus_api;

    /**
     * API instance
     *
     * @var WC_Optima_API
     */
    private $api;

    /**
     * Constructor
     *
     * @param WC_Optima_GUS_API $gus_api GUS API instance
     * @param WC_Optima_API $api API instance (optional)
     */
    public function __construct($gus_api, $api = null)
    {
        $this->gus_api = $gus_api;
        $this->api = $api;

        // Register AJAX handlers
        add_action('wp_ajax_wc_optima_verify_company', array($this, 'verify_company'));
        add_action('wp_ajax_nopriv_wc_optima_verify_company', array($this, 'verify_company'));

        // Register product search AJAX handlers
        if ($this->api) {
            add_action('wp_ajax_optima_search_products', [$this, 'search_products']);
            add_action('wp_ajax_optima_validate_product', [$this, 'validate_product']);
        }
    }

    /**
     * Verify company data using GUS API
     */
    public function verify_company()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_optima_verify_company')) {
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa', 'optima-woocommerce'));
            return;
        }

        // Check if NIP is provided
        if (!isset($_POST['nip']) || empty($_POST['nip'])) {
            wp_send_json_error(__('NIP jest wymagany', 'optima-woocommerce'));
            return;
        }

        $nip = sanitize_text_field($_POST['nip']);
        $nip = preg_replace('/[^0-9]/', '', $nip);

        // Validate NIP format
        if (!$this->gus_api->validate_nip($nip)) {
            wp_send_json_error(__('Nieprawidłowy format NIP', 'optima-woocommerce'));
            return;
        }

        // Get company data from GUS API
        $company_data = $this->gus_api->get_company_by_nip($nip);

        // Dodaj informacje debugowania
        if ($this->gus_api->is_debug_mode()) {
            $debug_logs = $this->gus_api->get_debug_log();

            // Jeśli nie znaleziono danych firmy, zwróć błąd z logami
            if (!$company_data) {
                wp_send_json_error([
                    'message' => __('Nie znaleziono firmy', 'optima-woocommerce'),
                    'debug_logs' => $debug_logs
                ]);
                return;
            }
        } else if (!$company_data) {
            // Jeśli nie znaleziono danych firmy i nie jest włączony tryb debugowania, zwróć prosty błąd
            wp_send_json_error(__('Nie znaleziono firmy', 'optima-woocommerce'));
            return;
        }

        // Extract relevant data
        $result = array();
        $debug_logs = $this->gus_api->is_debug_mode() ? $this->gus_api->get_debug_log() : [];

        if (is_array($company_data) && !empty($company_data)) {
            $company = $company_data[0];

            $result = array(
                'name' => isset($company['Nazwa']) ? $company['Nazwa'] : '',
                'regon' => isset($company['Regon']) ? $company['Regon'] : '',
                'address' => isset($company['Ulica']) ? $company['Ulica'] : '',
                'house_number' => isset($company['NrNieruchomosci']) ? $company['NrNieruchomosci'] : '',
                'apartment_number' => isset($company['NrLokalu']) ? $company['NrLokalu'] : '',
                'postcode' => isset($company['KodPocztowy']) ? $company['KodPocztowy'] : '',
                'city' => isset($company['Miejscowosc']) ? $company['Miejscowosc'] : '',
                'commune' => isset($company['Gmina']) ? $company['Gmina'] : '',
                'county' => isset($company['Powiat']) ? $company['Powiat'] : '',
                'province' => isset($company['Wojewodztwo']) ? $company['Wojewodztwo'] : '',
                'debug_logs' => $debug_logs
            );

            // Combine address parts
            if (!empty($result['address'])) {
                if (!empty($result['house_number'])) {
                    $result['address'] .= ' ' . $result['house_number'];
                }
                if (!empty($result['apartment_number'])) {
                    $result['address'] .= '/' . $result['apartment_number'];
                }
            }
        } else {
            // Jeśli nie znaleziono danych firmy, ale mamy logi debugowania, dołącz je do odpowiedzi
            $result = array(
                'debug_logs' => $debug_logs
            );
        }

        wp_send_json_success($result);
    }

    /**
     * Search products in Optima
     */
    public function search_products()
    {
        // Check if API is available
        if (!$this->api) {
            wp_send_json_error(__('API Optima nie jest dostępne.', 'optima-woocommerce'));
            return;
        }

        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'optima_search_products')) {
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa.', 'optima-woocommerce'));
            return;
        }

        // Check if search term is provided
        if (!isset($_POST['search_term']) || empty($_POST['search_term'])) {
            wp_send_json_error(__('Brak terminu wyszukiwania.', 'optima-woocommerce'));
            return;
        }

        $search_term = sanitize_text_field($_POST['search_term']);

        // Get all products from Optima
        $products = $this->api->get_optima_products();

        if (!$products) {
            wp_send_json_error(__('Nie udało się pobrać produktów z Optima.', 'optima-woocommerce'));
            return;
        }

        // Filter products by search term
        $filtered_products = [];
        foreach ($products as $product) {
            // Check if product has name and code
            if (!isset($product['name']) || !isset($product['code'])) {
                continue;
            }

            // Check if product name or code contains search term
            if (stripos($product['name'], $search_term) !== false || stripos($product['code'], $search_term) !== false) {
                // Add product to filtered list
                $filtered_products[] = [
                    'id' => isset($product['id']) ? $product['id'] : '',
                    'code' => isset($product['code']) ? $product['code'] : '',
                    'name' => isset($product['name']) ? $product['name'] : '',
                    'vatRate' => isset($product['vatRate']) ? $product['vatRate'] : '',
                    'unit' => isset($product['unit']) ? $product['unit'] : '',
                    'pkwiu' => isset($product['pkwiu']) ? $product['pkwiu'] : '',
                ];
            }
        }

        // Return filtered products
        wp_send_json_success($filtered_products);
    }

    /**
     * Validate product in Optima
     */
    public function validate_product()
    {
        // Check if API is available
        if (!$this->api) {
            wp_send_json_error(__('API Optima nie jest dostępne.', 'optima-woocommerce'));
            return;
        }

        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'optima_validate_product')) {
            wp_send_json_error(__('Nieprawidłowy token bezpieczeństwa.', 'optima-woocommerce'));
            return;
        }

        // Check if product ID is provided
        if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
            wp_send_json_error(__('Brak ID produktu.', 'optima-woocommerce'));
            return;
        }

        $product_id = sanitize_text_field($_POST['product_id']);

        // Get product from Optima
        $products = $this->api->get_optima_products();

        if (!$products) {
            wp_send_json_error(__('Nie udało się pobrać produktów z Optima.', 'optima-woocommerce'));
            return;
        }

        // Find product by ID
        $found_product = null;
        foreach ($products as $product) {
            if (isset($product['id']) && $product['id'] == $product_id) {
                $found_product = $product;
                break;
            }
        }

        if (!$found_product) {
            wp_send_json_error(__('Nie znaleziono produktu w Optima.', 'optima-woocommerce'));
            return;
        }

        // Return product data
        wp_send_json_success($found_product);
    }
}
