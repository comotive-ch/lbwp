(function ($) {
	$.fn.validate = function (edits) {
		if (this.length > 1) { // checks if there is more than one form
			jQuery(this).each(function () {
				jQuery(this).validate(edits);
			});
			return this;
		}

		var form = this;
		var val = {};
		var edits = typeof edits == "object" ? edits : {}; // checks if edits is set as an object else generates an empty object
		var correctAll = "date, email, number, phone, range, time, url, text, password, textarea"; // all form input types
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
			msgClassPrefix: "lbwp-form-" // Prefix for message classes
		};

		// Checks if custom edits are set and merge them in a new Array
		for (var key in settings) {
			val[key] = edits.hasOwnProperty(key) ? edits[key] : settings[key];
		}

		var prfx = val.msgClassPrefix;

		jQuery(form).addClass("validate");
		// Check if there is a submit button for triggering event
		if (jQuery(form.selector + " input[type=submit]").length != 0) {
			jQuery(form.selector + " input[type=submit]").mousedown(val.onSubmit);
		} else {
			// else trigger on submit
			jQuery(form).submit(val.onSubmit);
		}

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
		function validateForm(e) { // Main function
			e.preventDefault();
			// Selects all required fields
			var field = form.selector + " ".concat(val.fields.replace(new RegExp(", ", "g"), "[required], " + form.selector + " ").concat("[required]"));
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
			for (var key in types) {
				jQuery(form.selector + " input[type=" + types[key] + "]").trigger('focusout');
			}

			// Add the focus out errors
			error += val.hasFocusOutErrors;
			validationErrors = error;

			// Go trough all required fields once again
			jQuery(field).each(function () { // loop through all required fields
				var fieldElement = jQuery(this);
				var userMsg = fieldElement.attr("data-errorMsg"); // get custom error message from field
				var msg = userMsg != undefined ? userMsg : val.defaultText; // if there is no custom error message get default text
				var errorElem = "<label for=" + fieldElement.attr("id") + ">" + msg + "</label>"; // generate error element with message
				var name = this.name.replace("[]", ""); // if the name of a field is an array remove "[]"

				// Is the field even visible? If not, its not required anymore
				if (!fieldElement.is(':visible')) {
					return true;
				}

				if ((this.type == "radio" || this.type == "checkbox" ) && same != name) { // check if current field is a "radio" or "checkbox" field and its not the same as before
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
				} else if (!this.value.length && (this.type == "text" || this.type == "select-one")) { // Check if the value of "text", "select-one" or "textare" ar empty and set message
					setMessage(this, errorElem, "error");
					error++;
				} else if (!this.value.length && (this.type == "textarea")) { // Check if the value of "text", "select-one" or "textare" ar empty and set message
					setMessage(this, errorElem, "error");
					error++;
					validationErrors++;
				}
			});

			// Finally, see if there is an external function defined
			if (typeof(lbwpAdditionalValidationCallback) == 'function') {
				if (!lbwpAdditionalValidationCallback(form)) {
					error++;
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
					wrapper.find('form').before('<p class="lbwp-form-message error"></p>');
					domMsg = wrapper.find('.lbwp-form-message');
				}

				// Set the message to the object
				domMsg.text(message);

				// Animate a fast scroll up to the message
				jQuery('html, body').animate({
					scrollTop: domMsg.offset().top - 50
				}, 200);
			}

			// if there is no error unbind submit event and submit form
			!error && (currentForm.append("<input type='hidden' name='lbwpFormSend' value='1'>"), currentForm.unbind('submit').submit());
		}

		/**
		 * Try correcting wrong format in phone numbers without requiring
		 * the user to change it. Might serve for a little better data quality
		 */
		function correctPhoneNumbers()
		{
			var field = jQuery(this);
			var number = field.val();
			// Remove all spaces, slashes and parentheses
			number = number.replace(/\s/gi, '');
			number = number.replace('(0)', '');
			number = number.replace(/[a-zA-Z()\/-]/gi, '');

			// Replace 00 at the beginning with +
			if (number.substring(0,2) == '00') {
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
		function setMessage(elem, msg, mode)
		{
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
				jQuery(form.selector + " input[type=" + types[key] + "]").focusout(checkFocusOut);
			}
		}

		/**
		 * Check function that is validating and setting messages after leaving a form
		 */
		function checkFocusOut() {
			var fieldElement = jQuery(this);

			// Skip if the field is not visible
			if (!fieldElement.is(':visible')) {
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
					validField = vText(this);
					break;
				case "url":
					validField = vURL(this);
					break;
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
			var email = jQuery(elem).val();;
			var pattern = new RegExp(/^(("[\w-+\s]+")|([\w-+]+(?:\.[\w-+]+)*)|("[\w-+\s]+")([\w-+]+(?:\.[\w-+]+)*))(@((?:[\w-+]+\.)*\w[\w-+]{0,66})\.([a-z]{2,12}(?:\.[a-z]{2})?)$)|(@\[?((25[0-5]\.|2[0-4][\d]\.|1[\d]{2}\.|[\d]{1,2}\.))((25[0-5]|2[0-4][\d]|1[\d]{2}|[\d]{1,2})\.){2}(25[0-5]|2[0-4][\d]|1[\d]{2}|[\d]{1,2})\]?$)/i);
			if (email.length == 0 || pattern.test(email)) {
				jQuery(elem).closest(val.elem.item).removeClass(prfx + "error").addClass(prfx + "success");
				removeMessage(elem);
				return true;
			} else {
				jQuery(elem).closest(val.elem.item).addClass(prfx + "error").removeClass(prfx + "success");
				return false;
			}
		}

		/**
		 * Validate an url field
		 * @param elem
		 * @returns {boolean}
		 */
		function vURL(elem) {
			var url = jQuery(elem).val();
			var pattern = new RegExp(/^(ftp|https?):\/\/+(www\.)?[a-z0-9\-\.]{3,}\.[a-z]{2,}$/i);
			if (url.length == 0 || pattern.test(url)) {
				jQuery(elem).closest(val.elem.item).removeClass(prfx + "error").addClass(prfx + "success");
				removeMessage(elem);
				return true;
			} else {
				jQuery(elem).closest(val.elem.item).addClass(prfx + "error").removeClass(prfx + "success");
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

		return this;
	};
}(jQuery));
