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
            '<div class="notice notice-error"><p>Error: ' +
              (response.data || "Failed to fetch RO documents") +
              "</p></div>"
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-ro-documents-loading").hide();
        $("#wc-optima-ro-documents-results").html(
          '<div class="notice notice-error"><p>Error: ' + error + "</p></div>"
        );
      },
    });
  });

  // Function to display RO documents in a table
  function displayRODocuments(documents) {
    if (!documents.length) {
      $("#wc-optima-ro-documents-results").html(
        '<div class="notice notice-warning"><p>No RO documents found.</p></div>'
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

    $("<th>").text("ID").appendTo(headerRow);
    $("<th>").text("Type").appendTo(headerRow);
    $("<th>").text("Number").appendTo(headerRow);
    $("<th>").text("Foreign Number").appendTo(headerRow);
    $("<th>").text("Payment Method").appendTo(headerRow);
    $("<th>").text("Currency").appendTo(headerRow);
    $("<th>").text("Status").appendTo(headerRow);
    $("<th>").text("Sale Date").appendTo(headerRow);
    $("<th>").text("Amount To Pay").appendTo(headerRow);
    $("<th>").text("Category").appendTo(headerRow);
    $("<th>").text("Payer").appendTo(headerRow);
    $("<th>").text("Recipient").appendTo(headerRow);
    $("<th>").text("Elements").appendTo(headerRow);
    $("<th>").text("documentReservationDate").appendTo(headerRow);

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
          document.elements ? document.elements.length + " items" : "0 items"
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
      .text("Showing " + documents.length + " RO documents")
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
            '<div class="notice notice-success"><p>Sample customer created successfully!</p></div>'
          );

          // Display the new customer
          var customers = [response.data];
          displayCustomers(customers);
        } else {
          // Show error message
          $("#wc-optima-customers-results").html(
            '<div class="notice notice-error"><p>Error: ' +
              (response.data || "Failed to create sample customer") +
              "</p></div>"
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-customers-loading").hide();
        $("#wc-optima-customers-results").html(
          '<div class="notice notice-error"><p>Error: ' + error + "</p></div>"
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
            '<div class="notice notice-error"><p>Error: ' +
              (response.data || "Failed to fetch customers") +
              "</p></div>"
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-customers-loading").hide();
        $("#wc-optima-customers-results").html(
          '<div class="notice notice-error"><p>Error: ' + error + "</p></div>"
        );
      },
    });
  });

  // Function to display customers in a table
  function displayCustomers(customers) {
    if (!customers.length) {
      $("#wc-optima-customers-results").html(
        '<div class="notice notice-warning"><p>No customers found.</p></div>'
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

    $("<th>").text("ID").appendTo(headerRow);
    $("<th>").text("Code").appendTo(headerRow);
    $("<th>").text("Name").appendTo(headerRow);
    $("<th>").text("Email").appendTo(headerRow);
    $("<th>").text("Phone").appendTo(headerRow);
    $("<th>").text("City").appendTo(headerRow);

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
      .text("Showing " + customers.length + " customers")
      .prependTo("#wc-optima-customers-results");
  }

  $("#wc-optima-search-ro-document").on("click", function (e) {
    e.preventDefault();

    // Get the document ID from the input field
    var documentId = $("#wc-optima-document-id").val();

    if (!documentId) {
      alert("Please enter a document ID to search");
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
        nonce: wc_optima_params.ro_nonce,
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
            '<div class="notice notice-error"><p>Error: ' +
              (response.data || "Document not found") +
              "</p></div>"
          );
        }
      },
      error: function (xhr, status, error) {
        // Hide loading indicator and show error
        $("#wc-optima-ro-documents-loading").hide();
        $("#wc-optima-ro-documents-results").html(
          '<div class="notice notice-error"><p>Error: ' + error + "</p></div>"
        );
      },
    });
  });
});
