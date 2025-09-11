/**
 * Generic backend class for mini features
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpBackend = {
	/**
	 * @var bool false=invisible, true=active
	 */
	imageEditorLastState : false,
	/**
	 * @var bool false=invisible, true=active
	 */
	imageMediaModal : false,
	/**
	 * @var string the last media url that has been shown
	 */
	lastMediaUrlShown : '',

	/**
	 * Initialize all those helpers we need
	 */
	initialize : function()
	{
		this.globals = lbwpBackendData;

		// Handle image cache-busting in image editor
		LbwpBackend.handleImageCacheBusting();
		LbwpBackend.handleUnwantedNotices();
		LbwpBackend.handleSimpleConfirm();
		LbwpBackend.handleTableSorter();
		LbwpBackend.handleWoocommerce();
		LbwpBackend.pingForMediaModal();
	},

	/**
	 * Handle table sorter
	 */
	handleTableSorter : function()
	{
		if (jQuery.fn.tablesorter) {
			jQuery('.automatic-tablesort').tablesorter();
		}
	},

	/**
	 * Handles some crap in backend that woocommerce doas
	 */
	handleWoocommerce : function()
	{
		var otherPayments = jQuery('#settings-other-payment-methods');
		if (otherPayments.length) {
			otherPayments.closest('tr').remove();
		}
	},

	/**
	 * Handle image cache busting for the image editor to work properly
	 */
	handleImageCacheBusting : function()
	{
		// Add a random string on all possible images, on load
		LbwpBackend.addRandomStringToImages();

		// Randomize images to reload them, on changing views of the image editor
		if (jQuery('body.post-type-attachment').length == 1) {
			setInterval(function() {
				var currentState = jQuery('.image-editor').is(':visible');
				if (currentState != LbwpBackend.imageEditorLastState) {
					LbwpBackend.addRandomStringToImages();
					LbwpBackend.imageEditorLastState = currentState;
				}
			}, 750);
		}
	},

	/**
	 * This handles and shows a direct download link for files underneath the
	 * Input with the url for convenient downloading of files in a new window
	 */
	handleMediaDownloadLink : function()
	{
		setInterval(function() {
			var element = jQuery('.media-sidebar [data-setting=url] input');
			// If the element is present, print a link to the url beneath
			if (element.length > 0) {
				var url = element.val();
				if (url != LbwpBackend.lastMediaUrlShown && !LbwpBackend.isImage(url)) {
					var container = element.parent();
					container.find('.download-link').remove();
					container.append('<a href="' + url + '" class="download-link" target="_blank">Datei herunterladen</a>');
					LbwpBackend.lastMediaUrlShown = url;
				}
			}
		}, 2000);
	},

	/**
	 * Handle very simple generic JS confirms
	 */
	handleSimpleConfirm : function()
	{
		jQuery('a[data-lbwp-confirm]').click(function() {
			if (confirm(jQuery(this).data('lbwp-confirm'))) {
				return true;
			}
			return false;
		});
	},

	/**
	 * Handle the rewriting of secure asset links
	 */
	handleSecureAssets : function()
	{
		jQuery(document).on(
			'click',
			'#secured_asset',
			function() {
				if (jQuery(this).is(':checked')) {
					var input = jQuery('[data-setting=url] input');
					if (input.val().indexOf('/wp-file-proxy.php') < 0) {
						var sub = input.val().indexOf('/files/');
						if (sub > 0) {
							var key = input.val().substring(sub + 7);
							input.val(document.location.origin + '/wp-file-proxy.php?key=' + key);
						}
					}
				}
			}
		);
	},

	/**
	 * Check if it is an image
	 * @param url
	 * @returns {boolean}
	 */
	isImage : function(url)
	{
		return !(
			url.indexOf('.jpg') < 0 &&
			url.indexOf('.jpeg') < 0 &&
			url.indexOf('.png') < 0 &&
			url.indexOf('.gif') < 0
		);
	},

	/**
	 * Ping for media modal on a short basis to trigger more events
	 */
	pingForMediaModal : function()
	{
		LbwpBackend.pingMediaModal = setInterval(function() {
			if (jQuery('.media-modal-content, .media-frame-content').length > 0) {
				LbwpBackend.handleSecureAssets();
				LbwpBackend.handleMediaDownloadLink();
				clearInterval(LbwpBackend.pingMediaModal);
			}
		}, 2000);
	},

	/**
	 * Dismiss unwanted notices that are only hidden by CSS.
	 * This JS triggers clicks so notices are gone "forever"
	 */
	handleUnwantedNotices : function()
	{
		if (jQuery('.frash-notice').length > 0) {
			setTimeout(function() {
				jQuery('.frash-notice-dismiss').trigger('click');
			}, 1000);
		}
		if (jQuery('#welcome-panel').length > 0) {
			setTimeout(function() {
				jQuery('.welcome-panel-close').trigger('click');
			}, 1000);
		}
		if (jQuery('#yoast-first-time-configuration-notice').length > 0) {
			setTimeout(function() {
				jQuery('.notice-dismiss').trigger('click');
			}, 1000);
		}
		if (jQuery('#gf_dashboard_message').length > 0) {
			if (typeof(GFDismissUpgrade) == 'function') {
				GFDismissUpgrade();
			}
		}
		setTimeout(function() {
			let link = jQuery('.error a[href*=wpo_wcpdf_hide_no_dir_notice]');
			if (link.length === 1) {
				link[0].click();
			}
		}, 750);

		if (jQuery('#yoast-indexation-warning').length > 0) {
			setTimeout(function() {
				jQuery('#yoast-indexation-dismiss-button').trigger('click');
			}, 750);
		}
		// This dismisses all notices after 10 seconds (or almost immediate if not)
		if (jQuery('.is-dismissible').length > 0) {
			let visible = jQuery('.is-dismissible').is(':visible');
			let timeout = visible ? 10000 : 500;
			setTimeout(function() {
				jQuery('.is-dismissible .notice-dismiss').trigger('click');
			}, timeout);
		}
	},

	/**
	 * Add a random string to image urls to cache bust them on the fly
	 */
	addRandomStringToImages : function()
	{
		var randomString = Math.floor(Math.random() * (999 - 100)) + 100;
		jQuery('.details-image, .wp_attachment_image img, .imgedit-applyto img').each(function() {
			var image = jQuery(this);
			image.prop('src', image.prop('src') + '?' + randomString)
		});
	}
};

// Load on dom loaded
jQuery(function() {
	LbwpBackend.initialize();
});