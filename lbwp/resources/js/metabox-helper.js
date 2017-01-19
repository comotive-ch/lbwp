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
		MetaboxHelper.handleAddingNewItems();
		MetaboxHelper.handleAutosaving();
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
		jQuery('a.open-modal').off('click').on('click', function(event) {
			var link = jQuery(this);
			jQuery('.media-modal-backdrop-mbh').show();
			jQuery('#metaboxHelper_frame').attr('src', link.attr('href'));
			jQuery('#metaboxHelperContainer').css('bottom', 0);
			return MetaboxHelper.preventBubbling(event);
		});

		// Allow closing of iframe modals
		jQuery('.mbh-close-modal').off('click').on('click', function(event) {
			jQuery('.media-modal-backdrop-mbh').hide();
			jQuery('#metaboxHelper_frame').attr('src', '');
			jQuery('#metaboxHelperContainer').css('bottom', -10000);
			return MetaboxHelper.preventBubbling(event);
		});
	},

	/**
	 * Handles removal of objects whilst trashing them
	 */
	handleElementRemoval : function()
	{
		jQuery('a.trash-element').off('click').on('click', function(event) {
			if (confirm("Möchten Sie den Inhalt wirklich aus der Liste entfernen und löschen?")) {
				var choice = jQuery(this);
				// Trash the item via ajax and directly save in case the user leaves the page
				jQuery.post(ajaxurl, {
					action : 'trashAndRemoveItem',
					metaKey : choice.closest('.mbh-input').find('select').data('metakey'),
					elementId : choice.data("id"),
					postId : jQuery('#post_ID').val()
				});

				// Remove the element by triggering the remove action
				choice.closest('.search-choice').find('.search-choice-close').trigger('click');
			}

			return MetaboxHelper.preventBubbling(event);
		});
	},

	/**
	 * Prevent bubbling of an event (primarily used for chosen)
	 * @param event
	 * @returns {boolean} always false
	 */
	preventBubbling : function(event)
	{
		event.preventDefault();
		event.stopPropagation();
		return false;
	},

	/**
	 * Handle the adding of new items directly inline into a post-type assign dropdown
	 */
	handleAddingNewItems : function()
	{
		jQuery('.add-new-dropdown-item a.button').on('click', function() {
			var link = jQuery(this);
			var input = link.closest('.add-new-dropdown-item').find('input');
			var typeDropdown = link.closest('.add-new-dropdown-item').find('select[name=metaDropdown]');
			// Create data array to add the post item
			var data = {
				title : input.val(),
				postId : link.data('post-id'),
				postType : link.data('post-type'),
				selectId : link.data('original-select'),
				optionKey : link.data('option-key'),
				action : link.data('ajax-action')
			};

			// Add type information, if given
			if (typeDropdown.length == 1) {
				data.typeKey = typeDropdown.data('key');
				data.typeValue = typeDropdown.val();
			}

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
					link.text('Hinzufügen');
				});

				// Set a waiting text and revert the error
				link.text('Wird hinzugefügt...');
				input.removeClass('error');
			} else {
				input.addClass('error');
			}
		});
	},

	/**
	 * Automatically fires a save function
	 */
	handleAutosaving : function()
	{
		jQuery('.mbh-autosave-on-change').change(function() {
			var saveButton = jQuery('#save-post');
			if (saveButton.length == 1) {
				saveButton.trigger('click');
			} else {
				jQuery('#publish').trigger('click');
			}
		});
	},

	/**
	 * Handles all chosen events
	 * @param key (html id) of the chosen
	 */
	handleChosenEventsOnChange : function(chosenKey, optionKey)
	{
		// Create a placeholder (fixed) on search input, if findable
		var search = jQuery('#' + chosenKey + ' .search-field input');
		if (search.length > 0 && search.prop('placeholder').length == 0) {
			search.prop('placeholder', '+');
		}

		// Change the options to contain images or use "html" data attribute to represent them
		jQuery('#' + chosenKey + ' .search-choice').each(function() {
			// Get all options and the index via close button, to access data from it
			var options = jQuery('#' + optionKey).find('option');
			var index = parseInt(jQuery(this).find('.search-choice-close').data('option-array-index'));

			if (options.length > 0 && !isNaN(index)) {
				var option = options[index];
				if (jQuery(option).data('image')) {
					if (!jQuery(this).hasClass('has-image')) {
						jQuery('span', this).before('<img class="search-choice-image" src="' + jQuery(option).data('image') + '" />');
						jQuery(this).addClass('has-image');
					}
				}
				if (jQuery(option).data('html') && jQuery(option).data('html').length > 0) {
					jQuery('span', this).after(jQuery(option).data('html'));
					jQuery('span', this).remove();
				}
			}
		});

		// Re-Register edit and delete events
		MetaboxHelper.handleInlineModal();
		MetaboxHelper.handleElementRemoval();
	}
};

// Call the helper on DOM load
jQuery(function() {
	MetaboxHelper.initialize();
});

