/**
 * Backend JS for external translation services
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpExternalTranslation = {

	/**
	 * Called once the DOM is ready for action
	 */
	initialize : function()
	{
		// Only do most stuff if on post.php page
		if (jQuery('body.post-php').length == 1) {
			LbwpExternalTranslation.addTranslationLinks();
			LbwpExternalTranslation.addTranslationEvents();
		}
	},

	/**
	 * Add the links to actually add a new translation
	 */
	addTranslationLinks : function()
	{
		jQuery('#post-translations table tr').each(function() {
			var row = jQuery(this);
			// Only look for translatable languages
			if (row.find('.pll_icon_add').length == 2) {
				var language = row.find('.pll-translation-column span').attr('lang').substring(0,2);
				var newRow = ' \
					<tr>\
						<th class="pll-language-column">&nbsp;</th>\
						<td class="hidden"></td>\
						<td class="pll-edit-column pll-column-icon"></td>\
						<td class="pll-translation-column">\
							<span class="dashicons dashicons-randomize" style="font-size:18px;color:#656565;"></span>\
							<a href="#" class="request-translation" data-language="' + language + '">\
								' + LbwpExternalTranslationConfig.texts.createTranslation +  '\
							</a>\
						</td>\
					</tr>\
				';
				row.after(newRow);
			}
		});
	},

	/**
	 * Add the events that happen requesting a new translation
	 */
	addTranslationEvents : function()
	{
		jQuery('.request-translation').click(function() {
			var url = LbwpExternalTranslationConfig.texts.currentUrl;
			url += '&extReqTranslation=' + jQuery(this).data('language');
			// Add a confirmation
			if (confirm(LbwpExternalTranslationConfig.texts.confirmTranslation)) {
				document.location.href = url;
			}
		});
	},

	/**
	 * Locks down a post completely
	 */
	lockPost : function()
	{
		// First, display a little message
		jQuery('.wp-header-end').after(
			'<div class="notice notice-warning"><p>' + LbwpExternalTranslationConfig.texts.translationInProgress + '</p></div>'
		);

		// Hide most of the pages content
		jQuery('#edit-slug-box, .postarea, .postbox').hide();
		jQuery('#minor-publishing-actions, #misc-publishing-actions, #publishing-action').hide();
		// And now, show the publish box, which only shows trash bin
		jQuery('#submitdiv').show();
	}
};

jQuery(function() {
	LbwpExternalTranslation.initialize();
});