/**
 * Focus Point setting backend
 * @author Michael Sebel <michael@comotive.ch>
 */
var FocusPointBackend = {

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
		jQuery('#focuspoint-dialog').dialog({
      resizable: false,
      modal: true,
      height: 'auto',
      width: 600,
			open : FocusPointBackend.prepareDialog,
      buttons: {
        "Speichern": FocusPointBackend.saveDialog,
        "Abbrechen" : function() {
          jQuery(this).dialog('close');
        }
      }
    });

		// Set the attachment ID to the hidden field of our dialog
		setTimeout(function() {
			var attId = jQuery('.attachment-details').data('id');
			jQuery('#focuspointAttachmentId').val(attId);
			var focusLink = jQuery('.focus-point-frame');
			jQuery('#focuspointX').val(focusLink.data('x'));
			jQuery('#focuspointY').val(focusLink.data('y'));
			// Also,
		}, 500);
	},

	/**
	 * Prepare the dialog to handle the image focus point setting
	 * @param event the fired event by jqueryui
	 */
	prepareDialog : function(event)
	{
		var container = jQuery(event.target);
		var dialog = container.closest('.ui-dialog');

		// Get (copy) the image into the dialog in its wfull size
		var clonedImage = jQuery('.focuspoint-image-template').clone();
		var pointerImage = jQuery('.pointer-template').clone();
		clonedImage.addClass('focus-point-edit-image');
		clonedImage.removeClass('focuspoint-image-template');
		pointerImage.removeClass('pointer-template');
		clonedImage.css('max-width', '100%');
		// Flush content of container, add pointer, cloned image and resize the div containing the image
		jQuery('#focuspoint-image').html('')
			.append(pointerImage)
			.append(clonedImage)
			.width(clonedImage.width());

		// Register a click on the image to adjust the focus point
		clonedImage.click(FocusPointBackend.handleFocusChange);
		pointerImage.click(function(e) { FocusPointBackend.handleFocusChange(e); });

		// Call updating of pointer image depending on hidden fields
		FocusPointBackend.alignHelperPointer();

		// Re-position the dialog (with just a few more zindex than media modal
		jQuery('.ui-widget-overlay').css('z-index', 164000);
		dialog.css('z-index', 165000);
		dialog.css('position', 'absolute');
		dialog.css('top', '50px');
		dialog.css('left', Math.max(0, (
			(jQuery(window).width() - jQuery(this).outerWidth()) / 2) + jQuery(window).scrollLeft()) + 'px'
		);
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
		console.log('save focus point');

		// Finished, close the dialog
		jQuery(this).dialog('close');
	}
};

// Initialize as soon as ready
jQuery(function() {
	FocusPointBackend.initialize();
});