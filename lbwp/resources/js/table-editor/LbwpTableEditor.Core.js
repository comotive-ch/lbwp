if (typeof(LbwpTableEditor) == 'undefined') {
	var LbwpTableEditor = {};
}

/**
 * Global functions library and invoking of sub components
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpTableEditor.Core = {
	/**
	 * The id of the current form
	 */
	tableId: 0,
	/**
	 * Determine if the form is a new one (and hence needs some prefilling and preparation)
	 */
	isNewTable : false,
	/**
	 * Determines if the form has changes
	 */
	hasChanges : false,

	/**
	 * The main initializer, that also loads the sub classes
	 */
	initialize: function () {
		// Get the form id and set defaults
		LbwpTableEditor.Core.tableId = jQuery('#post_ID').val();
		LbwpTableEditor.Core.isNewTable = (adminpage == 'post-new-php');
		// Don't treat as new table, if there is a new_lang parameter
		if (document.location.search.indexOf('new_lang') >= 0) {
			LbwpTableEditor.Core.isNewTable = false;
		}
		// Initialize the empty data object
		LbwpTableEditor.Data = {};
		// Initialize the interface
		LbwpTableEditor.Interface.initialize();
	},

	/**
	 * Update the hidden field with current editor data
	 */
	updateJsonField: function () {
		var json = JSON.stringify(LbwpTableEditor.Data);
		LbwpTableEditor.Core.hasChanges = true;
		jQuery('#tableJson').val(json);
	}
};

// Loading the editor on load (eh..)
jQuery(function () {
	LbwpTableEditor.Core.initialize();
});
