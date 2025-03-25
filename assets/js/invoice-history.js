/**
 * Invoice History JavaScript
 * 
 * Handles AJAX requests and UI interactions for the invoice history page.
 */
jQuery(document).ready(function($) {
    'use strict';

    // Cache DOM elements
    const $filterForm = $('#optima-invoice-filters');
    const $searchButton = $('#optima-search-invoices');
    const $resetButton = $('#optima-reset-filters');
    const $loadingIndicator = $('#optima-invoices-loading');
    const $resultsContainer = $('#optima-invoices-results');
    const $dateFrom = $('#optima-date-from');
    const $dateTo = $('#optima-date-to');
    const $invoiceNumber = $('#optima-invoice-number');
    const $customerType = $('#optima-customer-type');
    const $documentType = $('#optima-document-type');

    /**
     * Initialize date pickers
     */
    if ($.fn.datepicker) {
        $dateFrom.datepicker({
            dateFormat: 'yy-mm-dd',
            maxDate: new Date()
        });
        $dateTo.datepicker({
            dateFormat: 'yy-mm-dd',
            maxDate: new Date()
        });
    }

    /**
     * Handle search button click
     */
    $searchButton.on('click', function(e) {
        e.preventDefault();
        searchInvoices();
    });

    /**
     * Handle reset button click
     */
    $resetButton.on('click', function(e) {
        e.preventDefault();
        resetFilters();
    });

    /**
     * Handle form submission
     */
    $filterForm.on('submit', function(e) {
        e.preventDefault();
        searchInvoices();
    });

    /**
     * Handle download invoice button click
     */
    $resultsContainer.on('click', '.optima-download-invoice', function(e) {
        e.preventDefault();
        const invoiceId = $(this).data('invoice-id');
        downloadInvoice(invoiceId);
    });

    /**
     * Search invoices based on filter criteria
     */
    function searchInvoices() {
        // Show loading indicator
        $loadingIndicator.show();
        $resultsContainer.empty();

        // Get filter values
        const filters = {
            date_from: $dateFrom.val(),
            date_to: $dateTo.val(),
            invoice_number: $invoiceNumber.val(),
            customer_type: $customerType.val(),
            document_type: $documentType.val()
        };

        // Make AJAX request
        $.ajax({
            url: optima_invoice_history.ajax_url,
            type: 'POST',
            data: {
                action: 'optima_search_invoices',
                nonce: optima_invoice_history.nonce,
                filters: filters
            },
            success: function(response) {
                $loadingIndicator.hide();
                
                if (response.success && response.data) {
                    // Check if response.data is an array or a single object
                    if (Array.isArray(response.data)) {
                        // It's an array, pass it directly
                        displayInvoices(response.data);
                    } else {
                        // It's a single object, wrap it in an array
                        displayInvoices([response.data]);
                    }
                } else {
                    displayError(response.data || 'No invoices found matching your criteria.');
                }
            },
            error: function(xhr, status, error) {
                $loadingIndicator.hide();
                displayError('Error searching invoices: ' + error);
            }
        });
    }

    /**
     * Download invoice PDF
     * 
     * @param {number} invoiceId The invoice ID
     */
    function downloadInvoice(invoiceId) {
        // Show loading indicator
        $loadingIndicator.show();

        // Make AJAX request
        $.ajax({
            url: optima_invoice_history.ajax_url,
            type: 'POST',
            data: {
                action: 'optima_download_invoice',
                nonce: optima_invoice_history.nonce,
                invoice_id: invoiceId
            },
            success: function(response) {
                $loadingIndicator.hide();
                
                if (response.success && response.data && response.data.pdf_url) {
                    // Open PDF in new window
                    window.open(response.data.pdf_url, '_blank');
                } else {
                    displayError(response.data || 'Error generating invoice PDF.');
                }
            },
            error: function(xhr, status, error) {
                $loadingIndicator.hide();
                displayError('Error downloading invoice: ' + error);
            }
        });
    }

    /**
     * Display invoices in the results container
     * 
     * @param {Array} invoices Array of invoice objects
     */
    function displayInvoices(invoices) {
        if (!invoices || invoices.length === 0) {
            displayError('No invoices found matching your criteria.');
            return;
        }

        // Create table
        const $table = $('<table class="optima-invoices-table"></table>');
        
        // Add table header
        const $thead = $('<thead></thead>');
        const $headerRow = $('<tr></tr>');
        
        $headerRow.append('<th>Invoice Number</th>');
        $headerRow.append('<th>Issue Date</th>');
        $headerRow.append('<th>Due Date</th>');
        $headerRow.append('<th>Net Value</th>');
        $headerRow.append('<th>Gross Value</th>');
        $headerRow.append('<th>Currency</th>');
        $headerRow.append('<th>Document Type</th>');
        $headerRow.append('<th>Actions</th>');
        
        $thead.append($headerRow);
        $table.append($thead);
        
        // Add table body
        const $tbody = $('<tbody></tbody>');
        
        // Add rows for each invoice
        $.each(invoices, function(index, invoice) {
            const $row = $('<tr></tr>');
            
            // Format dates
            const issueDate = formatDate(invoice.issueDate);
            const dueDate = formatDate(invoice.dueDate);
            
            // Format values
            const netValue = formatCurrency(invoice.netValue, invoice.currency);
            const grossValue = formatCurrency(invoice.grossValue, invoice.currency);
            
            $row.append('<td>' + escapeHtml(invoice.invoiceNumber) + '</td>');
            $row.append('<td>' + issueDate + '</td>');
            $row.append('<td>' + dueDate + '</td>');
            $row.append('<td>' + netValue + '</td>');
            $row.append('<td>' + grossValue + '</td>');
            $row.append('<td>' + escapeHtml(invoice.currency) + '</td>');
            $row.append('<td>' + escapeHtml(invoice.documentTypeName) + '</td>');
            
            // Add download button
            const $actionsCell = $('<td></td>');
            const $downloadButton = $('<button class="button button-small optima-download-invoice" data-invoice-id="' + invoice.id + '">Download PDF</button>');
            
            $actionsCell.append($downloadButton);
            $row.append($actionsCell);
            
            $tbody.append($row);
        });
        
        $table.append($tbody);
        $resultsContainer.append($table);
    }

    /**
     * Display error message
     * 
     * @param {string} message Error message to display
     */
    function displayError(message) {
        const $error = $('<div class="notice notice-error"></div>');
        $error.append('<p>' + message + '</p>');
        $resultsContainer.empty().append($error);
    }

    /**
     * Reset all filters
     */
    function resetFilters() {
        $dateFrom.val('');
        $dateTo.val('');
        $invoiceNumber.val('');
        $customerType.val('');
        $documentType.val('');
        $resultsContainer.empty();
    }

    /**
     * Format date string
     * 
     * @param {string} dateString Date string from API
     * @return {string} Formatted date
     */
    function formatDate(dateString) {
        if (!dateString) return '';
        
        // Parse date string (format: 2023-09-01T00:00:00)
        const date = new Date(dateString);
        
        // Format date (YYYY-MM-DD)
        return date.getFullYear() + '-' + 
               padZero(date.getMonth() + 1) + '-' + 
               padZero(date.getDate());
    }

    /**
     * Format currency value
     * 
     * @param {number} value Currency value
     * @param {string} currency Currency code
     * @return {string} Formatted currency value
     */
    function formatCurrency(value, currency) {
        if (value === null || value === undefined) return '';
        
        // Format number with 2 decimal places
        const formattedValue = parseFloat(value).toFixed(2);
        
        // Add currency code if available
        return formattedValue + (currency ? ' ' + currency : '');
    }

    /**
     * Pad number with leading zero if needed
     * 
     * @param {number} num Number to pad
     * @return {string} Padded number
     */
    function padZero(num) {
        return num < 10 ? '0' + num : num;
    }

    /**
     * Escape HTML special characters
     * 
     * @param {string} text Text to escape
     * @return {string} Escaped text
     */
    function escapeHtml(text) {
        if (!text) return '';
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Load invoices on page load if user is logged in and has customer ID
    if (optima_invoice_history.is_logged_in && optima_invoice_history.has_customer_id) {
        searchInvoices();
    }
});
