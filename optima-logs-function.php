<?php
/**
 * Function to create the Optima logs table
 * Add this to your theme's functions.php file or a custom plugin
 */

// Create the Optima logs table
function create_optima_logs_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wc_optima_api_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
    return dbDelta($sql);
}

// Check if the table exists and create it if it doesn't
function check_and_create_optima_logs_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_optima_api_logs';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        create_optima_logs_table();
        error_log("Created missing Optima logs table: $table_name");
    }
}

// Run the check on init
add_action('init', 'check_and_create_optima_logs_table');
