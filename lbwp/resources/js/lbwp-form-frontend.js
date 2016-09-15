/**
 * The frontend JS for lbwp form
 */
var LbwpFormFrontend = {

	/**
	 * Called on page load, registering events and such
	 */
	initialize : function()
	{
		LbwpFormFrontend.moveEmailField();
		LbwpFormFrontend.preventDoubleClick();
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
			setTimeout(LbwpFormFrontend.checkFormEnable, 250);
			setTimeout(LbwpFormFrontend.checkFormEnable, 1000);
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
	}
};

// Run the library on load
if (typeof(jQuery) != 'undefined') {
	jQuery(function() {
		LbwpFormFrontend.initialize();
	});
}