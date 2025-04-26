jQuery(document).ready(function ($) {
  // Handle the fetch RO documents button click
  $("#wc-optima-fetch-ro-documents").on("click", function (e) {
    e.preventDefault();

    // Show loading indicator
    $("#wc-optima-ro-documents-loading").show();
    $("#wc-optima-ro-documents-results").empty();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_fetch_ro_documents",
        nonce: wc_optima_params.ro_nonce,
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-ro-documents-loading").hide();

        if (response.success && response.data) {
          // Create table for RO documents
          displayRODocuments(response.data);
        } else {
          // Show error message
          $("#wc-optima-ro-documents-results").html(
            '<div class="notice notice-error"><p>' +
              wc_optima_params.error_prefix +
              " " +
              (response.data || wc_optima_params.error_fetching_documents) +
              "</p></div>",
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-ro-documents-loading").hide();
        $("#wc-optima-ro-documents-results").html(
          '<div class="notice notice-error"><p>' +
            wc_optima_params.error_prefix +
            " " +
            (error || wc_optima_params.generic_error) +
            "</p></div>",
        );
      },
    });
  });

  // Function to display RO documents in a table
  function displayRODocuments(documents) {
    if (!documents.length) {
      $("#wc-optima-ro-documents-results").html(
        '<div class="notice notice-warning"><p>' +
          wc_optima_params.no_documents_found +
          "</p></div>",
      );
      return;
    }

    // Create table
    var table = $('<table class="wp-list-table widefat fixed striped ro-documents">');

    // Add table header
    var thead = $("<thead>").appendTo(table);
    var headerRow = $("<tr>").appendTo(thead);

    $("<th>").text(wc_optima_params.th_id).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_type).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_number).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_foreign_number).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_payment_method).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_currency).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_status).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_sale_date).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_amount_to_pay).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_category).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_payer).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_recipient).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_elements).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_reservation_date).appendTo(headerRow);

    // Add table body
    var tbody = $("<tbody>").appendTo(table);

    // Add rows for each document
    $.each(documents, function (index, document) {
      var row = $("<tr>").appendTo(tbody);

      $("<td>")
        .text(document.id || "")
        .appendTo(row);
      $("<td>")
        .text(document.type || "")
        .appendTo(row);
      $("<td>")
        .text(document.fullNumber || "")
        .appendTo(row);
      $("<td>")
        .text(document.foreignNumber || "")
        .appendTo(row);
      $("<td>")
        .text(document.paymentMethod || "")
        .appendTo(row);
      $("<td>")
        .text(document.currency || "")
        .appendTo(row);
      $("<td>")
        .text(document.status || "")
        .appendTo(row);
      $("<td>")
        .text(
          document.documentSaleDate ? new Date(document.documentSaleDate).toLocaleDateString() : "",
        )
        .appendTo(row);
      $("<td>")
        .text(document.amountToPay || "")
        .appendTo(row);
      $("<td>")
        .text(document.category || "")
        .appendTo(row);
      $("<td>")
        .text(document.payer ? document.payer.name1 || document.payer.code : "")
        .appendTo(row);
      $("<td>")
        .text(document.recipient ? document.recipient.name1 || document.recipient.code : "")
        .appendTo(row);
      $("<td>")
        .text(
          document.elements
            ? document.elements.length + " " + wc_optima_params.items_suffix
            : "0 " + wc_optima_params.items_suffix,
        )
        .appendTo(row);
      $("<td>")
        .text(
          document.documentReservationDate
            ? new Date(document.documentReservationDate).toLocaleDateString()
            : "",
        )
        .appendTo(row);
    });

    // Add table to results div
    $("#wc-optima-ro-documents-results").html(table);

    // Add count message
    $("<p>")
      .text(wc_optima_params.showing_documents.replace("%d", documents.length))
      .prependTo("#wc-optima-ro-documents-results");
  }

  // Handle the create sample customer button click
  $("#wc-optima-create-customer").on("click", function (e) {
    e.preventDefault();

    // Show loading indicator
    $("#wc-optima-customers-loading").show();
    $("#wc-optima-customers-results").empty();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_create_sample_customer",
        nonce: wc_optima_params.nonce,
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-customers-loading").hide();

        if (response.success && response.data) {
          // Show success message
          $("#wc-optima-customers-results").html(
            '<div class="notice notice-success"><p>' +
              wc_optima_params.customer_created_success +
              "</p></div>",
          );

          // Display the new customer
          var customers = [response.data];
          displayCustomers(customers);
        } else {
          // Show error message
          $("#wc-optima-customers-results").html(
            '<div class="notice notice-error"><p>' +
              wc_optima_params.error_prefix +
              " " +
              (response.data || wc_optima_params.error_creating_customer) +
              "</p></div>",
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-customers-loading").hide();
        $("#wc-optima-customers-results").html(
          '<div class="notice notice-error"><p>' +
            wc_optima_params.error_prefix +
            " " +
            (error || wc_optima_params.generic_error) +
            "</p></div>",
        );
      },
    });
  });

  // Handle the fetch customers button click
  $("#wc-optima-fetch-customers").on("click", function (e) {
    e.preventDefault();

    // Show loading indicator
    $("#wc-optima-customers-loading").show();
    $("#wc-optima-customers-results").empty();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_fetch_customers",
        nonce: wc_optima_params.nonce,
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-customers-loading").hide();

        if (response.success && response.data) {
          // Create table for customers
          displayCustomers(response.data);
        } else {
          // Show error message
          $("#wc-optima-customers-results").html(
            '<div class="notice notice-error"><p>' +
              wc_optima_params.error_prefix +
              " " +
              (response.data || wc_optima_params.error_fetching_customers) +
              "</p></div>",
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-customers-loading").hide();
        $("#wc-optima-customers-results").html(
          '<div class="notice notice-error"><p>' +
            wc_optima_params.error_prefix +
            " " +
            (error || wc_optima_params.generic_error) +
            "</p></div>",
        );
      },
    });
  });

  // Function to display customers in a table
  function displayCustomers(customers) {
    if (!customers.length) {
      $("#wc-optima-customers-results").html(
        '<div class="notice notice-warning"><p>' +
          wc_optima_params.no_customers_found +
          "</p></div>",
      );
      return;
    }

    // Create table
    var table = $('<table class="wp-list-table widefat fixed striped customers">');

    // Add table header
    var thead = $("<thead>").appendTo(table);
    var headerRow = $("<tr>").appendTo(thead);

    $("<th>").text(wc_optima_params.th_id).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_code).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_name).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_email).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_phone).appendTo(headerRow);
    $("<th>").text(wc_optima_params.th_city).appendTo(headerRow);

    // Add table body
    var tbody = $("<tbody>").appendTo(table);

    // Add rows for each customer
    $.each(customers, function (index, customer) {
      var row = $("<tr>").appendTo(tbody);

      $("<td>")
        .text(customer.id || "")
        .appendTo(row);
      $("<td>")
        .text(customer.code || "")
        .appendTo(row);
      $("<td>")
        .text(customer.name1 || "")
        .appendTo(row);
      $("<td>")
        .text(customer.email || "")
        .appendTo(row);
      $("<td>")
        .text(customer.phone1 || "")
        .appendTo(row);
      $("<td>")
        .text(customer.city || "")
        .appendTo(row);
    });

    // Add table to results div
    $("#wc-optima-customers-results").html(table);

    // Add count message
    $("<p>")
      .text(wc_optima_params.showing_customers.replace("%d", customers.length))
      .prependTo("#wc-optima-customers-results");
  }

  $("#wc-optima-search-ro-document").on("click", function (e) {
    e.preventDefault();

    // Get the document ID from the input field
    var documentId = $("#wc-optima-document-id").val();

    if (!documentId) {
      alert(wc_optima_params.enter_doc_id_alert);
      return;
    }

    // Show loading indicator
    $("#wc-optima-ro-documents-loading").show();
    $("#wc-optima-ro-documents-results").empty();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_search_ro_document",
        nonce: wc_optima_params.search_nonce, // Use correct nonce for search
        document_id: documentId,
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-ro-documents-loading").hide();

        if (response.success && response.data) {
          // Create table for RO documents
          displayRODocuments([response.data]);
        } else {
          // Show error message
          $("#wc-optima-ro-documents-results").html(
            '<div class="notice notice-error"><p>' +
              wc_optima_params.error_prefix +
              " " +
              (response.data || wc_optima_params.document_not_found) +
              "</p></div>",
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-ro-documents-loading").hide();
        $("#wc-optima-ro-documents-results").html(
          '<div class="notice notice-error"><p>' +
            wc_optima_params.error_prefix +
            " " +
            (error || wc_optima_params.generic_error) +
            "</p></div>",
        );
      },
    });
  });

  // Handle the fetch invoices button click
  $("#wc-optima-fetch-invoices").on("click", function (e) {
    e.preventDefault();

    // Show loading indicator
    $("#wc-optima-invoices-loading").show();
    $("#wc-optima-invoices-results").empty();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_fetch_invoices",
        nonce: wc_optima_params.invoice_nonce,
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-invoices-loading").hide();

        if (response.success && response.data) {
          // Create table for invoices
          displayInvoices(response.data);
        } else {
          // Show error message
          $("#wc-optima-invoices-results").html(
            '<div class="notice notice-error"><p>Error: ' +
              (response.data || "Failed to fetch invoices") +
              "</p></div>",
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-invoices-loading").hide();
        $("#wc-optima-invoices-results").html(
          '<div class="notice notice-error"><p>Error: ' + error + "</p></div>",
        );
      },
    });
  });

  // Handle the search invoice button click
  $("#wc-optima-search-invoice").on("click", function (e) {
    e.preventDefault();

    // Get search parameters
    var invoiceNumber = $("#wc-optima-invoice-number").val();
    var dateFrom = $("#wc-optima-date-from").val();
    var dateTo = $("#wc-optima-date-to").val();
    var customerId = $("#wc-optima-customer-id").val();

    // Check if at least one search parameter is provided
    if (!invoiceNumber && !dateFrom && !dateTo && !customerId) {
      alert("Please enter at least one search parameter");
      return;
    }

    // Show loading indicator
    $("#wc-optima-invoices-loading").show();
    $("#wc-optima-invoices-results").empty();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_search_invoice",
        nonce: wc_optima_params.invoice_nonce,
        search_params: {
          invoice_number: invoiceNumber,
          date_from: dateFrom,
          date_to: dateTo,
          customer_id: customerId,
        },
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-invoices-loading").hide();

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
          // Show error message
          $("#wc-optima-invoices-results").html(
            '<div class="notice notice-warning"><p>' +
              (response.data || "No invoices found matching the search criteria") +
              "</p></div>",
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-invoices-loading").hide();
        $("#wc-optima-invoices-results").html(
          '<div class="notice notice-error"><p>Error: ' + error + "</p></div>",
        );
      },
    });
  });

  // Function to display invoices in a table
  function displayInvoices(invoices) {
    if (!invoices.length) {
      $("#wc-optima-invoices-results").html(
        '<div class="notice notice-warning"><p>No invoices found.</p></div>',
      );
      return;
    }

    // Create table
    var table = $('<table class="wp-list-table widefat fixed striped invoices">');

    // Add table header
    var thead = $("<thead>").appendTo(table);
    var headerRow = $("<tr>").appendTo(thead);

    // Add all invoice fields to the header
    $("<th>").text("ID").appendTo(headerRow);
    $("<th>").text("Invoice Number").appendTo(headerRow);
    $("<th>").text("Issue Date").appendTo(headerRow);
    $("<th>").text("Due Date").appendTo(headerRow);
    $("<th>").text("Sale Date").appendTo(headerRow);
    $("<th>").text("Payment Date").appendTo(headerRow);
    $("<th>").text("Net Value").appendTo(headerRow);
    $("<th>").text("Gross Value").appendTo(headerRow);
    $("<th>").text("Currency").appendTo(headerRow);
    $("<th>").text("Customer ID").appendTo(headerRow);
    $("<th>").text("Customer Name").appendTo(headerRow);
    $("<th>").text("Customer NIP").appendTo(headerRow);
    $("<th>").text("Document Type").appendTo(headerRow);
    $("<th>").text("Document Type ID").appendTo(headerRow);
    $("<th>").text("Payment Method").appendTo(headerRow);
    $("<th>").text("Payment Method ID").appendTo(headerRow);
    $("<th>").text("Status").appendTo(headerRow);
    $("<th>").text("Paid").appendTo(headerRow);
    $("<th>").text("Canceled").appendTo(headerRow);
    $("<th>").text("Foreign Number").appendTo(headerRow);
    $("<th>").text("Description").appendTo(headerRow);
    $("<th>").text("Discount").appendTo(headerRow);
    $("<th>").text("VAT Registration Country").appendTo(headerRow);
    $("<th>").text("Actions").appendTo(headerRow);

    // Add table body
    var tbody = $("<tbody>").appendTo(table);

    // Add rows for each invoice
    $.each(invoices, function (index, invoice) {
      var row = $("<tr>").appendTo(tbody);

      // Format dates and values
      var formatDate = function (dateString) {
        return dateString ? new Date(dateString).toLocaleDateString() : "";
      };

      var formatCurrency = function (value, currency) {
        return value ? parseFloat(value).toFixed(2) + " " + (currency || "") : "0.00";
      };

      // Add all invoice fields to the row
      $("<td>")
        .text(invoice.id || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.invoiceNumber || "")
        .appendTo(row);
      $("<td>").text(formatDate(invoice.issueDate)).appendTo(row);
      $("<td>").text(formatDate(invoice.dueDate)).appendTo(row);
      $("<td>").text(formatDate(invoice.saleDate)).appendTo(row);
      $("<td>").text(formatDate(invoice.paymentDate)).appendTo(row);
      $("<td>").text(formatCurrency(invoice.netValue, invoice.currency)).appendTo(row);
      $("<td>").text(formatCurrency(invoice.grossValue, invoice.currency)).appendTo(row);
      $("<td>")
        .text(invoice.currency || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.customerId || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.customerName || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.customerNip || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.documentTypeName || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.documentTypeId || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.paymentMethodName || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.paymentMethodId || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.status || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.paid ? "Yes" : "No")
        .appendTo(row);
      $("<td>")
        .text(invoice.canceled ? "Yes" : "No")
        .appendTo(row);
      $("<td>")
        .text(invoice.foreignNumber || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.description || "")
        .appendTo(row);
      $("<td>")
        .text(invoice.discount ? invoice.discount + "%" : "0%")
        .appendTo(row);
      $("<td>")
        .text(invoice.vatRegistrationCountry || "")
        .appendTo(row);

      // Add action buttons
      var actionsCell = $("<td>").appendTo(row);
      $("<button>")
        .addClass("button button-small")
        .text("Download PDF")
        .on("click", function () {
          downloadInvoicePdf(invoice.id);
        })
        .appendTo(actionsCell);
    });

    // Add table to results div
    $("#wc-optima-invoices-results").html(table);

    // Add count message
    $("<p>")
      .text("Showing " + invoices.length + " invoices")
      .prependTo("#wc-optima-invoices-results");

    // Add horizontal scroll container for better usability with many columns
    $("#wc-optima-invoices-results table").wrap('<div style="overflow-x: auto;"></div>');
  }

  // Function to download invoice PDF
  function downloadInvoicePdf(invoiceId) {
    // Show loading indicator
    $("#wc-optima-invoices-loading").show();

    // Make AJAX request
    $.ajax({
      url: wc_optima_params.ajax_url,
      type: "POST",
      data: {
        action: "wc_optima_get_invoice_pdf",
        nonce: wc_optima_params.invoice_nonce,
        invoice_id: invoiceId,
      },
      success: function (response) {
        // Hide loading indicator
        $("#wc-optima-invoices-loading").hide();

        if (response.success && response.data && response.data.download_url) {
          // Create a hidden iframe to trigger the download
          var iframe = document.createElement("iframe");
          iframe.style.display = "none";
          iframe.src = response.data.download_url;
          document.body.appendChild(iframe);

          // Remove the iframe after a short delay
          setTimeout(function () {
            document.body.removeChild(iframe);
          }, 2000);
        } else {
          // Show error message
          alert("Error: " + (response.data || "Failed to generate invoice"));
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-invoices-loading").hide();
        alert("Error: " + error);
      },
    });
  }
});

