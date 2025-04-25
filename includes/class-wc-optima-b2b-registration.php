<?php

/**
 * B2B Registration handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling B2B registration functionality
 */
class WC_Optima_B2B_Registration extends WC_Optima_Registration
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
        add_shortcode('optima_b2b_registration', array($this, 'registration_shortcode'));
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
            'password_match' => __('Hasła muszą być identyczne.', 'optima-woocommerce'),
            'nip_format' => __('Proszę podać prawidłowy numer NIP (10 cyfr).', 'optima-woocommerce'),
            'regon_format' => __('Proszę podać prawidłowy numer REGON (9 lub 14 cyfr).', 'optima-woocommerce'),
            'verify_company' => __('Weryfikacja danych firmy...', 'optima-woocommerce'),
            'company_verified' => __('Dane firmy zweryfikowane pomyślnie.', 'optima-woocommerce'),
            'company_verification_failed' => __('Nie udało się zweryfikować danych firmy. Proszę sprawdzić poprawność numeru NIP/REGON lub wprowadzić dane ręcznie.', 'optima-woocommerce'),
            'verified_readonly_info' => __('Dane zostały zweryfikowane i są tylko do odczytu.', 'optima-woocommerce'),
            'unlock_fields_button' => __('Odblokuj pola', 'optima-woocommerce'),
            'fields_unlocked_warning' => __('Pola zostały odblokowane. Możesz teraz edytować dane, ale nie będą one już zweryfikowane.', 'optima-woocommerce'),
            'debug_info_title' => __('Informacje debugowania:', 'optima-woocommerce'),
            'json_parse_error' => __('Błąd parsowania odpowiedzi JSON:', 'optima-woocommerce')
        ));

        // Add AJAX URL for company verification
        wp_localize_script('wc-optima-registration-validation', 'wc_optima_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_optima_verify_company')
        ));

        // Process form submission
        $result = array();
        if (isset($_POST['wc_optima_b2b_register']) && wp_verify_nonce($_POST['wc_optima_b2b_register_nonce'], 'wc_optima_b2b_register')) {
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
            'company_name' => __('Nazwa firmy', 'optima-woocommerce'),
            'nip' => __('NIP', 'optima-woocommerce'),
            'first_name' => __('Imię', 'optima-woocommerce'),
            'last_name' => __('Nazwisko', 'optima-woocommerce'),
            'email' => __('Adres e-mail', 'optima-woocommerce'),
            'phone' => __('Telefon', 'optima-woocommerce'),
            'address' => __('Adres', 'optima-woocommerce'),
            'postcode' => __('Kod pocztowy', 'optima-woocommerce'),
            'city' => __('Miasto', 'optima-woocommerce'),
            'password' => __('Hasło', 'optima-woocommerce'),
            'password_confirm' => __('Potwierdzenie hasła', 'optima-woocommerce'),
            'consent_data' => __('Zgoda na przetwarzanie danych osobowych', 'optima-woocommerce'),
            'consent_invoice' => __('Zgoda na przesyłanie faktur drogą elektroniczną', 'optima-woocommerce')
        );

        foreach ($required_fields as $field => $label) {
            if (empty($form_data[$field])) {
                return new WP_Error('registration-error-missing-field', sprintf(__('Pole %s jest wymagane.', 'optima-woocommerce'), $label));
            }
        }

        // Validate NIP
        $nip = preg_replace('/[^0-9]/', '', $form_data['nip']);
        if (!$this->gus_api->validate_nip($nip)) {
            return new WP_Error('registration-error-invalid-nip', __('Proszę podać prawidłowy numer NIP (10 cyfr).', 'optima-woocommerce'));
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
            'customer_type' => 'b2b',
            'phone' => sanitize_text_field($form_data['phone']),
            'address' => sanitize_text_field($form_data['address']),
            'city' => sanitize_text_field($form_data['city']),
            'postcode' => sanitize_text_field($form_data['postcode']),
            'country' => !empty($form_data['country']) ? sanitize_text_field($form_data['country']) : 'PL'
        );

        // Create user
        $user_id = $this->create_user($user_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Save company data
        update_user_meta($user_id, '_optima_company_name', sanitize_text_field($form_data['company_name']));
        update_user_meta($user_id, '_optima_nip', $nip);

        if (!empty($form_data['regon'])) {
            update_user_meta($user_id, '_optima_regon', sanitize_text_field($form_data['regon']));
        }

        if (!empty($form_data['krs'])) {
            update_user_meta($user_id, '_optima_krs', sanitize_text_field($form_data['krs']));
        }

        // Set billing company
        update_user_meta($user_id, 'billing_company', sanitize_text_field($form_data['company_name']));

        // Save consent data
        update_user_meta($user_id, '_optima_consent_data', 'yes');
        update_user_meta($user_id, '_optima_consent_invoice', 'yes');

        // Save marketing consent if provided
        if (!empty($form_data['consent_marketing'])) {
            update_user_meta($user_id, '_optima_consent_marketing', 'yes');
        } else {
            update_user_meta($user_id, '_optima_consent_marketing', 'no');
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
        <div class="wc-optima-registration-form b2b-registration-form">
            <h2><?php _e('Rejestracja konta firmowego (B2B)', 'optima-woocommerce'); ?></h2>

            <form method="post" id="wc-optima-b2b-registration-form">
                <?php wp_nonce_field('wc_optima_b2b_register', 'wc_optima_b2b_register_nonce'); ?>

                <h3><?php _e('Dane firmy', 'optima-woocommerce'); ?></h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nip"><?php _e('NIP', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="nip" id="nip" class="input-text" value="<?php echo isset($_POST['nip']) ? esc_attr($_POST['nip']) : ''; ?>" required>
                        <button type="button" id="verify-company" class="button"><?php _e('Weryfikuj dane firmy', 'optima-woocommerce'); ?></button>
                        <div id="company-verification-status"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="company_name"><?php _e('Nazwa firmy', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="company_name" id="company_name" class="input-text" value="<?php echo isset($_POST['company_name']) ? esc_attr($_POST['company_name']) : ''; ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="regon"><?php _e('REGON', 'optima-woocommerce'); ?></label>
                        <input type="text" name="regon" id="regon" class="input-text" value="<?php echo isset($_POST['regon']) ? esc_attr($_POST['regon']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="krs"><?php _e('KRS', 'optima-woocommerce'); ?></label>
                        <input type="text" name="krs" id="krs" class="input-text" value="<?php echo isset($_POST['krs']) ? esc_attr($_POST['krs']) : ''; ?>">
                    </div>
                </div>

                <h3><?php _e('Dane kontaktowe', 'optima-woocommerce'); ?></h3>

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
                        <label for="phone"><?php _e('Telefon', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="tel" name="phone" id="phone" class="input-text" value="<?php echo isset($_POST['phone']) ? esc_attr($_POST['phone']) : ''; ?>" required>
                    </div>
                </div>

                <h3><?php _e('Adres siedziby', 'optima-woocommerce'); ?></h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="address"><?php _e('Adres', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="address" id="address" class="input-text" value="<?php echo isset($_POST['address']) ? esc_attr($_POST['address']) : ''; ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="postcode"><?php _e('Kod pocztowy', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="postcode" id="postcode" class="input-text" value="<?php echo isset($_POST['postcode']) ? esc_attr($_POST['postcode']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="city"><?php _e('Miasto', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" name="city" id="city" class="input-text" value="<?php echo isset($_POST['city']) ? esc_attr($_POST['city']) : ''; ?>" required>
                    </div>
                </div>

                <h3><?php _e('Dane logowania', 'optima-woocommerce'); ?></h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><?php _e('Hasło', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <div class="password-field-wrapper">
                            <input type="password" name="password" id="password" class="input-text" required>
                            <span class="password-toggle-icon" title="<?php _e('Pokaż/Ukryj hasło', 'optima-woocommerce'); ?>"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm"><?php _e('Potwierdzenie hasła', 'optima-woocommerce'); ?> <span class="required">*</span></label>
                        <div class="password-field-wrapper">
                            <input type="password" name="password_confirm" id="password_confirm" class="input-text" required>
                            <span class="password-toggle-icon" title="<?php _e('Pokaż/Ukryj hasło', 'optima-woocommerce'); ?>"></span>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group rules">
                        <label class="checkbox">
                            <input type="checkbox" name="terms" id="terms" required>
                            <?php _e('Zapoznałem się i akceptuję <a href="/regulamin/" target="_blank">regulamin</a> oraz <a href="/polityka-prywatnosci/" target="_blank">politykę prywatności</a>.', 'optima-woocommerce'); ?> <span class="required">*</span>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group rules">
                        <label class="checkbox">
                            <input type="checkbox" name="consent_data" id="consent_data" required>
                            <?php _e('Wyrażam zgodę na przetwarzanie moich danych osobowych w celu założenia i prowadzenia mojego konta użytkownika. Podstawą prawną jest art. 6 ust. 1 lit. b RODO. Zapoznałem/zapoznałam się z <a href="/polityka-prywatnosci/" target="_blank">Polityką prywatności</a>.', 'optima-woocommerce'); ?> <span class="required">*</span>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group rules">
                        <label class="checkbox">
                            <input type="checkbox" name="consent_marketing" id="consent_marketing">
                            <?php _e('Wyrażam dobrowolną zgodę na otrzymywanie informacji handlowych (newsletter, oferty specjalne, nowości produktowe) drogą elektroniczną (e-mail). Zgodę można w każdej chwili wycofać bez podawania przyczyny, kontaktując się mailowo.', 'optima-woocommerce'); ?>
                        </label>
                    </div>
                </div>

                <div class="form-row rules">
                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" name="consent_invoice" id="consent_invoice" required>
                            <?php _e('Wyrażam zgodę na przesyłanie faktur VAT drogą elektroniczną na podany przeze mnie adres e-mail, w formacie PDF. Jestem świadomy/świadoma, że faktury elektroniczne mają taką samą moc prawną jak wersje papierowe.', 'optima-woocommerce'); ?> <span class="required">*</span>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <button type="submit" name="wc_optima_b2b_register" class="button" value="register"><?php _e('Zarejestruj się', 'optima-woocommerce'); ?></button>
                    </div>
                </div>
            </form>
        </div>
<?php
    }
}
