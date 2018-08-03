/**
 * The CRM User Admin handler
 * @author Michael Sebel <michael@comotive.ch>
 */
var CrmUserAdmin = {

	/**
	 * Called on load, this initializes the user admin UI
	 */
	initialize : function()
	{
		if (crmAdminData.editedIsMember) {
			CrmUserAdmin.cleanUpInterface();
			CrmUserAdmin.wrapElementsInTabs();
			CrmUserAdmin.prepareUI();
		} else {
			CrmUserAdmin.makeUiVisible();
		}
	},

	/**
	 * Register add and delete functions for contacts
	 */
	registerContactEvents : function()
	{
		// Add a new row (and maybe remove message that there are no contacts)
		jQuery('.add-contact').off('click').on('click', function() {
			var container = jQuery(this).closest('.contact-editor-container');
			var keyPrefix = container.data('input-key');
			var allowNeutral = container.data('allow-neutral') == 1;
			var allowDelete = container.data('allow-delete') == 1;
			var body = container.find('tbody');

			// Define the required fields, options and delete button
			var deleteBtn = '';
			var required = ' required="required"';
			var options = crmAdminData.defaultSalutations;
			if (allowNeutral) {
				required = '';
				options = crmAdminData.neutralSalutations;
			}
			if (allowDelete) {
				deleteBtn = '<a href="javascript:void(0)" class="dashicons dashicons-trash delete-contact"></a>';
			}

			// Integrate the new row
			body.append(
				'<tr>' +
					'<td><select name="' + keyPrefix + '[salutation][]">' + options + '</select></td>' +
					'<td><input type="text" name="' + keyPrefix + '[firstname][]" ' + required + ' /></td>' +
					'<td><input type="text" name="' + keyPrefix + '[lastname][]" ' + required + ' /></td>' +
					'<td><input type="text" name="' + keyPrefix + '[email][]"  required="required" /></td>' +
					'<td>' + deleteBtn + '</td>' +
				'</tr>'
			);
			// Make sure to remove no results info
			body.find('.no-contacts').remove();
			// Update visibility of add buttons
			CrmUserAdmin.hideAddOnMaxContacts();
			// Reattach eventual delete events
			CrmUserAdmin.registerContactEvents();
		});

		// Delete the contact after confirmation
		jQuery('.delete-contact').off('click').on('click', function() {
			var button = jQuery(this);
			var body = button.closest('tbody');
			if (confirm(crmAdminData.text.sureToDelete)) {
				button.closest('tr').remove();
			}
			// If there are not more contacts, add a message row
			if (body.find('tr').length == 0) {
				body.append(
					'<tr class="no-contacts">' +
						'<td colspan="5">' + crmAdminData.text.noContactsYet + '</td>' +
					'</tr>'
				);
			}

			// Update visibility of add buttons
			CrmUserAdmin.hideAddOnMaxContacts();
		});

		// Copy the first contact into the first set of elements in the current table
		jQuery('.copy-main-contact').off('click').on('click', function() {
			// Get values of main contact (first found)
			var button = jQuery(this);
			var container = button.closest('.contact-table-container');
			var row = jQuery('.contact-table:first tbody tr:first');
			var salutation = row.find('select').val();
			var firstname = row.find('input[name*=firstname]').val();
			var lastname = row.find('input[name*=lastname]').val();
			var email = row.find('input[name*=email]').val();

			// Get the current table and its first row
			var copy = container.find('.contact-table tbody tr:first');
			if (copy.hasClass('no-contacts')) {
				button.prev().trigger('click');
				// Select it again as it is now a new empty row
				copy = container.find('.contact-table tbody tr:first');
			}

			// Now copy the values into the new fields
			copy.find('select').val(salutation);
			copy.find('input[name*=firstname]').val(firstname);
			copy.find('input[name*=lastname]').val(lastname);
			copy.find('input[name*=email]').val(email);
		});
	},

	/**
	 * Check if add buttons need to be hidden, if max. number of addresses is reached
	 */
	hideAddOnMaxContacts : function()
	{
		var buttons = jQuery('.add-contact');
		// Make all invisible
		buttons.hide();
		// Go trough all, count contacts and show if max is not reached
		buttons.each(function() {
			var button = jQuery(this);
			var container = button.closest('.contact-editor-container');
			var maxContacts = parseInt(container.data('max-contacts'));
			// If there are less contacts than max, show the button
			if (maxContacts > container.find('tbody tr:not([class])').length) {
				button.show();
			}
		});
	},

	/**
	 * Cleans up the interface to be later wrapped into the first tab
	 */
	cleanUpInterface : function()
	{
		var profile = jQuery('#your-profile');
		// Remove what is removed in any case
		profile.find('h2, h3, .yoast-settings, .author-section').remove();
		// Remove the whole upper table
		jQuery('.user-admin-color-wrap').closest('.form-table').hide();
		// Remove most of the fields of the user table
		jQuery('.user-role-wrap, .user-first-name-wrap, .user-last-name-wrap, .user-nickname-wrap, .user-display-name-wrap').hide();
		// Remove most of the fields of the email tabke
		jQuery('.user-url-wrap, .user-googleplus-wrap, .user-twitter-wrap, .user-facebook-wrap').hide();
		// Also, remove the biographical fields
		jQuery('.user-description-wrap, .user-profile-picture, .user-sessions-wrap').hide();
		// Make email readonly as it is autoset
		jQuery('.user-email-wrap input').attr('readonly', 'readonly');
		// Make use of proper html5 validation
		profile.removeAttr('novalidate');

		// If not admin, remove even more
		if (!crmAdminData.userIsAdmin) {
			// Hide E-Mail as it is auto set
			jQuery('.user-email-wrap').hide();
		}

		// Prepare all form tables to be wrapped into the main tab if they don't have a tab yet
		jQuery('.form-table:not([data-target-tab])').attr('data-target-tab', 'main');
	},

	/**
	 * Prepare various UI controls after wrapping elements
	 */
	prepareUI : function()
	{
		// Make the profile categories and easy to use chosen
		jQuery('#profileCategories').chosen();
		// Override some main default elements
		CrmUserAdmin.overrideMainTitle();
		CrmUserAdmin.overrideSaveButtonText();
		// Register events for contact editing
		CrmUserAdmin.registerContactEvents();
		CrmUserAdmin.hideAddOnMaxContacts();
		// Add our own required field handler
		CrmUserAdmin.handleRequiredFields();
	},

	/**
	 * Overrides the main title if possible
	 */
	overrideMainTitle : function()
	{
		if (crmAdminData.titleOverrideField.length > 0) {
			try {
				var value = jQuery('#' + crmAdminData.titleOverrideField).val();
				if (value.length > 0) {
					jQuery('.wp-heading-inline').text(value);
				}
			} catch (ex) {

			}
		}
	},

	/**
	 * Overrides the button text for saving a user
	 */
	overrideSaveButtonText : function()
	{
		if (crmAdminData.saveUserButton.length > 0) {
			jQuery('#submit').val(crmAdminData.saveUserButton);
		}
	},

	/**
	 * We use html5 required validation, but sometimes fields from other tabs than the current are
	 * required and the user doesn't see that. We help here by posting a message and marking the tab
	 */
	handleRequiredFields : function()
	{
		jQuery('#submit').on('click', function() {
			var allValid = true;
			jQuery('[required]').each(function() {
				var field = jQuery(this);
				field.removeClass('field-error');
				if (field.val() == '') {
					allValid = false;
					field.addClass('field-error');
					// Add a class to the corresponding tab
					var tabId = field.closest('.tab-container').data('tab-id');
					jQuery('.crm-tab[data-tab="' + tabId + '"]').addClass('error');
				}
			});

			if (!allValid && jQuery('.required-fields-message').length == 0) {
				jQuery('.wp-header-end').after(
					'<div class="updated error required-fields-message"><p>' + crmAdminData.text.requiredFieldsMessage + '</p></div>'
				);
			}
		});
	},

	/**
	 * Make the whole profile visible after loading
	 */
	makeUiVisible : function()
	{
		jQuery('#your-profile').show();
	},

	/**
	 * Wraps the basic profile fields and the other tabs, once loaded
	 */
	wrapElementsInTabs : function()
	{
		// Wrap all elements into their according tabs
		jQuery('[data-target-tab]').each(function() {
			var wrappable = jQuery(this);
			var container = jQuery('.container-' + wrappable.data('target-tab'));
			container.append(wrappable);
		});

		// Set the main tab active by showing its content
		jQuery('.container-main').show();

		// Handle tab navigation when clicked
		jQuery('nav.crm-navigation a').on('click', function() {
			var link = jQuery(this);
			var tabId = link.data('tab');
			link.siblings().removeClass('nav-tab-active');
			link.addClass('nav-tab-active');
			jQuery('.tab-container').hide();
			jQuery('.container-' + tabId).show();
			// Remember the last clicked tab
			jQuery.cookie('crmLastClickedTab', tabId, { expires: 1 });
		});

		// If there is a last clicked tab saved, trigger it
		var lastTabId = jQuery.cookie('crmLastClickedTab');
		if (typeof(lastTabId) == 'string' && lastTabId.length > 0) {
			jQuery('.crm-tab[data-tab="' + lastTabId + '"]').trigger('click');
		}

		// Look at every empty tab and remove it
		jQuery('.tab-container').each(function() {
			var container = jQuery(this);
			if (container.is(':empty')) {
				var id = container.data('tab-id');
				container.remove();
				jQuery('[data-tab=' + id + ']').remove();
			}
		});

		// Now, finally, make the UI visible
		CrmUserAdmin.makeUiVisible();
	}
};

jQuery(function() {
	CrmUserAdmin.initialize();
});