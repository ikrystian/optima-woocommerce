<?php

/**
 * API handling class for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling API communication with Optima
 */
class WC_Optima_API
{
    /**
     * API URL
     *
     * @var string
     */
    private $api_url;

    /**
     * API username
     *
     * @var string
     */
    private $username;

    /**
     * API password
     *
     * @var string
     */
    private $password;

    /**
     * Access token
     *
     * @var string
     */
    private $access_token;

    /**
     * Token expiry
     *
     * @var int
     */
    private $token_expiry;

    /**
     * Constructor
     *
     * @param array $options Plugin options
     */
    public function __construct($options)
    {
        // Set API credentials from options
        $this->api_url = $options['api_url'];
        $this->username = $options['username'];
        $this->password = $options['password'];
    }

    /**
     * Get access token from Optima API
     * 
     * @return string|false Access token or false on failure
     */
    public function get_access_token()
    {
        // Check if credentials are set
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            error_log('WC Optima Integration: API credentials not configured.');
            return false;
        }

        // Check if we have a valid cached token
        $token_data = get_option('wc_optima_token_data', null);

        if ($token_data && isset($token_data['expires_at']) && $token_data['expires_at'] > time()) {
            $this->access_token = $token_data['access_token'];
            return $this->access_token;
        }

        // No valid token, request a new one
        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available through autoloading, let's use WordPress HTTP API
                return $this->get_token_with_wp_http();
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'form_params' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'grant_type' => 'password'
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ];

            $response = $client->request('POST', $this->api_url . '/Token', $options);
            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['access_token'])) {
                $this->access_token = $result['access_token'];

                // Save token with expiry information
                $token_data = [
                    'access_token' => $result['access_token'],
                    'expires_at' => time() + $result['expires_in'] - 300 // 5 minutes buffer
                ];

                update_option('wc_optima_token_data', $token_data);

                return $this->access_token;
            }
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting access token - ' . $e->getMessage());
            // Fall back to WordPress HTTP API if Guzzle fails
            return $this->get_token_with_wp_http();
        }

        return false;
    }

    /**
     * Get token using WordPress HTTP API as a fallback
     * 
     * @return string|false Access token or false on failure
     */
    private function get_token_with_wp_http()
    {
        // Check if credentials are set
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            error_log('WC Optima Integration: API credentials not configured.');
            return false;
        }

        $response = wp_remote_post($this->api_url . '/Token', [
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'username' => $this->username,
                'password' => $this->password,
                'grant_type' => 'password'
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['access_token'])) {
            $this->access_token = $result['access_token'];

            // Save token with expiry information
            $token_data = [
                'access_token' => $result['access_token'],
                'expires_at' => time() + $result['expires_in'] - 300 // 5 minutes buffer
            ];

            update_option('wc_optima_token_data', $token_data);

            return $this->access_token;
        }

        return false;
    }

    /**
     * Get products from Optima API
     * 
     * @return array|false Array of products or false on failure
     */
    public function get_optima_products()
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_products_with_wp_http($token);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Items', $options);
            $products = json_decode($response->getBody()->getContents(), true);

            return $products;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting products - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_products_with_wp_http($token);
        }

        return false;
    }

    /**
     * Get products using WordPress HTTP API as a fallback
     * 
     * @param string $token The access token
     * @return array|false Array of products or false on failure
     */
    private function get_products_with_wp_http($token)
    {
        $response = wp_remote_get($this->api_url . '/Items', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $products = json_decode($body, true);

        return $products;
    }

    /**
     * Get product stock quantities from Optima API
     * 
     * @return array|false Array of stock data or false on failure
     */
    public function get_optima_stock()
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_stock_with_wp_http($token);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Stocks', $options);
            $stocks = json_decode($response->getBody()->getContents(), true);

            return $stocks;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting stock quantities - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_stock_with_wp_http($token);
        }

        return false;
    }

    /**
     * Get stock using WordPress HTTP API as a fallback
     * 
     * @param string $token The access token
     * @return array|false Array of stock data or false on failure
     */
    private function get_stock_with_wp_http($token)
    {
        $response = wp_remote_get($this->api_url . '/Stocks', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $stocks = json_decode($body, true);

        return $stocks;
    }

    /**
     * Get customers from Optima API
     * 
     * @return array|false Array of customers or false on failure
     */
    public function get_optima_customers()
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_customers_with_wp_http($token);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Customers', $options);
            $customers = json_decode($response->getBody()->getContents(), true);

            return $customers;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting customers - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_customers_with_wp_http($token);
        }

        return false;
    }

    /**
     * Get customers using WordPress HTTP API as a fallback
     * 
     * @param string $token The access token
     * @return array|false Array of customers or false on failure
     */
    private function get_customers_with_wp_http($token)
    {
        $response = wp_remote_get($this->api_url . '/Customers', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $customers = json_decode($body, true);

        return $customers;
    }

    /**
     * Create a new customer in Optima
     * 
     * @param array $customer_data Customer data in Optima format
     * @return array|false New customer data if created, false on failure
     */
    public function create_optima_customer($customer_data)
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log('WC Optima Integration: Failed to get access token');
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->create_customer_with_wp_http($token, $customer_data);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($customer_data)
            ];

            $response = $client->request('POST', $this->api_url . '/Customers', $options);
            $result = json_decode($response->getBody()->getContents(), true);

            return $result;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error creating customer - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->create_customer_with_wp_http($token, $customer_data);
        }

        return false;
    }

    /**
     * Create customer using WordPress HTTP API as a fallback
     * 
     * @param string $token The access token
     * @param array $customer_data Customer data in Optima format
     * @return array|false New customer data if created, false on failure
     */
    private function create_customer_with_wp_http($token, $customer_data)
    {
        $response = wp_remote_post($this->api_url . '/Customers', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($customer_data)
        ]);

        if (is_wp_error($response)) {
            error_log('WC Optima Integration WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        return $result;
    }
}
