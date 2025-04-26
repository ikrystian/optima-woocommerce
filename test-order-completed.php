<?php
/**
 * Test script for the Order Completed functionality
 * 
 * This script simulates an order being marked as completed and tests the invoice creation,
 * link addition, and email sending functionality.
 * 
 * Usage: Run this script from the WordPress admin area or via WP-CLI.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if this is a test request
if (!isset($_GET['test_order_completed']) || !current_user_can('manage_options')) {
    return;
}

// Get the order ID from the request
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id <= 0) {
    wp_die('Invalid order ID. Please provide a valid order ID.');
}

// Get the order
$order = wc_get_order($order_id);

if (!$order) {
    wp_die('Order not found. Please provide a valid order ID.');
}

// Display header
echo '<h1>Testing Order Completed Functionality</h1>';
echo '<p>Order ID: ' . $order_id . '</p>';
echo '<p>Order Status: ' . $order->get_status() . '</p>';

// Check if the order is already completed
if ($order->get_status() === 'completed') {
    echo '<p>Order is already completed. Testing invoice creation...</p>';
    
    // Manually trigger the woocommerce_order_status_completed hook
    do_action('woocommerce_order_status_completed', $order_id);
    
    echo '<p>Done! Check the order notes for results.</p>';
} else {
    echo '<p>Changing order status to completed...</p>';
    
    // Change the order status to completed
    $order->update_status('completed', 'Testing order completed functionality');
    
    echo '<p>Done! Check the order notes for results.</p>';
}

// Add a link back to the order
echo '<p><a href="' . admin_url('post.php?post=' . $order_id . '&action=edit') . '">Back to Order</a></p>';
