/**
 * Registration form validation script
 */
jQuery(document).ready(function ($) {
  // Teksty dla przełącznika widoczności hasła
  const passwordVisibilityTexts = {
    show: "Pokaż hasło",
    hide: "Ukryj hasło",
  };

  // Password visibility toggle functionality
  $(".password-toggle-icon").on("click", function () {
    const passwordField = $(this).siblings("input");
    const currentType = passwordField.attr("type");

    // Toggle between password and text type
    if (currentType === "password") {
      passwordField.attr("type", "text");
      $(this).addClass("show-password");
      $(this).attr("title", passwordVisibilityTexts.hide);
      // Dodaj komunikat informacyjny
      if (!$(this).parent().next(".password-visibility-message").length) {
        $(this)
          .parent()
          .after('<span class="password-visibility-message">Hasło jest teraz widoczne</span>');
        // Ukryj komunikat po 2 sekundach
        setTimeout(function () {
          $(".password-visibility-message").fadeOut(300, function () {
            $(this).remove();
          });
        }, 2000);
      }
    } else {
      passwordField.attr("type", "password");
      $(this).removeClass("show-password");
      $(this).attr("title", passwordVisibilityTexts.show);
      if (!$(this).parent().next(".password-visibility-message").length) {
        $(this)
          .parent()
          .after('<span class="password-visibility-message">Hasło jest teraz ukryte</span>');
        // Ukryj komunikat po 2 sekundach
        setTimeout(function () {
          $(".password-visibility-message").fadeOut(300, function () {
            $(this).remove();
          });
        }, 2000);
      }
    }
  });

  // Inicjalizacja tooltipów
  $(".password-toggle-icon").each(function () {
    $(this).attr("title", passwordVisibilityTexts.show);
  });

  // B2C Registration form validation
  if ($("#wc-optima-b2c-registration-form").length) {
    const b2cForm = $("#wc-optima-b2c-registration-form");

    // Field validation on blur
    b2cForm.find("input[required]").on("blur", function () {
      validateRequiredField($(this));
    });

    // Email validation on blur
    $("#email").on("blur", function () {
      validateEmailField($(this));
    });

    // Password strength validation on blur
    $("#password").on("blur", function () {
      validatePasswordStrength($(this));
    });

    // Password confirmation validation on blur
    $("#password_confirm").on("blur", function () {
      validatePasswordMatch($(this), $("#password"));
    });

    // Terms checkbox validation on change
    $("#terms").on("change", function () {
      validateTermsCheckbox($(this));
    });

    // Form submission validation (fallback)
    b2cForm.on("submit", function (e) {
      var isValid = true;

      // Validate all required fields
      $(this)
        .find("input[required]")
        .each(function () {
          if (!validateRequiredField($(this))) {
            isValid = false;
          }
        });

      // Validate email
      if (!validateEmailField($("#email"))) {
        isValid = false;
      }

      // Validate password strength
      if (!validatePasswordStrength($("#password"))) {
        isValid = false;
      }

      // Validate password confirmation
      if (!validatePasswordMatch($("#password_confirm"), $("#password"))) {
        isValid = false;
      }

      // Validate terms checkbox
      if (!validateTermsCheckbox($("#terms"))) {
        isValid = false;
      }

      if (!isValid) {
        e.preventDefault();
      }
    });
  }

  // B2B Registration form validation
  if ($("#wc-optima-b2b-registration-form").length) {
    const b2bForm = $("#wc-optima-b2b-registration-form");

    // Field validation on blur for required fields
    b2bForm.find("input[required]").on("blur", function () {
      validateRequiredField($(this));
    });

    // Email validation on blur
    $("#email").on("blur", function () {
      validateEmailField($(this));
    });

    // NIP validation on blur
    $("#nip").on("blur", function () {
      validateNIP($(this));
    });

    // No validation for REGON field
    $("#regon").on("blur", function () {
      $(this).removeClass("error");
      $(this).next(".error-message").remove();
    });

    // Password strength validation on blur
    $("#password").on("blur", function () {
      validatePasswordStrength($(this));
    });

    // Password confirmation validation on blur
    $("#password_confirm").on("blur", function () {
      validatePasswordMatch($(this), $("#password"));
    });

    // Terms checkbox validation on change
    $("#terms").on("change", function () {
      validateTermsCheckbox($(this));
    });

    // NIP verification button
    $("#verify-company").on("click", function () {
      var nip = $("#nip")
        .val()
        .replace(/[^0-9]/g, "");

      if (nip.length !== 10) {
        $("#company-verification-status").html(
          '<span class="error-message">' + wc_optima_validation.nip_format + "</span>",
        );
        return;
      }

      $("#company-verification-status").html(
        '<span class="verifying">' + wc_optima_validation.verify_company + "</span>",
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
              '<span class="success">' + wc_optima_validation.company_verified + "</span>",
            );

            // Fill company data
            if (response.data) {
              // Przypisz dane firmy do pól formularza i ustaw je jako tylko do odczytu
              if (response.data.name) {
                $("#company_name").val(response.data.name).attr("readonly", true);
              }
              if (response.data.regon) {
                $("#regon").val(response.data.regon).attr("readonly", true);
              }
              if (response.data.address) {
                $("#address").val(response.data.address).attr("readonly", true);
              }
              if (response.data.postcode) {
                $("#postcode").val(response.data.postcode).attr("readonly", true);
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
                  "</button></div>",
              );

              // Obsługa przycisku do odblokowania pól
              $("#unlock-fields").on("click", function () {
                $("input.verified-field").removeAttr("readonly").removeClass("verified-field");
                $(".verified-info").remove();
                $("#company-verification-status").append(
                  '<div class="warning-info">' +
                    wc_optima_validation.fields_unlocked_warning +
                    "</div>",
                );
              });

              // Dodaj informacje debugowania, jeśli są dostępne
              if (response.data.debug_logs && response.data.debug_logs.length > 0) {
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
            if (response.data && response.data.debug_logs && response.data.debug_logs.length > 0) {
              debugInfo =
                '<div class="debug-info"><h4>' +
                wc_optima_validation.debug_info_title +
                "</h4><pre>" +
                JSON.stringify(response.data.debug_logs, null, 2) +
                "</pre></div>";
            }

            $("#company-verification-status").html(
              '<span class="error-message">' + errorMessage + "</span>" + debugInfo,
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
                if (response.data.debug_logs && response.data.debug_logs.length > 0) {
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
            '<span class="error-message">' + errorMessage + "</span>" + debugInfo,
          );
        },
      });
    });

    // Form validation on submit (fallback)
    b2bForm.on("submit", function (e) {
      var isValid = true;

      // Validate all required fields
      $(this)
        .find("input[required]")
        .each(function () {
          if (!validateRequiredField($(this))) {
            isValid = false;
          }
        });

      // Validate NIP
      if (!validateNIP($("#nip"))) {
        isValid = false;
      }

      // REGON validation removed - no validation needed

      // Validate email
      if (!validateEmailField($("#email"))) {
        isValid = false;
      }

      // Validate password strength
      if (!validatePasswordStrength($("#password"))) {
        isValid = false;
      }

      // Validate password confirmation
      if (!validatePasswordMatch($("#password_confirm"), $("#password"))) {
        isValid = false;
      }

      // Validate terms checkbox
      if (!validateTermsCheckbox($("#terms"))) {
        isValid = false;
      }

      if (!isValid) {
        e.preventDefault();
      }
    });
  }

  // Validation functions
  function validateRequiredField(field) {
    if (field.val() === "") {
      field.addClass("error");
      if (!field.next(".error-message").length) {
        field.after('<span class="error-message">' + wc_optima_validation.required + "</span>");
      }
      return false;
    } else {
      field.removeClass("error");
      field.next(".error-message").remove();
      return true;
    }
  }

  function validateEmailField(field) {
    if (field.val() === "") {
      return field.prop("required") ? validateRequiredField(field) : true;
    }

    if (!isValidEmail(field.val())) {
      field.addClass("error");
      if (!field.next(".error-message").length) {
        field.after('<span class="error-message">' + wc_optima_validation.email + "</span>");
      }
      return false;
    } else {
      field.removeClass("error");
      field.next(".error-message").remove();
      return true;
    }
  }

  function validatePasswordStrength(field) {
    if (field.val() === "") {
      return field.prop("required") ? validateRequiredField(field) : true;
    }

    if (!isStrongPassword(field.val())) {
      field.addClass("error");
      if (!field.next(".error-message").length) {
        field.after(
          '<span class="error-message">' + wc_optima_validation.password_strength + "</span>",
        );
      }
      return false;
    } else {
      field.removeClass("error");
      field.next(".error-message").remove();
      return true;
    }
  }

  function validatePasswordMatch(confirmField, passwordField) {
    if (confirmField.val() === "") {
      return confirmField.prop("required") ? validateRequiredField(confirmField) : true;
    }

    if (confirmField.val() !== passwordField.val()) {
      confirmField.addClass("error");
      if (!confirmField.next(".error-message").length) {
        confirmField.after(
          '<span class="error-message">' + wc_optima_validation.password_match + "</span>",
        );
      }
      return false;
    } else {
      confirmField.removeClass("error");
      confirmField.next(".error-message").remove();
      return true;
    }
  }

  function validateTermsCheckbox(field) {
    if (!field.is(":checked")) {
      field.parent().addClass("error");
      if (!field.parent().next(".error-message").length) {
        field
          .parent()
          .after('<span class="error-message">' + wc_optima_validation.required + "</span>");
      }
      return false;
    } else {
      field.parent().removeClass("error");
      field.parent().next(".error-message").remove();
      return true;
    }
  }

  function validateNIP(field) {
    if (field.val() === "") {
      return field.prop("required") ? validateRequiredField(field) : true;
    }

    if (!isValidNIP(field.val())) {
      field.addClass("error");
      if (!field.next(".error-message").length) {
        field.after('<span class="error-message">' + wc_optima_validation.nip_format + "</span>");
      }
      return false;
    } else {
      field.removeClass("error");
      field.next(".error-message").remove();
      return true;
    }
  }

  function validateREGON(field) {
    if (field.val() === "") {
      return true; // REGON is optional
    }

    if (!isValidREGON(field.val())) {
      field.addClass("error");
      if (!field.next(".error-message").length) {
        field.after('<span class="error-message">' + wc_optima_validation.regon_format + "</span>");
      }
      return false;
    } else {
      field.removeClass("error");
      field.next(".error-message").remove();
      return true;
    }
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

