/**
 * Main entry point the frontend media uploader
 * @author Mirko Baffa <mirko@wesign.ch>
 */
 MediaUpload = {
	/**
	 * The wp fileframe
	 */
	fileFrame : null,

	/**
	* Initialize it
	*/
	initialize : function(){
		let uploadBtn = jQuery('.media-uploader__button');
		let imageContainer = jQuery(uploadBtn.attr('data-image-container'));
		let imageContainerHtml = imageContainer.clone();
		imageContainer.remove();

		uploadBtn.click(function(e){
			e.preventDefault();

			// If the fileframe is already initialized, open it
			if(MediaUpload.fileFrame !== null){
				MediaUpload.fileFrame.open();
				return;
			}

			// Init the media fileframe
			MediaUpload.fileFrame = wp.media.frames.file_frame = wp.media({
				title: uploadBtn.data('popup_title'),
				button: {
					text: uploadBtn.data('popup_button_text')
				},
				multiple: true
			});

			MediaUpload.fileFrame.on('select', function(){
				let attachments = [];
				MediaUpload.fileFrame.state().get('selection').each(function(attachment) {
					// TODO: Add alt title and description
					attachment = attachment.toJSON();
					attachments.push({
						id: attachment.id,
						url: attachment.url,
						// alt: attachment.alt,
						// title: attachment.title,
						// description: attachment.description
					});
				});
				//console.log(MediaUpload.fileFrame.state().get('selection'));

				// Do something with the file here
				//uploadBtn.hide();
				jQuery('input[name="media-uploader-images"]').val(JSON.stringify(attachments));
			});
	
			MediaUpload.fileFrame.open();
		});
	}
 };

// Actually initialize on load
jQuery(function () {
	MediaUpload.initialize();
});