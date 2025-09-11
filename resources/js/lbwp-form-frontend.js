/**
 * The frontend JS for lbwp form
 */
var LbwpForm = {
  /**
   * @var bool to make sure that only one event can run conditions at a time
   */
  runningConditions: false,
  /**
   * @var bool to make sure moveing fields is done only once (rare cases trigger "initialize" multiple times)
   */
  movedEmailFields: false,
  /**
   * @var int id for the timeout to run invisibility update
   */
  invisibleUpdateTimeout: 0,
  /**
   * @var array of all input ids
   */
  allInputIds: [],

  /**
   * Called on page load, registering events and such
   */
  initialize: function () {
    LbwpForm.moveEmailField();
    LbwpForm.preventDoubleClick();
    LbwpForm.handleUploadFields();
    LbwpForm.handleItemWrapping();
    LbwpForm.hideInvisibleFields();
    LbwpForm.handleFieldConditions();
    LbwpForm.handleFieldCalculation();
    LbwpForm.handleSurveyResults();
    LbwpForm.updateInvisibleFieldInfo();
    LbwpForm.checkForPayrexxButton();
    LbwpForm.checkPreselection();
    LbwpForm.setupMutlisite();
    LbwpForm.setupNonceRenewal();
  },

  /**
   * Checks if survey results should be loaded
   */
  handleSurveyResults: function () {
    let formContainer = jQuery('.lbwp-form-override');
    let lbwpForm = formContainer.find('form');
    let surveyActive = lbwpForm.data('show-survey-results') == 1;
    let showSurvey = formContainer.find('.lbwp-form-message.success').length === 1;
    // If active, and we can show results, load them
    if (surveyActive && showSurvey) {
      let formKey = lbwpForm.prop('id');
      jQuery.post('/wp-json/lbwp/form/survey/', { id: formKey }, function (response) {
        if (response.status == 'success' && response.html.length > 0) {
          // Show the survey results after the form
          let surveyContainer = jQuery('<div class="lbwp-form-survey lbwp-form-survey-results"></div>');
          surveyContainer.html(response.html);
          // Append the survey results right after the lbwpForm element
          lbwpForm.after(surveyContainer);
        }
      });
    }
  },

  /**
   * Make sure nonces are generated and renewed
   */
  setupNonceRenewal : function(){
    // We don't need this on comment forms
    if (jQuery('#lbwpForm-comment').length > 0) {
      return;
    }
    // Refresh form nonce every 15 minutes (nonce is valid 45 minutes)
    LbwpForm.updateFormNonce();
    setInterval(LbwpForm.updateFormNonce, 1800000);
    // On tab visibility change to visible, update the nonce
    document.addEventListener('visibilitychange', function(){
      if (document.visibilityState === 'visible') {
        LbwpForm.updateFormNonce();
      }
    });
  },

  /**
   * This moves the deprecated email field
   */
  moveEmailField: function () {
    setTimeout(function () {
      if (!LbwpForm.movedEmailFields) {
        LbwpForm.movedEmailFields = true;
        // Move the email field out of the way
        var field = jQuery('.field_email_to');
        field.css('top', '-500px');
        field.css('right', '-5000px');
        // If it doesn't already start with xFc, add it
        if (field.length > 0 && field.val().indexOf('xFc') == -1) {
          field.val('xFc' + field.val());
        }
      }
    }, 750);
  },

  /**
   * Generate actual nonces for forms
   */
  updateFormNonce: function () {
    jQuery('form.lbwp-form').each(function () {
      var field = jQuery(this).find('input[name=form-nonce]');
      jQuery.post(lbwpFormNonceRetrievalUrl + '/wp-json/lbwp/form/nonce/', function (response) {
        field.val(response.nonce);
      });
    });
  },

  /**
   * Prevents double click and double/multi sending of forms
   */
  preventDoubleClick: function () {
    var sendButtons = jQuery('.lbwp-form input[name=lbwpFormSend]');

    // On click, set the button as disabled and add a disabled class
    sendButtons.on('mousedown', function () {
      var button = jQuery(this);
      LbwpForm.lockDownForm(button);
      // Check (twice!) if we need to release it, due to errors
      setTimeout(LbwpForm.checkFormEnable, 250);
      setTimeout(LbwpForm.checkFormEnable, 1000);
      return true;
    });
  },

  /**
   * Locks the form by locking the button
   */
  lockDownForm: function (button) {
    button.prop('disabled', 'disabled');
    button.attr('disabled', 'disabled');
    button.addClass('lbwp-button-disabled');
  },

  /**
   * Unlocks the form by unlocking the button
   */
  unlockForm: function (button) {
    button.removeProp('disabled');
    button.removeAttr('disabled');
    button.removeClass('lbwp-button-disabled');
  },

  /**
   * Checks if forms need to be re-enabled
   */
  checkFormEnable: function () {
    jQuery('.lbwp-form').each(function () {
      var form = jQuery(this);
      if (form.hasClass('validation-errors')) {
        var button = form.find('input[name=lbwpFormSend]');
        LbwpForm.unlockForm(button);
      }
    })
  },

  /**
   * Forms with the lbwp-wrap-items get their items wrapped in an additional div
   * This allows for better styling possibilities (As two col forms for example)
   */
  handleItemWrapping: function () {
    jQuery('.lbwp-wrap-items').each(function () {
      // Find all form elements
      jQuery(this).find('.forms-item').each(function () {
        var item = jQuery(this);
        var classes = item.attr('class');
        // Remove the forms item class, but add a wrapper class instead
        classes = classes.replace('forms-item', 'forms-item-wrapper');
        item.wrap('<div class="' + classes + '"></div>');
      });
    })
  },

  handleFieldCalculation: function () {
    // Get a list of all form field ids that can be part of a calculation
    var inputs = [];
    jQuery('.lbwp-form [id]').each(function () {
      LbwpForm.allInputIds.push(jQuery(this).attr('id'));
    });

    // Make all auto-calc fields readonly
    jQuery('[data-auto-calc-on=1]').attr('readonly', 'readonly');

    // Re-calc all calculations on blur of any element
    jQuery('.lbwp-form input, .lbwp-form select').on('keyup', function () {
      LbwpForm.updateAllAutoCalcFields();
    });
  },

  /**
   * Updates all calculation fields accordingly
   */
  updateAllAutoCalcFields: function () {
    // Prepare replacer array with according numeric values
    var replacers = {};
    jQuery.each(LbwpForm.allInputIds, function (key, value) {
      var parsed = jQuery('#' + value).val().replace(',', '.');
      var number = parseFloat(parsed);
      replacers['field:' + value] = !isNaN(number) ? number : 0;
    });

    // Update every found calculation field
    jQuery('[data-auto-calc-on=1]').each(function () {
      LbwpForm.updateAutoCalcField(jQuery(this), replacers);
    });
  },

  /**
   * Update an automatically calculated field
   * @param field the DOM field element that needs recalculation
   * @param replacers the list of replacement variables
   */
  updateAutoCalcField: function (field, replacers) {
    var result = 0;
    var syntax = field.data('auto-calc-syntax');
    // Replace all fields with the according replacer variables
    jQuery.each(replacers, function (field, number) {
      syntax = syntax.replace(field, number);
    });

    // Generate the result and put it into the field
    try {
      result = eval(syntax);
    } catch (e) {
      console.log('error in syntax: ' + syntax);
    }
    result = isNaN(result) ? 0 : result;
    result = Math.round(result * 100) / 100;
    field.val(result);
  },

  /**
   * Handles all kinds of field conditions and their effects/actions
   */
  handleFieldConditions: function () {
    if (jQuery('.lbwp-form.ignore-conditions').length > 0) {
      return true;
    }

    var selector = '.lbwp-form input, .lbwp-form select, .lbwp-form textarea';
    // Run field conditions whenever an element has changed
    jQuery(selector).on('keyup', function () {
      LbwpForm.runConditions('changed', jQuery(this));
    });
    jQuery(selector).on('change', function () {
      let input = jQuery(this);
      /* not used anymore
      if (LbwpForm.eventuallyPreventNonChange(input)) {
        return;
      } */
      LbwpForm.runConditions('changed', input);
    });
    jQuery('.lbwp-form input:not([type=radio]), .lbwp-form textarea').on('focus', function () {
      LbwpForm.runConditions('focused', jQuery(this));
    });

    // Initially, trigger a change on every data-field element
    if (jQuery('.lbwp-form-message.success').length === 0) {
      skipLbwpFormValidation = true;
      jQuery('[data-field]').trigger('change');
      skipLbwpFormValidation = false;
    }
    // Set the current select state on radios specifically
    /* not used anymore
    jQuery('.lbwp-form .forms-item.radio-field').each(function() {
      jQuery(this).attr('data-state', '');
    });*/
  },

  /**
   * @param input
   * @returns {boolean}
   */
  eventuallyPreventNonChange : function(input) {
    // Special handling for radio, as they trigger change, even when not changed
    if (input.prop('type') == 'radio') {
      let nativeState = input.val();
      let wrapperElement = input.closest('.radio-field');
      let lastKnownState = wrapperElement.attr('data-state');
      console.log(nativeState, lastKnownState)
      if (nativeState != lastKnownState) {
        wrapperElement.attr('data-state', nativeState);
      } else {
        // State is identical, skip doing conditions as nothing changed
        return true;
      }
    }

    return false;
  },

  /**
   * Run condition framework on all fields
   * @param type the event "changed" or "focused"
   * @param element the field name that has a change or focus
   */
  runConditions: function (type, element) {
    var condition = {};
    var test = false;
    var field = element.data('field');
    var formId = element.closest('.lbwp-form').attr('id');
    var item = null, value = null;
    var conditions = LbwpForm.getConditions(formId);

    // Skip execution if already running in another thread
    if (LbwpForm.runningConditions) {
      return false;
    }

    // Set the thread to running
    LbwpForm.runningConditions = true;

    // Traverse all conditions, only look at those who have a condition on the changed or focused form
    jQuery.each(conditions, function (target, conditions) {
      for (var i in conditions) {
        condition = conditions[i];
        if (field == condition.field) {
          // OK now we know what conditions need to match
          var matches = 0;
          var goal = (conditions[0].type == 'and') ? conditions.length : 1;
          // Check all conditions
          for (var j in conditions) {
            condition = conditions[j];
            // Some conditions need to be run on change, one only on focus
            if (condition.operator == 'focused' && type == 'focused') {
              // Get the item and immediately run the action
              item = jQuery('[data-field=' + condition.target + ']');
              LbwpForm.triggerItemAction(item, condition.action);
            } else if (condition.operator != 'focused' && type == 'changed') {
              // Get the item to compare its value
              item = jQuery('[data-field="' + condition.field + '"]');
              value = item.val();
              // Handle null, as safari provides "null" instead of empty string for empty values
              if (value == null) value = '';

              // Special value compare method for certain types of inputs
              switch (item.attr('type')) {
                case 'radio':
                  value = jQuery('[data-field="' + condition.field + '"]:checked').val();
                  if (typeof (value) == 'undefined') value = '';
                  break;
                case 'checkbox':
                  value = '';
                  jQuery('[data-field="' + condition.field + '"]:checked').each(function () {
                    value += jQuery(this).val() + ' ';
                  });
                  // Make sure to trim last space for single comparisons
                  value = value.trim();
                  break;
              }

              // If the value is empty, but we have more/lessthan condition, set 0
              if ((condition.operator == 'morethan' || condition.operator == 'lessthan') && value.length == 0) {
                value = 0;
              }

              // Run the tests, and only then, run the action
              switch (condition.operator) {
                case 'is':
                  test = (value == condition.value);
                  break;
                case 'not':
                  test = (value != condition.value);
                  break;
                case 'contains':
                  test = (value.indexOf(condition.value) != -1);
                  break;
                case 'absent':
                  test = (value.indexOf(condition.value) == -1);
                  break;
                case 'morethan':
                  test = (parseInt(value) >= parseInt(condition.value));
                  break;
                case 'lessthan':
                  test = (parseInt(value) <= parseInt(condition.value));
                  break;
              }

              // If the test didn't fail, trigger the target
              if (test) {
                matches++;
              }
            }
          }

          // If we reached goal matches, trigger action or trigger reverse if not
          if (matches >= goal) {
            LbwpForm.triggerItemAction(jQuery('[data-field="' + condition.target + '"]'), condition.action);
          } else {
            if (typeof (condition.reverse) == 'string' && condition.reverse.length > 0) {
              LbwpForm.triggerItemAction(jQuery('[data-field="' + condition.target + '"]'), condition.reverse);
            }
          }
        }
      }
    });

    // Finished running conditions
    LbwpForm.runningConditions = false;
    LbwpForm.updateInvisibleFieldInfo();
    // Let developers hook in here
    jQuery(document.body).trigger('lbwp_conditional_fields_loaded');
    return true;
  },

  /**
   * Trigger an action on an item or its container
   * @param item the element to be triggered
   * @param action the action to be executed on the element (or its container)
   */
  triggerItemAction: function (item, action) {
    switch (action) {
      case 'show':
        // Show the item and make sure to remove an eventual error class
        item.closest('.forms-item').show().removeClass('lbwp-form-error');
        break;
      case 'hide':
        // On hide, also remove an eventual error message
        var container = item.closest('.forms-item');
        var next = container.next();
        container.hide().removeClass('lbwp-form-error');
        // Now if theres an error message next to it
        if (next.hasClass('lbwp-form-error') && next.hasClass('validate-message')) {
          next.remove();
        }
      // OK, so hide needs to truncate as well... so we just skip the break here
      //break;
      case 'truncate':
        if (item.prop('tagName').toLowerCase() == 'select') {
          item.find('option').removeAttr('selected');
        } else if (item.prop('tagName').toLowerCase() == 'input' && item.attr('type') == 'radio') {
          item.removeAttr('checked');
        } else if (item.prop('tagName').toLowerCase() == 'input' && item.attr('type') == 'checkbox') {
          item.removeAttr('checked');
        } else {
          item.val('');
        }
        // Also trigger a change on it, as truncation might fire other show/hide events
        LbwpForm.runningConditions = false;
        item.trigger('change');
        break;
    }
  },

  /**
   * Update infos on invisible fields to use in backend validation
   */
  updateInvisibleFieldInfo: function () {
    if (jQuery('.lbwp-form.ignore-invisible-fields').length > 0) {
      return true;
    }
    if (LbwpForm.invisibleUpdateTimeout > 0) {
      clearTimeout(LbwpForm.invisibleUpdateTimeout);
    }

    LbwpForm.invisibleUpdateTimeout = setTimeout(function () {
      jQuery('.lbwp-form').each(function () {
        var form = jQuery(this);
        var list = '';
        form.find('[data-field]:not(:visible)').each(function () {
          var field = jQuery(this);
          // Make an exception for hidden fields
          if (field.prop('tagName').toLowerCase() == 'input' && field.attr('type') == 'hidden') {
            return true;
          }
          // Exception if the container of it is visible. This is needed because
          // checkboxes / radios are sometimes made invisible to style them
          if (field.parent().is(':visible')) {
            return true;
          }
          var id = field.data('field');
          if (list.indexOf(id) == -1) {
            list += id + ',';
          }
        });
        form.find('[name=lbwpHiddenFormFields]').val(list.substring(0, list.length - 1));
      });
    }, 100);
  },

  /**
   * Gracefully return the form field conditions or an empty array
   * @returns {Array}
   */
  getConditions: function (formId) {
    if (typeof (lbwpFormFieldConditions[formId]) == 'object') {
      return lbwpFormFieldConditions[formId];
    }
    return [];
  },

  /**
   * Hide fields that should be invisible
   */
  hideInvisibleFields: function () {
    jQuery('[data-init-invisible=1]').each(function () {
      var element = jQuery(this);
      element.closest('.forms-item').hide();
    });
  },

  /**
   * Handle all upload fields in a form
   */
  handleUploadFields: function () {
    jQuery('.lbwp-form .upload-field').each(function () {
      var field = jQuery(this);
      var file = field.find('input[type=file]');
      // Skip special features that only work for single, when multiple is configured
      if (file.prop('multiple') === true) {
        return;
      }

      field.find('.default-container').dmUploader(
        LbwpForm.getUploaderObject(field)
      );

      // Unset required, when we reloaded and the file is still present
      var file = field.find('input[type=file]');
      var hidden = field.find('input[type=hidden]');
      if (hidden.val().length > 0) {
        file.removeAttr('required');
      }
    });
  },

  /**
   * Get the uploader configuration for the field
   */
  getUploaderObject: function (field) {
    return {
      url: field.find('.upload-state-container').data('url-prefix') + '/wp-content/plugins/lbwp/views/api/upload.php',
      multiple: false,
      /**
       * Handle a new file that is added, reset old one possibly
       * @param id the internal id of the upload object
       * @param file the file object containing all file infos
       */
      onNewFile: function (id, file) {
        var container = jQuery(this);
        var state = container.find('.upload-state-container');
        // Reset the hidden field as a new upload starts
        container.find('input[type=hidden]').val('');
        // Display the upload field
        container.closest('.upload-field').removeClass('file-uploaded');
        // Update the state of the file
        state.addClass('in-progress');
        var info = state.find('.progress-text');
        info.text(info.data('template').replace('{filename}', file.name));
        var number = state.find('.progress-number');
        number.text(number.data('template').replace('{number}', '0'));
        state.find('.progress .progress-bar').css('width', '0%');
        // Lockdown of the form
        var button = container.closest('.lbwp-form').find('input[name=lbwpFormSend]');
        LbwpForm.lockDownForm(button);
      },
      /**
       * Update the progress bar and info
       * @param id the internal id of the upload object
       * @param percent the percentage of progress
       */
      onUploadProgress: function (id, percent) {
        var container = jQuery(this);
        var state = container.find('.upload-state-container');
        var number = state.find('.progress-number');
        number.text(number.data('template').replace('{number}', percent));
        state.find('.progress .progress-bar').css('width', percent + '%');
      },
      /**
       * @param id the internal id of the upload object
       * @param data the response data from the upload url
       */
      onUploadSuccess: function (id, data) {
        var container = jQuery(this);
        var state = container.find('.upload-state-container');
        // Set the hidden field to the current complete upload
        container.find('input[type=hidden]').val(data.url);

        // Display preview if setting is set
        let preview = state.siblings('.upload-preview-container');
        if(preview.length > 0){
          preview.find('img').attr('src', data.url);
          preview.show();
        }

        // Update the state of the file as finished
        state.removeClass('in-progress');
        state.find('.progress-text').html(data.message + '. <a href="" class="resetUpload">Datei ersetzen</a> oder <a href="" class="deleteUpload">löschen</a>.');
        state.find('.progress-number').text('');
        state.find('.progress .progress-bar').css('width', '0%');

        // On success, remove required attribute and errors if given
        if (data.status == 'success') {
          container.closest('.upload-field').addClass('file-uploaded');
          var inputField = container.find('input[type=file]');
          inputField.removeAttr('required');
          container.find('.lbwp-form-error').remove();
        }
        // Unlock the form
        var button = container.closest('.lbwp-form').find('input[name=lbwpFormSend]');
        LbwpForm.unlockForm(button);

        // Set the reset link/button
        state.find('.resetUpload').on('click', function (e) {
          e.preventDefault();
          var link = jQuery(this);
          var uploadCon = link.closest('.upload-field');

          // Remove preview
          let preview = state.siblings('.upload-preview-container');
          if(preview.length > 0){
            preview.find('img').attr('src', '');
            preview.hide();
          }

          uploadCon.removeClass('file-uploaded');
          // Click the actual upload to trigger browser file search
          uploadCon.find('input[type=file]').click();

          return false;
        });

        // Delete the upload file if desired
        state.find('.deleteUpload').on('click', function (e) {
          e.preventDefault();
          var link = jQuery(this);
          var uploadCon = link.closest('.upload-field');
          var hidden = uploadCon.find('input[type=hidden]');
          hidden.val('');
          uploadCon.removeClass('file-uploaded');
          state.find('.progress-text').html(''); // Reset the text
          return false;
        });
      },
      /**
       * Adds data to our post requests
       */
      extraData: function () {
        var field = jQuery(this).find('input[type=hidden]');
        return {
          cfgKey: field.data('cfg-key')
        };
      }
    };
  },

  /**
   * Disable the submit button if there is a payrexx payment button in the form
   */
  checkForPayrexxButton: function () {

    if (jQuery('.payrexx-payment').length > 0) {
      var button = jQuery('.lbwp-form').find('input[name=lbwpFormSend]');
      var payrexxBtn = jQuery('#payrexx-button');
      var getPayrexxData = jQuery('#payrexx-data');
      var payrexxData = getPayrexxData.attr('value');
      var variableAmountField = getPayrexxData.attr('data-amount-field');

      // get the form id
      var formId = button.closest('form').attr('id');

      // check if already payed
      var sessionPayment = sessionStorage.getItem(formId + '-payment'); // -> könnte somit mehrere leute anmeldent...

      button.closest('form').on('lbwpform:validated', function () {
        sessionStorage.removeItem(this.id + '-payment');
      });

      if (variableAmountField.length > 0) {
        var vaField = jQuery('#' + variableAmountField);
        vaField.on('change', function () {
          // Round to nearest 5 with 2 decimal
          var overrideAmount = (Math.round(Number(jQuery(this).val()) * 20) * 0.05).toFixed(2);
          if (overrideAmount <= 0) {
            overrideAmount = 1;
          }
          jQuery(this).val(overrideAmount);
          payrexxBtn.find('.payment_value').text(overrideAmount);
        });
      }

      if (sessionPayment == null) {
        // Lockdown of the form
        LbwpForm.lockDownForm(button);
        button.hide();

        payrexxBtn.on('click', function () {
          // First of all, trigger validation and don't proceed if it throws errors
          jQuery('.lbwp-form-message.error').remove();
          skipAfterValidationAutoSubmit = true;
          button.closest('form').trigger('lbwp:runvalidation');
          if (jQuery('.lbwp-form-message.error').length > 0) {
            return false;
          }
          // Make sure triggers to submit will work again after the check
          skipAfterValidationAutoSubmit = false;
          var allInputs = jQuery('.lbwp-form input');
          var inputFields = Array();
          jQuery.each(allInputs, function () {
            inputFields.push([jQuery(this).attr('name'), jQuery(this).val()]);
          });

          var ajaxData = {
            action: 'payrexx_payment',
            dataKey: payrexxData,
            invoiceId: false,
            fields: JSON.stringify(inputFields),
            variableAmountId: variableAmountField
          };

          jQuery.ajax({
            type: 'POST',
            url: '/wp-admin/admin-ajax.php',
            data: ajaxData,
            async: false,
            success: function (data) {
              payrexxBtn.attr('href', data['url']);
              payrexxBtn.off('click');
              paymentCheck = window.setInterval(function () {
                var status = null;
                var newData = {
                  action: 'payrexx_payment',
                  dataKey: payrexxData,
                  invoiceId: data['id']
                };

                jQuery.post('/wp-admin/admin-ajax.php', newData, function (data) {
                  status = data;
                  switch (status) {
                    case 'waiting':
                      payrexxBtn.addClass('waiting');
                      payrexxBtn.text('Warten auf Zahlungseingang');
                      break;
                    case 'cancelled':
                      payrexxBtn.addClass('failed');
                      window.clearInterval(paymentCheck);
                      break;
                    case 'declined':
                      payrexxBtn.addClass('failed');
                      window.clearInterval(paymentCheck);
                      break;
                    case 'confirmed':
                      window.clearInterval(paymentCheck);

                      payrexxBtn.addClass('completed');
                      button.show();
                      LbwpForm.unlockForm(button);
                      jQuery('#payrexx-result').attr('value', newData.invoiceId);
                      jQuery('input[name=lbwpFormSend]').trigger('click');
                      break;
                  }
                });
              }, 5000);
              return true;
            }
          });
        });
      }
    }
  },

  /**
   * Check for preselected radio and checkboxes
   */
  checkPreselection: function () {
    var prItems = jQuery('.preselect-first');
    if (prItems.length > 0) {
      jQuery.each(prItems, function () {
        var prItem = jQuery(this);
        var inputTypes = ['checkbox', 'radio'];

        for (var i = 0; i < inputTypes.length; i++) {
          var inputType = jQuery(this);
          var fields = prItem.find('input[type="' + inputTypes[i] + '"]');

          if (fields.length > 0) {
            fields.eq(0).attr('checked', 'true');
          }
        }
      });
    }
  },

  setupMutlisite : function(){
    let form = jQuery('.lbwp-form__multisite');

    if(form.length > 0){
      let steps = form.siblings('.lbwp-form-steps');
      let nav = form.siblings('.lbwp-form-navigation');
      let nextBtn = nav.find('.next');
      let prevBtn = nav.find('.prev');

      LbwpForm.progressBar = jQuery('.lbwp-form-progress__bar');
      if(LbwpForm.progressBar.length > 0){
        LbwpForm.progressBar.css('width', 100 / form.find('.lbwp-form-page').length + '%');
      }else{
        LbwpForm.progressBar = false;
      }

      steps.find('.lbwp-form-step').click(function(){
        LbwpForm.multisiteGoToPage(jQuery(this), form);
      });

      prevBtn.click(() => {
        LbwpForm.multisiteSwitchPage(prevBtn, form, true);
      });

      nextBtn.click(() =>{
        LbwpForm.multisiteSwitchPage(nextBtn, form);
      });

      let allInputs = form.find('.lbwp-form-page .forms-item');
      console.log(allInputs);
      jQuery.each(allInputs, function(){
        let container = jQuery(this);

        if(container.hasClass('send-button')){
          return;
        }

        let input = container.find('input, select');

        input.on('keydown', function(e){
          if(e.which === 13){
            e.preventDefault();
          }
        })
      });

      form.find('[type="submit"]').hide();

      LbwpForm.handleTabNavigation();

      LbwpForm.handleLimitPages();

      LbwpForm.renameSteps();
    }
  },

  handleTabNavigation : function(){
    LbwpForm.fieldList = [];
    let prevButton = jQuery('.lbwp-form-navigation .prev');
    let nextButton = jQuery('.lbwp-form-navigation .next');
    let nextVisibleField = (field, page, direction)=>{
      while(!field.is(':visible')){
        let nextIndex = LbwpForm.fieldList[page].indexOf(field) + direction;

        if(nextIndex < 0){
          field = prevButton;
          break;
        }

        if(nextIndex >= LbwpForm.fieldList[page].length){
          field = nextButton;
          break;
        }

        field = LbwpForm.fieldList[page][nextIndex];
      }
      return field;
    }

    jQuery.each(jQuery('.lbwp-form-page'), function(page){
      LbwpForm.fieldList.push([]);

      jQuery.each(jQuery(this).find('input, select, textarea'), function(){
        let field = jQuery(this);

        if(
          field.attr('type') === 'hidden' ||
          field.attr('type') === 'submit' ||
          field.hasClass('field_email_to')
        ){
          return;
        }

        LbwpForm.fieldList[page].push(field);

        field.on('keydown', function(e) {
          if (e.which === 9) {
            e.preventDefault();

            let index = LbwpForm.fieldList[page].indexOf(field);
            let direction = (e.shiftKey ? (-1) : 1);

            if(index + direction > LbwpForm.fieldList[page].length - 1){
              nextButton.focus();
              return;
            }else if(index + direction < 0){
              prevButton.focus();
              return;
            }

            let nextField = LbwpForm.fieldList[page][index + direction];

            if(!nextField.is(':visible')){
              nextField = nextVisibleField(nextField, page, direction);
            }

            nextField.focus();

          }
        });
      });
    });

    prevButton.on('keydown', (e) => {
      if(e.which === 9 && !e.shiftKey){
        e.preventDefault();

        let fields = LbwpForm.fieldList[jQuery('.lbwp-form-page.current').attr('data-page') - 1]
        if(!fields[0].is(':visible')){
          nextVisibleField(fields[0], jQuery('.lbwp-form-page.current').attr('data-page') - 1, 1).focus();
        }else{
          fields[0].focus();
        }
      }
    });
    nextButton.on('keydown', (e) => {
      if(e.which === 9 && e.shiftKey){
        e.preventDefault();

        let fields = LbwpForm.fieldList[jQuery('.lbwp-form-page.current').attr('data-page') - 1]
        if(!fields[fields.length - 1].is(':visible')){
          nextVisibleField(fields[fields.length - 1], jQuery('.lbwp-form-page.current').attr('data-page') - 1, -1).focus();
        }else{
          fields[fields.length - 1].focus();
        }
      }
    });
  },

  multisiteSwitchPage : function(btn, form, backwards){
    backwards = backwards === true;
    let curPage = jQuery('.lbwp-form-page.current');
    let nextPage = backwards ? curPage.prev('.lbwp-form-page') : curPage.next('.lbwp-form-page');
    let numPages = form.find('.lbwp-form-page').length;
    let pageIndex = Number(curPage.attr('data-page'));

    if(btn.hasClass('next')){
      if(!LbwpForm.multisiteValidateFields()){
        return;
      }

      if(pageIndex === numPages){
        form.trigger('lbwp:runvalidation');
        return;
      }
    }

    curPage.removeClass('current');
    nextPage.addClass('current');

    jQuery('.lbwp-form-step.current').removeClass('current');
    let curStep = jQuery('.lbwp-form-step[data-page="' + (backwards ? pageIndex - 1 : pageIndex + 1) + '"]');
    curStep.addClass('current');

    if(pageIndex <= numPages){
      LbwpForm.handleLimitPages();
      form.css('transform', 'translateX(-' + ((backwards ? pageIndex - 2 : pageIndex) * 100 / numPages) + '%)');
      if(LbwpForm.progressBar !== false){
        LbwpForm.progressBar.css('width', 100 * ((backwards ? pageIndex - 2 : pageIndex) + 1) / numPages + '%');
      }

      let fields = LbwpForm.fieldList[nextPage.attr('data-page') - 1];

      if(backwards){
        for(let i = fields.length - 1; i >= 0; i--){
          if(fields[i].is(':visible')){
            fields[i].focus();
            break;
          }
        }
      }else{
        for(let i = 0; i<fields.length; i++){
          if(fields[i].is(':visible')){
            fields[i].focus();
            break;
          }
        }
      }

      let stepsContainer = curStep.closest('.lbwp-form-steps');
      let headerHeight = jQuery('.s03-header').length > 0 ? jQuery('.s03-header').height() : 0;
      window.scrollTo({
        behavior: 'smooth',
        top: stepsContainer.get(0).getBoundingClientRect().top - document.body.getBoundingClientRect().top - headerHeight,
      })
    }
  },

  multisiteGoToPage : function(step, form){
    let curPage = jQuery('.lbwp-form-page.current');
    let numPages = form.find('.lbwp-form-page').length;
    let curPageIndex = Number(curPage.attr('data-page'));
    let stepIndex = Number(step.attr('data-page'));

    if(stepIndex >= curPageIndex + 2){
      for(let i = curPageIndex; i < stepIndex; i++){
        if(form.find('.lbwp-form-page[data-page="' + i + '"]').attr('data-valid') !== 'true'){
          return;
        }
      }
    }

    // Trigger field validation only goind forwards
    if(curPageIndex < stepIndex && !LbwpForm.multisiteValidateFields()){
      return
    }

    jQuery('.lbwp-form-step.current').removeClass('current');
    step.addClass('current');
    curPage.removeClass('current');
    let nextPage = jQuery('.lbwp-form-page[data-page="' + stepIndex + '"]');
    nextPage.addClass('current');

    LbwpForm.handleLimitPages();
    form.css('transform', 'translateX(-' + ((stepIndex - 1) * 100 / numPages) + '%)');
    nextPage.find('.forms-item-wrapper:first-of-type').find('input, textarea, select').focus();

    let stepsContainer = step.closest('.lbwp-form-steps');
    let headerHeight = jQuery('.s03-header').length > 0 ? jQuery('.s03-header').height() : 0;
    window.scrollTo({
      behavior: 'smooth',
      top: stepsContainer.get(0).getBoundingClientRect().top - document.body.getBoundingClientRect().top - headerHeight,
    });
  },

  multisiteValidateFields : function(){
    let curPage = jQuery('.lbwp-form-page.current');
    let fields = curPage.find('input:not([type="submit"]):not([type="hidden"]):not(.field_email_to)[required], select:visible[required], textarea:visible[required]');

    // Filter fields that are not visible
    fields = fields.filter(function(){
      return jQuery(this).is(':visible');
    });

    curPage.closest('form').trigger('lbwp:runvalidation', [fields, true]);

    let valid = curPage.find('.lbwp-form-error:visible').length <= 0;

    if(valid){
      curPage.closest('form').siblings('.lbwp-form-message').remove();
    }

    curPage.attr('data-valid', valid);
    jQuery('.lbwp-form-step[data-page="' + curPage.attr('data-page') + '"]').attr('data-valid', valid);

    return valid;
  },

  handleLimitPages : function(){
    let curPage = Number(jQuery('.lbwp-form-page.current').attr('data-page'));
    let numPages = jQuery('.lbwp-form-page').length;
    let buttons = jQuery('.lbwp-form-navigation .prev, .lbwp-form-navigation .next');

    if(curPage === 1){
      buttons.eq(0).hide();
    }else{
      buttons.eq(0).show();
    }

    if(curPage >= numPages){
      buttons.eq(1).text(jQuery('.lbwp-form__multisite [type="submit"]').val());
    }else{
      buttons.eq(1).text(buttons.eq(1).val());
    }
  },

  renameSteps : function () {
    let pages = jQuery('.lbwp-form-page');

    jQuery.each(pages, function(){
      let page = jQuery(this);
      let pageName = page.attr('data-page-name');

      if(typeof pageName !== 'undefined' && pageName !== ''){
        jQuery('.lbwp-form-step[data-page="' + page.attr('data-page') + '"]').text(pageName);
      }
    });
  }
};

// Run the library on load
if (typeof (jQuery) != 'undefined') {
  jQuery(function () {
    LbwpForm.initialize();
  });
}