<?php
/**
 * Script to create the missing wp_wc_optima_api_logs table
 * 
 * This script should be placed in the root of your WordPress installation
 * and run once to create the missing table.
 */

// Load WordPress environment
require_once('wp-load.php');

// Create the logs table
function create_optima_logs_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wc_optima_api_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
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
    $result = dbDelta($sql);
    
    return $result;
}

// Check if the table already exists
function check_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_optima_api_logs';
    return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
}

// Main execution
if (check_table_exists()) {
    echo "Table {$wpdb->prefix}wc_optima_api_logs already exists.\n";
} else {
    $result = create_optima_logs_table();
    echo "Table {$wpdb->prefix}wc_optima_api_logs has been created.\n";
    echo "Result: ";
    print_r($result);
}

// Also check if the plugin activation function needs to be run
$logs = new WC_Optima_Logs();
$logs->create_table();
echo "Ran the plugin's own table creation function as well.\n";

echo "Done!\n";
