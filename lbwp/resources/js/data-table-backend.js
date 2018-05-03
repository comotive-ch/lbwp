
jQuery(function() {
	// Provide Export functions
	jQuery('.export').click(function() {
		var link = jQuery(this);
		jQuery('#exportType').val(link.data('type'));
		jQuery('#exportEncoding').val(link.data('encoding'));
		jQuery('#exportForm').fadeIn('fast', function() {
			// Make the column selector a multi select sortable chosen
			jQuery('.col-select').chosen();
			jQuery('.col-select').chosenSortable();
			// Set the chosen container just as fat as the table
			var width = jQuery('.wrap').width();
			jQuery('.chosen-container').css('width', width + 'px');
		});
	});

	// Provide closing function for export
	jQuery('.export-cancel, .export-start').click(function() {
		jQuery('#exportForm').fadeOut('fast');
	});

	// Delete a row after confirmation
	jQuery('.delete-row').click(function() {
		if (confirm('Datensatz wirklich löschen?')) {
			// Actually delete the row on the table
			jQuery.post(ajaxurl, {
				'action' : 'deleteDataTableRow',
				'formId' : jQuery(this).data('formid'),
				'eventId' : jQuery('#eventId').val(),
				'rowIndex' : jQuery(this).data('index')
			});

			// Remove the row visually
			jQuery(this).closest('tr').fadeOut('fast', function() {
				jQuery(this).remove();
			})
		}
	});

	// Make field editable and show the save icon
	jQuery('.edit-row').click(function() {
		// Switch icons
		var eventId = jQuery('#eventId').val();
		var row = jQuery(this).closest('tr');
		row.find('.save-row').show();
		row.find('.edit-row').hide();
		// Make all textareas visible, hide text version
		row.find('td span').hide();
		row.find('td textarea').fadeIn('fast');
		// Get the next row, to see if its a hidden event info row
		if (eventId > 0) {
			row.next().css('display', 'table-row');
		}
	});

	// Make a previously edited row saveable, and revert to the edit icon
	jQuery('.save-row').click(function() {
		// Switch icons
		var eventId = jQuery('#eventId').val();
		var row = jQuery(this).closest('tr');
		row.find('.edit-row').show();
		row.find('.save-row').hide();

		// Get the new data array from all textareas
		var data = {};
		row.find('textarea').each(function() {
			var area = jQuery(this);
			var newValue = area.val();
			area.prev().text(newValue);
			data[area.data('key')] = newValue;
		});

		// Gather event data too
		if (eventId > 0) {
			var eventData = {
				'eventId' : eventId,
				'subscriberId' : row.next().find('.subscriberId').val(),
				'subscribed' : false,
				'subscribers' : 0
			};
			// See if there is meaningful data to push
			if (row.next().find('[data-key=subscribe-yes]').is(':checked')) {
				eventData.subscribed = true;
			}
			// Subscribes are easier, but parseInt them
			eventData.subscribers = parseInt(row.next().find('[data-key=subscribers]').val());
		}

		// Actually save the row, and switch back to text mode
		jQuery.post(ajaxurl, {
			'action' : 'editDataTableRow',
			'formId' : jQuery(this).data('formid'),
			'rowIndex' : jQuery(this).data('index'),
			'rowData' : data,
			'eventInfo' : eventData
		});

		// Switch back to normal mode
		row.find('td textarea').hide();
		row.find('td span').show();
		// Get the next row, to see if its a hidden event info row
		if (eventId > 0) {
			row.next().css('display', 'none');
		}
	});
});