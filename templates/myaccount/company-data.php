<?php
/**
 * Company data template for My Account page
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Get user data
$user_id = get_current_user_id();
$customer_type = get_user_meta($user_id, '_optima_customer_type', true);

// Only show for B2B customers
if ($customer_type !== 'b2b') {
    return;
}

$company_name = get_user_meta($user_id, '_optima_company_name', true);
$nip = get_user_meta($user_id, '_optima_nip', true);
$regon = get_user_meta($user_id, '_optima_regon', true);
$last_update = get_user_meta($user_id, '_optima_company_data_last_update', true);
?>

<div class="woocommerce-company-data">
    <h3><?php _e('Dane firmy', 'optima-woocommerce'); ?></h3>
    
    <table class="woocommerce-company-data-table">
        <tr>
            <th><?php _e('Nazwa firmy', 'optima-woocommerce'); ?></th>
            <td><?php echo esc_html($company_name); ?></td>
        </tr>
        <tr>
            <th><?php _e('NIP', 'optima-woocommerce'); ?></th>
            <td><?php echo esc_html($nip); ?></td>
        </tr>
        <?php if (!empty($regon)) : ?>
        <tr>
            <th><?php _e('REGON', 'optima-woocommerce'); ?></th>
            <td><?php echo esc_html($regon); ?></td>
        </tr>
        <?php endif; ?>
        <?php if (!empty($last_update)) : ?>
        <tr>
            <th><?php _e('Ostatnia aktualizacja', 'optima-woocommerce'); ?></th>
            <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_update)); ?></td>
        </tr>
        <?php endif; ?>
    </table>
    
    <div class="woocommerce-company-data-actions">
        <button type="button" id="update-company-data" class="button"><?php _e('Odśwież dane firmy', 'optima-woocommerce'); ?></button>
        <span id="update-company-data-status"></span>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#update-company-data').on('click', function() {
            var button = $(this);
            var status = $('#update-company-data-status');
            
            button.prop('disabled', true);
            status.html('<span class="updating"><?php _e('Aktualizowanie danych...', 'optima-woocommerce'); ?></span>');
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'wc_optima_update_company_data',
                    user_id: <?php echo $user_id; ?>,
                    nonce: '<?php echo wp_create_nonce('wc_optima_update_company_data'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        status.html('<span class="success"><?php _e('Dane zaktualizowane pomyślnie.', 'optima-woocommerce'); ?></span>');
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        status.html('<span class="error"><?php _e('Błąd aktualizacji danych.', 'optima-woocommerce'); ?></span>');
                        button.prop('disabled', false);
                    }
                },
                error: function() {
                    status.html('<span class="error"><?php _e('Błąd połączenia z serwerem.', 'optima-woocommerce'); ?></span>');
                    button.prop('disabled', false);
                }
            });
        });
    });
    </script>
    
    <style type="text/css">
    .woocommerce-company-data {
        margin-bottom: 30px;
    }
    
    .woocommerce-company-data-table {
        width: 100%;
        margin-bottom: 20px;
        border-collapse: collapse;
    }
    
    .woocommerce-company-data-table th,
    .woocommerce-company-data-table td {
        padding: 10px;
        border-bottom: 1px solid #eee;
    }
    
    .woocommerce-company-data-table th {
        text-align: left;
        width: 30%;
    }
    
    .woocommerce-company-data-actions {
        margin-top: 20px;
    }
    
    #update-company-data-status {
        margin-left: 10px;
        font-size: 0.9em;
    }
    
    #update-company-data-status .updating {
        color: #0073aa;
    }
    
    #update-company-data-status .success {
        color: #46b450;
    }
    
    #update-company-data-status .error {
        color: #e2401c;
    }
    </style>
</div>
