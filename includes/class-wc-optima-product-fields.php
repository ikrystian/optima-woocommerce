<?php

/**
 * Product fields handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling product fields and mapping for Optima integration
 */
class WC_Optima_Product_Fields
{
    /**
     * API instance
     *
     * @var WC_Optima_API
     */
    private $api;

    /**
     * Constructor
     *
     * @param WC_Optima_API $api API instance
     */
    public function __construct($api)
    {
        $this->api = $api;

        // Add product data tab
        add_filter('woocommerce_product_data_tabs', [$this, 'add_optima_product_data_tab']);
        
        // Add product data fields
        add_action('woocommerce_product_data_panels', [$this, 'add_optima_product_data_fields']);
        
        // Save product data
        add_action('woocommerce_process_product_meta', [$this, 'save_optima_product_data']);
        
        // Add bulk edit action
        add_filter('bulk_actions-edit-product', [$this, 'register_optima_bulk_actions']);
        
        // Handle bulk edit action
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_optima_bulk_actions'], 10, 3);
        
        // Add admin notice after bulk action
        add_action('admin_notices', [$this, 'optima_bulk_action_admin_notice']);
    }

    /**
     * Add Optima tab to product data tabs
     *
     * @param array $tabs Product data tabs
     * @return array Modified product data tabs
     */
    public function add_optima_product_data_tab($tabs)
    {
        $tabs['optima'] = [
            'label'    => __('Optima', 'optima-woocommerce'),
            'target'   => 'optima_product_data',
            'class'    => [],
            'priority' => 90
        ];
        return $tabs;
    }

