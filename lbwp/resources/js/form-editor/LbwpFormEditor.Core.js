if (typeof(LbwpFormEditor) == 'undefined') {
	var LbwpFormEditor = {};
}

/**
 * Global functions library and invoking of sub components
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpFormEditor.Core = {
	/**
	 * The id of the current form
	 */
	formId: 0,
	/**
	 * Determine if the form is a new one (and hence needs some prefilling and preparation)
	 */
	isNewForm : false,
	/**
	 * Determines if the form has changes
	 */
	hasChanges : false,
	/**
	 * Selector for action fields
	 */
	actionFieldSelector : '#editor-action-tab .frame-right .field-settings .lbwp-editField',
	/**
	 * Selector for action fields
	 */
	itemFieldSelector : '#editor-form-tab .frame-right .field-settings .lbwp-editField',
	/**
	 * Valid field selector suffix
	 */
	validFieldSelector : '.forms-item:not(.send-button)',

	/**
	 * The main initializer, that also loads the sub classes
	 */
	initialize: function () {
		// Get the form id and set defaults
		LbwpFormEditor.Core.formId = jQuery('#post_ID').val();
		LbwpFormEditor.Core.isNewForm = (adminpage == 'post-new-php');
		// Initialize the empty data object
		LbwpFormEditor.Data = {};
		// Initialize the interface
		LbwpFormEditor.Interface.initialize();
	},

	/**
	 * Update the hidden field with current editor data
	 */
	updateJsonField: function () {
		var json = JSON.stringify(LbwpFormEditor.Data);
		LbwpFormEditor.Core.hasChanges = true;
		jQuery('#formJson').val(json);
	},

	/**
	 * Key split handling
	 * @param key
	 * @returns {*}
	 */
	handleKey : function(key) {
		// Special handling by doing a split
		if (key.indexOf('zipcity_') != -1) {
			return key.split('-')[0];
		}

		// Everything else gets the key 1:1
		return key;
	}
};

// Loading the editor on load (eh..)
jQuery(function () {
	LbwpFormEditor.Core.initialize();
});
