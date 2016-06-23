if (typeof(LbwpFormEditor) == 'undefined') {
	var LbwpFormEditor = {};
}

/**
 * This handles preparation and tabbing of the interface as well as saving
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpFormEditor.Interface = {

	/**
	 * Initializes the interface and then loads the sub interfaces
	 */
	initialize: function () {
		// Prepare the interface to be used
		LbwpFormEditor.Interface.cleanInterface();
		LbwpFormEditor.Interface.loadInterface();
		LbwpFormEditor.Interface.handleLeaving();
	},

	/**
	 * Remove all metaboxes, permalink settings and make the layout 1 column
	 */
	cleanInterface: function () {
		// Remove metaboxes (make invisible, because parts of it are needed)
		jQuery('.postbox-container').hide();
		// Remove permalink box
		jQuery('#edit-slug-box').hide();
		// Switch to 1 column layout
		jQuery('#post-body').removeClass('columns-2').addClass('columns-1');
		// Create form container with loading text
		var formContainer = '<div class="form-editor-container">' + LbwpFormEditor.Text.editorLoading + '</div>';
		jQuery('#post-body-content').after(formContainer);
	},

	/**
	 * Add the save button next to "erstellen" and make it execute the publish button
	 */
	addSaveButton: function () {
		// Create a new save button and edit the text of the create button
		var button = '<button class="button-primary save-form-button">' + LbwpFormEditor.Text.saveButton + '</button>';
		jQuery('.nav-tab-wrapper').append(button);

		// Add a click event to that new button that executes the "original" save
		jQuery('.save-form-button').click(function () {
			// Set hasChanges to false, so the user doesn't see a leave prompt
			LbwpFormEditor.Core.hasChanges = false;
			jQuery('#publish').trigger('click');
		});
	},

	/**
	 * This loads the skeleton of the interface by ajax.
	 * After loading, the sub interfaces (Form, Action, Settings) are initialized
	 */
	loadInterface: function () {
		var data = {
			formId: LbwpFormEditor.Core.formId,
			action: 'getInterfaceHtml'
		};

		// Get the UI and form infos
		jQuery.post(ajaxurl, data, function (response) {
			// Add the new container after the title bar
			jQuery('.form-editor-container').html(response.content);
			// Update with form content if available
			if (response.hasFormData) {
				LbwpFormEditor.Form.updateHtml(response.formHtml);
				// Set the json object
				LbwpFormEditor.Data = response.formJsonObject;
				LbwpFormEditor.Core.updateJsonField();
			} else {
				// Still add the empty data to have an object to work with
				LbwpFormEditor.Data = response.formJsonObject;
			}

			// Add the UI events itself
			LbwpFormEditor.Interface.addSaveButton();
			LbwpFormEditor.Interface.addEvents();
			LbwpFormEditor.Interface.initializeInterface();
			// After that, load the subinterfaces
			LbwpFormEditor.Interface.loadSubinterfaces();
		});
	},

	/**
	 * Handles leaving the form editor
	 */
	handleLeaving : function() {
		window.onbeforeunload = function() {
			if (LbwpFormEditor.Core.hasChanges) {
				return "Möchten Sie diese Website verlassen?";
			}
		};
	},

	/**
	 * Attach all main UI events, like saving and tabbing
	 */
	addEvents: function() {
		// Make the navigation tabbable
		jQuery('.nav-tab').click(LbwpFormEditor.Interface.onNavTabClick);
	},

	/**
	 * Click on a nav tab to switch between modes
	 */
	onNavTabClick: function () {
		// Unselect all tabs and hide them
		var container = jQuery('.form-editor-container');
		container.find('.nav-tab').removeClass('nav-tab-active');
		container.find('.form-editor-tab').hide();
		// Mark clicked as active and show tab
		var clickedTab = jQuery(this);
		clickedTab.addClass('nav-tab-active');
		jQuery(clickedTab.data('tab-id')).fadeIn('fast');
		// set cookie to current tab
		jQuery.cookie(LbwpFormEditor.Core.formId + 'tab', jQuery(this).attr("id"), { expires: 7 });
	},

	/**
	 * Initialize the interface by loading the form view
	 */
	initializeInterface: function () {
		// Execute a click on current editor view
		var tab = jQuery.cookie(LbwpFormEditor.Core.formId + 'tab');
		tab = tab == undefined ? "form-tab" : tab
		jQuery('#' + tab).trigger('click');
	},

	/**
	 * Load all subinterfaces so they can attach their own events
	 */
	loadSubinterfaces: function () {
		LbwpFormEditor.Form.initialize();
		LbwpFormEditor.Action.initialize();
		LbwpFormEditor.Settings.initialize();
		// Everything loaded, not reset changes
		LbwpFormEditor.Core.hasChanges = false;
	}
};
