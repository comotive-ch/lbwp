/**
 * Callback function for the 'click' event of the 'Set Footer Image'
 * anchor in its meta box.
 *
 * Displays the media uploader for selecting an image.
 *
 * @since 0.1.0
 */
function renderMediaUploader() {
	'use strict';

	var file_frame, image_data;

	/**
	 * If an instance of file_frame already exists, then we can open it
	 * rather than creating a new instance.
	 */
	if (undefined !== file_frame) {

		file_frame.open();
		return;

	}

	/**
	 * If we're this far, then an instance does not exist, so we need to
	 * create our own.
	 *
	 * Here, use the wp.media library to define the settings of the Media
	 * Uploader. We're opting to use the 'post' frame which is a template
	 * defined in WordPress core and are initializing the file frame
	 * with the 'insert' state.
	 *
	 * We're also not allowing the user to select more than one image.
	 */
	file_frame = wp.media.frames.file_frame = wp.media({
		frame: 'post',
		state: 'insert',
		multiple: false
	});

	/**
	 * Setup an event handler for what to do when an image has been
	 * selected.
	 *
	 * Since we're using the 'view' state when initializing
	 * the file_frame, we need to make sure that the handler is attached
	 * to the insert event.
	 */
	file_frame.on('insert', function () {
		// Read the JSON data returned from the Media Uploader
		var image = file_frame.state().get('selection').first().toJSON();
		console.log(image,genericLastSaveTo);
		// First, make sure that we have the URL of an image to display
		if (0 > jQuery.trim(image.url.length)) {
			return;
		}

		// After that, set the properties of the image and display it
		var container = jQuery('#' + genericLastSaveTo);
		var imgElement = container.children('img');
		// If there is no image yet, create it
		if (imgElement.length === 0) {
			container.append('<img src="" />');
			imgElement = container.children('img');
		}

		// Set the image
		imgElement
			.attr('src', image.url)
			.show()
			.parent()
			.removeClass('hidden');

		// Also, set the id to the corresponding hidden field
		jQuery('input[name=' + genericLastSaveTo + ']').val(image.id)
	});

	// Now display the actual file_frame
	file_frame.open();

}

(function ($) {
	'use strict';

	jQuery(function () {
		jQuery('.generic-media-upload').on('click', function (evt) {
			genericLastSaveTo = jQuery(this).data('save-to');
			// Stop the anchor's default behavior
			evt.preventDefault();
			// Display the media uploader
			renderMediaUploader();
		});

		jQuery('.remove-generic-media-upload').on('click', function() {
			var imageId = jQuery(this).data('remove-image');
			jQuery('input[name=' + imageId + ']').val('');
			jQuery('#' + imageId).addClass('hidden');
		});
	});

})(jQuery);

var genericLastSaveTo = '';