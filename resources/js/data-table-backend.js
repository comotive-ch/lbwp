
jQuery(function() {
	// Privacy settings features
	jQuery('.open-privacy-settings').on('click', function() {
		jQuery('.privacy-settings').show();
	});
	jQuery('.close-privacy-settings').on('click', function() {
		jQuery('.privacy-settings').hide();
	});
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

			// Remove the row visually and rebuild the indexes (as they're not maintained when deleting
			jQuery(this).closest('tr').fadeOut('fast', function() {
				jQuery(this).remove();
				var sIndex = 0;
				jQuery('#lbwp-data-table tbody .save-row').each(function() {
					jQuery(this).attr('data-index', sIndex++);
				});
				var dIndex = 0;
				jQuery('#lbwp-data-table tbody .delete-row').each(function() {
					jQuery(this).attr('data-index', dIndex++);
				});
			});
		}
	});

	jQuery('.edit-row').click(function() {
		let row = jQuery(this).closest('.data-table-row');
		let modal = jQuery('.data-table-edit-modal');
		let fields = row.find('> td');

		modal.addClass('open');
		modal.attr('data-editing-row', jQuery(this).attr('data-index'));

		fields.each(function() {
			let field = jQuery(this);
			if(field.hasClass('options')){
				return;
			}

			let key = field.attr('data-key');
			let value = field.attr('data-value');
			jQuery('.data-table-edit-modal textarea[name="' + key + '"]').val(value);
		});

		let eventData = row.find('.data-table-edit-modal__subscriber-data');
		if(eventData.length > 0){
			let subFields = jQuery('.data-table-edit-modal__field.subscriber');
			let subscribe = [
				subFields.find('input[data-key="subscribe-yes"]'),
				subFields.find('input[data-key="subscribe-no"]')
			];

			subscribe[0].attr('name', 'subscribe-' + eventData.attr('data-subscriber-id'));
			subscribe[1].attr('name', 'subscribe-' + eventData.attr('data-subscriber-id'));

			if(Number(eventData.attr('data-subscribed')) === 1){
				subscribe[0][0].checked = true;
			}else{
				subscribe[1][0].checked = true;
			}
			subFields.find('input[data-key="subscribers"]').val(eventData.attr('data-subscribers'));
		}
	});

	jQuery('.data-table-edit-modal__close, .data-table-edit-modal__close-btn').click(function() {
		jQuery('.data-table-edit-modal').removeClass('open');
	});

jQuery('.data-table-edit-modal__field--submit input').click(function(e) {
		e.preventDefault();

		let data = {};
		let modal = jQuery('.data-table-edit-modal');
		modal.find('textarea').each(function() {
			let area = jQuery(this);
			let newValue = area.val();

			let tableField = jQuery('.data-table-row:nth-child(' + (Number(modal.attr('data-editing-row')) + 1) + ') td[data-key="' + area.attr('name') + '"] p');
			tableField.text(newValue);
			tableField.parent().attr('data-value', newValue);
			data[area.attr('name')] = newValue;
		});

		// Gather event data too
		let eventFields = modal.find('.data-table-edit-modal__field.subscriber');
		let eventId = Number(eventFields.attr('data-event-id'));
		let eventData = null;
		if (eventId > 0) {
			let subscribedField = eventFields.find('input[data-key="subscribe-yes"]');
			eventData = {
				'eventId' : eventId,
				'subscriberId' : subscribedField.attr('name').split('-')[1],
				'subscribed' : subscribedField[0].checked,
				'subscribers' : Number(eventFields.find('input[data-key="subscribers"]').val())
			};
			let tableEventField = jQuery('.data-table-edit-modal__subscriber-data[data-subscriber-id="' + eventData.subscriberId + '"]');
			tableEventField.attr('data-subscribed', eventData.subscribed ? 1 : 0);
			tableEventField.attr('data-subscribers', eventData.subscribers);
		}

		// Actually save the row, and switch back to text mode
		let postData = {
			'action': 'editDataTableRow',
			'formId': jQuery(this).data('formid'),
			'rowIndex': modal.attr('data-editing-row'),
			'rowData': data
		}

		if(eventData !== null){
			postData.eventInfo = eventData;
		}

		jQuery.post(ajaxurl, postData, () => {
			jQuery('.data-table-edit-modal').removeClass('open');
		});
	});

	// Fixed table header with scrollable body
	var theTable = jQuery('#lbwp-data-table');
	theTable
		.addClass('rendered')
		.parent()
		.css('max-height','calc(100vh - ' + (theTable.parent().offset().top + 75) + 'px)');

	// DEPRECATED
	/*var headings = [];

	// First collect all column width
	theTable.find('thead th').each(function(index, element) {
		var w = jQuery(element).css('width');
		jQuery(element).css('width', w);
		headings[index] = w;
	});

	// Add the column width to each cell of each first row
	theTable.find('tr:first-child').each(function() {
		jQuery(this).find('td, th').each(function(i) {
			if (headings[i].length > 0) {
				var cell = jQuery(this);
				var hasColspan = parseInt(cell.attr('colspan')) > 0;
				if (!hasColspan) {
					cell
						.css('width', headings[i])
						.css('min-width', headings[i])
						.css('max-width', headings[i]);
				}
			}
		});
	});*/

});