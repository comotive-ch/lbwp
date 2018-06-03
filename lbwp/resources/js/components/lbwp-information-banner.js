/**
 * Provides a frontend information banner that is cookie handled
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpInfoBanner = {
	/**
	 * The actual element
	 */
	element : null,

	/**
	 * Initialize the library
	 */
	initialize : function()
	{
		LbwpInfoBanner.element = jQuery('.lbwp-info-banner');
		if (LbwpInfoBanner.element.length > 0) {
			LbwpInfoBanner.showBanner();
			LbwpInfoBanner.handleClosing();
		}
	},

	/**
	 * Shows the flyout if there's need to do so
	 */
	showBanner : function()
	{
		// Is it even active, basically?
		var active = lbwpInfoBannerConfig.isActive;
		// Is it a correct timeframe to show it?
		var ts = Math.round(new Date().getTime()/1000);
		active = (active && ts > lbwpInfoBannerConfig.showFrom && ts < lbwpInfoBannerConfig.showUntil);
		// Was it removed once in the past? then dont show
		active = (active && !LbwpInfoBanner.hasBeenRemovedInPast());

		// If the element is to be shown, show it
		if (active) {
			LbwpInfoBanner.element.removeAttr('style');
			jQuery('body').prepend(LbwpInfoBanner.element);
			LbwpInfoBanner.element.addClass('show');
		} else {
			// Not active, so remove the item from DOM
			LbwpInfoBanner.element.remove();
		}
	},

	/**
	 * On close, set a cookie to remember closing, and remove the flyout from DOM
	 */
	handleClosing : function()
	{
		jQuery('.lbwp-close-info-banner').click(function() {
			jQuery.cookie(lbwpInfoBannerConfig.cookieId, 1, { expires: (365*10) , path: '/' });
			LbwpInfoBanner.element.remove();
		});
	},

	/**
	 * Checks if the item has been removed in the past
	 */
	hasBeenRemovedInPast : function()
	{
		return jQuery.cookie(lbwpInfoBannerConfig.cookieId) == 1;
	}
};

// Start the fancyness
jQuery(function() {
	LbwpInfoBanner.initialize();
});