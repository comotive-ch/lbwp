if (typeof(LbwpTableEditor) == 'undefined') {
	var LbwpTableEditor = {};
}

/**
 * This handles everything in the cells
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpTableEditor.Cells = {

	/**
	 * Get the backend html, and adds events afterwards
	 */
	updateBackendHtml: function()
	{
		var data = {
			action : 'getBackendTableHtml',
			json : LbwpTableEditor.Data
		};
		jQuery.post(ajaxurl, data, function(response) {
			jQuery('.table-container').html(response.html);
			LbwpTableEditor.Cells.addBackendEvents();
		});
	},

	/**
	 * Add all the events on the backend html table
	 */
	addBackendEvents : function()
	{

	},

	/**
	 * Add a new row at the set point
	 */
	addNewRow : function()
	{
		var key = 0;
		var data = [];
		var copy = LbwpTableEditor.Data.data;
		var position = jQuery('select[name=newRow]').val();

		switch (position) {
			case 'top':
				// Rebuild the actual data array, use first row as template
				data.push(LbwpTableEditor.Cells.copyRow(copy[0]));
				for (key in copy) data.push(copy[key]);
				break;
			case 'bottom':
			default:
				// Rebuild the actual data array
				for (key in copy) data.push(copy[key]);
				// Use the last row as template
				data.push(LbwpTableEditor.Cells.copyRow(copy[copy.length-1]));
				break;
		}

		// Save data back into the main array
		LbwpTableEditor.Data.data = data;

		// After rebuilding the data, update it in field and reload html
		LbwpTableEditor.Core.updateJsonField();
		LbwpTableEditor.Cells.updateBackendHtml();

		return false;
	},

	/**
	 * Add a new column at the set point
	 */
	addNewColumn : function()
	{
		var key = 0;
		var copy = LbwpTableEditor.Data.data;
		var position = jQuery('select[name=newColumn]').val();

		switch (position) {
			case 'left':
				copy.forEach(function(row, rowIndex) {
					// Rebuild the row, duplicating first element
					var newRow = [];
					// Make the duplicate, flush content and add it first
					var cellDuplicate = jQuery.extend(true, {}, row[0]);
					cellDuplicate.content = '';
					newRow.push(cellDuplicate);
					// Now add all other cells
					for (key in row) newRow.push(row[key]);
					copy[rowIndex] = newRow;
				});
				break;
			case 'right':
			default:
				copy.forEach(function(row) {
					// Make the duplicate and add it to the existing row
					var cellDuplicate = jQuery.extend(true, {}, row[row.length-1]);
					cellDuplicate.content = '';
					row.push(cellDuplicate);
					return row;
				});
				break;
		}

		// Save data back into the main array
		LbwpTableEditor.Data.data = copy;

		// After rebuilding the data, update it in field and reload html
		LbwpTableEditor.Core.updateJsonField();
		LbwpTableEditor.Cells.updateBackendHtml();
		return false;
	},

	/**
	 * Show the general table settings dialog
	 */
	showTableSettings : function()
	{
		alert('table-settings not implemented yet');
		return false;
	},

	/**
	 * Copy a row and remove contents
	 * @param row
	 */
	copyRow : function(row)
	{
		var newRow = [];
		row.forEach(function(cell, key) {
			newRow[key] = jQuery.extend(true, {}, cell);
			newRow[key].content = '';
		});

		return newRow;
	}
};

