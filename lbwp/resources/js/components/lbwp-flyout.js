/**
 * Provides a frontend flyout that is cookie handled
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpFlyout = {
	/**
	 * The actual element
	 */
	element : null,

	/**
	 * Initialize the library
	 */
	initialize : function()
	{
		LbwpFlyout.element = jQuery('.lbwp-flyout');
		if (LbwpFlyout.element.length > 0) {
			LbwpFlyout.showFlyout();
			LbwpFlyout.handleClosing();
		}
	},

	/**
	 * Shows the flyout if there's need to do so
	 */
	showFlyout : function()
	{
		// Is it even active, basically?
		var active = lbwpFlyoutConfig.isActive;
		// Is it a correct timeframe to show it?
		var ts = Math.round(new Date().getTime()/1000);
		active = (active && ts > lbwpFlyoutConfig.showFrom && ts < lbwpFlyoutConfig.showUntil);
		// Was it removed once in the past? then dont show
		active = (active && !LbwpFlyout.hasBeenRemovedInPast());

		// If the element is to be shown, show it
		if (active) {
			LbwpFlyout.element.removeAttr('style');
			//LbwpFlyout.element.insertBefore(jQuery('body'));
			jQuery('body').prepend(LbwpFlyout.element);
			LbwpFlyout.element.addClass('show');
		} else {
			// Not active, so remove the item from DOM
			LbwpFlyout.element.remove();
		}
	},

	/**
	 * On close, set a cookie to remember closing, and remove the flyout from DOM
	 */
	handleClosing : function()
	{
		jQuery('.lbwp-close-flyout').click(function() {
			jQuery.cookie('flyout-' + lbwpFlyoutConfig.cookieId, 1, { expires: 365, path: '/' });
			LbwpFlyout.element.remove();
		});
	},

	/**
	 * Checks if the item has been removed in the past
	 */
	hasBeenRemovedInPast : function()
	{
		return jQuery.cookie('flyout-' + lbwpFlyoutConfig.cookieId) == 1;
	}
};

// Start the fancyness
jQuery(function() {
	LbwpFlyout.initialize();
});