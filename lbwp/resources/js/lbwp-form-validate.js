(function ($) {
	$.fn.validate = function (edits) {
		if(this.length > 1){ // checks if there is more than one form
			$(this).each(function(){
				$(this).validate(edits);
			})
			return this;
		}
		var form = this;
		var val = {};
		var edits = typeof edits == "object" ? edits : {}; // checks if edits is set as an object else generates an empty object
		var correctAll = "date, email, number, range, tel, time, url, text"; // all form input types
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
		}

		for (var key in settings) val[key] = edits.hasOwnProperty(key) ? edits[key] : settings[key]; // Checks if custom edits are set and merge them in a new Array

		var prfx = val.msgClassPrefix

		$(form).addClass("validate"); // add Prefix to current class
		if($(form.selector + " input[type=submit]").length != 0){ // Check if there is a submit button for triggering event
			$(form.selector + " input[type=submit]").click(val.onSubmit);
		}else{// else trigger on submit
			$(form).submit(val.onSubmit);
		}
		$(form.selector + " [required]").change(updateChange) // add change function to all "required" fields

		val.correct && (checkfields()) // Correct is true all certain fields get checked on "keyup"

		/**************** FUNCTIONS ***********************/

		function validateForm(e) { // Main function
			e.preventDefault();
			var field = form.selector + " ".concat(val.fields.replace(new RegExp(", ", "g"), "[required], " + form.selector + " ").concat("[required]")); // Selects all required fields
			var same = ""; // Variable for checking radio and check buttons
			var error = 0; // Set errors to 0
			$(form.selector + " ." + val.alertClass).remove() // remove all previous alerts
			$(field).each(function () { // loop through all required fields
				var userMsg = $(this).attr("data-errorMsg"); // get custom error message from field
				var msg = userMsg != undefined ? userMsg : val.defaultText; // if there is no custom error message get default text
				var errorElem = "<label for=" + $(this).attr("id") + ">" + msg + "</label>"; // generate error element with message
				var name = this.name.replace("[]", ""); // if the name of a field is an array remove "[]"

				if ((this.type == "radio" || this.type == "checkbox" ) && same != name) { // check if current field is a "radio" or "checkbox" field and its not the same as before
					$("input[name^=" + name + "]").each(function (i) { // loop through every box
						if ($(this).is(':checked')) { // if one is checked set same to current and return false
							same = name;
							return false;
						} else if (i == $(this).closest(val.elem.inputbox).children().length - 1) { // if not one is checked set message and count error up
							setMessage(this, errorElem, "error");
							error++;
							same = name;
						}
					});
				} else if (!this.value.length && (this.type == "text" || this.type == "select-one" || this.type == "textarea")) { // Check if the value of "text", "select-one" or "textare" ar empty and set message
					setMessage(this, errorElem, "error");
					error++;
				} else if (this.type == "email") { // if its an email field, check if email is valid
					if(!vEmail(this)){
						setMessage(this, errorElem, "error");
						error++;
					}
				}
			});
			// Add a validation class if there are errors
			if (error) {
				$(form).addClass('validation-errors');
			} else {
				$(form).removeClass('validation-errors');
			}
			//!error && (form.unbind('submit').submit()); // if there is no error unbind submit event and submit form
			!error && ($(form).append("<input type='hidden' name='lbwpFormSend' value='1'>"), $(form).unbind('submit').submit()); // if there is no error unbind submit event and submit form
		}

		function setMessage(elem, msg, mode) { // Function for setting Message
			var cont = $(elem).closest(val.elem.item); // get closest item to current element
			var height = cont.outerHeight(); // get outer height of content
			var prev = cont.prev(); // get previous element

			removeMessage(elem); // removes all message on current element

			if (mode != undefined) { // if mode is defined it adds current mode as class
				$(elem).closest(val.elem.item).addClass(prfx + mode);
			}
			// places message after content element
			cont.after("<div class='" + val.elem.item.replace(".", "") + " " + prfx + mode + " " + val.messageBoxClass + "'><div class='" + val.elem.label.replace(".", "") + "'></div><div class='" + val.elem.inputbox.replace(".", "") + "'>" + msg + "</div></div>");
		}

		function updateChange() {
			var correct = val.correct === true ? correctAll : val.correct;

			if (!(correct.indexOf(this.type) > -1)) {
				removeMessage(this);
				$(this).closest(val.elem.item).removeClass(prfx + "error " + prfx + "warning").addClass(prfx + "success")
				if($(this).val().length == 0){
						$(this).closest(val.elem.item).removeClass(prfx + "success").addClass(prfx + "error");
				}
			}
		}

		function checkfields() { // add fields "keyup" event
			var correct = val.correct === true ? correctAll : val.correct; // Set correct to correctAll if val.correct is set to true
			var types = correct.split(", "); // Converts string to array

			for (var key in types) { // loop through all types and add "keyup" and "focusout" event;
				$(form.selector + " input[type=" + types[key] + "]").keyup(checkKeyUp).focusout(checkFocusOut);
			}
		}

		function checkKeyUp() { // "keyup" function
			switch (this.type) {
				case "email": // if type of current element is "email" vEmail gets called
					vEmail(this);
					break;
				case "number": // if type of current element is "number" vNumber gets called
					vNumber(this);
					break;
				case "text": // if type of current element is "email" vEmail gets called
					vText(this);
					break;
			}
		}

		function checkFocusOut() { // "focusout" function
			var type = $(this).prop('required') ? "error" : "warning"; // if field is required set type to "error" elso to "warning"
			var userMsg = $(this).attr("data-" + type + "Msg"); // get custom message
			var msg = userMsg != undefined ? userMsg : val.defaultText; // if there is no custom message take default
			var msgElem = "<label class=" + val.alertClass + " for=" + $(this).attr("id") + ">" + msg + "</label>"; // create message element
			var name = this.name.replace("[]", ""); // remove "[]" in name if there are any

			if (type == "warning" && !$(this).val().length) { // if type is warning and field value isnt empty
				removeMessage(this); // remove all message that belong to this element
				$(this).closest(val.elem.item).removeClass(prfx + "warning").addClass(prfx + "success"); // remove "warning" class and add "success"
			} else if ($(this).closest(val.elem.item).hasClass(prfx + type)) { // else if closest element has class with current type
				setMessage(this, msgElem, type); // set error or warning message
			}
		}

		function removeMessage(elem) { // removes message to element given
			$(elem).closest(val.elem.item).next("." + val.messageBoxClass).remove();
		}


		/********************* VALIDATE FIELDS FUNCTIONS *******************************/
		function vEmail(elem) { // validates email field
			var email = $(elem).val(); // get value of email field
			var type = $(elem).prop('required') ? "error" : "warning"; // check if field is required or not
			var pattern = new RegExp(/^(("[\w-+\s]+")|([\w-+]+(?:\.[\w-+]+)*)|("[\w-+\s]+")([\w-+]+(?:\.[\w-+]+)*))(@((?:[\w-+]+\.)*\w[\w-+]{0,66})\.([a-z]{2,12}(?:\.[a-z]{2})?)$)|(@\[?((25[0-5]\.|2[0-4][\d]\.|1[\d]{2}\.|[\d]{1,2}\.))((25[0-5]|2[0-4][\d]|1[\d]{2}|[\d]{1,2})\.){2}(25[0-5]|2[0-4][\d]|1[\d]{2}|[\d]{1,2})\]?$)/i);
			if (pattern.test(email)) { // if test is successful it removes every message and adds class success
				$(elem).closest(val.elem.item).removeClass(prfx + type).addClass(prfx + "success");
				removeMessage(elem);
				return true;
			} else {
				$(elem).closest(val.elem.item).addClass(prfx + type).removeClass(prfx + "success");
				return false;
			}
		}

		function vText(elem) { // validates normal text field
			var type = $(elem).prop('required') ? "error" : "warning"; // check if field is required or not
			if ($(elem).val().length > 0) {
				$(elem).closest(val.elem.item).removeClass(prfx + type).addClass(prfx + "success");
				removeMessage(elem);
			} else {
				$(elem).closest(val.elem.item).addClass(prfx + type).removeClass(prfx + "success")
			}
		}

		function vNumber(elem) { // validates number field
			var value = parseInt($(elem).val());
			var type = $(elem).prop('required') ? "error" : "warning";
			if (!isNaN(value)) {
				$(elem).closest(val.elem.item).removeClass(prfx + type).addClass(prfx + "success");
				removeMessage(elem);
			} else {
				$(elem).closest(val.elem.item).addClass(prfx + type).removeClass(prfx + "success");
			}
		}

		return this;

	};


}(jQuery));
