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
              "</p></div>"
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
            "</p></div>"
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
          "</p></div>"
      );
      return;
    }

    // Create table
    var table = $(
      '<table class="wp-list-table widefat fixed striped ro-documents">'
    );

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
          document.documentSaleDate
            ? new Date(document.documentSaleDate).toLocaleDateString()
            : ""
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
        .text(
          document.recipient
            ? document.recipient.name1 || document.recipient.code
            : ""
        )
        .appendTo(row);
      $("<td>")
        .text(
          document.elements
            ? document.elements.length + " " + wc_optima_params.items_suffix
            : "0 " + wc_optima_params.items_suffix
        )
        .appendTo(row);
      $("<td>")
        .text(
          document.documentReservationDate
            ? new Date(document.documentReservationDate).toLocaleDateString()
            : ""
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
              "</p></div>"
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
              "</p></div>"
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
            "</p></div>"
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
              "</p></div>"
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
            "</p></div>"
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
          "</p></div>"
      );
      return;
    }

    // Create table
    var table = $(
      '<table class="wp-list-table widefat fixed striped customers">'
    );

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
              "</p></div>"
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
            "</p></div>"
        );
      },
    });
  });
});
