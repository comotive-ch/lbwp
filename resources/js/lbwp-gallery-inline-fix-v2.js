/**
 * Fixes the inline heights and widths and styles and bad classes from WordPress
 * @author Michael Sebel <michael@comotive.ch>
 */
jQuery(function() {

	// Add the alignment class to caption objects, if found
	jQuery('.wp-caption').each(function() {
		var caption = jQuery(this);
		// check for image with a wp-size
		var image = caption.find('img[class*="size-"], figure[class*="size-"] img');
		if (image.length > 0) {
			// Get the class and add it to the caption
			caption.addClass(image.attr('class'));
			caption.css('width', '');
			// While at it, remove fixed sizes on the image
			InlineFix_removeImageSizes(image);
		}
	});

	// Fix fixed heights on images inserted with WP
	jQuery('img[class*="size-"], figure[class*="size-"] img').each(function() {
		InlineFix_removeImageSizes(jQuery(this));
	});

	// Fix fixed heights on wordpress galleries
	jQuery('div.gallery .gallery-item img').each(function() {
		InlineFix_removeImageSizes(jQuery(this));
	});

	// Find all images that are just images or linked, but have no wrap around them.
	// Wrap them, and within the wrapper copy all classes from img to wrapper.
	jQuery('.lbwp-editor-content img[class*="wp-image-"]').each(function() {
		InlineFix_MaybeWrapImage(jQuery(this));
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

	// Wrap image or linked image with a div and move the classes of img to the div
	function InlineFix_MaybeWrapImage(image)
	{
		var parent = image.parent();
		var wrappable = image;
		// Check if this is an a, then, parent() again
		if (parent.prop('tagName') == 'A') {
			wrappable = parent;
			parent = parent.parent();
		}

		// The ultimate parent now needs to be a paragraph, then we can proceed
		if (parent.prop('tagName') == 'P') {
			wrappable.wrap('<div class="' + image.attr('class') + '"></div>');
			image.removeAttr('class');
		}
	}
});