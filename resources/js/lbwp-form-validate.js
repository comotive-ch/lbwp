(function ($) {
  $.fn.validate = function (edits) {
    if (this.length > 1) { // checks if there is more than one form
      jQuery(this).each(function () {
        jQuery(this).validate(edits);
      });
      return this;
    }

    var form = this;
    form.selector = '#' + form.attr('id');
    var val = {};
    var edits = typeof edits == "object" ? edits : {}; // checks if edits is set as an object else generates an empty object
    var correctAll = "date, email, number, phone, tel, range, time, url, text, password, textarea"; // all form input types
    var isSubmitting = false;
    var settings = { // Set default settings
      defaultText: "Füllen Sie bitte das Feld korrekt aus",
      elem: { // Different form elements
        item: ".forms-item",
        label: ".default-label",
        inputbox: ".default-container"
      },
      fields: "input, select, textarea", // default fields to validate
      messageBoxClass: "validate-message",
      correct: true,
      onSubmit: validateForm, // This function will be triggered on submit
      msgClassPrefix: "lbwp-form-", // Prefix for message classes
      doBotScan: false,
      hideSendBtn: false
    };

    // If bot check should be used
    if (form.data('use-botcheck') == 1) {
      settings.doBotScan = true;
    }

    // If the send button shold be hidden until all required fields are filled out
    if(form.data('hide-send-button') == 1){
      settings.hideSendBtn = true;
      hideSubmitButton();
    }

    // Start the bot probaility scan (only for erneuerbar atm)
    if (settings.doBotScan) {
      initBotProbabilityCheck();
    }

    // Checks if custom edits are set and merge them in a new Array
    for (var key in settings) {
      val[key] = edits.hasOwnProperty(key) ? edits[key] : settings[key];
    }

    var prfx = val.msgClassPrefix;

    jQuery(form).addClass("validate");
    // Check if there is a submit button for triggering event
    if (jQuery(form.selector + " input[type=submit]").length != 0) {
      /*
      Problems with form submit, might be better on using "submit" here as well
      As of 13.11, trying to use "submit" ON TOP of mousedown/keydown (which is fired earlier)
      on "submit" handles if autocomplete is used OR [enter] is used on any of the form fields but not the submit
      */
      jQuery(form.selector).on('submit', val.onSubmit);
      jQuery(form.selector + " input[type=submit]").mousedown(val.onSubmit);
      jQuery(form.selector + " input[type=submit]").on('keydown', (e)=>{
        if(e.keyCode == 13){
          val.onSubmit(e);
        }
      });
    } else {
      // else trigger on submit
      jQuery(form).on('submit', val.onSubmit);
    }
    // Make it possible to trigger validation from outside
    jQuery(form).on('lbwp:runvalidation', val.onSubmit);

    // add change function to all "required" fields
    jQuery(form.selector + " [required]").change(updateChange);
    // Special for stricter phone validation (only correction, no required)
    jQuery('.strict-phone-validation input[type=text]').change(correctPhoneNumbers);
    // If correct option is true all certain fields get checked on "keyup"
    val.correct && (checkfields());

    /**
     * Actually validate the whole form on submit (again)
     * @param e
     */
    function validateForm(e, validateField, preventSubmit) { // Main function
      e.preventDefault();
      // Prevent multi submit
      if (isSubmitting === true) {
        return;
      }

      isSubmitting = true;
      // Selects all required fields
      var field = typeof validateField === 'undefined' ?
        form.selector + " ".concat(val.fields.replace(new RegExp(", ", "g"), "[required], " + form.selector + " ").concat("[required]")) :
        validateField;
      var currentForm = jQuery(form);
      // Variable for checking radio and check buttons
      var same = "";
      // Set errors to 0
      var error = 0, validationErrors = 0;
      // remove all previous alerts
      jQuery(form.selector + " ." + val.alertClass).remove();

      // Trigger all focus out and see, if there were errors
      // Set correct to correctAll if val.correct is set to true
      var correct = val.correct === true ? correctAll : val.correct;
      var types = correct.split(", "); // Converts string to array
      val.hasFocusOutErrors = 0;
      if (typeof validateField === 'undefined') {
        for (var key in types) {
          jQuery(form.selector + " input[type=" + types[key] + "]").trigger('focusout');
        }
      } else {
        validateField.trigger('focusout');
      }

      // Add the focus out errors
      error += val.hasFocusOutErrors;
      validationErrors = error;

      // Go trough all required fields once again
      jQuery(field).each(function () { // loop through all required fields
        var fieldElement = jQuery(this);
        var userMsg = fieldElement.attr("data-errormsg"); // get custom error message from field
        var msg = userMsg != undefined ? userMsg : val.defaultText; // if there is no custom error message get default text
        var errorElem = "<label for=" + fieldElement.attr("id") + ">" + msg + "</label>"; // generate error element with message
        var name = this.name.replace("[]", ""); // if the name of a field is an array remove "[]"

        // Is the field even visible? If not, its not required anymore
        if (!fieldElement.parent().is(':visible')) {
          return true;
        }

        if ((this.type == "radio" || this.type == "checkbox") && same != name) { // check if current field is a "radio" or "checkbox" field and its not the same as before
          jQuery("input[name^=" + name + "]").each(function (i) { // loop through every box
            if (jQuery(this).is(':checked')) { // if one is checked set same to current and return false
              same = name;
              return false;
            } else if (i == jQuery(this).closest('.field-list').children().length - 1) { // if not one is checked set message and count error up
              setMessage(this, errorElem, "error");
              error++;
              validationErrors++;
              same = name;
            }
          });
        } else if (!this.value.length && (this.type == "text" || this.type == "file")) { // Check if the value of "text", "select-one" or "textare" ar empty and set message
          setMessage(this, errorElem, "error");
          error++;
        } else if (!this.value.length && (this.type == "textarea" || this.type == "select-one")) { // Check if the value of "text", "select-one" or "textare" ar empty and set message
          setMessage(this, errorElem, "error");
          error++;
          validationErrors++;
        }
      });

      // Finally, see if there is an external function defined
      if (typeof (lbwpAdditionalValidationCallback) == 'function') {
        if (!lbwpAdditionalValidationCallback(form)) {
          error++;
          validationErrors++;
        }
      }

      // Add a validation class if there are errors
      if (error) {
        currentForm.addClass('validation-errors');
      } else {
        currentForm.removeClass('validation-errors');
      }

      // If we have errors, show a message on how many fixes need to be made
      if (validationErrors > 0) {
        // Check if single or multiple errors
        var wrapper = form.parent();
        var message = (validationErrors == 1)
          ? form.data('message-single').replace('{number}', validationErrors)
          : form.data('message-multi').replace('{number}', validationErrors);

        // Display that message in an existing or newly created element
        var domMsg = wrapper.find('.lbwp-form-message');
        if (domMsg.length == 1) {
          // Reset classes on the object, to reuse it
          domMsg.attr('class', 'lbwp-form-message error');
        } else {
          // Create the new message objects
          wrapper.find('form.lbwp-form').before('<p class="lbwp-form-message error"></p>');
          domMsg = wrapper.find('.lbwp-form-message');
        }

        // Set the message to the object
        domMsg.text(message);

        // Animate a fast scroll up to the message
        jQuery('html, body').animate({
          scrollTop: currentForm.siblings('.lbwp-form-anchor').offset().top
        }, 200);
      }

      let formPreventSubmit = form.attr('data-prevent-submit');
      if(formPreventSubmit === 'true'){
        preventSubmit = true;
      }

      // Let developers to thins if all is good
      if (!error && !skipAfterValidationAutoSubmit && preventSubmit !== true) {
        if (settings.doBotScan) {
          evaluateBpScan();
        }
        currentForm.trigger('lbwpform:validated');
        currentForm.append("<input type='hidden' name='lbwpFormSend' value='1'>");
        currentForm.unbind('submit').submit();
      }

      isSubmitting = false;
    }

    /**
     * Try correcting wrong format in phone numbers without requiring
     * the user to change it. Might serve for a little better data quality
     */
    function correctPhoneNumbers() {
      var field = jQuery(this);
      var number = field.val();
      // Remove all spaces, slashes and parentheses
      number = number.replace(/\s/gi, '');
      number = number.replace('(0)', '');
      number = number.replace(/[a-zA-Z()\/-]/gi, '');

      // Replace 00 at the beginning with +
      if (number.substring(0, 2) == '00') {
        number = '+' + number.substring(2);
      }

      // See if the country number is missing
      if (number.indexOf('+') != 0) {
        // If missing, see if there is a block of 0XX in the beginning
        var firstpart = parseInt(number.substring(0, 3));
        if (!isNaN(firstpart) && firstpart < 100) {
          // If yes, replace the leading zero with the default swiss country number
          number = '+41' + firstpart + number.substring(3);
        }
      }

      // In the end, maximum of 14 charachters
      field.val(number.substring(0, 14));
    }

    /**
     * Set a message to a certain field
     * @param elem
     * @param msg
     * @param mode
     */
    function setMessage(elem, msg, mode) {
      // get closest item to current element
      var cont = jQuery(elem).closest(val.elem.item);
      // removes all message on current element
      removeMessage(elem);

      // if mode is defined it adds current mode as class
      if (mode != undefined) {
        jQuery(elem).closest(val.elem.item).addClass(prfx + mode);
      }

      // places message after content element
      cont.after("<div class='" + val.elem.item.replace(".", "") + " " + prfx + mode + " " + val.messageBoxClass + "'><div class='" + val.elem.label.replace(".", "") + "'></div><div class='" + val.elem.inputbox.replace(".", "") + "'>" + msg + "</div></div>");
    }

    /**
     * Runs on every required field
     */
    function updateChange() {
      // Maybe skip validate if ordered to do so
      if (skipLbwpFormValidation) return;

      var correct = val.correct === true ? correctAll : val.correct;

      if (!(correct.indexOf(this.type) > -1)) {
        removeMessage(this);
        jQuery(this).closest(val.elem.item).removeClass(prfx + "error " + prfx + "warning").addClass(prfx + "success")
        if (jQuery(this).val().length == 0) {
          jQuery(this).closest(val.elem.item).removeClass(prfx + "success").addClass(prfx + "error");
        }
      }
    }

    /**
     * Registers focus out events, to check fields on leaving
     */
    function checkfields() {
      // Set correct to correctAll if val.correct is set to true
      var correct = val.correct === true ? correctAll : val.correct;
      var types = correct.split(", "); // Converts string to array

      // loop through all types and add "keyup" and "focusout" event;
      for (var key in types) {
        let fieldSelector = types[key] === 'textarea' ? "textarea" : "input[type=" + types[key] + "]";
        jQuery(form.selector + " " + fieldSelector).focusout(checkFocusOut);
      }
    }

    /**
     * Check function that is validating and setting messages after leaving a form
     */
    function checkFocusOut() {
      var fieldElement = jQuery(this);

      // Skip if the field is not visible
      if (!fieldElement.parent().is(':visible')) {
        return false;
      }

      var validField = true;
      // First, actually validate, if the form input has a value
      switch (this.type) {
        case "email":
          validField = vEmail(this);
          break;
        case "number":
          validField = vNumber(this);
          break;
        case "text":
        case "password":
        case "textarea":
        case "date":
          validField = vText(this);
          break;
        case "url":
          validField = vURL(this);
          break;
      }

      // Extended email check if needed
      if (fieldElement.data("dns-validation") == 1) {
        // Check if we already have a result
        var result = fieldElement.attr("data-dns-validation-result");
        if (result !== undefined) {
          validField = result == 1;
          // Remove attr for potential next validation after another focusout event
          fieldElement.removeAttr("data-dns-validation-result");
        } else {
          jQuery.post(lbwpFormNonceRetrievalUrl + '/wp-json/lbwp/form/emailcheck/', {email: fieldElement.val()}, function (response) {
            fieldElement.attr("data-dns-validation-result", response.success);
            // Trigger focosout on that field to re-run the validation
            fieldElement.trigger('focusout');
          });
        }
      }

      // Now show the message, if needed
      if (fieldElement.prop('required') && fieldElement.val().trim().length == 0 || !validField) {
        // Warn, if it was validated and isn't valid, else error, since it's required
        var type = !validField ? "warning" : "error";
        var userMsg = fieldElement.attr("data-" + type + "Msg");
        // if there is no custom message take default
        var msg = userMsg != undefined ? userMsg : val.defaultText;
        var msgElem = "<label class=" + val.alertClass + " for=" + fieldElement.attr("id") + ">" + msg + "</label>";
        var name = this.name.replace("[]", "");
        // Set error and count focus out errors
        setMessage(this, msgElem, "error");
        val.hasFocusOutErrors++;
      } else if (validField) {
        removeMessage(this);
      }
    }

    /**
     * Remove the message block from the given element
     * @param elem
     */
    function removeMessage(elem) {
      jQuery(elem).closest(val.elem.item).next("." + val.messageBoxClass).remove();
    }

    /**
     * validates email field
     * @param elem
     * @returns {boolean}
     */
    function vEmail(elem) {
      let prevEmailField = null;
      let emailField = jQuery(elem);
      let email = emailField.val();
      let emailConfirmed = true;

      if(emailField.closest('.lbwp-email-confirmation').length > 0){
        if (emailField.closest('form').hasClass('lbwp-wrap-items')) {
          prevEmailField = emailField.closest('.forms-item-wrapper').prevAll('.forms-item-wrapper.email-field').find('input');
        } else {
          prevEmailField = emailField.closest('.forms-item').prevAll('.forms-item.email-field').find('input');
        }
        emailConfirmed = email === prevEmailField.val();
      }

      let pattern = new RegExp(/^(("[\w-+\s]+")|([\w-+]+(?:\.[\w-+]+)*)|("[\w-+\s]+")([\w-+]+(?:\.[\w-+]+)*))(@((?:[\w-+]+\.)*\w[\w-+]{0,66})\.([a-z]{2,12}(?:\.[a-z]{2})?)$)|(@\[?((25[0-5]\.|2[0-4][\d]\.|1[\d]{2}\.|[\d]{1,2}\.))((25[0-5]|2[0-4][\d]|1[\d]{2}|[\d]{1,2})\.){2}(25[0-5]|2[0-4][\d]|1[\d]{2}|[\d]{1,2})\]?$)/i);
      if ((email.length == 0 || pattern.test(email)) && emailConfirmed) {
        emailField.closest(val.elem.item).removeClass(prfx + "error").addClass(prfx + "success");
        removeMessage(elem);
        return true;
      } else {
        emailField.closest(val.elem.item).addClass(prfx + "error").removeClass(prfx + "success");
        return false;
      }
    }

    /**
     * Validate an url field jsut like input[type=url]
     * @param elem
     * @returns {boolean}
     */
    function vURL(elem) {
      var input = elem instanceof jQuery ? elem[0] : elem;
      var url = input.value;
      // Native validation just like input[type=url]
      var isValid = input.type === "url"
        ? input.checkValidity()
        : /^[a-zA-Z][a-zA-Z0-9+\-.]*:/.test(url); // fallback

      if (url.length == 0 || isValid) {
        jQuery(input).closest(val.elem.item).removeClass(prfx + "error").addClass(prfx + "success");
        removeMessage(input);
        return true;
      } else {
        jQuery(input).closest(val.elem.item).addClass(prfx + "error").removeClass(prfx + "success");
        return false;
      }
    }

    /**
     * There is no need of focusout validation, as this is done by required field handler
     * @param elem
     * @returns {boolean}
     */
    function vText(elem) {
      jQuery(elem).closest(val.elem.item).removeClass(prfx + "error").addClass(prfx + "success");
      removeMessage(elem);
      return true;
    }

    /**
     * validates number field
     * @param elem
     * @returns {boolean}
     */
    function vNumber(elem) {
      var value = parseInt(jQuery(elem).val());
      if (jQuery(elem).val().length == 0 || !isNaN(value)) {
        jQuery(elem).closest(val.elem.item).removeClass(prfx + "error").addClass(prfx + "success");
        removeMessage(elem);
        return true;
      } else {
        jQuery(elem).closest(val.elem.item).addClass(prfx + "error").removeClass(prfx + "success");
        return false;
      }
    }

    /**
     * Init the bot probability check
     */
    function initBotProbabilityCheck() {
      form.pbData = {
        field: form.find('#lbwpBtProbField'),
        probability: '',
        time: {
          start: new Date().getTime(),
          end: 0,
          firstChange: 0,
        },
        fields: {}, // foreach field: fieldId => [hover, click, focus, time, chars, group, empty/not required]
        userBehaviour: [0, 0, 0, 0], //Scroll, mousemove, click, keypress
      }

      runBpScanner();
    }

    /**
     * Run the bot probability scanner
     * Scan valutation:
     *  - a = absolutely not a bot :)
     *  - b = böh, could be a bot
     *  - c = certainly a bot (99%)
     *  - d = definitely a bot (100%) -> will not send the form
     *  - f = filled out automatically by the browser (especially chrome)
     *  - e = eventually autofilled by the browser even if hidden (especially chrome)
     */
    function runBpScanner() {
      var fields = form.find(settings.fields);
      var doc = jQuery(document);

      jQuery.each(fields, function (index) {
        var field = jQuery(this);
        var type = field.attr('type') === undefined ? field[0].tagName.toLowerCase() : field.attr('type');
        var behaviour = [0, 0, 0, 0, 0, 0, 0]; // hover, click, focus, focus-time , focusout, grouped, not-required, autofilled
        var nonSkippableHidden = ['sentForm', 'form-token'];
        var invisible = type === 'hidden' || field.hasClass('field_email_to');
        var skippable = type === 'submit' ||
          (type === 'hidden' && field.prev().attr('type') === 'file') ||
          (type === 'hidden' && (nonSkippableHidden.indexOf(field.attr('name')) === -1 || field.hasClass('non-skippable')));
        var id = type + '_' + index;
        var timer = null;

        if(!invisible && !skippable && !field.hasClass('field_email_to')) {
          detectAutofill(this);
        }

        field.change(function () {
          // change of a hidden field
          if (invisible && !skippable) {
            form.pbData.probability += typeof field.attr('autofilled') !== 'undefined' ? 'e' : 'd';
            return;
          }
        });

        if (invisible || skippable) {
          return;
        }

        if (type === 'radio' || type === 'checkbox') {
          // check if input is in a group
          if (jQuery('input[name="' + field.attr('name') + '"]').length > 1) {
            behaviour[5] = 1;
          }

          if (field.css('display') === 'none') {
            field = jQuery(this).closest('label');
          } else {
            fields.push(jQuery(this).closest('label'));
          }
        }

        field.hover(function () {
          behaviour[0] = 1;
        });

        field.click(function () {
          behaviour[1] = 1;
        });

        field.focus(function () {
          behaviour[2] = 1;

          if (form.pbData.time.firstChange === 0) {
            firstChange = false;
            form.pbData.time.firstChange = new Date().getTime();
          }

          timer = setInterval(function () {
            behaviour[3] += 0.01;
          }, 10);
        });

        field.focusout(function () {
          clearInterval(timer);
          behaviour[4] = field.val().length;

          // Check time between changes
          /*else{
            var calcTime = new Date().getTime();
            if(form.pbData.time.between.length === 0) {
              calcTime -= form.pbData.time.firstChange;
              form.pbData.time.between.push(calcTime);
            }else{
              calcTime -= form.pbData.time.firstChange + form.pbData.time.between[form.pbData.time.between.length - 1];
              form.pbData.time.between.push(calcTime + type);
            }*/

        });

        if (field.val() === '' && field.attr('required') === undefined) {
          behaviour[6] = 1 + behaviour[0] + behaviour[1] + behaviour[2];
        }

        field.on('autofill', function () {
          behaviour[7] = 1;
          jQuery('input[name*="to_recept_"]').val('');
        })

        form.pbData.fields[id] = behaviour;
      });

      doc.scroll(function () {
        form.pbData.userBehaviour[0] = 1;
      });

      doc.mousemove(function () {
        form.pbData.userBehaviour[1] = 1;
      });

      doc.click(function () {
        form.pbData.userBehaviour[2] = 1;
      });

      doc.on('touchmove', function () {
        form.pbData.userBehaviour[1] = 1;
      });

      doc.on('tap', function () {
        form.pbData.userBehaviour[2] = 1;
      });

      doc.keypress(function () {
        form.pbData.userBehaviour[3] = 1;
      });
    }

    /**
     * Evaluate the form data
     * Checks:
     *  - total time (start -> end) based on num fields
     *  - init of the form (start -> firstChange)
     *  - speed per field
     *  - field proprieties -> hover, click, focus (3: good, 2: okey, 1: meh, no focus (index 2): bad)
     *  - user behaviour -> scroll, click, keypress, etc. (all 4: good, 3: okay, 2-0: bad)
     */
    function evaluateBpScan() {
      form.pbData.time.end = new Date().getTime();

      var valutation = {
        fill: 'a',
        start: 'a',
        speed: '',
        fields: '',
        user: 'a',
      }
      var formSpeed = 0;

      // Speed check
      // only check time (fast) for: button, checkbox, radio -> average time: 2-3s
      // only check time (slow) for: color, date, file, image, month, number, range, time -> average time: > 3s
      // check based on typingspeed for: email, phone, tel, url, text, password, textarea -> average time:
      // 190 and 200 characters per minute ~ 3.3 per seconds (source: https://www.livechat.com/typing-speed-test/#/)
      var checkType = 'typing';
      var fieldSpeed = {
        fast: ['button', 'checkbox', 'radio', 'label'],
        slow: ['color', 'date', 'file', 'image', 'month', 'number', 'range', 'time', 'select'],
      }
      var avSpeed = {
        fast: 1,
        slow: 3,
        typing: 3.3
      }

      var startDelay = form.pbData.time.firstChange / 1000 - form.pbData.time.start / 1000;
      if (startDelay < 1) {
        valutation.start = 'c';
      } else if (startDelay < 2) {
        valutation.start = 'b';
      }

      // Check the filling speed of the fields
      jQuery.each(form.pbData.fields, function (key) {
        var data = this;
        var type = key.substring(0, key.indexOf('_'));

        if(data[7] === 1){
            form.pbData.probability += 'f';
            valutation.speed += 'a';
            valutation.fields += 'a';
          return;
        }

        // handle empty and not required fields
        if (data[6] === 1) {
          valutation.fields += 'b';
          valutation.speed += 'b';
          return;
        } else if (data[6] > 1) {
          valutation.fields += 'a';
          valutation.speed += 'a';
          return;
        }

        if (fieldSpeed.fast.indexOf(type) !== -1) {
          checkType = 'fast';
        }

        if (fieldSpeed.slow.indexOf(type) !== -1) {
          checkType = 'slow';
        }

        switch (checkType) {
          case 'typing':
            var deviation = avSpeed.typing - (data[4] / data[3]);
            formSpeed += data[4] / data[3];

            if (deviation < -2) {
              valutation.speed += 'c';
            } else if (deviation > 3) {
              valutation.speed += 'b';
            } else {
              valutation.speed += 'a';
            }

            break;

          case 'fast':
            var deviation = avSpeed.fast - data[3];
            formSpeed += data[3];

            if (deviation < 0.1 && deviation > 0) {
              valutation.speed += 'c';
            } else {
              valutation.speed += 'a';
            }

            break;

          case 'slow':
            var deviation = avSpeed.slow - data[3];
            formSpeed += data[3];

            if (deviation > 2 && deviation > 0) {
              valutation.speed += 'c';
            } else {
              valutation.speed += 'a';
            }

            break;
        }

        //field hover, click and focus
        if (data[2] === 0 && data[5] !== 1) {
          valutation.fields += 'c';
        } else if (data[0] + data[1] + data[2] === 3) {
          valutation.fields += 'a';
        } else {
          valutation.fields += 'b';
        }
      });

      // check the time used to fill out the whole form
      var totalTime = form.pbData.time.end / 1000 - form.pbData.time.start / 1000;
      if (formSpeed === totalTime) {
        valutation.fill = 'c';
      } else if (Math.abs(totalTime - formSpeed) < 3) {
        valutation.fill = 'b';
      }

      const sum = arr => arr.reduce((a, b) => a + b, 0);
      if (sum(form.pbData.userBehaviour) === 3) {
        valutation.user = 'b';
      } else {
        valutation.user = 'c';
      }

      form.pbData.field.val(form.pbData.probability + '-' +
        valutation.fill + '-' +
        valutation.start + '-' +
        valutation.speed + '-' +
        valutation.fields + '-' +
        valutation.user
      );
    }

    /**
     * Hide the submit button until all fields are filled out
     */
    function hideSubmitButton(){
      settings.sendBtn = form.find('input[type="submit"]');
      settings.sendBtn.hide();

      form.find('input:required, select:required, textarea:required').on('input', checkInputs);
      jQuery(document.body).on('lbwp_conditional_fields_loaded', checkInputs);
    }

    /**
     * Check the inputs if they have content
     */
    function checkInputs(){
      var theInputs = inputList();
      var showSubmit = [
        !(theInputs.default.length > 0),
        !(theInputs.radios.length > 0),
        !(theInputs.checkboxes.length > 0)
      ];

      // Check if not empty string
      if(theInputs.default.indexOf('') < 0){
        showSubmit[0] = true;
      }

      // Check if one (of the group) is selected
      jQuery.each(theInputs.radios, function(){
        if(this.indexOf(true) < 0){
          showSubmit[1] = false;
          return false;
        }
      });

      // Check if one is selected
      jQuery.each(theInputs.checkboxes, function(){
        if(this.indexOf(true) < 0){
          showSubmit[2] = false;
          return false;
        }
      });

      // If all are fine, then show the submit button
      if(showSubmit.indexOf(false) < 0){
        settings.sendBtn.show();
      }else{
        settings.sendBtn.hide();
      }
    }

    /**
     * Get the input list (only required ones)
     * @returns {{default: *[], checkboxes: {}, radios: {}}}
     */
    function inputList(){
      var getInputs = jQuery('.default-container');
      var inputGroup = {
        default : [],   // Inputs, selects and textareas: only have to be checked if not an empty string
        radios : {},    // Radios: only one of the group has to be selected
        checkboxes : {},// Checkboxes: only one of them needs to be selected
      };

      // Get inputs based on their type
      jQuery.each(getInputs, function(){
        var input = jQuery(this).find('input:required, select:required, textarea:required');

        if(input.length > 1){
          jQuery.each(input, function(){
            var group = jQuery(this);
            if(!group.parent().is(':visible')){
              return true;
            }

            if(group.attr('type') === 'checkbox'){
              if(inputGroup.checkboxes[group.attr('name')] === undefined){
                inputGroup.checkboxes[group.attr('name')] = [];
              }

              inputGroup.checkboxes[group.attr('name')].push(group[0].checked);

            } else if(group.attr('type') === 'radio'){
              if(inputGroup.radios[group.attr('name')] === undefined){
                inputGroup.radios[group.attr('name')] = [];
              }

              inputGroup.radios[group.attr('name')].push(group[0].checked);

            }else {
              inputGroup.default.push(group.val());
            }
          });
        }else{
          if(!input.parent().is(':visible')){
            return true;
          }
          inputGroup.default.push(input.val());
        }

      });

      return inputGroup;
    }

    return this;
  };
}(jQuery));
var skipLbwpFormValidation = false;
var skipAfterValidationAutoSubmit = false;

