/**
 * Registration form validation script
 */
jQuery(document).ready(function ($) {
  // B2C Registration form validation
  if ($("#wc-optima-b2c-registration-form").length) {
    $("#wc-optima-b2c-registration-form").on("submit", function (e) {
      var isValid = true;

      // Validate required fields
      $(this)
        .find("input[required]")
        .each(function () {
          if ($(this).val() === "") {
            $(this).addClass("error");
            if (!$(this).next(".error-message").length) {
              $(this).after(
                '<span class="error-message">' +
                  wc_optima_validation.required +
                  "</span>"
              );
            }
            isValid = false;
          } else {
            $(this).removeClass("error");
            $(this).next(".error-message").remove();
          }
        });

      // Validate email
      var emailField = $("#email");
      if (emailField.val() !== "" && !isValidEmail(emailField.val())) {
        emailField.addClass("error");
        if (!emailField.next(".error-message").length) {
          emailField.after(
            '<span class="error-message">' +
              wc_optima_validation.email +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate password strength
      var passwordField = $("#password");
      if (
        passwordField.val() !== "" &&
        !isStrongPassword(passwordField.val())
      ) {
        passwordField.addClass("error");
        if (!passwordField.next(".error-message").length) {
          passwordField.after(
            '<span class="error-message">' +
              wc_optima_validation.password_strength +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate password confirmation
      var passwordConfirmField = $("#password_confirm");
      if (
        passwordConfirmField.val() !== "" &&
        passwordConfirmField.val() !== passwordField.val()
      ) {
        passwordConfirmField.addClass("error");
        if (!passwordConfirmField.next(".error-message").length) {
          passwordConfirmField.after(
            '<span class="error-message">' +
              wc_optima_validation.password_match +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate terms checkbox
      var termsCheckbox = $("#terms");
      if (!termsCheckbox.is(":checked")) {
        termsCheckbox.parent().addClass("error");
        if (!termsCheckbox.parent().next(".error-message").length) {
          termsCheckbox
            .parent()
            .after(
              '<span class="error-message">' +
                wc_optima_validation.required +
                "</span>"
            );
        }
        isValid = false;
      } else {
        termsCheckbox.parent().removeClass("error");
        termsCheckbox.parent().next(".error-message").remove();
      }

      if (!isValid) {
        e.preventDefault();
      }
    });
  }

  // B2B Registration form validation
  if ($("#wc-optima-b2b-registration-form").length) {
    // NIP verification button
    $("#verify-company").on("click", function () {
      var nip = $("#nip")
        .val()
        .replace(/[^0-9]/g, "");

      if (nip.length !== 10) {
        $("#company-verification-status").html(
          '<span class="error-message">' +
            wc_optima_validation.nip_format +
            "</span>"
        );
        return;
      }

      $("#company-verification-status").html(
        '<span class="verifying">' +
          wc_optima_validation.verify_company +
          "</span>"
      );

      $.ajax({
        url: wc_optima_ajax.ajax_url,
        type: "POST",
        data: {
          action: "wc_optima_verify_company",
          nip: nip,
          nonce: wc_optima_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            $("#company-verification-status").html(
              '<span class="success">' +
                wc_optima_validation.company_verified +
                "</span>"
            );

            // Fill company data
            if (response.data) {
              // Przypisz dane firmy do pól formularza i ustaw je jako tylko do odczytu
              if (response.data.name) {
                $("#company_name")
                  .val(response.data.name)
                  .attr("readonly", true);
              }
              if (response.data.regon) {
                $("#regon").val(response.data.regon).attr("readonly", true);
              }
              if (response.data.address) {
                $("#address").val(response.data.address).attr("readonly", true);
              }
              if (response.data.postcode) {
                $("#postcode")
                  .val(response.data.postcode)
                  .attr("readonly", true);
              }
              if (response.data.city) {
                $("#city").val(response.data.city).attr("readonly", true);
              }

              // Dodaj klasę CSS dla pól tylko do odczytu
              $("input[readonly]").addClass("verified-field");

              // Dodaj informację o zweryfikowanych danych i przycisk do odblokowania pól
              $("#company-verification-status").append(
                '<div class="verified-info">' +
                  wc_optima_validation.verified_readonly_info +
                  ' <button type="button" id="unlock-fields" class="button-small">' +
                  wc_optima_validation.unlock_fields_button +
                  "</button></div>"
              );

              // Obsługa przycisku do odblokowania pól
              $("#unlock-fields").on("click", function () {
                $("input.verified-field")
                  .removeAttr("readonly")
                  .removeClass("verified-field");
                $(".verified-info").remove();
                $("#company-verification-status").append(
                  '<div class="warning-info">' +
                    wc_optima_validation.fields_unlocked_warning +
                    "</div>"
                );
              });

              // Dodaj informacje debugowania, jeśli są dostępne
              if (
                response.data.debug_logs &&
                response.data.debug_logs.length > 0
              ) {
                var debugInfo =
                  '<div class="debug-info"><h4>' +
                  wc_optima_validation.debug_info_title +
                  "</h4><pre>" +
                  JSON.stringify(response.data.debug_logs, null, 2) +
                  "</pre></div>";
                $("#company-verification-status").append(debugInfo);
              }
            }
          } else {
            var errorMessage = wc_optima_validation.company_verification_failed;
            var debugInfo = "";

            // Dodaj informacje debugowania, jeśli są dostępne
            if (
              response.data &&
              response.data.debug_logs &&
              response.data.debug_logs.length > 0
            ) {
              debugInfo =
                '<div class="debug-info"><h4>' +
                wc_optima_validation.debug_info_title +
                "</h4><pre>" +
                JSON.stringify(response.data.debug_logs, null, 2) +
                "</pre></div>";
            }

            $("#company-verification-status").html(
              '<span class="error-message">' +
                errorMessage +
                "</span>" +
                debugInfo
            );
          }
        },
        error: function (xhr, status, error) {
          var errorMessage = wc_optima_validation.company_verification_failed;
          var debugInfo = "";

          // Próba pobrania informacji debugowania z odpowiedzi
          try {
            var response = JSON.parse(xhr.responseText);
            if (response && response.data) {
              if (typeof response.data === "string") {
                errorMessage = response.data;
              } else if (response.data.message) {
                errorMessage = response.data.message;

                // Dodaj informacje debugowania, jeśli są dostępne
                if (
                  response.data.debug_logs &&
                  response.data.debug_logs.length > 0
                ) {
                  debugInfo =
                    '<div class="debug-info"><h4>' +
                    wc_optima_validation.debug_info_title +
                    "</h4><pre>" +
                    JSON.stringify(response.data.debug_logs, null, 2) +
                    "</pre></div>";
                }
              }
            }
          } catch (e) {
            console.error(wc_optima_validation.json_parse_error, e);
          }

          $("#company-verification-status").html(
            '<span class="error-message">' +
              errorMessage +
              "</span>" +
              debugInfo
          );
        },
      });
    });

    // Form validation on submit
    $("#wc-optima-b2b-registration-form").on("submit", function (e) {
      var isValid = true;

      // Validate required fields
      $(this)
        .find("input[required]")
        .each(function () {
          if ($(this).val() === "") {
            $(this).addClass("error");
            if (!$(this).next(".error-message").length) {
              $(this).after(
                '<span class="error-message">' +
                  wc_optima_validation.required +
                  "</span>"
              );
            }
            isValid = false;
          } else {
            $(this).removeClass("error");
            $(this).next(".error-message").remove();
          }
        });

      // Validate NIP
      var nipField = $("#nip");
      if (nipField.val() !== "" && !isValidNIP(nipField.val())) {
        nipField.addClass("error");
        if (!nipField.next(".error-message").length) {
          nipField.after(
            '<span class="error-message">' +
              wc_optima_validation.nip_format +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate REGON if provided
      var regonField = $("#regon");
      if (regonField.val() !== "" && !isValidREGON(regonField.val())) {
        regonField.addClass("error");
        if (!regonField.next(".error-message").length) {
          regonField.after(
            '<span class="error-message">' +
              wc_optima_validation.regon_format +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate email
      var emailField = $("#email");
      if (emailField.val() !== "" && !isValidEmail(emailField.val())) {
        emailField.addClass("error");
        if (!emailField.next(".error-message").length) {
          emailField.after(
            '<span class="error-message">' +
              wc_optima_validation.email +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate password strength
      var passwordField = $("#password");
      if (
        passwordField.val() !== "" &&
        !isStrongPassword(passwordField.val())
      ) {
        passwordField.addClass("error");
        if (!passwordField.next(".error-message").length) {
          passwordField.after(
            '<span class="error-message">' +
              wc_optima_validation.password_strength +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate password confirmation
      var passwordConfirmField = $("#password_confirm");
      if (
        passwordConfirmField.val() !== "" &&
        passwordConfirmField.val() !== passwordField.val()
      ) {
        passwordConfirmField.addClass("error");
        if (!passwordConfirmField.next(".error-message").length) {
          passwordConfirmField.after(
            '<span class="error-message">' +
              wc_optima_validation.password_match +
              "</span>"
          );
        }
        isValid = false;
      }

      // Validate terms checkbox
      var termsCheckbox = $("#terms");
      if (!termsCheckbox.is(":checked")) {
        termsCheckbox.parent().addClass("error");
        if (!termsCheckbox.parent().next(".error-message").length) {
          termsCheckbox
            .parent()
            .after(
              '<span class="error-message">' +
                wc_optima_validation.required +
                "</span>"
            );
        }
        isValid = false;
      } else {
        termsCheckbox.parent().removeClass("error");
        termsCheckbox.parent().next(".error-message").remove();
      }

      if (!isValid) {
        e.preventDefault();
      }
    });
  }

  // Helper functions
  function isValidEmail(email) {
    var re =
      /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(String(email).toLowerCase());
  }

  function isStrongPassword(password) {
    // At least 8 characters, one uppercase letter, one lowercase letter, and one number
    var re = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
    return re.test(password);
  }

  function isValidNIP(nip) {
    nip = nip.replace(/[^0-9]/g, "");

    if (nip.length !== 10) {
      return false;
    }

    // NIP validation algorithm
    var weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    var sum = 0;

    for (var i = 0; i < 9; i++) {
      sum += parseInt(nip.charAt(i)) * weights[i];
    }

    var checkDigit = sum % 11;
    if (checkDigit === 10) {
      checkDigit = 0;
    }

    return checkDigit === parseInt(nip.charAt(9));
  }

  function isValidREGON(regon) {
    regon = regon.replace(/[^0-9]/g, "");

    if (regon.length !== 9 && regon.length !== 14) {
      return false;
    }

    if (regon.length === 9) {
      // 9-digit REGON validation algorithm
      var weights = [8, 9, 2, 3, 4, 5, 6, 7];
      var sum = 0;

      for (var i = 0; i < 8; i++) {
        sum += parseInt(regon.charAt(i)) * weights[i];
      }

      var checkDigit = sum % 11;
      if (checkDigit === 10) {
        checkDigit = 0;
      }

      return checkDigit === parseInt(regon.charAt(8));
    } else {
      // 14-digit REGON validation algorithm
      var weights = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
      var sum = 0;

      for (var i = 0; i < 13; i++) {
        sum += parseInt(regon.charAt(i)) * weights[i];
      }

      var checkDigit = sum % 11;
      if (checkDigit === 10) {
        checkDigit = 0;
      }

      return checkDigit === parseInt(regon.charAt(13));
    }
  }
});
