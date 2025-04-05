<?php

/**
 * B2C Registration handling class for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling B2C registration functionality
 */
class WC_Optima_B2C_Registration extends WC_Optima_Registration
{
    /**
     * Constructor
     *
     * @param array $options Plugin options
     * @param WC_Optima_GUS_API $gus_api GUS API instance
     */
    public function __construct($options, $gus_api)
    {
        parent::__construct($options, $gus_api);

        // Register shortcode
        add_shortcode('optima_b2c_registration', array($this, 'registration_shortcode'));
    }

    /**
     * Registration shortcode callback
     *
     * @param array $atts Shortcode attributes
     * @return string HTML form
     */
    public function registration_shortcode($atts)
    {
        // If user is already logged in, show message
        if (is_user_logged_in()) {
            return '<p>' . __('Jesteś już zalogowany.', 'optima-woocommerce') . '</p>';
        }

        // Enqueue scripts and styles
        wp_enqueue_script('wc-optima-registration-validation');
        wp_enqueue_style('wc-optima-registration-styles');

        // Localize script with validation messages
        wp_localize_script('wc-optima-registration-validation', 'wc_optima_validation', array(
            'required' => __('To pole jest wymagane.', 'optima-woocommerce'),
            'email' => __('Proszę podać prawidłowy adres e-mail.', 'optima-woocommerce'),
            'password_strength' => __('Hasło musi mieć co najmniej 8 znaków i zawierać co najmniej jedną dużą literę, jedną małą literę i jedną cyfrę.', 'optima-woocommerce'),
            'password_match' => __('Hasła muszą być identyczne.', 'optima-woocommerce')
        ));

        // Process form submission
        $result = array();
        if (isset($_POST['wc_optima_b2c_register']) && wp_verify_nonce($_POST['wc_optima_b2c_register_nonce'], 'wc_optima_b2c_register')) {
            $result = $this->process_registration($_POST);
        }

        // Get form HTML
        ob_start();
        $this->display_registration_form($result);
        return ob_get_clean();
    }

