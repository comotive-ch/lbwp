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
				meldung : '',
				button : '',
				after_submit : ''
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
				case "meldung":
					LbwpFormEditor.Settings.setMeldung(settings[key]);
					break;
				case "after_submit":
					LbwpFormEditor.Settings.setAfterSubmit(settings[key]);
					break;
				case "button":
					LbwpFormEditor.Settings.setButton(settings[key]);
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
	setButton: function (val) {
		var button = jQuery("#button");
		button.val(val != "" ? val : button.val()).keyup();
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
	}
};