    /**
     * Add Optima product data fields
     */
    public function add_optima_product_data_fields()
    {
        global $post;
        
        // Get the product ID
        $product_id = $post->ID;
        
        // Get existing Optima meta data
        $optima_id = get_post_meta($product_id, '_optima_id', true);
        $optima_code = get_post_meta($product_id, '_optima_code', true);
        $optima_vat_rate = get_post_meta($product_id, '_optima_vat_rate', true);
        $optima_unit = get_post_meta($product_id, '_optima_unit', true);
        $optima_pkwiu = get_post_meta($product_id, '_optima_pkwiu', true);
        $optima_warehouse_id = get_post_meta($product_id, '_optima_warehouse_id', true);
        
        // Start the Optima data panel
        ?>
        <div id="optima_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="optima_id"><?php _e('ID Optima', 'optima-woocommerce'); ?></label>
                    <input type="text" id="optima_id" name="optima_id" value="<?php echo esc_attr($optima_id); ?>" />
                    <span class="description"><?php _e('ID produktu w systemie Optima', 'optima-woocommerce'); ?></span>
                </p>
                
                <p class="form-field">
                    <label for="optima_code"><?php _e('Kod Optima', 'optima-woocommerce'); ?></label>
                    <input type="text" id="optima_code" name="optima_code" value="<?php echo esc_attr($optima_code); ?>" />
                    <span class="description"><?php _e('Kod produktu w systemie Optima', 'optima-woocommerce'); ?></span>
                </p>
                
                <p class="form-field">
                    <label for="optima_vat_rate"><?php _e('Stawka VAT (%)', 'optima-woocommerce'); ?></label>
                    <input type="number" id="optima_vat_rate" name="optima_vat_rate" value="<?php echo esc_attr($optima_vat_rate); ?>" step="0.01" min="0" max="100" />
                    <span class="description"><?php _e('Stawka VAT produktu w systemie Optima (np. 23)', 'optima-woocommerce'); ?></span>
                </p>
                
                <p class="form-field">
                    <label for="optima_unit"><?php _e('Jednostka miary', 'optima-woocommerce'); ?></label>
                    <select id="optima_unit" name="optima_unit">
                        <option value="" <?php selected($optima_unit, ''); ?>><?php _e('-- Wybierz --', 'optima-woocommerce'); ?></option>
                        <option value="szt" <?php selected($optima_unit, 'szt'); ?>><?php _e('szt', 'optima-woocommerce'); ?></option>
                        <option value="kg" <?php selected($optima_unit, 'kg'); ?>><?php _e('kg', 'optima-woocommerce'); ?></option>
                        <option value="m" <?php selected($optima_unit, 'm'); ?>><?php _e('m', 'optima-woocommerce'); ?></option>
                        <option value="m2" <?php selected($optima_unit, 'm2'); ?>><?php _e('m²', 'optima-woocommerce'); ?></option>
                        <option value="m3" <?php selected($optima_unit, 'm3'); ?>><?php _e('m³', 'optima-woocommerce'); ?></option>
                        <option value="l" <?php selected($optima_unit, 'l'); ?>><?php _e('l', 'optima-woocommerce'); ?></option>
                        <option value="opak" <?php selected($optima_unit, 'opak'); ?>><?php _e('opak', 'optima-woocommerce'); ?></option>
                        <option value="kpl" <?php selected($optima_unit, 'kpl'); ?>><?php _e('kpl', 'optima-woocommerce'); ?></option>
                    </select>
                    <span class="description"><?php _e('Jednostka miary produktu w systemie Optima', 'optima-woocommerce'); ?></span>
                </p>
                
                <p class="form-field">
                    <label for="optima_pkwiu"><?php _e('PKWiU', 'optima-woocommerce'); ?></label>
                    <input type="text" id="optima_pkwiu" name="optima_pkwiu" value="<?php echo esc_attr($optima_pkwiu); ?>" />
                    <span class="description"><?php _e('Kod PKWiU produktu', 'optima-woocommerce'); ?></span>
                </p>
                
                <p class="form-field">
                    <label for="optima_warehouse_id"><?php _e('ID Magazynu', 'optima-woocommerce'); ?></label>
                    <input type="number" id="optima_warehouse_id" name="optima_warehouse_id" value="<?php echo esc_attr($optima_warehouse_id); ?>" min="1" />
                    <span class="description"><?php _e('ID magazynu w systemie Optima (domyślnie 1)', 'optima-woocommerce'); ?></span>
                </p>
                
                <div class="optima-product-search">
                    <h4><?php _e('Wyszukaj produkt w Optima', 'optima-woocommerce'); ?></h4>
                    <p>
                        <input type="text" id="optima_product_search" placeholder="<?php _e('Wpisz nazwę lub kod produktu...', 'optima-woocommerce'); ?>" />
                        <button type="button" class="button" id="optima_search_button"><?php _e('Szukaj', 'optima-woocommerce'); ?></button>
                    </p>
                    <div id="optima_search_results" style="display: none;">
                        <select id="optima_product_select" size="5" style="width: 100%;">
                        </select>
                        <button type="button" class="button" id="optima_select_product"><?php _e('Wybierz produkt', 'optima-woocommerce'); ?></button>
                    </div>
                    <div id="optima_search_message"></div>
                </div>
            </div>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Search button click handler
                    $('#optima_search_button').on('click', function() {
                        var searchTerm = $('#optima_product_search').val();
                        if (searchTerm.length < 3) {
                            $('#optima_search_message').html('<p style="color: red;"><?php _e('Wpisz co najmniej 3 znaki', 'optima-woocommerce'); ?></p>');
                            return;
                        }
                        
                        $('#optima_search_message').html('<p><?php _e('Wyszukiwanie...', 'optima-woocommerce'); ?></p>');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'optima_search_products',
                                search_term: searchTerm,
                                nonce: '<?php echo wp_create_nonce('optima_search_products'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#optima_product_select').empty();
                                    
                                    if (response.data.length > 0) {
                                        $.each(response.data, function(i, product) {
                                            $('#optima_product_select').append(
                                                $('<option></option>')
                                                    .attr('value', product.id)
                                                    .data('product', product)
                                                    .text(product.name + ' (' + product.code + ')')
                                            );
                                        });
                                        
                                        $('#optima_search_results').show();
                                        $('#optima_search_message').html('<p style="color: green;"><?php _e('Znaleziono produkty. Wybierz z listy.', 'optima-woocommerce'); ?></p>');
                                    } else {
                                        $('#optima_search_results').hide();
                                        $('#optima_search_message').html('<p style="color: orange;"><?php _e('Nie znaleziono produktów.', 'optima-woocommerce'); ?></p>');
                                    }
                                } else {
                                    $('#optima_search_results').hide();
                                    $('#optima_search_message').html('<p style="color: red;">' + response.data + '</p>');
                                }
                            },
                            error: function() {
                                $('#optima_search_message').html('<p style="color: red;"><?php _e('Błąd podczas wyszukiwania.', 'optima-woocommerce'); ?></p>');
                            }
                        });
                    });
                    
                    // Select product button click handler
                    $('#optima_select_product').on('click', function() {
                        var selectedOption = $('#optima_product_select option:selected');
                        if (selectedOption.length) {
                            var product = selectedOption.data('product');
                            
                            // Fill in the form fields
                            $('#optima_id').val(product.id);
                            $('#optima_code').val(product.code);
                            $('#optima_vat_rate').val(product.vatRate);
                            $('#optima_unit').val(product.unit);
                            $('#optima_pkwiu').val(product.pkwiu);
                            
                            $('#optima_search_message').html('<p style="color: green;"><?php _e('Produkt wybrany.', 'optima-woocommerce'); ?></p>');
                        } else {
                            $('#optima_search_message').html('<p style="color: red;"><?php _e('Nie wybrano produktu.', 'optima-woocommerce'); ?></p>');
                        }
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Save Optima product data
     *
     * @param int $product_id Product ID
     */
    public function save_optima_product_data($product_id)
    {
        // Save Optima ID
        if (isset($_POST['optima_id'])) {
            update_post_meta($product_id, '_optima_id', sanitize_text_field($_POST['optima_id']));
        }
        
        // Save Optima code
        if (isset($_POST['optima_code'])) {
            update_post_meta($product_id, '_optima_code', sanitize_text_field($_POST['optima_code']));
        }
        
        // Save Optima VAT rate
        if (isset($_POST['optima_vat_rate'])) {
            update_post_meta($product_id, '_optima_vat_rate', sanitize_text_field($_POST['optima_vat_rate']));
        }
        
        // Save Optima unit
        if (isset($_POST['optima_unit'])) {
            update_post_meta($product_id, '_optima_unit', sanitize_text_field($_POST['optima_unit']));
        }
        
        // Save Optima PKWiU
        if (isset($_POST['optima_pkwiu'])) {
            update_post_meta($product_id, '_optima_pkwiu', sanitize_text_field($_POST['optima_pkwiu']));
        }
        
        // Save Optima warehouse ID
        if (isset($_POST['optima_warehouse_id'])) {
            update_post_meta($product_id, '_optima_warehouse_id', absint($_POST['optima_warehouse_id']));
        }
    }

    /**
     * Register bulk actions for Optima
     *
     * @param array $bulk_actions Existing bulk actions
     * @return array Modified bulk actions
     */
    public function register_optima_bulk_actions($bulk_actions)
    {
        $bulk_actions['optima_sync_products'] = __('Synchronizuj z Optima', 'optima-woocommerce');
        return $bulk_actions;
    }

    /**
     * Handle bulk actions for Optima
     *
     * @param string $redirect_to Redirect URL
     * @param string $action Action name
     * @param array $post_ids Selected post IDs
     * @return string Modified redirect URL
     */
    public function handle_optima_bulk_actions($redirect_to, $action, $post_ids)
    {
        if ($action !== 'optima_sync_products') {
            return $redirect_to;
        }

        // Get Optima products
        $optima_products = $this->api->get_optima_products();
        
        if (!$optima_products) {
            // Add error parameter to URL
            $redirect_to = add_query_arg(
                'optima_bulk_action_error',
                __('Nie udało się pobrać produktów z Optima.', 'optima-woocommerce'),
                $redirect_to
            );
            return $redirect_to;
        }
        
        // Create a lookup array for faster access
        $optima_products_lookup = [];
        foreach ($optima_products as $product) {
            if (isset($product['id'])) {
                $optima_products_lookup[$product['id']] = $product;
            }
        }
        
        // Count successful updates
        $updated_count = 0;
        
        // Process each selected product
        foreach ($post_ids as $post_id) {
            $product = wc_get_product($post_id);
            if (!$product) {
                continue;
            }
            
            // Get current Optima ID
            $optima_id = get_post_meta($post_id, '_optima_id', true);
            
            if (empty($optima_id)) {
                // No Optima ID, can't sync
                continue;
            }
            
            // Check if this Optima ID exists in the lookup
            if (isset($optima_products_lookup[$optima_id])) {
                $optima_product = $optima_products_lookup[$optima_id];
                
                // Update product meta data
                update_post_meta($post_id, '_optima_code', isset($optima_product['code']) ? $optima_product['code'] : '');
                update_post_meta($post_id, '_optima_vat_rate', isset($optima_product['vatRate']) ? $optima_product['vatRate'] : '');
                update_post_meta($post_id, '_optima_unit', isset($optima_product['unit']) ? $optima_product['unit'] : '');
                update_post_meta($post_id, '_optima_pkwiu', isset($optima_product['pkwiu']) ? $optima_product['pkwiu'] : '');
                
                // Update SKU if it's not already set
                if (empty($product->get_sku())) {
                    $product->set_sku($optima_id);
                    $product->save();
                }
                
                $updated_count++;
            }
        }
        
        // Add success parameter to URL
        $redirect_to = add_query_arg(
            'optima_bulk_action_success',
            $updated_count,
            $redirect_to
        );
        
        return $redirect_to;
    }

    /**
     * Display admin notice after bulk action
     */
    public function optima_bulk_action_admin_notice()
    {
        if (!empty($_REQUEST['optima_bulk_action_error'])) {
            $error_message = sanitize_text_field($_REQUEST['optima_bulk_action_error']);
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
        }
        
        if (!empty($_REQUEST['optima_bulk_action_success'])) {
            $updated_count = intval($_REQUEST['optima_bulk_action_success']);
            $message = sprintf(
                _n(
                    'Zaktualizowano %d produkt z danymi z Optima.',
                    'Zaktualizowano %d produktów z danymi z Optima.',
                    $updated_count,
                    'optima-woocommerce'
                ),
                $updated_count
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
}