    /**
     * Process registration form
     *
     * @param array $form_data Form data
     * @return array|WP_Error Result array on success, WP_Error on failure
     */
    public function process_registration($form_data)
    {
        // Validate required fields
        $required_fields = array(
            'first_name' => __('Imię', 'optima-woocommerce'),
            'last_name' => __('Nazwisko', 'optima-woocommerce'),
            'email' => __('Adres e-mail', 'optima-woocommerce'),
            'password' => __('Hasło', 'optima-woocommerce'),
            'password_confirm' => __('Potwierdzenie hasła', 'optima-woocommerce')
        );

        foreach ($required_fields as $field => $label) {
            if (empty($form_data[$field])) {
                return new WP_Error('registration-error-missing-field', sprintf(__('Pole %s jest wymagane.', 'optima-woocommerce'), $label));
            }
        }

        // Validate email
        if (!$this->validate_email($form_data['email'])) {
            return new WP_Error('registration-error-invalid-email', __('Proszę podać prawidłowy adres e-mail.', 'optima-woocommerce'));
        }

        // Validate password strength
        if (!$this->validate_password_strength($form_data['password'])) {
            return new WP_Error('registration-error-password-strength', __('Hasło musi mieć co najmniej 8 znaków i zawierać co najmniej jedną dużą literę, jedną małą literę i jedną cyfrę.', 'optima-woocommerce'));
        }

        // Check if passwords match
        if ($form_data['password'] !== $form_data['password_confirm']) {
            return new WP_Error('registration-error-password-mismatch', __('Hasła muszą być identyczne.', 'optima-woocommerce'));
        }

        // Prepare user data
        $user_data = array(
            'first_name' => sanitize_text_field($form_data['first_name']),
            'last_name' => sanitize_text_field($form_data['last_name']),
            'email' => sanitize_email($form_data['email']),
            'password' => $form_data['password'],
            'customer_type' => 'b2c'
        );

        // Add optional fields if provided
        if (!empty($form_data['phone'])) {
            $user_data['phone'] = sanitize_text_field($form_data['phone']);
        }

        if (!empty($form_data['address'])) {
            $user_data['address'] = sanitize_text_field($form_data['address']);
        }

        if (!empty($form_data['city'])) {
            $user_data['city'] = sanitize_text_field($form_data['city']);
        }

        if (!empty($form_data['postcode'])) {
            $user_data['postcode'] = sanitize_text_field($form_data['postcode']);
        }

        if (!empty($form_data['country'])) {
            $user_data['country'] = sanitize_text_field($form_data['country']);
        } else {
            $user_data['country'] = 'PL'; // Default to Poland
        }

        // Create user
        $user_id = $this->create_user($user_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Log the user in
        wp_set_auth_cookie($user_id, true);

        // Return success
        return array(
            'success' => true,
            'user_id' => $user_id,
            'redirect' => wc_get_page_permalink('myaccount')
        );
    }

    /**
     * Display registration form
     *
     * @param array|WP_Error $result Result from form processing
     */
    public function display_registration_form($result = array())
    {
        // Display error message if any
        if (is_wp_error($result)) {
            echo '<div class="woocommerce-error">' . $result->get_error_message() . '</div>';
        }

        // Display success message if registration was successful
        if (isset($result['success']) && $result['success']) {
            echo '<div class="woocommerce-message">' . __('Rejestracja zakończona pomyślnie. Przekierowywanie...', 'optima-woocommerce') . '</div>';
            echo '<script>window.location.href = "' . esc_url($result['redirect']) . '";</script>';
            return;
        }
        ?>
        <div class="wc-optima-registration-form b2c-registration-form">
            <h2><?php _e('Rejestracja konta indywidualnego (B2C)', 'optima-woocommerce'); ?></h2>
            
            <form method="post" id="wc-optima-b2c-registration-form">
                <?php wp_nonce_field('wc_optima_b2c_register', 'wc_optima_b2c_register_nonce'); ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name"><?php _e('Imię', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="first_name" id="first_name" class="input-text" value="<?php echo isset($_POST['first_name']) ? esc_attr($_POST['first_name']) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name"><?php _e('Nazwisko', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="last_name" id="last_name" class="input-text" value="<?php echo isset($_POST['last_name']) ? esc_attr($_POST['last_name']) : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email"><?php _e('Adres e-mail', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="email" name="email" id="email" class="input-text" value="<?php echo isset($_POST['email']) ? esc_attr($_POST['email']) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone"><?php _e('Telefon', 'optima-woocommerce'); ?></label>
                        <input type="tel" name="phone" id="phone" class="input-text" value="<?php echo isset($_POST['phone']) ? esc_attr($_POST['phone']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="address"><?php _e('Adres', 'optima-woocommerce'); ?></label>
                        <input type="text" name="address" id="address" class="input-text" value="<?php echo isset($_POST['address']) ? esc_attr($_POST['address']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="postcode"><?php _e('Kod pocztowy', 'optima-woocommerce'); ?></label>
                        <input type="text" name="postcode" id="postcode" class="input-text" value="<?php echo isset($_POST['postcode']) ? esc_attr($_POST['postcode']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="city"><?php _e('Miasto', 'optima-woocommerce'); ?></label>
                        <input type="text" name="city" id="city" class="input-text" value="<?php echo isset($_POST['city']) ? esc_attr($_POST['city']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><?php _e('Hasło', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="password" name="password" id="password" class="input-text" required>
                        <small class="password-hint"><?php _e('Hasło musi mieć co najmniej 8 znaków i zawierać co najmniej jedną dużą literę, jedną małą literę i jedną cyfrę.', 'optima-woocommerce'); ?></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm"><?php _e('Potwierdzenie hasła', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="password" name="password_confirm" id="password_confirm" class="input-text" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" name="terms" id="terms" required>
                            <?php _e('Zapoznałem się i akceptuję <a href="/regulamin/" target="_blank">regulamin</a> oraz <a href="/polityka-prywatnosci/" target="_blank">politykę prywatności</a>.', 'optima-woocommerce'); ?> <span class="required">*</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <button type="submit" name="wc_optima_b2c_register" class="button" value="register"><?php _e('Zarejestruj się', 'optima-woocommerce'); ?></button>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <p><?php _e('Masz już konto?', 'optima-woocommerce'); ?> <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php _e('Zaloguj się', 'optima-woocommerce'); ?></a></p>
                        <p><?php _e('Jesteś firmą?', 'optima-woocommerce'); ?> <a href="<?php echo esc_url(add_query_arg('type', 'b2b', remove_query_arg('type'))); ?>"><?php _e('Zarejestruj konto firmowe', 'optima-woocommerce'); ?></a></p>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}
