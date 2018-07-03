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

		// If not admin, remove even more
		if (!crmAdminData.userIsAdmin) {
			// Hide E-Mail as it is auto set
			jQuery('.user-email-wrap').hide();
		}

		// Prepare all form tables to be wrapped into the main tab
		jQuery('.form-table').attr('data-target-tab', 'main');
	},

	/**
	 * Prepare various UI controls after wrapping elements
	 */
	prepareUI : function()
	{
		jQuery('#profileCategories').chosen();
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
			link.siblings().removeClass('nav-tab-active');
			link.addClass('nav-tab-active');
			jQuery('.tab-container').hide();
			jQuery('.container-' + link.data('tab')).show();
		});

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