/**
 * Author: jonathantneal - https://gist.github.com/jonathantneal/d462fc2bf761a10c9fca60eb634f6977
 * @param scope
 * @returns {(function(): void)|*}
 */
function detectAutofill(scope) {
  // match the filter on autofilled elements in Firefox
  const mozFilterMatch = /^grayscale\(.+\) brightness\((1)?.*\) contrast\(.+\) invert\(.+\) sepia\(.+\) saturate\(.+\)$/

  scope.addEventListener('animationstart', onAnimationStart)
  scope.addEventListener('input', onInput)
  scope.addEventListener('transitionstart', onTransitionStart)

  function onAnimationStart(event) {
    // detect autofills in Chrome and Safari by:
    //   - assigning animations to :-webkit-autofill, only available in Chrome and Safari
    //   - listening to animationstart for those specific animations
    if (event.animationName === 'onautofillstart') {
      // during autofill, the animation name is onautofillstart
      autofill(event.target)
    } else if (event.animationName === 'onautofillcancel') {
      // during autofill cancel, the animation name is onautofillcancel
      cancelAutofill(event.target)
    }
  }

  function onInput(event) {
    if ('data' in event) {
      // input events with data may cancel autofills
      cancelAutofill(event.target)
    } else {
      // input events without data are autofills
      autofill(event.target)
    }
  }

  function onTransitionStart(event) {
    // detect autofills in Firefox by:
    //   - listening to transitionstart, only available in Edge, Firefox, and Internet Explorer
    //   - checking filter style, which is only changed in Firefox
    const mozFilterTransition =
      event.propertyName === 'filter' &&
      getComputedStyle(event.target).filter.match(mozFilterMatch)

    if (mozFilterTransition) {
      if (mozFilterTransition[1]) {
        // during autofill, brightness is 1
        autofill(event.target)
      } else {
        // during autofill cancel, brightness is not 1
        cancelAutofill(event.target)
      }
    }
  }

  function autofill(target) {
    if (!target.isAutofilled) {
      target.isAutofilled = true
      target.setAttribute('autofilled', '')
      target.dispatchEvent(new CustomEvent('autofill', { bubbles: true }))
    }
  }

  function cancelAutofill(target) {
    if (target.isAutofilled) {
      target.isAutofilled = false
      target.removeAttribute('autofilled')
      target.dispatchEvent(new CustomEvent('autofillcancel', { bubbles: true }))
    }
  }

  return () => {
    scope.removeEventListener('animationstart', onAnimationStart)
    scope.removeEventListener('input', onInput)
    scope.removeEventListener('transitionstart', onTransitionStart)
  }
}