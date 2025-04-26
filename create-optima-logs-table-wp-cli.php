<?php
/**
 * WP-CLI script to create the Optima logs table
 * 
 * Usage: wp eval-file create-optima-logs-table-wp-cli.php
 */

// Check if running in WP-CLI
if (!defined('WP_CLI') || !WP_CLI) {
    echo "This script must be run using WP-CLI.\n";
    exit;
}

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
    WP_CLI::success("Table {$GLOBALS['wpdb']->prefix}wc_optima_api_logs already exists.");
} else {
    $result = create_optima_logs_table();
    WP_CLI::success("Table {$GLOBALS['wpdb']->prefix}wc_optima_api_logs has been created.");
    
    // Also try to run the plugin's own table creation function
    if (class_exists('WC_Optima_Logs')) {
        $logs = new WC_Optima_Logs();
        $logs->create_table();
        WP_CLI::log("Also ran the plugin's own table creation function.");
    } else {
        WP_CLI::warning("Could not find WC_Optima_Logs class to run its create_table method.");
    }
}
