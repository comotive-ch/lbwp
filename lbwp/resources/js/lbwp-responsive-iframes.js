/**
 * Simple class to operate with iframes
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpResponsiveIframes = {

	/**
	 * Filled with config before loading
	 */
	Config : {
		selectors : '',
		containerClasses : '',
		containerTag : '',
		wrapperClasses : '',
		wrapperTag : ''
	},

	/**
	 * Called after DOM is ready to operate
	 */
	initialize : function()
	{
		jQuery(LbwpResponsiveIframes.Config.selectors).each(function() {
			var config = LbwpResponsiveIframes.Config;
			var responsiveElement = jQuery(this);
			var ratio = responsiveElement.height() / responsiveElement.width() * 100;
			var container = '<' + config.containerTag + ' class="' + config.containerClasses + '"></' + config.containerTag + '>';

			// If there is a wrapper, wrap the container in it
			if (config.wrapperTag.length > 0) {
				var container = '<' + config.wrapperTag + ' class="' + config.wrapperClasses + '">' + container + '</' + config.wrapperTag + '>';
			}

			// Wrap our object in the container, then recalc its padding if padding makes sense
			responsiveElement.wrap(container);
			if (!isNaN(ratio) && isFinite(ratio) && ratio > 0) {
				responsiveElement.parent().css('padding-bottom', ratio + '%');
			}


		});
	}
};

// Init, override config and start class
jQuery(function() {
	if (typeof(lbwpResponsiveIframeConfig) != 'undefined') {
		LbwpResponsiveIframes.Config = lbwpResponsiveIframeConfig;
		LbwpResponsiveIframes.initialize();
	}
});