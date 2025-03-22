jQuery(document).ready(function ($) {
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
});
