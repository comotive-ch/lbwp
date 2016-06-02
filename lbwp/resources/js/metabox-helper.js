/**
 * Metabox helper JS class
 * @author Michael Sebel <michael@comotive.ch>
 */
var MetaboxHelper = {

	/**
	 * Called on loading DOM
	 */
	initialize : function()
	{
		MetaboxHelper.handleMediaHelper();
		MetaboxHelper.handleDynamicPostHelper();
		MetaboxHelper.handleInlineModal();
		MetaboxHelper.handleAddingNewItems();
	},

	/**
	 * Handle events for removing and changing media items
	 */
	handleMediaHelper : function()
	{
		/**
		 * use to remove an image from media upload
		 */
		jQuery('.mbhRemoveMedia').on('click', function(event) {
			event.preventDefault();

			var eventCallerElement = jQuery(this);
			// search relative .media-uploader
			var editorContainer = eventCallerElement.closest('.mbh-field').find('.media-uploader');

			// reset selected image id
			editorContainer.find('.field-attachment-id').val('').trigger('change');

			// remove css class "has-attachment"
			editorContainer.removeClass('has-attachment');

			// reset image wrapper
			editorContainer.find('.wrapper').html('');
			editorContainer.find('.wrapper').attr('style', '');

			return false;
		});

		/**
		 * controll the "Bild entfernen"-link
		 */
		jQuery('.field-attachment-id').on('change', function() {
			var eventCallerElement = jQuery(this);
			var attachmentId = parseInt(eventCallerElement.val());
			var mediaHiddenField = eventCallerElement.closest('.mbh-field').find('.mbhRemoveMedia');

			if (isNaN(attachmentId)) {
				mediaHiddenField.hide();
			} else {
				mediaHiddenField.show();
			}
		});
	},

	/**
	 * Handles ajav for dynamic post assignment search
	 */
	handleDynamicPostHelper : function()
	{
		// Autocomplete for post assign field
		if (jQuery('#newAssignedItem').length > 0) {
			jQuery('#newAssignedItem').autocomplete({
				minLength : 3,
				source : '/wp-admin/admin-ajax.php?action=mbhAssignPostsData',
				select : function(event, selection) {
					// Add this to the assigned item list (bottom)
					MetaboxHelper.addAssignedItem(selection.item.id, selection.item.value);
				}
			});

			// Prevent submit of form when no item is selected
			jQuery('#newAssignedItem').keydown(function(event) {
				if (event.keyCode == 13) {
					return false;
				}
			});
		}

		// Traverse trough mbhAssignPostsData if available
		if (typeof(mbhAssignedPostsData) != 'undefined' && jQuery.isArray(mbhAssignedPostsData)) {
			jQuery.each(mbhAssignedPostsData, function(key, item) {
				MetaboxHelper.addAssignedItem(item.id, item.value);
			});
		}
	},

	/**
	 * Displays a row of an assigned post element
	 * @param postId the post id that is assigned
	 * @param postTitle the title of the post for displaying
	 */
	addAssignedItem : function(postId, postTitle)
	{
		// Create the item
		var html = '<div class="mbh-assigned-post-item">'
			+ '<input type="hidden" name="assignedPostsId[]" value=" ' + postId + '" />'
			+ '<div class="post-id">' + postId + '</div>'
			+ '<div class="post-title">' + postTitle + '</div>'
			+ '<a class="delete-link">Löschen</div>'
			+ '</div>';

		// Add it to the containers bottom
		jQuery('#mbh-assign-posts-container').append(html);
		jQuery('#newAssignedItem').val('');

		// Reassign the delete link click event
		jQuery('#mbh-assign-posts-container .delete-link').unbind('click');
		jQuery('#mbh-assign-posts-container .delete-link').click(function() {
			// Remove the whole box
			jQuery(this).parent().remove();
		});

		// Make the items sortable
		jQuery('#mbh-assign-posts-container').sortable();
	},

	/**
	 * Handles the use of modal inline-editable posts
	 */
	handleInlineModal : function()
	{
		// Allow to open iframe modals
		jQuery(document).on('click', 'a.open-modal', function() {
			var link = jQuery(this);
			jQuery('.media-modal-backdrop-mbh').show();
			jQuery('#metaboxHelper_frame').attr('src', link.attr('href'));
			jQuery('#metaboxHelperContainer').css('bottom', 0);
			return false;
		});

		// Allow closing of iframe modals
		jQuery(document).on('click', '.mbh-close-modal', function() {
			jQuery('.media-modal-backdrop-mbh').hide();
			jQuery('#metaboxHelper_frame').attr('src', '');
			jQuery('#metaboxHelperContainer').css('bottom', 10000);
		});
	},

	/**
	 * Handle the adding of new items directly inline into a post-type assign dropdown
	 */
	handleAddingNewItems : function()
	{
		jQuery('.add-new-dropdown-item a.button').on('click', function() {
			var link = jQuery(this);
			var input = link.closest('.add-new-dropdown-item').find('input');
			var data = {
				title : input.val(),
				postId : link.data('post-id'),
				postType : link.data('post-type'),
				selectId : link.data('original-select'),
				optionKey : link.data('option-key'),
				action : link.data('ajax-action')
			};

			// Check if there is an input
			if (data.title.length > 0 && data.postId > 0) {
				// Create the new post by ajax
				jQuery.post(ajaxurl, data, function(response) {
					var select = jQuery('#' + data.selectId);
					select.append(jQuery(response.newOptionHtml));
					// Updated calls chosen events, ready calls our own afterwards
					select.trigger('chosen:updated');
					select.trigger('chosen:ready');
					// Remove the text from input
					input.val('');
				});

				// Set a waiting text and revert the error
				link.text('Wird hinzugefügt...');
				input.removeClass('error');
			} else {
				input.addClass('error');
			}
		});
	}
};

// Call the helper on DOM load
jQuery(function() {
	MetaboxHelper.initialize();
});

