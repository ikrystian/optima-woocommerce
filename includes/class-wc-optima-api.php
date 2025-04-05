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
     * Get a specific RO document by ID from Optima API
     *
     * @param string $document_id Document ID
     * @return array|false Document data if found, false otherwise
     */
    public function get_ro_document_by_id($document_id)
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu', 'optima-woocommerce'));
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_ro_document_by_id_with_wp_http($token, $document_id);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Documents/' . $document_id, $options);
            $document = json_decode($response->getBody()->getContents(), true);

            return $document;
        } catch (Exception $e) {
            error_log(__('Integracja WC Optima: Błąd podczas pobierania dokumentu RO wg ID - ', 'optima-woocommerce') . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_ro_document_by_id_with_wp_http($token, $document_id);
        }

        return false;
    }

    /**
     * Get RO document by ID using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param string $document_id Document ID
     * @return array|false Document data if found, false otherwise
     */
    private function get_ro_document_by_id_with_wp_http($token, $document_id)
    {
        $response = wp_remote_get($this->api_url . '/Documents/' . $document_id, [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $document = json_decode($body, true);

        return $document;
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
            error_log(__('Integracja WC Optima: Dane uwierzytelniające API nie zostały skonfigurowane.', 'optima-woocommerce'));
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
            error_log(__('Integracja WC Optima: Błąd podczas uzyskiwania tokena dostępu - ', 'optima-woocommerce') . $e->getMessage());
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
            error_log(__('Integracja WC Optima: Dane uwierzytelniające API nie zostały skonfigurowane.', 'optima-woocommerce'));
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
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
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
     * @param int $offset Optional. Offset for pagination.
     * @param int $limit Optional. Number of products per page. Default 100.
     * @return array|false Array of products or false on failure
     */
    public function get_optima_products($offset = 0, $limit = 100)
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu', 'optima-woocommerce'));
            return false;
        }

        $all_products = [];
        $total_fetched = 0;
        $has_more = true;

        // Continue fetching until no more products are returned
        while ($has_more) {
            try {
                if (!class_exists('\\GuzzleHttp\\Client')) {
                    $page_products = $this->get_products_with_wp_http($token, $offset + $total_fetched, $limit);
                } else {
                    $client = new \GuzzleHttp\Client();
                    $options = [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token
                        ],
                    ];

                    $url = $this->api_url . '/Items?offset=' . ($offset + $total_fetched) . '&limit=' . $limit;
                    $response = $client->request('GET', $url, $options);
                    $page_products = json_decode($response->getBody()->getContents(), true);
                }

                if (!$page_products || !is_array($page_products) || count($page_products) === 0) {
                    $has_more = false;
                } else {
                    // Add this page's products to our collection
                    $all_products = array_merge($all_products, $page_products);
                    $total_fetched += count($page_products);

                    // If we got fewer products than the limit, we've reached the end
                    if (count($page_products) < $limit) {
                        $has_more = false;
                    }

                    // Log progress
                    error_log(sprintf(__('Integracja WC Optima: Pobranych %d produktów do tej pory', 'optima-woocommerce'), $total_fetched));
                }
            } catch (Exception $e) {
                error_log(__('Integracja WC Optima: Błąd podczas pobierania produktów - ', 'optima-woocommerce') . $e->getMessage());
                // If there's an error, stop the loop and return what we have so far
                // or fall back to WordPress HTTP API for the current page
                if (count($all_products) === 0) {
                    $page_products = $this->get_products_with_wp_http($token, $offset, $limit);
                    if (is_array($page_products)) {
                        return $page_products;
                    }
                    return false;
                }
                $has_more = false;
            }
        }

        error_log(sprintf(__('Integracja WC Optima: Zakończono pobieranie wszystkich %d produktów', 'optima-woocommerce'), count($all_products)));
        return $all_products;
    }

    /**
     * Get products using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param int $offset Offset for pagination
     * @param int $limit Number of products per page
     * @return array|false Array of products or false on failure
     */
    private function get_products_with_wp_http($token, $offset = 0, $limit = 2000)
    {
        $response = wp_remote_get($this->api_url . '/Items?offset=' . $offset . '&limit=' . $limit, [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
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
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu', 'optima-woocommerce'));
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
            error_log(__('Integracja WC Optima: Błąd podczas pobierania stanów magazynowych - ', 'optima-woocommerce') . $e->getMessage());
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
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $stocks = json_decode($body, true);

        return $stocks;
    }

    /**
     * Get customers from Optima API
     *
     * @param int $limit Optional. Maximum number of customers to return. Default 0 (all customers).
     * @return array|false Array of customers or false on failure
     */
    public function get_optima_customers($limit = 0)
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu', 'optima-woocommerce'));
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

            // Apply limit if specified
            if ($limit > 0 && is_array($customers)) {
                $customers = array_slice($customers, 0, $limit);
            }

            return $customers;
        } catch (Exception $e) {
            error_log(__('Integracja WC Optima: Błąd podczas pobierania klientów - ', 'optima-woocommerce') . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_customers_with_wp_http($token, $limit);
        }

        return false;
    }

    /**
     * Get customers using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param int $limit Optional. Maximum number of customers to return. Default 0 (all customers).
     * @return array|false Array of customers or false on failure
     */
    private function get_customers_with_wp_http($token, $limit = 0)
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
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $customers = json_decode($body, true);

        // Apply limit if specified
        if ($limit > 0 && is_array($customers)) {
            $customers = array_slice($customers, 0, $limit);
        }

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
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu', 'optima-woocommerce'));
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
            error_log(__('Integracja WC Optima: Błąd podczas tworzenia klienta - ', 'optima-woocommerce') . $e->getMessage());
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
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        return $result;
    }

    /**
     * Create RO document in Optima
     *
     * @param array $order_data Order data in Optima format
     * @return array|false New document data if created, false or error array on failure
     */
    public function create_ro_document($order_data)
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu do tworzenia dokumentu RO', 'optima-woocommerce'));
            return [
                'error' => true,
                'message' => __('Nie udało się uzyskać tokena dostępu do API Optima', 'optima-woocommerce')
            ];
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->create_ro_with_wp_http($token, $order_data);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($order_data)
            ];

            // Add retry mechanism for Guzzle requests
            $max_retries = 3; // Maximum number of retry attempts
            $retry_delay = 2; // Delay between retries in seconds
            $retry_count = 0;

            while ($retry_count <= $max_retries) {
                try {
                    $response = $client->request('POST', $this->api_url . '/Documents', $options);
                    $result = json_decode($response->getBody()->getContents(), true);
                    return $result;
                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    // Handle 4xx errors (client errors)
                    $response = $e->getResponse();
                    $status_code = $response->getStatusCode();
                    $body = $response->getBody()->getContents();
                    $result = json_decode($body, true);

                    $error_message = '';
                    // Try to extract error message from response
                    if (is_array($result) && isset($result['Message'])) {
                        $error_message = $result['Message'];
                    } elseif (is_array($result) && isset($result['message'])) {
                        $error_message = $result['message'];
                    } elseif (is_array($result) && isset($result['error']) && isset($result['error']['message'])) {
                        $error_message = $result['error']['message'];
                    } elseif (!empty($body)) {
                        $error_message = $body;
                    } else {
                        $error_message = $e->getMessage();
                    }

                    error_log(sprintf(__('Integracja WC Optima - Błąd API (%d): %s', 'optima-woocommerce'), $status_code, $error_message));

                    // Client errors (4xx) are usually not worth retrying unless it's a rate limit (429)
                    if ($status_code == 429 && $retry_count < $max_retries) {
                        $retry_count++;
                        error_log(sprintf(
                            __('Integracja WC Optima - Ponowna próba (%d/%d) za %d sekund', 'optima-woocommerce'),
                            $retry_count,
                            $max_retries,
                            $retry_delay
                        ));
                        sleep($retry_delay);
                        continue;
                    }

                    return [
                        'error' => true,
                        'status_code' => $status_code,
                        'message' => $error_message,
                        'retries' => $retry_count
                    ];
                } catch (\GuzzleHttp\Exception\ServerException $e) {
                    // Handle 5xx errors (server errors)
                    error_log(__('Integracja WC Optima: Błąd serwera podczas tworzenia dokumentu RO - ', 'optima-woocommerce') . $e->getMessage());

                    // Server errors are good candidates for retry
                    if ($retry_count < $max_retries) {
                        $retry_count++;
                        error_log(sprintf(
                            __('Integracja WC Optima - Ponowna próba (%d/%d) za %d sekund', 'optima-woocommerce'),
                            $retry_count,
                            $max_retries,
                            $retry_delay
                        ));
                        sleep($retry_delay);
                        continue;
                    }

                    // Fall back to WordPress HTTP API after exhausting retries
                    return $this->create_ro_with_wp_http($token, $order_data);
                } catch (\GuzzleHttp\Exception\ConnectException $e) {
                    // Handle connection errors
                    error_log(__('Integracja WC Optima: Błąd połączenia podczas tworzenia dokumentu RO - ', 'optima-woocommerce') . $e->getMessage());

                    // Connection errors are good candidates for retry
                    if ($retry_count < $max_retries) {
                        $retry_count++;
                        error_log(sprintf(
                            __('Integracja WC Optima - Ponowna próba (%d/%d) za %d sekund', 'optima-woocommerce'),
                            $retry_count,
                            $max_retries,
                            $retry_delay
                        ));
                        sleep($retry_delay);
                        continue;
                    }

                    // Fall back to WordPress HTTP API after exhausting retries
                    return $this->create_ro_with_wp_http($token, $order_data);
                }
            }
        } catch (Exception $e) {
            error_log(__('Integracja WC Optima: Błąd podczas tworzenia dokumentu RO - ', 'optima-woocommerce') . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->create_ro_with_wp_http($token, $order_data);
        }
    }

    /**
     * Create RO document using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param array $order_data Order data
     * @param int $retry_count Number of retries attempted (default 0)
     * @return array|false New document data if created, false or error array on failure
     */
    private function create_ro_with_wp_http($token, $order_data, $retry_count = 0)
    {
        $max_retries = 3; // Maximum number of retry attempts
        $retry_delay = 2; // Delay between retries in seconds

        $response = wp_remote_post($this->api_url . '/Documents', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($order_data)
        ]);

        if (is_wp_error($response)) {
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());

            // Retry on connection errors if we haven't exceeded max retries
            if ($retry_count < $max_retries) {
                error_log(sprintf(
                    __('Integracja WC Optima - Ponowna próba (%d/%d) za %d sekund', 'optima-woocommerce'),
                    $retry_count + 1,
                    $max_retries,
                    $retry_delay
                ));

                // Wait before retrying
                sleep($retry_delay);

                // Retry with incremented retry count
                return $this->create_ro_with_wp_http($token, $order_data, $retry_count + 1);
            }

            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // Check for error responses (non-2xx status codes)
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = '';

            // Try to extract error message from response
            if (is_array($result) && isset($result['Message'])) {
                $error_message = $result['Message'];
            } elseif (is_array($result) && isset($result['message'])) {
                $error_message = $result['message'];
            } elseif (is_array($result) && isset($result['error']) && isset($result['error']['message'])) {
                $error_message = $result['error']['message'];
            } elseif (!empty($body)) {
                $error_message = $body;
            } else {
                $error_message = 'Unknown error (Status code: ' . $status_code . ')';
            }

            error_log(sprintf(__('Integracja WC Optima - Błąd API (%d): %s', 'optima-woocommerce'), $status_code, $error_message));

            // Retry on server errors (5xx) if we haven't exceeded max retries
            if ($status_code >= 500 && $retry_count < $max_retries) {
                error_log(sprintf(
                    __('Integracja WC Optima - Ponowna próba (%d/%d) za %d sekund', 'optima-woocommerce'),
                    $retry_count + 1,
                    $max_retries,
                    $retry_delay
                ));

                // Wait before retrying
                sleep($retry_delay);

                // Retry with incremented retry count
                return $this->create_ro_with_wp_http($token, $order_data, $retry_count + 1);
            }

            // Return the error information so it can be used in the order note
            return [
                'error' => true,
                'status_code' => $status_code,
                'message' => $error_message,
                'retries' => $retry_count
            ];
        }

        return $result;
    }

    /**
     * Get RO documents from Optima API
     *
     * @return array|false Array of RO documents or false on failure
     */
    public function get_ro_documents()
    {
        $token = $this->get_access_token();

        if (!$token) {
            error_log(__('Integracja WC Optima: Nie udało się uzyskać tokena dostępu', 'optima-woocommerce'));
            return false;
        }

        try {
            // Check if GuzzleHttp exists
            if (!class_exists('\\GuzzleHttp\\Client')) {
                // Since Guzzle isn't available, use WordPress HTTP API
                return $this->get_ro_documents_with_wp_http($token);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Documents', $options);
            $documents = json_decode($response->getBody()->getContents(), true);

            return $documents;
        } catch (Exception $e) {
            error_log(__('Integracja WC Optima: Błąd podczas pobierania dokumentów RO - ', 'optima-woocommerce') . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_ro_documents_with_wp_http($token);
        }

        return false;
    }

    /**
     * Get RO documents using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param int $limit Optional. Maximum number of documents to return. Default 0 (all documents).
     * @return array|false Array of RO documents or false on failure
     */
    private function get_ro_documents_with_wp_http($token, $limit = 0)
    {
        $response = wp_remote_get($this->api_url . '/Documents', [
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if (is_wp_error($response)) {
            error_log(__('Integracja WC Optima - Błąd WP HTTP: ', 'optima-woocommerce') . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $documents = json_decode($body, true);

        // Apply limit if specified
        if ($limit > 0 && is_array($documents)) {
            $documents = array_slice($documents, 0, $limit);
        }

        return $documents;
    }

    /**
     * Get invoices from Optima API
     *
     * @param array $filters Optional. Filters to apply to the invoice search.
     * @param int $limit Optional. Maximum number of invoices to return. Default 0 (all invoices).
     * @return array|false Array of invoices or false on failure
     */
    public function get_optima_invoices($filters = array(), $limit = 0)
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
                return $this->get_invoices_with_wp_http($token, $filters, $limit);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            // Build query string for filters
            $query_string = '';
            if (!empty($filters)) {
                $query_params = array();
                foreach ($filters as $key => $value) {
                    $query_params[] = $key . '=' . urlencode($value);
                }
                $query_string = '?' . implode('&', $query_params);
            }

            $response = $client->request('GET', $this->api_url . '/Invoices' . $query_string, $options);
            $invoices = json_decode($response->getBody()->getContents(), true);

            // Apply limit if specified
            if ($limit > 0 && is_array($invoices)) {
                $invoices = array_slice($invoices, 0, $limit);
            }

            return $invoices;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting invoices - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_invoices_with_wp_http($token, $filters, $limit);
        }

        return false;
    }

    /**
     * Get invoices using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param array $filters Optional. Filters to apply to the invoice search.
     * @param int $limit Optional. Maximum number of invoices to return. Default 0 (all invoices).
     * @return array|false Array of invoices or false on failure
     */
    private function get_invoices_with_wp_http($token, $filters = array(), $limit = 0)
    {
        // Build query string for filters
        $query_string = '';
        if (!empty($filters)) {
            $query_params = array();
            foreach ($filters as $key => $value) {
                $query_params[] = $key . '=' . urlencode($value);
            }
            $query_string = '?' . implode('&', $query_params);
        }

        $response = wp_remote_get($this->api_url . '/Invoices' . $query_string, [
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
        $invoices = json_decode($body, true);

        // Apply limit if specified
        if ($limit > 0 && is_array($invoices)) {
            $invoices = array_slice($invoices, 0, $limit);
        }

        return $invoices;
    }

    /**
     * Get a specific invoice by ID from Optima API
     *
     * @param string $invoice_id Invoice ID
     * @return array|false Invoice data if found, false otherwise
     */
    public function get_optima_invoice_by_id($invoice_id)
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
                return $this->get_invoice_by_id_with_wp_http($token, $invoice_id);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Invoices/' . $invoice_id, $options);
            $invoice = json_decode($response->getBody()->getContents(), true);

            return $invoice;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting invoice by ID - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_invoice_by_id_with_wp_http($token, $invoice_id);
        }

        return false;
    }

    /**
     * Get invoice by ID using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param string $invoice_id Invoice ID
     * @return array|false Invoice data if found, false otherwise
     */
    private function get_invoice_by_id_with_wp_http($token, $invoice_id)
    {
        $response = wp_remote_get($this->api_url . '/Invoices/' . $invoice_id, [
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
        $invoice = json_decode($body, true);

        return $invoice;
    }

    /**
     * Search for invoices in Optima API based on search parameters
     *
     * @param array $search_params Search parameters
     * @return array|false Array of invoices or false on failure
     */
    public function search_optima_invoices($search_params)
    {
        // Convert search parameters to filters
        $filters = array();

        if (isset($search_params['invoice_number']) && !empty($search_params['invoice_number'])) {
            $filters['invoiceNumber'] = $search_params['invoice_number'];
        }

        if (isset($search_params['date_from']) && !empty($search_params['date_from'])) {
            $filters['dateFrom'] = $search_params['date_from'];
        }

        if (isset($search_params['date_to']) && !empty($search_params['date_to'])) {
            $filters['dateTo'] = $search_params['date_to'];
        }

        if (isset($search_params['customer_id']) && !empty($search_params['customer_id'])) {
            $filters['customerId'] = $search_params['customer_id'];
        }

        // Get invoices with filters
        return $this->get_optima_invoices($filters);
    }

    /**
     * Get a specific customer by ID from Optima API
     *
     * @param string $customer_id Customer ID
     * @return array|false Customer data if found, false otherwise
     */
    public function get_optima_customer_by_id($customer_id)
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
                return $this->get_customer_by_id_with_wp_http($token, $customer_id);
            }

            $client = new \GuzzleHttp\Client();

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ];

            $response = $client->request('GET', $this->api_url . '/Customers/' . $customer_id, $options);
            $customer = json_decode($response->getBody()->getContents(), true);

            return $customer;
        } catch (Exception $e) {
            error_log('WC Optima Integration: Error getting customer by ID - ' . $e->getMessage());
            // Fall back to WordPress HTTP API
            return $this->get_customer_by_id_with_wp_http($token, $customer_id);
        }

        return false;
    }

    /**
     * Get customer by ID using WordPress HTTP API as a fallback
     *
     * @param string $token The access token
     * @param string $customer_id Customer ID
     * @return array|false Customer data if found, false otherwise
     */
    private function get_customer_by_id_with_wp_http($token, $customer_id)
    {
        $response = wp_remote_get($this->api_url . '/Customers/' . $customer_id, [
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
        $customer = json_decode($body, true);

        return $customer;
    }
}
