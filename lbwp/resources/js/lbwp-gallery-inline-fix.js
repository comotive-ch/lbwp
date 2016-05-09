/**
 * Fixes the inline heights and widths and styles and bad classes from WordPress
 * @author Michael Sebel <michael@comotive.ch>
 */
jQuery(function() {

	// Add the alignment class to caption objects, if found
	jQuery('.wp-caption').each(function() {
		var caption = jQuery(this);
		// check for image with a wp-size
		var image = caption.find('img[class*="size-"]');
		if (image.length > 0) {
			// Get the class and add it to the caption
			caption.addClass(image.attr('class'));
			caption.css('width', '');
			// While at it, remove fixed sizes on the image
			InlineFix_removeImageSizes(image);
		}
	});

	// Fix fixed heights on images inserted with WP
	jQuery('img[class*="size-"]').each(function() {
		InlineFix_removeImageSizes(jQuery(this));
	});

	// Fix fixed heights on wordpress galleries
	jQuery('div.gallery .gallery-item img').each(function() {
		InlineFix_removeImageSizes(jQuery(this));
	});

	// Function to remove image sizes compatible
	function InlineFix_removeImageSizes(image)
	{
		try {
			image.attr('width', '');
			image.attr('height', '');
			image.removeAttr('width');
			image.removeAttr('height');
		} catch (exception) {
			// Nothing to do here
		}
	}
});