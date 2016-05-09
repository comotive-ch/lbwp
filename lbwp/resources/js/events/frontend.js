/**
 * The events frontend
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpEventFrontend = {

	/**
	 * Assign all needed events
	 */
	initialize : function()
	{
		LbwpEventFrontend.handleAutoSubmit();
	},

	/**
	 * Auto submit on filter changes
	 */
	handleAutoSubmit : function()
	{
		jQuery('.event-autosubmit').change(function() {
			jQuery(this).closest('form').submit();
		});
	}
};


jQuery(function() {
	LbwpEventFrontend.initialize();
});