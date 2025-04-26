<?php
/**
 * Plugin Name: Fix Optima Logs Table
 * Description: Creates the missing wp_wc_optima_api_logs table
 * Version: 1.0
 * Author: Support
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Add admin notice
add_action('admin_notices', 'fix_optima_logs_table_notice');

function fix_optima_logs_table_notice() {
    // Check if the table exists
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_optima_api_logs';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if (!$table_exists) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e('Tabela logów Optima API nie istnieje. <a href="?page=fix-optima-logs-table">Kliknij tutaj</a>, aby ją utworzyć.', 'optima-woocommerce'); ?></p>
        </div>
        <?php
    }
}

// Add admin menu
add_action('admin_menu', 'fix_optima_logs_table_menu');

function fix_optima_logs_table_menu() {
    add_submenu_page(
        null, // No parent menu
        'Fix Optima Logs Table',
        'Fix Optima Logs Table',
        'manage_options',
        'fix-optima-logs-table',
        'fix_optima_logs_table_page'
    );
}

// Admin page
function fix_optima_logs_table_page() {
    // Check if the table exists
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_optima_api_logs';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if ($table_exists) {
        echo '<div class="wrap">';
        echo '<h1>Fix Optima Logs Table</h1>';
        echo '<div class="notice notice-success"><p>Tabela ' . $table_name . ' już istnieje.</p></div>';
        echo '<p><a href="' . admin_url('admin.php?page=wc-optima-settings') . '" class="button button-primary">Wróć do ustawień Optima</a></p>';
        echo '</div>';
        return;
    }
    
    // Create the table
    if (isset($_POST['create_table']) && check_admin_referer('fix_optima_logs_table')) {
        // Create the table
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
        
        // Check if the table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        echo '<div class="wrap">';
        echo '<h1>Fix Optima Logs Table</h1>';
        
        if ($table_exists) {
            echo '<div class="notice notice-success"><p>Tabela ' . $table_name . ' została utworzona pomyślnie.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Nie udało się utworzyć tabeli ' . $table_name . '.</p></div>';
            echo '<pre>' . print_r($result, true) . '</pre>';
        }
        
        echo '<p><a href="' . admin_url('admin.php?page=wc-optima-settings') . '" class="button button-primary">Wróć do ustawień Optima</a></p>';
        echo '</div>';
        return;
    }
    
    // Display the form
    ?>
    <div class="wrap">
        <h1>Fix Optima Logs Table</h1>
        <p>Tabela logów Optima API (<?php echo $table_name; ?>) nie istnieje. Kliknij przycisk poniżej, aby ją utworzyć.</p>
        
        <form method="post">
            <?php wp_nonce_field('fix_optima_logs_table'); ?>
            <p>
                <input type="submit" name="create_table" class="button button-primary" value="Utwórz tabelę">
            </p>
        </form>
    </div>
    <?php
}

// Also try to create the table on plugin activation
register_activation_hook(__FILE__, 'fix_optima_logs_table_activate');

function fix_optima_logs_table_activate() {
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
    dbDelta($sql);
}
