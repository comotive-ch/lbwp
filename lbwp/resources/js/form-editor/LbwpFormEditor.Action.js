if (typeof(LbwpFormEditor) == 'undefined') {
	var LbwpFormEditor = {};
}

/**
 * This handles everything in the action UI
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpFormEditor.Action = {

	/**
	 * Initializes the action interface
	 */
	initialize: function () {
		LbwpFormEditor.Action.getActions();
		LbwpFormEditor.Action.setEvents();
	},

	/**
	 * Get all current actions with selected fields
	 */
	getActions: function () {
		// clear action html
		if (Object.keys(LbwpFormEditor.Data.Actions).length > 0) {
			jQuery("#editor-action-tab .lbwp-action-list").html('');
		}

		// Display actions initially
		for (var i in LbwpFormEditor.Data.Actions) {
			var key = LbwpFormEditor.Data.Actions[i].key;
			var params = LbwpFormEditor.Data.Actions[i].params;
			var fields = LbwpFormEditor.Action[key] != undefined ? LbwpFormEditor.Action[key]() : {};
			var add = "";
			for (var fieldname in fields) {
				if (params[fields[fieldname]].value != "" && params[fields[fieldname]].value != null) {
					var value = params[fields[fieldname]].value;
					if (value.indexOf("field:") == 0) {
						if (LbwpFormEditor.Data.Items[value.slice(6, value.length)] != undefined) {
							value = LbwpFormEditor.Data.Items[value.slice(6, value.length)].params[0].value;
						} else {
							value = "<i>Dieses Feld ist nicht (mehr) verfügbar</i>"
						}
					}
					add += "<br><b>" + fieldname + "</b>: " + value
				}
			}
			jQuery("#editor-action-tab .lbwp-action-list").append('<div class="action-item" id="' + i + '"><strong>' + jQuery("[data-key=" + key + "]").children("strong").text() + "</strong><br>" + add + '<span class="edit dashicons dashicons-edit"></span><span class="delete dashicons dashicons-trash"></span></div>')
		}
	},

	/**
	 * Set which fields should be displayed for "sendmail"
	 */
	sendmail: function () {
		return {
			"Betreff": 2,
			"An": 3,
			"CC": 4,
			"BCC": 5
		};
	},

	/**
	 * Set which fields should be displayed for "datatable"
	 */
	datatable: function () {
		return {
			"Tabellen Name": 2
		};
	},

	/**
	 * Set which fields should be displayed for "datatable"
	 */
	"auto-close": function () {
		return {
			"Schliessen am": 3,
			"Um": 4
		};
	},

	/**
	 * Set all event handlers
	 */
	setEvents: function () {
		// sort left block
		jQuery("#editor-action-tab .frame-left .inside ").sortable({
			connectWith: ".lbwp-action-list",
			helper: function (e, li) {
				this.copyHelper = li.clone().insertAfter(li);
				li.addClass("action-item selected").attr("id", "action_" + jQuery(".action-item").length);
				jQuery(this).data('copied', false);
				jQuery(".lbwp-action-list").addClass("drag-target");
				return li.clone();
			},
			stop: function (e, li) {
				var copied = jQuery(this).data('copied');
				if (!copied) {
					li.item.remove();
				}
				jQuery(".lbwp-action-list").removeClass("drag-target");
				this.copyHelper = null;
			}
		});

		// sort action list
		jQuery("#editor-action-tab .lbwp-action-list").sortable({
			receive: function (e, ui) {
				ui.sender.data('copied', true);
			},
			items: ".forms-item:not(.send-button)",
			update: function () {
				jQuery(this).find(".help-message").remove();
				LbwpFormEditor.Action.checkOrder();
			}
		});

		// load edits for selected action
		jQuery("#editor-action-tab .action-item").unbind("click").click(function (e) {
			e.preventDefault();
			jQuery(this).siblings(".selected").removeClass("selected");
			LbwpFormEditor.Action.loadEdits(jQuery(this).attr("id"));
		});

		// remove element
		jQuery("#editor-action-tab .delete").unbind("click").click(function (e) {
			e.preventDefault();
			var check = confirm(LbwpFormEditor.Text.confirmDelete);
			if (check) {
				jQuery(this).parent().remove();
				jQuery("#editor-action-tab .field-settings").html(LbwpFormEditor.Text.deletedAction);
				LbwpFormEditor.Action.checkOrder();
				check = false;
			}
		});
	},

	/**
	 * Check all action and write JSON
	 */
	checkOrder: function () {
		var newJson = {};
		var sub = 0;
		var current;
		jQuery(".lbwp-action-list").children(".action-item").each(function (i) {
			var key = jQuery(this).attr("id");
			if (jQuery(this).data("key") != undefined) {
				jQuery(this).append('<span class="edit dashicons dashicons-edit"></span><span class="delete dashicons dashicons-trash"></span>')
				key = "action" + "_" + (i + 1);
				sub = 1;
				newJson[key] = {
					key: jQuery(this).data("key"),
					params: []
				}
				current = key;
			} else {
				newJson[key] = LbwpFormEditor.Data.Actions[key];
			}
		});

		LbwpFormEditor.Data.Actions = newJson;
		LbwpFormEditor.Core.updateJsonField();
		LbwpFormEditor.Action.loadAjax(current, LbwpFormEditor.Action.loadEdits);
	},

	/**
	 * Load new JSON
	 */
	loadAjax: function (current, func) {
		var data = {
			formJson: JSON.stringify(LbwpFormEditor.Data),
			action: 'updateFormHtml'
		};
		var current = current;
		// Get the UI and form infos
		jQuery.post(ajaxurl, data, function (response) {
			// Set the json object
			LbwpFormEditor.Data = response.formJsonObject;
			LbwpFormEditor.Core.updateJsonField();
			jQuery("div[id^=action]").each(function () {
				jQuery(this).attr("id", "action_" + (jQuery(this).index() + 1))
			});

			// Set events
			LbwpFormEditor.Action.setEvents();
			if (current != undefined) {
				jQuery("#" + current).siblings(".selected").removeClass("selected");
				jQuery("#" + current).addClass("selected");
				func != undefined && (func(current));
			}
		});
	},

	/**
	 * Load settings to selected action
	 */
	loadEdits: function (key) {
		var html = "";
		var fields = LbwpFormEditor.Data.Actions[key].params
		jQuery("#" + key).siblings(".selected").removeClass("selected");
		jQuery("#" + key).addClass("selected");
		for (var i in fields) {
			var editField = "";
			switch (fields[i].type) {
				case "textfield":
					editField = LbwpFormEditor.Form.getTextfield(fields[i]);
					break;
				case "textarea":
					editField = LbwpFormEditor.Form.getTextarea(fields[i]);
					break;
				case "dropdown":
					editField = LbwpFormEditor.Form.getDropdown(fields[i]);
					break;
				case "radio":
					editField = LbwpFormEditor.Form.getRadioButtons(fields[i]);
					break;
				case "textfieldArray":
					editField = LbwpFormEditor.Form.getTextfieldArray(fields[i]);
					break;
			}
			var ref = fields[i].type == "textfield" ? '<a title="' + LbwpFormEditor.Text.useFromFieldHeading + '" class="ref dashicons dashicons-admin-generic"></a>' : "";
			var help = fields[i].help != undefined ? '<span class="helpText">' + fields[i].help + '</span><span class="help dashicons dashicons-editor-help"></span>' : "";
			html += '<div class="lbwp-editField action-property" data-key="' + fields[i].key + '">' + editField + help + ref + '</div>';
		}

		LbwpFormEditor.Action.editEvents(html)
		LbwpFormEditor.Action.loadConditions(key);
	},

	/**
	 * All events related to the actions settings
	 */
	editEvents: function (html) {
		var time = 500;

		// animate settings field background
		if (typeof(html) == 'string' && html.length > 0) {
			jQuery("#editor-action-tab .field-settings").html(html).stop().animate({
				backgroundColor: "#fafafa"
			}, time, function () {
				jQuery(this).stop().animate({backgroundColor: "white"})
			});
		}

		jQuery("#editor-action-tab .postbox").accordion({heightStyle: "content"});
		jQuery("#editor-action-tab .hndle-conditions").show();

		// Destroy the sortable if existing
		try {
			jQuery("#editor-action-tab .textfieldArray").parent().sortable('destroy');
		} catch (exception) {
			// Nothing to handle here
		}

		// sort textfieldarray
		jQuery("#editor-action-tab .textfieldArray").parent().sortable({
			items: ".textfieldArray",
			handle: ".drag",
			stop: function () {
				jQuery(LbwpFormEditor.Core.actionFieldSelector).find("input, select").first().change();
			}
		});

		// delete textfieldarray field
		jQuery("#editor-action-tab .textfieldArray .delete").off('click').click(function () {
			if (confirm(LbwpFormEditor.Text.confirmDelete)) {
				jQuery(this).parent().remove();
				jQuery(LbwpFormEditor.Core.actionFieldSelector).find("input, select").first().change();
			}
		});

		// change text on keyup on fieldname
		jQuery(LbwpFormEditor.Core.actionFieldSelector + "[data-key=feldname]").off('keyup').keyup(function () {
			var selected = jQuery("#editor-action-tab .frame-middle .forms-item.selected label");
			if (selected.text().indexOf("*") > -1) selected.text(jQuery(this).find("input").val() + " *");
			else selected.text(jQuery(this).find("input").val());
		});

		// Update json on keyup on form fields to immediately save changes to json
		jQuery(LbwpFormEditor.Core.actionFieldSelector).find("input, textarea").off('keyup').keyup(function () {
			var current = jQuery("#editor-action-tab .frame-middle .action-item.selected").attr("id");
			var item = LbwpFormEditor.Data.Actions[current].params;
			for (var key in item) {
				switch (item[key].type) {
					case "textfield":
					case "textarea":
						LbwpFormEditor.Data.Actions[current].params[key].value = jQuery(LbwpFormEditor.Core.actionFieldSelector).eq(key).find("input, textarea, select").val();
						break;
				}
			}

			LbwpFormEditor.Core.updateJsonField();
		});

		// change JSON on edit field change
		jQuery(LbwpFormEditor.Core.actionFieldSelector).find("input, select, textarea").off('change').change(function () {
			var current = jQuery("#editor-action-tab .frame-middle .action-item.selected").attr("id");

			var item = LbwpFormEditor.Data.Actions[current].params;
			for (var key in item) {
				switch (item[key].type) {
					case "textfield":
					case "textarea":
					case "dropdown":
						LbwpFormEditor.Data.Actions[current].params[key].value = jQuery(LbwpFormEditor.Core.actionFieldSelector).eq(key).find("input, textarea, select").val();
						break;
					case "radio":
						LbwpFormEditor.Data.Actions[current].params[key].value = jQuery(LbwpFormEditor.Core.actionFieldSelector).eq(key).find("input:checked").val();
						break;
					case "textfieldArray":
						LbwpFormEditor.Data.Actions[current].params[key].value = "";
						jQuery(LbwpFormEditor.Core.actionFieldSelector).eq(key).find("input").each(function () {
							LbwpFormEditor.Data.Actions[current].params[key].value += jQuery(this).val() != "" ? jQuery(this).val() + "," : "";
						});
						break;
				}
			}

			LbwpFormEditor.Core.updateJsonField();
			LbwpFormEditor.Action.loadAjax(current);
			LbwpFormEditor.Action.getActions();
		});

		// trigger help text
		jQuery(".help").click(function () {
			var helptext = jQuery(this).siblings(".helpText");
			helptext.css("display") == "none" ? helptext.css("display", "block") : helptext.hide();
		});

		// adds references box
		jQuery(".ref").unbind("click").click(function () {
			var first = true;
			jQuery("body").unbind("click").click(function (e) {
				if (!jQuery(e.toElement).closest("#reference").length && jQuery("#reference").length > 0 && first == false) {
					jQuery("body").unbind("click");
					jQuery(".currentRef").removeClass("currentRef");
					jQuery("#reference").remove();
				}
				first = false;
			});

			if (jQuery(this).hasClass("currentRef")) {
				jQuery("#reference").remove();
				jQuery(this).removeClass("currentRef");
			} else {
				jQuery("#reference").remove();
				jQuery(".currentRef").removeClass("currentRef");
				var elem = jQuery(this).addClass("currentRef");
				var wrap = elem.closest(".postbox");
				var fields = "";
				for (var key in LbwpFormEditor.Data.Items) {
					fields += LbwpFormEditor.Data.Items[key].params[0].value != ""
						? '<a href="' + key + '" class="">' + LbwpFormEditor.Data.Items[key].params[0].value + '</a>'
						: "";
				}

				wrap.append('\
					<div id="reference"><span class="close">X</span>\
					<h3>' + LbwpFormEditor.Text.useFromFieldHeading + '</h3>\
					<p>' + LbwpFormEditor.Text.useFromFieldText + '</p>' + fields + '\
					</div>\
				');

				var ref = jQuery("#reference");

				ref.children("a").click(function (e) {
					e.preventDefault();
					elem.parent().find("input").val("field:" + jQuery(this).attr("href")).change();
					jQuery("#reference").remove();
				});

				ref.css({
					top: (elem.offset().top - wrap.offset().top) - ref.outerHeight(true) / 2 + elem.height() / 2
				});

				// close button for references
				jQuery("#reference .close").click(function () {
					jQuery(".currentRef").removeClass("currentRef");
					jQuery(this).closest("#reference").remove();
				})
			}
		});
	},
	/**
	 * Load all conditions related to current field
	 */
	loadConditions: function (key) {
		var params = LbwpFormEditor.Data.Actions[key].params
		var html = "<p>" + LbwpFormEditor.Text.conditionText + "</p>";
		for (var i in params) {
			if (params[i].key == "condition_type") {
				html += '\
					<input id="andRadio" type="radio" value="AND" name="type" ' + (params[i].value == "AND" || params[i].value == null ? "checked" : "") + '/>\
					<label for="andRadio">' + LbwpFormEditor.Text.conditionAndSelection + '</label><br>\
					<input id="orRadio" type="radio" value="OR" name="type" ' + (params[i].value == "OR" ? "checked" : "") + '/>\
					<label for="orRadio">' + LbwpFormEditor.Text.conditionOrSelection + '</label>\
				';
			}
		}

		// Basic heading
		html += '\
			<table><thead><tr>\
				<th>' + LbwpFormEditor.Text.conditionField + '</th><th>=\
				</th><th>' + LbwpFormEditor.Text.conditionValue + '</th><th></th>\
			</tr></thead><tbody>\
		';

		var hasCond = false;
		var cond = "";
		for (var i in params) {
			if (params[i].key == "conditions" && params[i].value != "") {
				hasCond = true;
				var value = (params[i].value == null || params[i].value == "") ? "=" : params[i].value;
				cond = value.split(";")
				for (var elem in cond) {
					html += LbwpFormEditor.Action.getConditionRow(cond[elem].split("=")[0], cond[elem].split("=")[1])
				}
				break;
			}
		}

		!hasCond && (html += LbwpFormEditor.Action.getConditionRow("", ""));

		html += '</tbody></table>';
		html += '<a href="#" class="addCond button">' + LbwpFormEditor.Text.conditionAdd + '</a>'
		jQuery(".field-conditions").html(html);

		LbwpFormEditor.Action.conditionEvents(key);

	},

	/**
	 * Return a condition row
	 */
	getConditionRow: function (option, value) {
		var html = '<tr><td><select>'
		for (var key in LbwpFormEditor.Data.Items) {
			var selected = key == option ? "selected" : "";
			html += LbwpFormEditor.Data.Items[key].params[0].value != ""
				? '<option value="' + key + '"' + selected + '>' + LbwpFormEditor.Data.Items[key].params[0].value + '</option>'
				: '<option value="' + key + '"' + selected + '>ID: ' + key + '</option>';
		}
		html += '</select></td><td>=</td>';
		html += '<td><input type="text" value="' + value + '"></td>'
		html += '<td><span class="delete dashicons dashicons-trash"></span></td></td></tr>';

		return html;
	},

	/**
	 * Sets all events related to conditions
	 */
	conditionEvents: function (key) {
		// adds a new Condition
		jQuery(".field-conditions .addCond").unbind("click").click(function (e) {
			e.preventDefault();
			jQuery(".field-conditions table tbody").append(LbwpFormEditor.Action.getConditionRow("", ""));
			LbwpFormEditor.Action.conditionEvents(key)
		});

		// Deletes a condition
		jQuery(".field-conditions .delete").unbind("click").click(function (e) {
			e.preventDefault();
			var check = confirm(LbwpFormEditor.Text.confirmDelete);
			if (check) jQuery(this).closest("tr").remove();
		});

		// Safe on change in JSON
		jQuery(".field-conditions table select, .field-conditions table input").change(function () {
			var params = LbwpFormEditor.Data.Actions[key].params;
			var table = jQuery(this).closest("table");
			var val = [];
			table.find("tbody").find("tr").each(function () {
				val.push(jQuery(this).find("select").val() + "=" + jQuery(this).find("input").val());
			})
			for (var i in params) {
				if (params[i].key == "conditions") break;
			}
			LbwpFormEditor.Data.Actions[key].params[i].value = val.join(";");
			LbwpFormEditor.Core.updateJsonField();
		});

		// Safe radio on change in JSON
		jQuery(".field-conditions input[type=radio]").unbind("change").change(function () {
			var params = LbwpFormEditor.Data.Actions[key].params;
			for (var i in params) {
				if (params[i].key == "condition_type") break;
			}
			LbwpFormEditor.Data.Actions[key].params[i].value = jQuery(this).val();
			LbwpFormEditor.Core.updateJsonField();
		});
	}
};

