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
		// Row actions
		jQuery('.row-settings').click(LbwpTableEditor.Cells.showRowSettings);
		jQuery('.row-delete').click(LbwpTableEditor.Cells.deleteRow);
		jQuery('.row-move-up').click(LbwpTableEditor.Cells.moveRowUp);
		jQuery('.row-move-down').click(LbwpTableEditor.Cells.moveRowDown);

		// Column actions
		jQuery('.column-settings').click(LbwpTableEditor.Cells.showColumnSettings);
		jQuery('.column-delete').click(LbwpTableEditor.Cells.deleteColumn);
		jQuery('.column-move-left').click(LbwpTableEditor.Cells.moveColumnLeft);
		jQuery('.column-move-right').click(LbwpTableEditor.Cells.moveColumnRight);

		// Cell actions
		jQuery('.edit-cell-content').click(LbwpTableEditor.Cells.showCellContentDialog);
		jQuery('.edit-cell-settings').click(LbwpTableEditor.Cells.showCellSettingsDialog);
		jQuery('.responsive-cell').hover(
			LbwpTableEditor.Cells.onCellHoverIn,
			LbwpTableEditor.Cells.onCellHoverOut
		);
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

		// Save the data and update html
		LbwpTableEditor.Interface.saveData(data);

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

		// Save the data and update html
		LbwpTableEditor.Interface.saveData(copy);

		return false;
	},

	/** TODO
	 * Shows the row settings (sames as cell settings, taken on all cells of the row)
	 */
	showRowSettings : function()
	{
		var button = jQuery(this);
		var rowIndex = button.parent().data('row-settings');
		alert('show settings for row: ' + rowIndex);
	},

	/**
	 * Deletes a row after confirmation
	 */
	deleteRow : function()
	{
		var button = jQuery(this);
		var rowIndex = button.parent().data('row-settings');

		// First of all, confirm deletion
		if (confirm(LbwpTableEditor.Text.confirmRowDelete.replace('{x}', rowIndex+1))) {
			// Build a new table without that index
			var newTable = [];
			LbwpTableEditor.Data.data.forEach(function(row, index) {
				if (index != rowIndex) {
					newTable.push(row);
				}
			});

			// Save the data and update html
			LbwpTableEditor.Interface.saveData(newTable);
		}
	},

	/**
	 * Moves a row up, if not already on top
	 */
	moveRowUp : function()
	{
		var button = jQuery(this);
		var rowIndex = button.parent().data('row-settings');
		var copy = LbwpTableEditor.Data.data;

		// Only move, if not on top
		if (rowIndex > 0) {
			// Switch the rows by their index, leaving references intact
			var switchedRow = copy[rowIndex - 1];
			copy[rowIndex - 1] = copy[rowIndex];
			copy[rowIndex] = switchedRow;
			// Save this copy
			LbwpTableEditor.Interface.saveData(copy);
		}
	},

	/**
	 * Moves a row down, if not already on bottom
	 */
	moveRowDown : function()
	{
		var button = jQuery(this);
		var rowIndex = button.parent().data('row-settings');
		var copy = LbwpTableEditor.Data.data;
		var highestIndex = (copy.length - 1);

		// Only move down if possible
		if (rowIndex < highestIndex) {
			// Switch the rows by their index, leaving references intact
			var switchedRow = copy[rowIndex + 1];
			copy[rowIndex + 1] = copy[rowIndex];
			copy[rowIndex] = switchedRow;
			// Save this copy
			LbwpTableEditor.Interface.saveData(copy);
		}
	},

	/** TODO
	 * Shows the column settings (sames as cell settings, taken on all cells of the column)
	 */
	showColumnSettings : function()
	{
		var button = jQuery(this);
		var colIndex = button.parent().data('col-settings');
		alert('show settings for col: ' + colIndex);
	},

	/**
	 * Deletes a column after confirmation
	 */
	deleteColumn : function()
	{
		var button = jQuery(this);
		var copy = LbwpTableEditor.Data.data;
		var colIndex = button.parent().data('col-settings');

		// First of all, confirm deletion
		if (confirm(LbwpTableEditor.Text.confirmColDelete.replace('{x}', colIndex+1))) {
			// Go trough each row of the copy and unset col at index
			copy.forEach(function(row) {
				row.splice(colIndex, 1);
			});

			// And save this
			LbwpTableEditor.Interface.saveData(copy);
		}
	},

	/**
	 * Moves a column to the left (of not leftest)
	 */
	moveColumnLeft : function()
	{
		var button = jQuery(this);
		var colIndex = button.parent().data('col-settings');
		var switchedIndex = (colIndex - 1);
		var copy = LbwpTableEditor.Data.data;

		// Only go left, if there is some left to go to. Whew.
		if (colIndex > 0) {
			// Switch the indizes in every row for each cell
			copy.forEach(function(row, key) {
				var cell = copy[key][switchedIndex];
				copy[key][switchedIndex] = copy[key][colIndex];
				copy[key][colIndex] = cell;
			});
			// And save this
			LbwpTableEditor.Interface.saveData(copy);
		}
	},

	/**
	 * Moves a column to the right (if not rightest)
	 */
	moveColumnRight : function()
	{
		var button = jQuery(this);
		var colIndex = button.parent().data('col-settings');
		var switchedIndex = (colIndex + 1);
		var copy = LbwpTableEditor.Data.data;
		var rightestIndex = (copy[0].length - 1);

		// Only move right, if possible
		if (colIndex < rightestIndex) {
			// Switch the indizes in every row for each cell
			copy.forEach(function(row, key) {
				var cell = copy[key][switchedIndex];
				copy[key][switchedIndex] = copy[key][colIndex];
				copy[key][colIndex] = cell;
			});
			// And save this
			LbwpTableEditor.Interface.saveData(copy);
		}
	},

	/** TODO
	 * Show the general table settings dialog
	 */
	showTableSettings : function()
	{
		alert('table-settings not implemented yet');
		return false;
	},

	/**
	 * On hovering onto a cell
	 */
	onCellHoverIn : function()
	{
		jQuery(this).find('.cell-options').css('visibility', 'visible');
	},

	/**
	 * On leaving the cell with the mouse
	 */
	onCellHoverOut : function()
	{
		jQuery(this).find('.cell-options').css('visibility', 'hidden');
	},

	/**
	 * Shows the dialog for editing cell content with the editor
	 */
	showCellContentDialog : function()
	{
		var button = jQuery(this);
		// Get the cell id and make sure it is a splittable string
		var cellId = button.closest('.cell-options').parent().data('cell') + '';
		var c = LbwpTableEditor.Cells.getCoordsFromCellId(cellId);
		var content = LbwpTableEditor.Data.data[c.x][c.y].content;

		// Show the editor
		LbwpTableEditor.Interface.showCellEditor(cellId, content);
	},

	/** TODO
	 * Shows the dialog for editing the cell settings
	 */
	showCellSettingsDialog : function()
	{
		var button = jQuery(this);
		// Get the cell id and make sure it is a splittable string
		var cellId = button.closest('.cell-options').parent().data('cell') + '';

		alert('edit settings for cell: ' + cellId);
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
	},

	/**
	 * Get the coord object from cell id to access native data
	 * @param cellId
	 * @returns {{x: *, y: *}}
	 */
	getCoordsFromCellId : function(cellId)
	{
		var ids = cellId.split('.');
		return {
			'x' : parseInt(ids[0]),
			'y' : parseInt(ids[1])
		};
	}
};

