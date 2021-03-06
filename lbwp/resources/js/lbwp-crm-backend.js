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
			CrmUserAdmin.handleSubAccountUI();
			CrmUserAdmin.handleUserListPage();
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
			var hiddenFields = container.data('hidden-fields');
			var allowNeutral = container.data('allow-neutral') == 1;
			var allowDelete = container.data('allow-delete') == 1;
			var optionalEmail = container.data('optional-email') == 1;
			var body = container.find('tbody');

			// Define the required fields, options and delete button
			var deleteBtn = '';
			var required = ' required="required"';
			var emailRequired = ' required="required"';
			var options = crmAdminData.defaultSalutations;
			if (optionalEmail) {
				emailRequired = '';
			}
			if (allowNeutral) {
				required = '';
				options = crmAdminData.neutralSalutations;
			}
			if (allowDelete) {
				deleteBtn = '<a href="javascript:void(0)" class="dashicons dashicons-trash delete-contact"></a>';
			}

			// Add core fields as needed
			var html = '<tr>';
			if (jQuery.inArray('salutation', hiddenFields) < 0)
				html += '<td><select name="' + keyPrefix + '[salutation][]">' + options + '</select></td>';
			if (jQuery.inArray('firstname', hiddenFields) < 0)
				html += '<td><input type="text" name="' + keyPrefix + '[firstname][]" ' + required + ' /></td>';
			if (jQuery.inArray('lastname', hiddenFields) < 0)
				html += '<td><input type="text" name="' + keyPrefix + '[lastname][]" ' + required + ' /></td>';
			if (jQuery.inArray('email', hiddenFields) < 0)
				html += '<td><input type="text" name="' + keyPrefix + '[email][]" ' + emailRequired + ' /></td>';

			// See if there are custom fields
			var customFields = container.find('.contact-custom-field');
			if (customFields.length > 0) {
				customFields.each(function() {
					html += '<td><input type="text" name="' + keyPrefix + '[' + jQuery(this).data('cfkey') + '][]" /></td>'
				})
			}

			// Delete button and close row
			html += '<td>' + deleteBtn + '</td></tr>';

			// Integrate the new row
			body.append(html);
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
			var container = body.closest('.contact-editor-container');
			var minContacts = container.data('min-contacts');
			var numberContacts = body.find('tr').length;

			// If we have same number of contacts as minimum, disallow deletion
			if (minContacts == numberContacts) {
				alert(crmAdminData.text.deleteImpossible.replace('{number}', minContacts));
				return;
			}

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
		jQuery('.user-user-login-wrap, .user-role-wrap, .user-first-name-wrap, .user-last-name-wrap, .user-nickname-wrap, .user-display-name-wrap').hide();
		// Remove most of the fields of the email tabke
		jQuery('.user-email-wrap, .user-url-wrap, .user-googleplus-wrap, .user-twitter-wrap, .user-facebook-wrap').hide();
		// Also, remove the biographical fields
		jQuery('.user-description-wrap, .user-profile-picture, .user-sessions-wrap').hide();
		// Make email readonly as it is autoset
		jQuery('.user-email-wrap input').attr('readonly', 'readonly');
		// Make use of proper html5 validation
		profile.removeAttr('novalidate');
		// Make the form a proper upload form, just in case
		profile.attr('enctype', 'multipart/form-data');

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
		CrmUserAdmin.handleHelpIcons();
		CrmUserAdmin.handleSubAccounts();
		CrmUserAdmin.handleHistoryIcons();
		CrmUserAdmin.handleCustomFieldTables();
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
	 * Opens and closes all help labels
	 */
	handleHelpIcons : function()
	{
		jQuery('.crmcf-description .dashicons, .contact-help .dashicons').on('click', function() {
			var help = jQuery(this).next();
			if (help.is(':visible')) {
				help.css('display', 'none');
			} else {
				help.css('display', 'block');
			}
		});

		// Remove all help icons that don't provide any text
		jQuery('.crmcf-description label:empty, .contact-help label:empty').parent().hide();
	},

	/**
	 * Handle displaying of field history
	 */
	handleHistoryIcons : function()
	{
		// Show fields that have a history
		if (crmAdminData.userIsAdmin) {
			jQuery('[data-history=1]').each(function () {
				var container = jQuery(this).closest('td');
				container.find('.crmcf-history').show();
			});
		}

		// Handle displaying of the history
		jQuery('.crmcf-history .dashicons').on('click', function() {
			jQuery(this).closest('.tab-container').find('[data-history=1]').each(function() {
				var container = jQuery(this).closest('td');
				var field = container.find('.crmcf-input');
				var data = {
					user_id: crmAdminData.editedUserId,
					key: field.data('field-key')
				};
				// Get the history block and replace the whole container
				jQuery.post(ajaxurl + '?action=getCrmFieldHistory', data, function (response) {
					if (response.success) {
						field.remove();
						container.find('.crmcf-history').remove();
						container.prepend('<div class="history-container">' + response.html + '</div>');
					}
				});
			});
		});
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
	 * Handle events on sub accounts
	 */
	handleSubAccounts : function()
	{
		CrmUserAdmin.handleAddSubAccount();
		CrmUserAdmin.handleDeleteSubAccount();
		CrmUserAdmin.handleGenericToggles();
	},

	/**
	 * Handles creation of sub accounts
	 */
	handleAddSubAccount : function()
	{
		jQuery('.crm-add-user-button').on('click', function() {
			var link = jQuery(this);
			var state = link.data('state');

			// Handle the closed state, it just shows the input fields
			if (state == 'closed') {
				link.text(link.data('save'));
				link.closest('.crm-new-user-forms').addClass('open');
				link.data('state', 'open');
			}

			// Handle the open state, validate and save the new user
			if (state == 'open') {
				var errors = 0;
				var email = jQuery('.crm-new-user-forms input[name="subaccount[email]"]');
				var firstname = jQuery('.crm-new-user-forms input[name="subaccount[firstname]"]');
				var lastname = jQuery('.crm-new-user-forms input[name="subaccount[lastname]"]');
				var password = jQuery('.crm-new-user-forms input[name="subaccount[password]"]');

				// Check if they are legitimate
				if (email.val().length == 0) { email.addClass('field-error'); errors++; }
				if (firstname.val().length == 0) { firstname.addClass('field-error'); errors++; }
				if (lastname.val().length == 0) { lastname.addClass('field-error'); errors++; }
				if (password.val().length == 0) { password.addClass('field-error'); errors++; }

				// If all is good, send the form by saving the whole thing
				if (errors == 0) {
					jQuery('#submit').trigger('click');
				}
			}
		});
	},

	/**
	 * Handle sub account deletion
	 */
	handleDeleteSubAccount : function()
	{
		jQuery('.delete-subaccount').on('click', function() {
			var link = jQuery(this);
			var row = link.closest('tr');
			var tick = row.find('.delete-subacc-tick');

			if (!row.hasClass('mark-for-deletion')) {
				if (confirm(crmAdminData.text.sureToDeleteSubAccount)) {
					row.addClass('mark-for-deletion');
					tick.val(1);
				}
			} else {
				// Unmark for deletion and reset tick
				row.removeClass('mark-for-deletion');
				tick.val(0);
			}
		});
	},

	/**
	 * Handles the sub account if if needed
	 */
	handleSubAccountUI : function()
	{
		// Only change the UI if matching subaccount role
		if (jQuery.inArray(crmAdminData.editedUserRole, crmAdminData.config.subaccountRoles) >= 0) {
			// First, do the full cleanup
			CrmUserAdmin.cleanUpInterface();
			// Now additionally reveal email, first and lastname again
			jQuery('.user-first-name-wrap, .user-last-name-wrap, .user-email-wrap').show();
		}
	},

	/**
	 * Handles some generic toggles
	 */
	handleGenericToggles : function()
	{
		jQuery('.crm-show-prev').on('click', function() {
			jQuery(this).prev().show();
		});
		jQuery('.crm-show-next').on('click', function() {
			jQuery(this).next().show();
		});
		jQuery('.crm-toggle-remove').on('click', function() {
			jQuery(this).remove();
		});
	},

	/**
	 * Make the whole profile visible after loading
	 */
	makeUiVisible : function()
	{
		jQuery('#profile-page').show();
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
	},

	/**
	 * Handle adding and deletion of table rows
	 */
	handleCustomFieldTables : function()
	{
		// Add a new row
		jQuery('.add-crmcf-row').on('click', function() {
			var table = jQuery(this.closest('.crmcf-table'));
			var key = table.data('key');
			var readonly = table.data('readonly');
			var disabled = table.data('disabled');
			var columns = table.find('thead td[data-slug]');

			// Create the row html
			var row = '<tr>';
			jQuery.each(columns, function(id, column) {
				row += '<td><input type="text" name="' + key + '[' + jQuery(column).data('slug') + '][]" /></td>';
			});
			row += '<td class="crmcf-head"><span class="dashicons dashicons-trash delete-crmcf-row"></span></td>';
			row += '</tr>';
			// And append the new row to the table
			table.append(row);
		});

		// Delete an existing row
		jQuery(document).on('click', '.delete-crmcf-row', function() {
			if (confirm("Möchten Sie diese Zeile wirklich löschen?")) {
				jQuery(this).closest('tr').remove();
			}
		});
	},

	/**
	 * Handles the overview page logic
	 */
	handleUserListPage : function()
	{
		if (jQuery('.users-php').length == 1) {
			// Handle removal of all if there is a default display group
			if (typeof(crmAdminData.config.misc.defaultDisplayRole) == 'string') {
				jQuery('.subsubsub .all').remove();
			}

			// Handle the active / inactive dropdown
			var dropdown = jQuery("#status-filter");
			jQuery(".tablenav-pages").prepend(dropdown);
			dropdown = jQuery("#status-filter");
			//dropdown = jQuery("#status-filter");
			dropdown.css("display", "inline");
			// Add functionality
			dropdown.on("change", function() {
				var params = CrmUserAdmin.getParams(document.location.search.substring(1));
				// Add or replace the status filter param
				params['status-filter'] = jQuery(this).val();
				var url = '/wp-admin/users.php?';
				jQuery.each(params, function(key, value) {
					console.log(key, value);
					url += key + '=' + value + '&';
				});

				// Reload with new params
				document.location.href = url.substring(0, url.length - 1);
			});
		}
	},

	/**
	 * Extracts a query string
	 * @param query
	 * @returns {{}}
	 */
	getParams : function(query)
	{
		var vars = query.split("&");
		var query_string = {};
		for (var i = 0; i < vars.length; i++) {
			var pair = vars[i].split("=");
			var key = decodeURIComponent(pair[0]);
			var value = decodeURIComponent(pair[1]);
			// If first entry with this name
			if (typeof query_string[key] === "undefined") {
				query_string[key] = decodeURIComponent(value);
				// If second entry with this name
			} else if (typeof query_string[key] === "string") {
				var arr = [query_string[key], decodeURIComponent(value)];
				query_string[key] = arr;
				// If third or later entry with this name
			} else {
				query_string[key].push(decodeURIComponent(value));
			}
		}
		return query_string;
	}
};

jQuery(function() {
	CrmUserAdmin.initialize();
});