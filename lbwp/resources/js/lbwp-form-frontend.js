/**
 * The frontend JS for lbwp form
 */
var LbwpForm = {
	/**
	 * @var bool to make sure that only one event can run conditions at a time
	 */
	runningConditions : false,
	/**
	 * Called on page load, registering events and such
	 */
	initialize : function()
	{
		LbwpForm.moveEmailField();
		LbwpForm.preventDoubleClick();
		LbwpForm.handleItemWrapping();
		LbwpForm.hideInvisibleFields();
		LbwpForm.handleFieldConditions();
	},

	/**
	 * This moves the deprecated email field
	 */
	moveEmailField : function()
	{
		// Move the email field out of the way
		var field = jQuery('.field_email_to');
		field.css('position', 'absolute');
		field.css('top', '-500px');
		field.css('left', '-3500px');
	},

	/**
	 * Prevents double click and double/multi sending of forms
	 */
	preventDoubleClick : function()
	{
		var sendButtons = jQuery('.lbwp-form input[name=lbwpFormSend]');

		// On click, set the button as disabled and add a disabled class
		sendButtons.on('mousedown', function() {
			var button = jQuery(this);
			button.prop('disabled', 'disabled');
			button.attr('disabled', 'disabled');
			button.addClass('lbwp-button-disabled');
			// Check (twice!) if we need to release it, due to errors
			setTimeout(LbwpForm.checkFormEnable, 250);
			setTimeout(LbwpForm.checkFormEnable, 1000);
			return true;
		});
	},

	/**
	 * Checks if forms need to be re-enabled
	 */
	checkFormEnable : function()
	{
		jQuery('.lbwp-form').each(function() {
			var form = jQuery(this);
			if (form.hasClass('validation-errors')) {
				var button = form.find('input[name=lbwpFormSend]');
				button.removeProp('disabled');
				button.removeAttr('disabled');
				button.removeClass('lbwp-button-disabled');
			}
		})
	},

	/**
	 * Forms with the lbwp-wrap-items get their items wrapped in an additional div
	 * This allows for better styling possibilities (As two col forms for example)
	 */
	handleItemWrapping : function()
	{
		jQuery('.lbwp-wrap-items').each(function() {
			// Find all form elements
			jQuery(this).find('.forms-item').each(function() {
				var item = jQuery(this);
				var classes = item.attr('class');
				// Remove the forms item class, but add a wrapper class instead
				classes = classes.replace('forms-item', 'forms-item-wrapper');
				item.wrap('<div class="' + classes + '"></div>');
			});
		})
	},

	/**
	 * Handles all kinds of field conditions and their effects/actions
	 */
	handleFieldConditions : function()
	{
		var selector = '.lbwp-form input, .lbwp-form select, .lbwp-form textarea';
		// Run field conditions whenever an element has changed
		jQuery(selector).on('keyup', function() {
			LbwpForm.runConditions('changed', jQuery(this));
		});
		jQuery(selector).on('change', function() {
			LbwpForm.runConditions('changed', jQuery(this));
		});
		jQuery(selector).on('focus', function() {
			LbwpForm.runConditions('focused', jQuery(this));
		});

		// Initially, trigger a change on every data-field element
		skipLbwpFormValidation = true;
		jQuery('[data-field]').trigger('change');
		skipLbwpFormValidation = false;
	},

	/**
	 * Run condition framework on all fields
	 * @param type the event "changed" or "focused"
	 * @param element the field name that has a change or focus
	 */
	runConditions : function(type, element)
	{
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
		for (var i in conditions) {
			condition = conditions[i];
			if (field == condition.field) {
				// Some conditions need to be run on change, one only on focus
				if (condition.operator == 'focused' && type == 'focused') {
					// Get the item and immediately run the action
					item = jQuery('[data-field=' + condition.target + ']');
					LbwpForm.triggerItemAction(item, condition.action);
				} else if (condition.operator != 'focused' && type == 'changed') {
					// Get the item to compare its value
					item = jQuery('[data-field="' + field + '"]');
					value = item.val();

					// Special value compare method for certain types of inputs
					switch (item.attr('type')) {
						case 'radio':
							value = jQuery('[data-field="' + field + '"]:checked').val();
							break;
						case 'checkbox':
							value = '';
							jQuery('[data-field="' + field + '"]:checked').each(function() {
								value += jQuery(this).val() + ' ';
							});
							break;
					}

					// If the value is empty, but we have more/lessthan condition, set 0
					if ((condition.operator == 'morethan' || condition.operator == 'lessthan') && value.length == 0) {
						value = 0;
					}

					// Run the tests, and only then, run the action
					switch (condition.operator) {
						case 'is': 				test = (value == condition.value); break;
						case 'not': 			test = (value != condition.value); break;
						case 'contains': 	test = (value.indexOf(condition.value) != -1); break;
						case 'absent': 		test = (value.indexOf(condition.value) == -1); break;
						case 'morethan': 	test = (parseInt(value) >= parseInt(condition.value)); break;
						case 'lessthan': 	test = (parseInt(value) <= parseInt(condition.value)); break;
					}

					// If the test didn't fail, trigger the target
					if (test) {
						LbwpForm.triggerItemAction(jQuery('[data-field="' + condition.target + '"]'), condition.action);
					}
				}
			}
		}

		// Finished running conditions
		LbwpForm.runningConditions = false;
		return true;
	},

	/**
	 * Trigger an action on an item or its container
	 * @param item the element to be triggered
	 * @param action the action to be executed on the element (or its container)
	 */
	triggerItemAction: function(item, action)
	{
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
				break;
			case 'truncate':
				if (item.prop('tagName').toLowerCase() == 'select') {
					item.find('option').removeAttr('selected');
				} else if (item.prop('tagName').toLowerCase() == 'input' && item.attr('type') == 'radio') {
					item.removeAttr('checked');
				}	else if (item.prop('tagName').toLowerCase() == 'input' && item.attr('type') == 'checkbox') {
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
	 * Gracefully return the form field conditions or an empty array
	 * @returns {Array}
	 */
	getConditions : function(formId)
	{
		if (typeof(lbwpFormFieldConditions[formId]) == 'object') {
			return lbwpFormFieldConditions[formId];
		}
		return [];
	},

	/**
	 * Hide fields that should be invisible
	 */
	hideInvisibleFields : function()
	{
		jQuery('[data-init-invisible=1]').each(function() {
			var element = jQuery(this);
			element.closest('.forms-item').hide();
		});
	}
};

// Run the library on load
if (typeof(jQuery) != 'undefined') {
	jQuery(function() {
		LbwpForm.initialize();
	});
}