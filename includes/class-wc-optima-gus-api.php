<?php

/**
 * GUS API handling class for Optima WooCommerce integration
 *
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling API communication with GUS (Polish Central Statistical Office)
 */
class WC_Optima_GUS_API
{
    /**
     * API key for GUS API
     *
     * @var string
     */
    private $api_key;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * Production mode
     *
     * @var bool
     */
    private $production_mode;

    /**
     * Login URL for production environment
     *
     * @var string
     */
    protected $login_url = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc/ajaxEndpoint/Zaloguj';

    /**
     * Search data URL for production environment
     *
     * @var string
     */
    protected $search_data_url = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc/ajaxEndpoint/daneSzukaj';

    /**
     * Login URL for test environment
     *
     * @var string
     */
    protected $login_test_url = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc/ajaxEndpoint/Zaloguj';

    /**
     * Search data URL for test environment
     *
     * @var string
     */
    protected $search_data_test_url = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc/ajaxEndpoint/daneSzukaj';

    /**
     * Session ID
     *
     * @var string|null
     */
    protected $session = null;

    /**
     * Debug log
     *
     * @var array
     */
    protected $debug_log = [];

    /**
     * Constructor
     *
     * @param array $options Plugin options
     */
    public function __construct($options)
    {
        $this->api_key = isset($options['gus_api_key']) ? $options['gus_api_key'] : '';
        $this->debug_mode = isset($options['gus_debug_mode']) && $options['gus_debug_mode'] === 'yes';
        $this->production_mode = isset($options['gus_production_mode']) && $options['gus_production_mode'] === 'yes';
    }

    /**
     * Make cURL request to GUS API
     *
     * @param string $field JSON encoded data
     * @param string $url API endpoint URL
     * @return mixed Response from API
     */
    protected function make_curl($field, $url)
    {
        if ($this->debug_mode) {
            $this->debug_log[] = [
                'time' => current_time('mysql'),
                'request' => [
                    'url' => $url,
                    'data' => $field,
                    'session' => $this->session
                ]
            ];
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $field);
        curl_setopt($curl, CURLOPT_ENCODING, 'UTF-8');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($field), 'sid:' . $this->session]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.114 Safari/537.36');
        curl_setopt($curl, CURLOPT_HEADER, false);

        $result = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($this->debug_mode) {
            $this->debug_log[count($this->debug_log) - 1]['response'] = [
                'http_code' => $http_code,
                'curl_error' => $curl_error,
                'data' => $result
            ];
        }

