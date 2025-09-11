/**
 * Metabox helper JS class
 * @author Michael Sebel <michael@comotive.ch>
 */
var MetaboxHelper = {
	/**
	 * By default do not prevent adding of new dropdown items
	 */
	preventAdd : false,
	/**
	 * Called on loading DOM
	 */
	initialize : function()
	{
		MetaboxHelper.handleMediaHelper();
		MetaboxHelper.handleDynamicPostHelper();
		MetaboxHelper.handleAddingNewItems();
		MetaboxHelper.handleAutosaving();
		MetaboxHelper.handleAutosaveListType();
		MetaboxHelper.handleEditableTables();
		MetaboxHelper.handleGroups();
		MetaboxHelper.handleDateDefaults();
	},

	/**
	 * Handle date defaults on metabox date fields
	 */
	handleDateDefaults : function()
	{
		var selectors = [];
		var fields = jQuery('[data-default-date-from]');

		// First create all source selectors (which can be added multiple times)
		fields.each(function() {
			var id = '#' + jQuery(this).data('default-date-from');
			if (jQuery.inArray(id, selectors) < 0) {
				selectors.push(id);
			}
		});

		// Create a multi selector from that, but only if there are selectors
		if (selectors.length == 0) {
			return;
		}
		selectors = selectors.join(', ');

		// Add change event on every source, to be delivered on change to the defaultings
		jQuery(selectors).on('change', function() {
			var source = jQuery(this);
			var id = source.attr('id');
			var date = source.val();
			// If it is a valid date, set as default for all connected
			if (date.length > 0 && date.indexOf('.') > 0) {
				var datepickers = jQuery('[data-default-date-from="' + id + '"]');
				datepickers.each(function(datepicker) {
					jQuery(this).datepicker('option', { 'defaultDate': date });
				});
			}
		});

		// Call the event to be triggered initially, to eventually set the default on fields that have not been set yet
		setTimeout(function() {
			jQuery(selectors).trigger('change')
		}, 50);
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
	 * Handles the autosave of a list type
	 */
	handleAutosaveListType : function()
	{
		jQuery('body.post-type-lbwp-mailing-list select[data-metakey="list-type"]').on('change', function() {
			// Trigger save button if available
			jQuery('#save-post').trigger('click');
		});
	},

	/**
	 * Automatically saves the order of the phoenix... the sortable. sorry.
	 * @param element the dom element that has been sorted
	 * @param list a list of ids in the new corrected order
	 */
	handleAutosaveChosenSortable : function(element, list)
	{
		var flag = element.closest('.mbh-input').find('select').attr('id');
		// Save the new order of ids into the post meta
		jQuery.post(ajaxurl + '?action=updateChosenSortOrder', { flag: flag, ids : list.val() });
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
			jQuery('#metaboxHelperContainer').show();
			return MetaboxHelper.preventBubbling(event);
		});

		// Allow closing of iframe modals
		jQuery('.mbh-close-modal').off('click').on('click', function(event) {
			jQuery('.media-modal-backdrop-mbh').hide();
			jQuery('#metaboxHelper_frame').attr('src', '');
			jQuery('#metaboxHelperContainer').hide();
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
	},

	/**
	 * Handle editable tables (add / delete)
	 */
	handleEditableTables : function()
	{
		jQuery('.mbh-editable-table-add').on('click', function() {
			var link = jQuery(this);
			var columns = link.data('columns');
			var table = link.closest('.mbh-item-normal').find('.mbh-editable-table');
			var nextId = parseInt(table.data('last-id')) + 1;
			console.log(nextId);
			var key = table.data('key');
			var html = '';

			// Build html for the new row and attach it
			html += '<tr>';
			for (var field in columns) {
				html += '<td><input type="text" name="' + key + '[' + nextId + '][' + field + ']" value="" /></td>'
			}
			// If deletion is allowed
			if (table.find('.deletion-allowed').length === 1) {
				html += '<td><a href="javascript:void(0)" class="mbh-editable-table-delete dashicons dashicons-trash"></a></td>';
			}
			html += '</tr>';
			table.find('tbody').append(html);
			// Add this new id back to the table for the next new row
			table.data('last-id', nextId);
			// Re-Attach delete events
			MetaboxHelper.handleEditableTableDelete();
		});

		// Attach delete events for existing entries
		MetaboxHelper.handleEditableTableDelete();
	},

	/**
	 * Handle deletion of editable table entries
	 */
	handleEditableTableDelete : function()
	{
		jQuery('.mbh-editable-table-delete').off('click').on('click', function() {
			if (confirm('Möchten Sie den Datensatz wirklich löschen?')) {
				jQuery(this).closest('tr').remove();
			}
		});
	},

	/**
	 * Handles groups
	 */
	handleGroups : function()
	{
		// Give every mbh-item-normal a group id
		var groupId = '';
		var totalGroups = 0;
		jQuery('.mbh-item-normal').each(function() {
			var item = jQuery(this);
			var grouper = item.find('.mbh-field-grouper');
			if (grouper.length === 1) {
				totalGroups++;
				groupId = grouper.data('id');
			}
			item.attr('data-group-id', groupId);
		});

		if (totalGroups > 0) {
			// Set every tinymce to code mode, as eventual moving will kill them
			setTimeout(function() {
				jQuery('.wp-switch-editor.switch-html').click();
			}, 1000);
		}

		// Handle the clicks to move up and down
		jQuery('.mbh-move-group-up').on('click', function() {
			MetaboxHelper.moveGroup(jQuery(this), 'up');
		});
		jQuery('.mbh-move-group-down').on('click', function() {
			MetaboxHelper.moveGroup(jQuery(this), 'down');
		});
	},

	/**
	 * Move a group above or below the next one
	 * @param trigger
	 * @param direction
	 */
	moveGroup : function(trigger, direction)
	{
		var group = trigger.closest('.mbh-field-grouper');
		var id = group.attr('data-id');
		var groups = jQuery('.mbh-field-grouper.start');
		var currentId = parseInt(group.attr('data-item'));
		var switchId = (direction === 'up') ? currentId-1 : currentId+1;
		var switchClass = (direction === 'up') ? 'start' : 'end';

		// Don't do anything if doing something is impossible
		if (switchId === -1 || switchId === groups.length) {
			return;
		}

		// Find the items in the current group that should be switched
		var items = jQuery('.mbh-item-normal[data-group-id="' + id + '"]');
		// find the group item of the switched group
		var switchPointItem = jQuery('.mbh-field-grouper.' + switchClass + '[data-item="' + switchId + '"]').closest('.mbh-item-normal');
		// Position them after the switchpoint, but in reverse order to maintain the actual order (for down)
		if(direction === 'up'){
			for (var i = 0; i < items.length; i++) {
				switchPointItem.before(items[i]);
			}
		}else{
			for (var i = items.length-1;i >= 0; i--) {
				switchPointItem.after(items[i]);
			}
		}

		// Reset the item ids for the next switch
		var itemId = 0;
		jQuery('.mbh-field-grouper.start').each(function() {
			jQuery(this).attr('data-item', itemId++)
		});
		itemId = 0;
		jQuery('.mbh-field-grouper.end').each(function() {
			jQuery(this).attr('data-item', itemId++)
		});
	}
};

// Call the helper on DOM load
jQuery(function() {
	MetaboxHelper.initialize();
});

