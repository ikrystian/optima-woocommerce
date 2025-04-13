/**
 * Optima WooCommerce Admin JavaScript
 */
jQuery(document).ready(function($) {
    // Handle send invoice email button click
    $('.send-invoice-email').on('click', function() {
        var button = $(this);
        var resultSpan = button.next('.invoice-email-result');
        var orderId = button.data('order-id');
        var nonce = button.data('nonce');
        
        // Disable button and show sending message
        button.prop('disabled', true);
        resultSpan.removeClass('success error').text(wc_optima_params.sending_text);
        
        // Send AJAX request
        $.ajax({
            url: wc_optima_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_optima_send_invoice_email',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    resultSpan.addClass('success').text(wc_optima_params.success_text);
                } else {
                    resultSpan.addClass('error').text(response.data || wc_optima_params.error_text);
                }
                
                // Re-enable button after a delay
                setTimeout(function() {
                    button.prop('disabled', false);
                }, 2000);
            },
            error: function() {
                resultSpan.addClass('error').text(wc_optima_params.error_text);
                
                // Re-enable button
                button.prop('disabled', false);
            }
        });
    });
});