        if ($this->session == null) {
            return json_decode($result)->d;
        } else {
            return str_replace('\u000d\u000a', '', $result);
        }
    }

    /**
     * Login to GUS API
     *
     * @return string Session ID
     */
    protected function login()
    {
        $login_url = $this->production_mode ? $this->login_url : $this->login_test_url;
        // Używamy klucza produkcyjnego lub testowego w zależności od trybu
        $api_key = $this->production_mode ? $this->api_key : 'abcde12345abcde12345'; // Test key for test environment

        if ($this->debug_mode) {
            $this->debug_log[] = [
                'time' => current_time('mysql'),
                'info' => __('Próba logowania', 'optima-woocommerce'),
                'api_key' => substr($api_key, 0, 5) . '...',
                'mode' => $this->production_mode ? __('produkcyjny', 'optima-woocommerce') : __('testowy', 'optima-woocommerce'),
                'url' => $login_url
            ];
        }

        $login = json_encode(["pKluczUzytkownika" => $api_key]);
        $result = $this->make_curl($login, $login_url);

        return $result;
    }

    /**
     * Check if NIP is valid
     *
     * @param string $nip NIP number
     * @return bool True if NIP is valid, false otherwise
     */
    public function validate_nip($nip)
    {
        // Remove any non-digit characters
        $nip = preg_replace('/[^0-9]/', '', $nip);

        // NIP must be 10 digits
        if (strlen($nip) != 10) {
            return false;
        }

        // Check control digit
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * $nip[$i];
        }

        $control_digit = $sum % 11;
        if ($control_digit == 10) {
            $control_digit = 0;
        }

        return $control_digit == $nip[9];
    }

    /**
     * Check if REGON is valid
     *
     * @param string $regon REGON number
     * @return bool True if REGON is valid, false otherwise
     */
    public function validate_regon($regon)
    {
        // Remove any non-digit characters
        $regon = preg_replace('/[^0-9]/', '', $regon);

        // REGON must be 9 or 14 digits
        if (strlen($regon) != 9 && strlen($regon) != 14) {
            return false;
        }

        if (strlen($regon) == 9) {
            // Check control digit for 9-digit REGON
            $weights = [8, 9, 2, 3, 4, 5, 6, 7];
            $sum = 0;

            for ($i = 0; $i < 8; $i++) {
                $sum += $weights[$i] * $regon[$i];
            }

            $control_digit = $sum % 11;
            if ($control_digit == 10) {
                $control_digit = 0;
            }

            return $control_digit == $regon[8];
        } else {
            // Check control digit for 14-digit REGON
            $weights = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
            $sum = 0;

            for ($i = 0; $i < 13; $i++) {
                $sum += $weights[$i] * $regon[$i];
            }

            $control_digit = $sum % 11;
            if ($control_digit == 10) {
                $control_digit = 0;
            }

            return $control_digit == $regon[13];
        }
    }

    /**
     * Get company data by NIP
     *
     * @param string $nip NIP number
     * @return array|false Company data if found, false otherwise
     */
    public function get_company_by_nip($nip)
    {
        if (!$this->validate_nip($nip)) {
            if ($this->debug_mode) {
                $this->debug_log[] = [
                    'time' => current_time('mysql'),
                    'error' => __('Nieprawidłowy format NIP:', 'optima-woocommerce') . ' ' . $nip
                ];
            }
            return false;
        }

        if ($this->session == null) {
            $this->session = $this->login();
        }

        $search_url = $this->production_mode ? $this->search_data_url : $this->search_data_test_url;

        $search_data = json_encode(
            [
                'jestWojPowGmnMiej' => true,
                'pParametryWyszukiwania' => [
                    'AdsSymbolGminy' => null,
                    'AdsSymbolMiejscowosci' => null,
                    'AdsSymbolPowiatu' => null,
                    'AdsSymbolUlicy' => null,
                    'AdsSymbolWojewodztwa' => null,
                    'Dzialalnosci' => null,
                    'FormaPrawna' => null,
                    'Krs' => null,
                    'Krsy' => null,
                    'NazwaPodmiotu' => null,
                    'Nip' => $nip,
                    'Nipy' => null,
                    'NumerwRejestrzeLubEwidencji' => null,
                    'OrganRejestrowy' => null,
                    'PrzewazajacePKD' => false,
                    'Regon' => null,
                    'Regony14zn' => null,
                    'Regony9zn' => null,
                    'RodzajRejestru' => null,
                ]
            ]
        );

        $result = $this->make_curl($search_data, $search_url);
        $data = json_decode($result, true);

        if (isset($data['d']) && !empty($data['d'])) {
            // Odpowiedź jest w formacie XML, więc musimy ją przetworzyć
            $xml_string = $data['d'];

            // Dodaj informacje debugowania
            if ($this->debug_mode) {
                $this->debug_log[] = [
                    'time' => current_time('mysql'),
                    'info' => __('Odpowiedź XML', 'optima-woocommerce'),
                    'data' => $xml_string
                ];
            }

            // Konwertuj XML na tablicę
            $xml = simplexml_load_string($xml_string);

            if ($xml) {
                $company_data = [];

                // Przetwarzaj każdy element <dane> w XML
                foreach ($xml->dane as $dane) {
                    $company = [];

                    // Konwertuj obiekt SimpleXML na tablicę
                    foreach ($dane as $key => $value) {
                        $company[(string)$key] = (string)$value;
                    }

                    $company_data[] = $company;
                }

                return $company_data;
            }
        }

        return false;
    }

    /**
     * Get company data by REGON
     *
     * @param string $regon REGON number
     * @return array|false Company data if found, false otherwise
     */
    public function get_company_by_regon($regon)
    {
        if (!$this->validate_regon($regon)) {
            if ($this->debug_mode) {
                $this->debug_log[] = [
                    'time' => current_time('mysql'),
                    'error' => __('Nieprawidłowy format REGON:', 'optima-woocommerce') . ' ' . $regon
                ];
            }
            return false;
        }

        if ($this->session == null) {
            $this->session = $this->login();
        }

        $search_url = $this->production_mode ? $this->search_data_url : $this->search_data_test_url;

        $search_data = json_encode(
            [
                'jestWojPowGmnMiej' => true,
                'pParametryWyszukiwania' => [
                    'AdsSymbolGminy' => null,
                    'AdsSymbolMiejscowosci' => null,
                    'AdsSymbolPowiatu' => null,
                    'AdsSymbolUlicy' => null,
                    'AdsSymbolWojewodztwa' => null,
                    'Dzialalnosci' => null,
                    'FormaPrawna' => null,
                    'Krs' => null,
                    'Krsy' => null,
                    'NazwaPodmiotu' => null,
                    'Nip' => null,
                    'Nipy' => null,
                    'NumerwRejestrzeLubEwidencji' => null,
                    'OrganRejestrowy' => null,
                    'PrzewazajacePKD' => false,
                    'Regon' => $regon,
                    'Regony14zn' => null,
                    'Regony9zn' => null,
                    'RodzajRejestru' => null,
                ]
            ]
        );

        $result = $this->make_curl($search_data, $search_url);
        $data = json_decode($result, true);

        if (isset($data['d']) && !empty($data['d'])) {
            // Odpowiedź jest w formacie XML, więc musimy ją przetworzyć
            $xml_string = $data['d'];

            // Dodaj informacje debugowania
            if ($this->debug_mode) {
                $this->debug_log[] = [
                    'time' => current_time('mysql'),
                    'info' => __('Odpowiedź XML', 'optima-woocommerce'),
                    'data' => $xml_string
                ];
            }

            // Konwertuj XML na tablicę
            $xml = simplexml_load_string($xml_string);

            if ($xml) {
                $company_data = [];

                // Przetwarzaj każdy element <dane> w XML
                foreach ($xml->dane as $dane) {
                    $company = [];

                    // Konwertuj obiekt SimpleXML na tablicę
                    foreach ($dane as $key => $value) {
                        $company[(string)$key] = (string)$value;
                    }

                    $company_data[] = $company;
                }

                return $company_data;
            }
        }

        return false;
    }

    /**
     * Get debug log
     *
     * @return array Debug log
     */
    public function get_debug_log()
    {
        return $this->debug_log;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug mode is enabled, false otherwise
     */
    public function is_debug_mode()
    {
        return $this->debug_mode;
    }
}
