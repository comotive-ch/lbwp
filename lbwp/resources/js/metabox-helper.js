/**
 * Backend JS functions for the metabox helper
 */
jQuery(function() {

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

    // hide "Bild entfernen"-link
    // eventCallerElement.hide();

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

	// Autocomplete for post assign field
	if (jQuery('#newAssignedItem').length > 0) {
		jQuery('#newAssignedItem').autocomplete({
			minLength : 3,
			source : '/wp-admin/admin-ajax.php?action=mbhAssignPostsData',
			select : function(event, selection) {
				// Add this to the assigned item list (bottom)
				mbhAssignedPosts_addItem(selection.item.id, selection.item.value);
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
			mbhAssignedPosts_addItem(item.id, item.value);
		});
	}
});

/**
 * Displays a row of an assigned post element
 * @param postId the post id that is assigned
 * @param postTitle the title of the post for displaying
 */
function mbhAssignedPosts_addItem(postId, postTitle)
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
}