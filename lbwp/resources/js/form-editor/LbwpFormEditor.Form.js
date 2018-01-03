if (typeof(LbwpFormEditor) == 'undefined') {
	var LbwpFormEditor = {};
}

/**
 * This handles everything in the form UI
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpFormEditor.Form = {

	/**
	 * Initializes the form interface
	 */
	initialize: function () {
		LbwpFormEditor.Form.removeRequired();
		LbwpFormEditor.Form.setEvents();
	},

	/**
	 * Update only the forms HTML output (completely)
	 * @param html the new html to set
	 */
	updateHtml: function (html) {
		if (typeof(html) != 'undefined' && html.length > 0) {
			jQuery('.lbwp-form-preview').html(html);
		}
	},

	/**
	 * Remove every required attribut
	 */
	removeRequired: function () {
		jQuery("[required]").removeAttr("required")
		jQuery("[aria-required]").removeAttr("aria-required")
	},

	/**
	 * Set events
	 */
	setEvents: function () {
		// sort block left
		jQuery("#editor-form-tab .frame-left .inside").sortable({
			connectWith: ".lbwp-form",
			helper: function (e, li) {
				this.copyHelper = li.clone().insertAfter(li);
				li.addClass("forms-item");
				jQuery(this).data('copied', false);
				jQuery(".lbwp-form-preview").addClass("drag-target");

				return li.clone();
			},
			stop: function (e, li) {
				var copied = jQuery(this).data('copied');
				if (!copied) {
					li.item.remove();
				}
				jQuery(".lbwp-form-preview").removeClass("drag-target");
				this.copyHelper = null;
			}
		});

		// sort forms-item
		jQuery("#editor-form-tab .lbwp-form").sortable({
			receive: function (e, ui) {
				ui.sender.data('copied', true);
			},
			items: LbwpFormEditor.Core.validFieldSelector,
			update: function () {
				LbwpFormEditor.Form.checkOrder();
			}
		});

		// load settigs for forms-item
		jQuery("#editor-form-tab .lbwp-form " + LbwpFormEditor.Core.validFieldSelector).unbind("click").click(function (e) {
			e.preventDefault();
			jQuery(this).siblings(".selected").removeClass("selected");
			var formItem = jQuery(this).find("label").attr("for");
			LbwpFormEditor.Form.loadEdits(LbwpFormEditor.Core.handleKey(formItem));
		}).hover(function () {
			jQuery(this).find('.default-container').append('<span class="delete dashicons dashicons-trash"></span>');
			jQuery(this).find('.delete').click(function (event) {
				var trigger = jQuery(this).closest(LbwpFormEditor.Core.validFieldSelector);
				var check = confirm(LbwpFormEditor.Text.confirmDelete);
				if (check) {
					trigger.remove();
					jQuery("#editor-form-tab .field-settings").html(LbwpFormEditor.Text.deletedField);
					LbwpFormEditor.Form.checkOrder();
				}
			})
		}, function () {
			jQuery(this).find('.delete').remove();
		});
	},

	/**
	 * Checks new order in form
	 */
	checkOrder: function () {
		var newJson = {};
		var sub = 0;
		var current;

		jQuery(".lbwp-form").children(LbwpFormEditor.Core.validFieldSelector).each(function (i) {
			var key = jQuery(this).children("label").attr("for");
			if (jQuery(this).data("key") != undefined) {
				key = jQuery(this).data("key") + "_" + Date.now();
				sub = 1;
				newJson[key] = {
					key: jQuery(this).data("key"),
					params: [
						{
							key: 'feldname',
							type: 'textfield',
							value: jQuery(this).data("title")
						}
					]
				}
				current = key;
			} else {
				key = LbwpFormEditor.Core.handleKey(key);
				newJson[key] = LbwpFormEditor.Data.Items[key];
			}
		});

		LbwpFormEditor.Data.Items = newJson;
		LbwpFormEditor.Core.updateJsonField();
		LbwpFormEditor.Form.loadAjax(current, LbwpFormEditor.Form.loadEdits);
	},

	/**
	 *
	 * @param current
	 * @param func
	 */
	loadAjax: function (current, func) {
		var data = {
			formJson: JSON.stringify(LbwpFormEditor.Data),
			action: 'updateFormHtml'
		};
		var current = current;
		// Get the UI and form infos
		jQuery.post(ajaxurl, data, function (response) {
			LbwpFormEditor.Form.updateHtml(response.formHtml);
			// Set the json object
			LbwpFormEditor.Data = response.formJsonObject;
			LbwpFormEditor.Core.updateJsonField();

			// Set events
			LbwpFormEditor.Form.removeRequired();
			LbwpFormEditor.Form.setEvents();
			if (current != undefined) {
				jQuery("[for^=" + current + "]").closest(".forms-item").addClass("selected");
				func != undefined && (func(current))
			}
		});
	},

	/**
	 * Load all settings related to current field
	 */
	loadEdits: function (key) {
		var html = "";
		var fields = LbwpFormEditor.Data.Items[key].params;
		jQuery("[for^=" + key + "]").closest(".forms-item").addClass("selected");

		for (var fieldKey in fields) {
			var editField = "";
			switch (fields[fieldKey].type) {
				case "textfield":
					editField = LbwpFormEditor.Form.getTextfield(fields[fieldKey]);
					break;
				case "dropdown":
					editField = LbwpFormEditor.Form.getDropdown(fields[fieldKey]);
					break;
				case "radio":
					editField = LbwpFormEditor.Form.getRadioButtons(fields[fieldKey]);
					break;
				case "textfieldArray":
					editField = LbwpFormEditor.Form.getTextfieldArray(fields[fieldKey]);
					break;
				case "textarea":
					editField = LbwpFormEditor.Form.getTextarea(fields[fieldKey]);
					break;
			}
			var optin = fields[fieldKey].optin == 1 ? '<span class="edit-optin dashicons dashicons-edit" data-key="' + fields[fieldKey].key + '"></span>' : "";
			var help = fields[fieldKey].help != undefined ? '<span class="helpText">' + fields[fieldKey].help + '</span><span class="help dashicons dashicons-editor-help"></span>' : "";
			html += '<div class="lbwp-editField" data-key="' + fields[fieldKey].key + '">' + editField + optin + help + '</div>';
		}

		LbwpFormEditor.Form.editEvents(html, key)
	},
	/**
	 * Returns html for a textfield
	 */
	getTextfield: function (field) {
		var html = '';
		var additionalAttr = '';

		// Make field disabled or readonly if configured
		if (typeof(field.availability) == 'string') {
			if (field.availability == 'readonly' || field.availability == 'disabled') {
				additionalAttr = field.availability + '="' + field.availability + '"';
			}
		}

		// Create html
		html += "<label>" + field.name + "</label>";
		html += '<input type="text" value="' + (field.value == null ? "" : field.value) + '"' + additionalAttr + '>'

		return html;
	},

	/**
	 * Returns html for a dropdown
	 */
	getDropdown: function (field) {
		var html = "";
		html += "<label>" + field.name + "</label>";
		html += '<select>';


		for (var key in field.values) {
			selected = field.value == key ? "selected" : "";
			html += '<option value="' + key + '" ' + selected + '>' + field.values[key] + '</option>'
		}

		html += '</select>'
		return html;
	},

	/**
	 * Returns html for radio buttons
	 */
	getRadioButtons: function (field) {
		var html = "";
		var fieldIndex = 0;
		html += "<label>" + field.name + "</label>";

		for (var key in field.values) {
			fieldIndex += 1;
			selected = field.value == key ? "checked" : "";
			inputId = field.key + '-' + fieldIndex;
			html += '<input type="radio" name="' + field.key + '" id="' + inputId + '" value="' + key + '" ' + selected + '>' + '<label class="radio" for="' + inputId + '">' + field.values[key] + '</label>';
		}

		return html;
	},

	/**
	 * Returns html for a textfieldArray
	 */
	getTextfieldArray: function (field) {
		var html = "";
		var separator = (typeof(field.separator)) == 'string' ? field.separator : ',';
		var values = field.value.split(separator);
		html += "<label>" + field.name + "</label>";

		for (var key in values) {
			html += '<div class="textfieldArray"><input type="text" value="' + values[key].trim() + '"><span class="delete dashicons dashicons-trash"></span><span class="drag dashicons dashicons-sort"></span></div>'
		}

		if (values.length == 0) {
			html += '<div class="textfieldArray"><input type="text" value=""><span class="delete dashicons dashicons-trash"></span><span class="drag dashicons dashicons-sort"></span></div>'
		}
		html += '<span class="addOption button" onclick="LbwpFormEditor.Form.addInputfield(this)">' + LbwpFormEditor.Text.addOption + '</span>';

		return html;
	},

	/**
	 * Returns html for a textarea
	 */
	getTextarea: function (field) {
		var html = "";
		html += "<label>" + field.name + "</label>";
		html += '<textarea>' + field.value + '</textarea>';
		return html;
	},

	/**
	 * Adds an input field to the textfieldArray
	 */
	addInputfield: function (e) {
		// Clone element and flush its inputs value
		var newElement = jQuery(e).prev().clone();
		newElement.find('input').val('');
		jQuery(e).before(newElement);
		LbwpFormEditor.Form.editEvents('', '');
	},

	/**
	 * All events related to the edit fields
	 */
	editEvents: function (html, key) {
		var time = 100;

		// add html to field-settings, if given
		if (typeof(html) == 'string' && html.length > 0) {
			jQuery("#editor-form-tab .field-settings").html(html);
		}

		// Destroy the sortable if existing
		try {
			jQuery("#editor-form-tab .textfieldArray").parent().sortable('destroy');
		} catch (exception) {
			// Nothing to handle here
		}

		// sort textfield array
		jQuery("#editor-form-tab .textfieldArray").parent().sortable({
			items: ".textfieldArray",
			handle: ".drag",
			stop: function () {
				jQuery(LbwpFormEditor.Core.itemFieldSelector).find("input, select").first().change();
			}
		});

		// delete textfieldarray item
		jQuery("#editor-form-tab .textfieldArray .delete").off('click').click(function () {
			if (confirm(LbwpFormEditor.Text.confirmDelete)) {
				jQuery(this).parent().remove();
				jQuery(LbwpFormEditor.Core.itemFieldSelector).find("input, select").first().change();
			}
		});

		// change text of fieldname on keyup
		jQuery(LbwpFormEditor.Core.itemFieldSelector + "[data-key=feldname]").off('keyup').keyup(function () {
			var selected = jQuery("#editor-form-tab .frame-middle .forms-item.selected > label");
			if (selected.text().indexOf("*") > -1) {
				selected.text(jQuery(this).find("input").val() + " *");
			} else {
				selected.text(jQuery(this).find("input").val())
			}
		});

		// Update json on keyup on form fields to immediately save changes to json
		jQuery(LbwpFormEditor.Core.itemFieldSelector).find("input, textarea").off('keyup').keyup(function () {
			var current = LbwpFormEditor.Core.handleKey(
				jQuery("#editor-form-tab .frame-middle .forms-item.selected label").attr("for")
			);
			var item = LbwpFormEditor.Data.Items[current].params;
			for (var key in item) {
				switch (item[key].type) {
					case "textfield":
					case "textarea":
						LbwpFormEditor.Data.Items[current].params[key].value = jQuery(LbwpFormEditor.Core.itemFieldSelector).eq(key).find("input, select, textarea").val();
						break;
				}
			}

			LbwpFormEditor.Core.updateJsonField();
		});

		// update JSON on change
		jQuery(LbwpFormEditor.Core.itemFieldSelector).find("input, select, textarea").off('change').change(function () {
			var current = LbwpFormEditor.Core.handleKey(
				jQuery("#editor-form-tab .frame-middle .forms-item.selected label").attr("for")
			);
			var item = LbwpFormEditor.Data.Items[current].params;
			for (var key in item) {
				switch (item[key].type) {
					case "textfield":
					case "textarea":
					case "dropdown":
						LbwpFormEditor.Data.Items[current].params[key].value = jQuery(LbwpFormEditor.Core.itemFieldSelector).eq(key).find("input, select, textarea").val();
						break;
					case "radio":
						LbwpFormEditor.Data.Items[current].params[key].value = jQuery(LbwpFormEditor.Core.itemFieldSelector).eq(key).find("input:checked").val();
						break;
					case "textfieldArray":
						LbwpFormEditor.Data.Items[current].params[key].value = "";
						jQuery(LbwpFormEditor.Core.itemFieldSelector).eq(key).find("input").each(function () {
							var separator = LbwpFormEditor.Data.Items[current].params[key].separator;
							if (typeof(separator) != 'string') separator = ',';
							LbwpFormEditor.Data.Items[current].params[key].value += jQuery(this).val() != "" ? jQuery(this).val() + separator : "";
						});
						break;
				}
			}

			LbwpFormEditor.Core.updateJsonField();
			LbwpFormEditor.Form.loadAjax(current);
		});

		// Toggle help text
		jQuery(".help").click(function () {
			var helptext = jQuery(this).siblings(".helpText");
			helptext.css("display") == "none" ? helptext.css("display", "block") : helptext.hide();
		});

		// Toggle edit opt-in buttons
		jQuery('.edit-optin').click(function() {
			var selector = '.lbwp-editField[data-key=' + jQuery(this).data('key') + ']';
			// Use this for select and input
			jQuery(selector + ' input, ' + selector + ' select').removeAttr('disabled').removeAttr('readonly');
		});

		// If "spamschutz" field, hide the pflichtfeld option
		var parts = key.split('_');
		if (parts[0] == 'calculation') {
			var container = jQuery('.lbwp-editField[data-key=pflichtfeld]');
			container.find('input[value=ja]').trigger('click');
			container.hide();
		}
	}
};
