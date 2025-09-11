if (typeof(LbwpFormEditor) == 'undefined') {
	var LbwpFormEditor = {};
}

/**
 * This handles everything in the settings UI
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpFormEditor.Settings = {

	/**
	 * Initializes the settings interface
	 */
	initialize: function () {
		LbwpFormEditor.Settings.setDefaults();
		LbwpFormEditor.Settings.addEvents();
		LbwpFormEditor.Settings.setData();
	},

	/**
	 * Set defaults, if not already set
	 */
	setDefaults: function () {
		if (LbwpFormEditor.Data.Settings == null) {
			LbwpFormEditor.Data.Settings = {
				redirect : 0,
				hide_after_success : 1,
				hide_send_button : 0,
				use_spambotcheck : 0,
				show_survey_results : 0,
				disable_enctype : 0,
				back_link_text : '',
				meldung : '',
				button : '',
				external_action_url : '',
				css_classes : '',
				after_submit : '',
				enable_multisite : 0,
				use_progress_bar : 0,
				first_step_name : '',
				back_button_title : '',
				next_button_title : ''
			};
		}
	},

	/**
	 * Loop threw all existing Data and load set-functions
	 */
	setData: function () {
		var settings = LbwpFormEditor.Data.Settings;
		for (var key in settings) {
			switch (key) {
				case "redirect":
					LbwpFormEditor.Settings.setRedirect(settings[key]);
					break;
				case "hide_after_success":
					LbwpFormEditor.Settings.setHideAfterSuccess(settings[key]);
					break;
				case "hide_send_button":
					LbwpFormEditor.Settings.setHideSendButton(settings[key]);
					break;
				case "use_spambotcheck":
					LbwpFormEditor.Settings.useSpamBotCheck(settings[key]);
					break;
				case "show_survey_results":
					LbwpFormEditor.Settings.showSurveyResults(settings[key]);
					break;
				case "disable_enctype":
					LbwpFormEditor.Settings.setDisableEnctype(settings[key]);
					break;
				case "back_link_text":
					LbwpFormEditor.Settings.setBackLinkText(settings[key]);
					break;
				case "meldung":
					LbwpFormEditor.Settings.setMeldung(settings[key]);
					break;
				case "after_submit":
					LbwpFormEditor.Settings.setAfterSubmit(settings[key]);
					break;
				case "button":
					LbwpFormEditor.Settings.setButton(settings[key]);
					break;
				case "css_classes":
					LbwpFormEditor.Settings.setCssClasses(settings[key]);
					break;
				case "external_action_url":
					LbwpFormEditor.Settings.setExternalAction(settings[key]);
					break;
				case "enable_multisite":
					LbwpFormEditor.Settings.enableMultisite(settings[key]);
					break;
				case "use_progress_bar":
					LbwpFormEditor.Settings.useProgressBar(settings[key]);
					break;
				case "first_step_name":
					LbwpFormEditor.Settings.setFirstStepTitle(settings[key]);
					break;
				case "back_button_title":
					LbwpFormEditor.Settings.setBackButtonTitle(settings[key]);
					break;
				case "next_button_title":
					LbwpFormEditor.Settings.setNextButtonTitle(settings[key]);
					break;
			}
		}
	},

	/**
	 * Check and set value if value is given
	 */
	setRedirect: function (val) {
		val != "" && jQuery("#page_id option[value=" + val + "]").prop("selected", true);
	},

	/**
	 * Set hide after success checkbox on load, if given
	 */
	setHideAfterSuccess: function (val) {
		val == 1 && jQuery('#hide_after_success').prop('checked', true);
	},

	/**
	 * Set hide after success checkbox on load, if given
	 */
	setHideSendButton: function (val) {
		val == 1 && jQuery('#hide_send_button').prop('checked', true);
	},

	useSpamBotCheck : function(val) {
		val == 1 && jQuery('#use_spambotcheck').prop('checked', true);
	},

	showSurveyResults : function(val) {
		val == 1 && jQuery('#show_survey_results').prop('checked', true);
	},

	/**
	 * Set hide after success checkbox on load, if given
	 */
	setDisableEnctype: function (val) {
		val == 1 && jQuery('#disable_enctype').prop('checked', true);
	},

	/**
	 * Set the backlink text on load, if given
	 */
	setBackLinkText: function (val) {
		val != "" && jQuery("#back_link_text").val(val);
	},

	/**
	 * Check and set value if value is given
	 */
	setMeldung: function (val) {
		val != "" && jQuery("#messageBox").val(val).keyup();
	},

	/**
	 * Check and set value if value is given
	 */
	setAfterSubmit: function (val) {
		val != "" && jQuery("#onceMessage").val(val).keyup();
	},

	/**
	 * Check and set value if value is given
	 */
	setCssClasses: function (val) {
		val != "" && jQuery("#css_classes").val(val).keyup();
	},

	/**
	 * Check and set value if value is given
	 */
	setExternalAction: function (val) {
		val != "" && jQuery("#external_action_url").val(val).keyup();
	},

	/**
	 * Check and set value if value is given
	 */
	setButton: function (val) {
		var button = jQuery("#button");
		button.val(val != "" ? val : button.val()).keyup();
	},

	enableMultisite : function(val){
		val == 1 && jQuery('#enable_multisite').prop('checked', true);
	},

	useProgressBar : function(val){
		val == 1 && jQuery('#use_progress_bar').prop('checked', true);
	},

	setFirstStepTitle : function(val){
		val != "" && jQuery("#first_step_name").val(val).keyup();
	},

	setBackButtonTitle : function(val){
		val != "" && jQuery("#back_button_title").val(val).keyup();
	},

	setNextButtonTitle : function(val){
		val != "" && jQuery("#next_button_title").val(val).keyup();
	},

	/**
	 * All input events for the fields
	 */
	addEvents: function () {

		jQuery("#page_id").change(function () {
			jQuery('#redir').click();
			LbwpFormEditor.Data.Settings.redirect = jQuery(this).val();
			LbwpFormEditor.Data.Settings.meldung = '';
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('input[name=sent]').change(function() {
			switch(jQuery(this).val()) {
				case 'meldung':
					LbwpFormEditor.Data.Settings.meldung = jQuery('#messageBox').val();
					LbwpFormEditor.Data.Settings.redirect = 0;
					break;
				case 'weiterleitung':
					LbwpFormEditor.Data.Settings.redirect = jQuery('#page_id').val();
					LbwpFormEditor.Data.Settings.meldung = '';
					break;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#hide_after_success').change(function() {
			LbwpFormEditor.Data.Settings.hide_after_success = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.hide_after_success = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#hide_send_button').change(function() {
			LbwpFormEditor.Data.Settings.hide_send_button = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.hide_send_button = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#use_spambotcheck').change(function() {
			LbwpFormEditor.Data.Settings.use_spambotcheck = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.use_spambotcheck = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#show_survey_results').change(function() {
			LbwpFormEditor.Data.Settings.show_survey_results = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.show_survey_results = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#disable_enctype').change(function() {
			LbwpFormEditor.Data.Settings.disable_enctype = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.disable_enctype = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#back_link_text').change(function() {
			LbwpFormEditor.Data.Settings.back_link_text = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery("#msg").click(function (e) {
			jQuery("#messageBox").val().length == 0 && e.preventDefault();
			jQuery("#messageBox").focus();
		});

		jQuery("#once").click(function (e) {
			e.preventDefault();
			jQuery("#onceMessage").focus();
		});

		jQuery("#messageBox").keyup(function () {
			jQuery("#msg").prop("checked", jQuery(this).val().length)
			jQuery("#redir").prop("checked", !jQuery(this).val().length)
			LbwpFormEditor.Data.Settings.meldung = jQuery(this).val();
			LbwpFormEditor.Data.Settings.redirect = 0;
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery("#onceMessage").keyup(function () {
			jQuery("#once").prop("checked", jQuery(this).val().length ? true : false)
			LbwpFormEditor.Data.Settings.after_submit = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery("#button").keyup(function () {
			LbwpFormEditor.Data.Settings.button = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery("#css_classes").keyup(function () {
			LbwpFormEditor.Data.Settings.css_classes = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery("#external_action_url").keyup(function () {
			LbwpFormEditor.Data.Settings.external_action_url = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#enable_multisite').change(function() {
			LbwpFormEditor.Data.Settings.enable_multisite = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.enable_multisite = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#use_progress_bar').change(function() {
			LbwpFormEditor.Data.Settings.use_progress_bar = 0;
			if (jQuery(this).is(':checked')) {
				LbwpFormEditor.Data.Settings.use_progress_bar = 1;
			}
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#first_step_name').change(function() {
			LbwpFormEditor.Data.Settings.first_step_name = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#back_button_title').change(function() {
			LbwpFormEditor.Data.Settings.back_button_title = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});

		jQuery('#next_button_title').change(function() {
			LbwpFormEditor.Data.Settings.next_button_title = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});
	},
};
