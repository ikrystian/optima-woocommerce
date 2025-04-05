<?php

/**
 * Registration handling class for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for handling registration functionality
 */
class WC_Optima_Registration
{
    /**
     * Plugin options
     *
     * @var array
     */
    protected $options;

    /**
     * GUS API instance
     *
     * @var WC_Optima_GUS_API
     */
    protected $gus_api;

    /**
     * Constructor
     *
     * @param array $options Plugin options
     * @param WC_Optima_GUS_API $gus_api GUS API instance
     */
    public function __construct($options, $gus_api)
    {
        $this->options = $options;
        $this->gus_api = $gus_api;

        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'register_scripts'));
    }

    /**
     * Register scripts and styles
     */
    public function register_scripts()
    {
        // Register validation script
        wp_register_script(
            'wc-optima-registration-validation',
            plugins_url('assets/js/registration-validation.js', OPTIMA_WC_PLUGIN_FILE),
            array('jquery'),
            '1.0.0',
            true
        );

        // Register styles
        wp_register_style(
            'wc-optima-registration-styles',
            plugins_url('assets/css/registration-styles.css', OPTIMA_WC_PLUGIN_FILE),
            array(),
            '1.0.0'
        );
    }

    /**
     * Validate email
     *
     * @param string $email Email to validate
     * @return bool True if email is valid, false otherwise
     */
    protected function validate_email($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @return bool True if password is strong enough, false otherwise
     */
    protected function validate_password_strength($password)
    {
        // Password must be at least 8 characters long
        if (strlen($password) < 8) {
            return false;
        }

        // Password must contain at least one uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }

        // Password must contain at least one lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }

        // Password must contain at least one number
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }

        return true;
    }

    /**
     * Create user account
     *
     * @param array $user_data User data
     * @return int|WP_Error User ID on success, WP_Error on failure
     */
    protected function create_user($user_data)
    {
        // Check if email already exists
        if (email_exists($user_data['email'])) {
            return new WP_Error('registration-error-email-exists', __('Konto z tym adresem e-mail już istnieje.', 'optima-woocommerce'));
        }

        // Create user
        $user_id = wp_create_user(
            $user_data['email'],
            $user_data['password'],
            $user_data['email']
        );

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Update user meta
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $user_data['first_name'],
            'last_name' => $user_data['last_name'],
            'display_name' => $user_data['first_name'] . ' ' . $user_data['last_name']
        ));

        // Set user role
        $user = new WP_User($user_id);
        $user->set_role('customer');

        // Save additional user meta
        if (isset($user_data['phone'])) {
            update_user_meta($user_id, 'billing_phone', $user_data['phone']);
        }

        if (isset($user_data['address'])) {
            update_user_meta($user_id, 'billing_address_1', $user_data['address']);
        }

        if (isset($user_data['city'])) {
            update_user_meta($user_id, 'billing_city', $user_data['city']);
        }

        if (isset($user_data['postcode'])) {
            update_user_meta($user_id, 'billing_postcode', $user_data['postcode']);
        }

        if (isset($user_data['country'])) {
            update_user_meta($user_id, 'billing_country', $user_data['country']);
        }

        // Set customer type
        update_user_meta($user_id, '_optima_customer_type', $user_data['customer_type']);

        return $user_id;
    }

    /**
     * Process registration form
     *
     * @param array $form_data Form data
     * @return array|WP_Error Result array on success, WP_Error on failure
     */
    public function process_registration($form_data)
    {
        // This method should be implemented by child classes
        return new WP_Error('not-implemented', __('Ta metoda powinna być zaimplementowana przez klasy potomne.', 'optima-woocommerce'));
    }

    /**
     * Display registration form
     *
     * @return string HTML form
     */
    public function get_registration_form()
    {
        // This method should be implemented by child classes
        return __('Ta metoda powinna być zaimplementowana przez klasy potomne.', 'optima-woocommerce');
    }
}
