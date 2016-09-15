if (typeof(LbwpTableEditor) == 'undefined') {
	var LbwpTableEditor = {};
}

/**
 * This handles preparation and tabbing of the interface as well as saving
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpTableEditor.Interface = {

	/**
	 * Initializes the interface and then loads the sub interfaces
	 */
	initialize: function()
	{
		// Prepare the interface to be used
		LbwpTableEditor.Interface.cleanInterface();
		LbwpTableEditor.Interface.loadInterface();
		LbwpTableEditor.Interface.handleLeaving();
		LbwpTableEditor.Interface.prepareEditor();
	},

	/**
	 * Remove all metaboxes, permalink settings and make the layout 1 column
	 */
	cleanInterface: function()
	{
		// Remove metaboxes (make invisible, because parts of it are needed)
		jQuery('.postbox-container').hide();
		// Remove permalink box
		jQuery('#edit-slug-box').hide();
		// Switch to 1 column layout
		jQuery('#post-body').removeClass('columns-2').addClass('columns-1');
		// Create form container with loading text
		var text = (LbwpTableEditor.Core.isNewTable) ? '' : LbwpTableEditor.Text.editorLoading;
		var formContainer = '<div class="table-editor-container">' + text + '</div>';
		jQuery('#post-body-content').after(formContainer);
	},

	/**
	 * This loads the skeleton of the interface by ajax.
	 * After loading, the sub interfaces (Form, Action, Settings) are initialized
	 */
	loadInterface: function()
	{
		var data = {
			tableId: LbwpTableEditor.Core.tableId,
			isNew : LbwpTableEditor.Core.isNewTable,
			action: 'getTableInterfaceHtml'
		};

		// Get the UI and form infos
		jQuery.post(ajaxurl, data, function (response) {
			// Add the new container after the title bar
			jQuery('.table-editor-container').html(response.tableHtml);
			// Update with form content if available
			if (response.hasData) {
				// Set the json object
				LbwpTableEditor.Data = response.tableJson;
				LbwpTableEditor.Core.updateJsonField();
				LbwpTableEditor.Cells.updateBackendHtml();
			} else {
				// Still add the empty data to have an object to work with
				LbwpTableEditor.Data = response.tableJson;
			}

			// Add the UI events itself
			LbwpTableEditor.Interface.addInterfaceEvents();
			LbwpTableEditor.Core.hasChanges = false;
		});
	},

	/**
	 * Handles leaving the form editor
	 */
	handleLeaving : function()
	{
		window.onbeforeunload = function() {
			if (LbwpTableEditor.Core.hasChanges) {
				return "Möchten Sie diese Website verlassen?";
			}
		};
	},

	/**
	 * Attach all main UI events, like saving and tabbing
	 */
	addInterfaceEvents: function()
	{
		// Let the user add new rows and cols
		jQuery('.add-new-row').click(LbwpTableEditor.Cells.addNewRow);
		jQuery('.add-new-col').click(LbwpTableEditor.Cells.addNewColumn);
		// Let the user change table settings
		jQuery('.table-settings-button').click(LbwpTableEditor.Cells.showTableSettings);

		// Add the preview button event
		jQuery('.table-preview-button').click(function() {
			jQuery('#sample-permalink a').trigger('click');
			window.open(jQuery('#sample-permalink a').attr('href'));
			return false;
		});

		// Add a click event to that new button that executes the "original" save
		jQuery('.save-table-button').click(function () {
			// Set hasChanges to false, so the user doesn't see a leave prompt
			LbwpTableEditor.Core.hasChanges = false;
			jQuery('#publish').trigger('click');
		});
	},

	/**
	 * Saves the data to our main array and updates json field for saving and updates html
	 * @param data
	 */
	saveData : function(data)
	{
		// Save data back into the main array
		LbwpTableEditor.Data.data = data;
		// After rebuilding the data, update it in field and reload html
		LbwpTableEditor.Core.updateJsonField();
		LbwpTableEditor.Cells.updateBackendHtml();
	},

	/**
	 * Prepare the editor container to be used
	 */
	prepareEditor : function()
	{
		jQuery('body').append('<div id="editor-container" class="modal-container-generic"></div>');
		// Get that inserted container object
		var container = jQuery('#editor-container');

		// Move the editor object into the container
		var editor = jQuery('#lbwp-table__table-helper .cell-editor');
		container.append(editor);

		// Also, add close-, save buttons and hiddens
		container.append('<a class="button save-cell-editor">' + LbwpTableEditor.Text.saveCellContent + '</a>');
		container.append('<a class="dashicons dashicons-no-alt button editor-close-modal"></a>');
		container.append('<input type="hidden" id="editedCellId" value="" />');

		// Add events onto the buttons
		container.find('.save-cell-editor').click(LbwpTableEditor.Interface.saveCurrentCellEditor);
		container.find('.editor-close-modal').click(LbwpTableEditor.Interface.closeCurrentCellEditor);

	},

	/**
	 * Show the cell editor
	 * @param cellId the cell id being edited
	 * @param html content
	 */
	showCellEditor : function(cellId, html)
	{
		// Input the content into the editor
		if (jQuery('.mce-container').is(':visible')) {
			tinyMCE.activeEditor.setContent(html);
		} else {
			jQuery('textarea.wp-editor-area').val(html)
		}

		// Show the container
		jQuery('#editedCellId').val(cellId);
		jQuery('#editor-container').css('bottom', 0);
		jQuery('.media-modal-backdrop-mbh').fadeIn('fast');
	},

	/**
	 * Save the current editor back into the cell and hide editor
	 */
	saveCurrentCellEditor : function()
	{
		var cellId = jQuery('#editedCellId').val();
		var c = LbwpTableEditor.Cells.getCoordsFromCellId(cellId);
		var data = LbwpTableEditor.Data.data;
		var newContent = '';

		// Get the edited content from our editor
		if (jQuery('.mce-container').is(':visible')) {
			newContent = tinyMCE.activeEditor.getContent();
		} else {
			newContent = jQuery('textarea.wp-editor-area').val();
		}

		// Put this into the data array
		data[c.x][c.y].content = newContent;

		// Update the data object
		LbwpTableEditor.Interface.saveData(data);
		// Close the editor
		LbwpTableEditor.Interface.closeCurrentCellEditor();
	},

	/**
	 * Close editor without saving
	 */
	closeCurrentCellEditor : function()
	{
		// Remove current cell id and hide the container
		jQuery('#editedCellId').val('');
		jQuery('#editor-container').css('bottom', -10000);
		jQuery('.media-modal-backdrop-mbh').fadeOut('fast');
	}
};
