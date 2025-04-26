<?php

/**
 * Logs handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling API logs
 */
class WC_Optima_Logs
{
    /**
     * Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wc_optima_api_logs';
    }

    /**
     * Create logs table
     */
    public function create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            endpoint varchar(255) NOT NULL,
            request_method varchar(10) NOT NULL,
            request_data longtext,
            response_data longtext,
            status_code int(5),
            success tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log API request
     *
     * @param string $endpoint API endpoint
     * @param string $method Request method (GET, POST, etc.)
     * @param array|string $request_data Request data
     * @param array|string $response_data Response data
     * @param int $status_code HTTP status code
     * @param bool $success Whether the request was successful
     * @return int|false The ID of the inserted log or false on failure
     */
    public function log_request($endpoint, $method, $request_data, $response_data, $status_code, $success)
    {
        global $wpdb;

        // Sanitize request data (remove sensitive information)
        $sanitized_request = $this->sanitize_data($request_data);
        $sanitized_response = $this->sanitize_data($response_data);

        // Insert log into database
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'timestamp' => current_time('mysql'),
                'endpoint' => $endpoint,
                'request_method' => $method,
                'request_data' => is_array($sanitized_request) || is_object($sanitized_request) ? json_encode($sanitized_request, JSON_UNESCAPED_UNICODE) : $sanitized_request,
                'response_data' => is_array($sanitized_response) || is_object($sanitized_response) ? json_encode($sanitized_response, JSON_UNESCAPED_UNICODE) : $sanitized_response,
                'status_code' => $status_code,
                'success' => $success ? 1 : 0
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );

        if ($result) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get logs with pagination
     *
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array Logs data
     */
    public function get_logs($page = 1, $per_page = 20)
    {
        global $wpdb;

        $offset = ($page - 1) * $per_page;

        // Get logs
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $this->table_name ORDER BY timestamp DESC LIMIT %d, %d",
                $offset,
                $per_page
            ),
            ARRAY_A
        );

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");

        return array(
            'logs' => $logs,
            'total' => (int) $total,
            'pages' => ceil($total / $per_page)
        );
    }

    /**
     * Get a single log by ID
     *
     * @param int $log_id Log ID
     * @return array|null Log data or null if not found
     */
    public function get_log($log_id)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $this->table_name WHERE id = %d",
                $log_id
            ),
            ARRAY_A
        );
    }

    /**
     * Clear all logs
     *
     * @return int|false Number of rows affected or false on error
     */
    public function clear_logs()
    {
        global $wpdb;

        return $wpdb->query("TRUNCATE TABLE $this->table_name");
    }

    /**
     * Sanitize data to remove sensitive information
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private function sanitize_data($data)
    {
        if (is_string($data)) {
            // Try to decode JSON string
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        if (is_array($data)) {
            // Remove sensitive fields
            $sensitive_fields = array('password', 'access_token', 'Authorization', 'authorization');
            
            foreach ($data as $key => $value) {
                if (in_array($key, $sensitive_fields, true)) {
                    $data[$key] = '***REDACTED***';
                } elseif (is_array($value)) {
                    $data[$key] = $this->sanitize_data($value);
                }
            }

            // Check for headers specifically
            if (isset($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $header => $value) {
                    if (in_array($header, $sensitive_fields, true)) {
                        $data['headers'][$header] = '***REDACTED***';
                    }
                }
            }
        }

        return $data;
    }
}
