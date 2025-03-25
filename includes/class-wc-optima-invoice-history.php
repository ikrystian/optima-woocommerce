<?php

/**
 * Invoice History functionality for Optima WooCommerce integration
 * 
 * @package Optima_WooCommerce
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for handling invoice history functionality
 */
class WC_Optima_Invoice_History
{
    /**
     * Plugin options
     *
     * @var array
     */
    private $options;

    /**
     * Constructor
     *
     * @param array $options Plugin options
     */
    public function __construct($options)
    {
        $this->options = $options;
    }
}
