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
	 * Initialize all those helpers we need
	 */
	initialize : function()
	{
		// Handle image cache-busting in image editor
		LbwpBackend.handleImageCacheBusting();
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