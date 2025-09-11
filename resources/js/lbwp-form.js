/**
 * LBWP form javascript library
 */
var LbwpForm = {

	/**
	 * This is run on DOM loaded
	 */
	initialize : function()
	{
		// Change of the form item selector
		jQuery('#formItemSelect').change(function() {
			var code = jQuery(this).val();
			jQuery('#formItemCode').val(code);
		});

		// Change of the form action selector
		jQuery('#formActionSelect').change(function() {
			var code = jQuery(this).val();
			jQuery('#formActionCode').val(code);
		});
	}
};

// Load the library on startup
jQuery(function() {
	LbwpForm.initialize();
});