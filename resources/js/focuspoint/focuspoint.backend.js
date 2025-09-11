/**
 * Focus Point setting backend
 * @author Michael Sebel <michael@comotive.ch>
 */
var FocusPointBackend = {
	/**
	 * Caches focus point information by attachment id, so we can load it from
	 * here if an image is called multiple times (and especially saved)
	 */
	pointCache : [],

	/**
	 * Fired on dom ready
	 */
	initialize : function()
	{
		FocusPointBackend.handleIntegration();
	},

	/**
	 * Handle the integration between media manager and our own dialog
	 */
	handleIntegration : function()
	{
		// Global handler, because media manager cancels everything
		jQuery(document).on(
			'click',
			'.focus-point-frame',
			FocusPointBackend.openDialog
		);
	},

	/**
	 * Opens the dialog to set the focus point on an image
	 * @param event
	 */
	openDialog : function(event)
	{
		// Preset all data
		var details = FocusPointBackend.getCurrentDetails();
		var attId = jQuery('ul.attachments li.attachment.selected').data('id');
		if (typeof(attId) == 'undefined') {
			// Context of media library, try getting it elsewhere
			attId = jQuery('.focuspoint-image-template').data('id');
		}
		jQuery('#focuspointAttachmentId').val(attId);
		// Get information from link, or cache, if given
		if (typeof(FocusPointBackend.pointCache[attId]) == 'object') {
			var point = FocusPointBackend.pointCache[attId];
			jQuery('#focuspointX').val(point.x);
			jQuery('#focuspointY').val(point.y);
		} else {
			var focusLink = details.next().find('.focus-point-frame');
			if (focusLink.length == 0) {
				focusLink = details.find('.focus-point-frame');
			}
			jQuery('#focuspointX').val(focusLink.data('x'));
			jQuery('#focuspointY').val(focusLink.data('y'));
		}

		// Now, open the dialog
		jQuery('#focuspoint-dialog').dialog({
      resizable: false,
      modal: true,
      height: 535,
      width: 600,
			open : FocusPointBackend.prepareDialog,
			close : FocusPointBackend.closeAndResetDialog,
      buttons: {
        "Speichern": FocusPointBackend.saveDialog,
        "Abbrechen" : FocusPointBackend.closeAndResetDialog
      }
    });
	},

	/**
	 * Depening on current context, get the attachment details
	 */
	getCurrentDetails : function()
	{
		var details = false;
		jQuery('.attachment-details').each(function() {
			if (jQuery(this).is(':visible')) {
				details =  jQuery(this);
			}
		});

		return details;
	},

	/**
	 * Prepare the dialog to handle the image focus point setting
	 * @param event the fired event by jqueryui
	 */
	prepareDialog : function(event)
	{
		var container = jQuery(event.target);
		var dialog = container.closest('.ui-dialog');
		var details = FocusPointBackend.getCurrentDetails();

		// Get (copy) the image into the dialog in its wfull size
		var clonedImage = details.next().find('.focuspoint-image-template').clone();
		if (clonedImage.length == 0) {
			clonedImage = details.find('.focuspoint-image-template').clone();
		}
		var pointerImage = jQuery('.pointer-template').clone();
		clonedImage.addClass('focus-point-edit-image');
		clonedImage.removeClass('focuspoint-image-template');
		pointerImage.removeClass('pointer-template');

		// Flush content of container, add pointer, cloned image and resize the div containing the image
		jQuery('#focuspoint-image').html('')
			.append(pointerImage)
			.append(clonedImage);
		// Make sure to adjust size if necessary
		setTimeout(function() {
			jQuery('#focuspoint-image')
				.css('width', clonedImage.width() + 'px')
			  .css('height', clonedImage.height() + 'px');
			// Register a click on the image to adjust the focus point
			clonedImage.click(FocusPointBackend.handleFocusChange);
			pointerImage.click(function(e) { FocusPointBackend.handleFocusChange(e); });
			// Call updating of pointer image depending on hidden fields
			FocusPointBackend.alignHelperPointer();
		}, 500);
		// Also move the dialog
		setTimeout(function() {
			jQuery('.ui-widget-overlay').css('z-index', 164000);
			dialog.css('z-index', 165000);
			dialog.css('width', (clonedImage.width() + 25) + 'px');
			dialog.css('position', 'absolute');
			dialog.css('top', '100px');
		}, 100);
	},

	/**
	 * Handles the change of the focus point
	 * @param event
	 */
	handleFocusChange : function(event)
	{
		var image = jQuery('.focus-point-edit-image');
		// Calculate FocusPoint coordinates
		var offsetX = event.pageX - image.offset().left;
		var offsetY = event.pageY - image.offset().top;
		var focusX = (offsetX / image.width() - .5) * 2;
		var focusY = (offsetY / image.height() - .5) * -2;

		// Set the values back to our fields
		jQuery('#focuspointX').val(focusX.toFixed(2));
		jQuery('#focuspointY').val(focusY.toFixed(2));

		// Directly re-align the helper pointer based on new data
		FocusPointBackend.alignHelperPointer();
	},

	/**
	 * Align the helper image for the user to see where the current focus is set.
	 * This recalculates the percentage from the focus coordinates.
	 */
	alignHelperPointer : function()
	{
		var image = jQuery('.focus-point-edit-image');
		var focusX = jQuery('#focuspointX').val();
		var focusY = jQuery('#focuspointY').val();
		var offsetX = (focusX / 2 + .5) * image.width();
		var offsetY = (focusY / -2 + .5) * image.height();
		var percentageX = (offsetX / image.width()) * 100;
		var percentageY = (offsetY / image.height()) * 100;

		jQuery('.focuspoint-pointer').css({
			'top': percentageY + '%',
			'left': percentageX + '%'
		});
	},

	/**
	 * Save the focus point via ajax back to post/attachment meta data
	 * @param event jqueryui event object
	 */
	saveDialog : function(event)
	{
		var attId = jQuery('#focuspointAttachmentId').val();
		// Set the data array to be sent back to the server
		var data = {
			action : 'saveFocuspointMeta',
			attachmentId : attId,
			focusX : jQuery('#focuspointX').val(),
			focusY : jQuery('#focuspointY').val(),
		};

		// Just post and assume its saved
		jQuery.post(ajaxurl, data);

		// Save the point in pointCache for this attachment ID
		FocusPointBackend.pointCache[attId] = {
			x : data.focusX,
			y : data.focusY
		};

		// Finished, close the dialog
		FocusPointBackend.closeAndResetDialog();
	},

	/**
	 * Close and reset the dialog
	 */
	closeAndResetDialog : function()
	{
		jQuery('#focuspoint-image').removeAttr('style');
		jQuery('#focuspoint-dialog').dialog('close');
	}
};

// Initialize as soon as ready
jQuery(function() {
	FocusPointBackend.initialize();
});