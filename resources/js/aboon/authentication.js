/**
 * Two factor authentification
 * @author Mirko Baffa <mirko@comotive.ch>
 */
var AboonAuth = {
  /**
   * The email input
   */
  input: jQuery('input[name="username"]'),

  /**
   * Set to true if the content of the input field changes
   */
  inputUpdate: false,

  /**
   * init the auth
   */
  initialize: function () {
    if (AboonAuth.input.length > 0) {
      AboonAuth.setupInput();
    }
  },

  /**
   * Register the event on the email input field
   */
  setupInput: function () {
    if (AboonAuth.input.val() !== '') {
      AboonAuth.inputUpdate = true;
      AboonAuth.validate();
    }

    AboonAuth.input.on('change', function () {
      AboonAuth.inputUpdate = true;
    });

    AboonAuth.input.on('blur', function () {
      AboonAuth.validate();
    });
  },

  /**
   * Validate the input and run ajax
   */
  validate: function () {
    var val = AboonAuth.input.val();
    var sbumitBtn = jQuery('button[name="login"]');

    if (AboonAuth.inputUpdate && val.length > 0) {
      AboonAuth.inputUpdate = false;

      sbumitBtn.prop('disabled', true);

      jQuery.post(
        aboonAjax.ajaxurl,
        {
          action: 'checkUserAuth',
          data: {
            email: val
          },
        },
        function (response) {
          sbumitBtn.prop('disabled', false);

          if (response) {
            AboonAuth.generateAuthfield();
          }
        }
      );
    }
  },

  /**
   * Generate the text and auth field html
   */
  generateAuthfield: function () {
    if (jQuery('.aboon-2f-auth-container').length === 0) {
      var getForm = jQuery('.woocommerce-form-login'); // TBD
      var html = '<div class="aboon-2f-auth-container woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">' +
        '<p>Der Authentifizierungs-Code wurde soeben an Ihre E-Mail-Adresse gesendet.</p>' +
        '<input type="text" id="aboon-2f-auth" name="' + aboonAjax.inputName + '" placeholder="Authentifizierungs-Code" required>' +
        '</div>';

      getForm.find('#password').closest('.woocommerce-form-row').after(html);
    }
  }
}

// Load on dom loaded
jQuery(function () {
  AboonAuth.initialize();
});
