if (typeof(LbwpFormEditor) == 'undefined') {
	var LbwpFormEditor = {};
}

/**
 * This handles everything in the form UI
 * @author Michael Sebel <michael@comotive.ch>
 */
LbwpFormEditor.Form = {
	/**
	 * @var int internal id to be used to generate ids on text elements if needed
	 */
	internalId : 1,
	/**
	 * @var int timeout id for temporary keyup event distraction
	 */
	keyupTimeoutId : 0,
	/**
	 * @var array currently selected fields
	 */
	fieldSelection : [],
	/**
	 * The operators a condition can have
	 */
	fieldConditionOperators : {
		'is' : 'Ist gleich',
		'not' : 'Ist nicht gleich',
		'contains' : 'Enthält',
		'absent' : 'Enthält nicht',
		'morethan' : 'Ist grösser oder gleich',
		'lessthan' : 'Ist kleiner oder gleich',
		'focused' : 'Ist aktiv'
	},
	/**
	 * The various effects/actions a condition can run
	 */
	fieldConditionActions : {
		'show' : 'Zeige das Feld an',
		'hide' : 'Blende das Feld aus',
		'truncate' : 'Entferne den Feld-Inhalt'
	},

	/**
	 * Initializes the form interface
	 */
	initialize: function ()
	{
		LbwpFormEditor.Form.removeRequired();
		LbwpFormEditor.Form.setEvents();
	},

	/**
	 * Update only the forms HTML output (completely)
	 * @param html the new html to set
	 */
	updateHtml: function (html)
	{
		if (typeof(html) != 'undefined' && html.length > 0) {
			jQuery('.lbwp-form-preview').html(html);
			LbwpFormEditor.Form.onAfterHtmlUpdate();
		}
	},

	/**
	 * Remove every required attribut
	 */
	removeRequired: function ()
	{
		jQuery("[required]").removeAttr("required")
		jQuery("[aria-required]").removeAttr("aria-required")
	},

	/**
	 * Set events
	 */
	setEvents: function ()
	{
		// sort block left
		jQuery("#editor-form-tab .frame-left .inside").sortable({
			appendTo: 'body',
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
		jQuery("#editor-form-tab .lbwp-form " + LbwpFormEditor.Core.validFieldSelector).unbind("click").click(function (evt) {
			evt.preventDefault();
			// Make sure to flush html of both views and mark everything as unselected
			jQuery("#editor-form-tab").find(".field-settings, .field-conditions").html('');
			jQuery(".forms-item.selected").removeClass("selected");
			var i = 0, id = 0;
			var fieldTitle = '', conditionTitle = '';
			var selectedElement = jQuery(this);
			var selectedId = selectedElement.data('editor-id');
			var isSelected = jQuery.inArray(selectedId, LbwpFormEditor.Form.fieldSelection) !== -1;

			// Calculate which fields should be selected
			if (evt.ctrlKey) {
				LbwpFormEditor.Form.handleCtrlSelection(isSelected, selectedId);
			} else if (evt.shiftKey) {
				LbwpFormEditor.Form.handleShiftSelection(isSelected, selectedId);
			} else {
				LbwpFormEditor.Form.fieldSelection = [selectedElement.data('editor-id')];
			}

			// Decide what to do depending on number of selected fields
			if (LbwpFormEditor.Form.fieldSelection.length == 1) {
				fieldTitle = LbwpFormEditor.Text.titleEditField;
				conditionTitle = LbwpFormEditor.Text.titleEditCondition;
				var element = jQuery('[data-editor-id=' + LbwpFormEditor.Form.fieldSelection[0] + ']');
				LbwpFormEditor.Form.loadEdits(LbwpFormEditor.Form.getKey(element));
			} else {
				fieldTitle = LbwpFormEditor.Text.titleEditMultiField;
				conditionTitle = LbwpFormEditor.Text.titleEditMultiCondition;
				LbwpFormEditor.Form.loadMultiEdits();
			}

			// Change the titles of the edit screens
			jQuery('.field-settings-title').text(fieldTitle);
			jQuery('.condition-settings-title').text(conditionTitle);
		}).hover(function () {
			jQuery(this).find('.default-container').append(
				'<span class="delete dashicons dashicons-trash"></span>' +
				'<span class="duplicate dashicons dashicons-admin-page"></span>'
			);
			// Add the delete function
			jQuery(this).find('.delete').click(function() {
				var trigger = jQuery(this).closest(LbwpFormEditor.Core.validFieldSelector);
				if (confirm(LbwpFormEditor.Text.confirmDelete)) {
					var key = LbwpFormEditor.Form.getKey(trigger);
					LbwpFormEditor.Form.deleteLinkedConditions(key);
					trigger.remove();
					jQuery("#editor-form-tab .field-settings").html(LbwpFormEditor.Text.deletedField);
					LbwpFormEditor.Form.checkOrder();
				}
			});
			// Add the duplicate function
			jQuery(this).find('.duplicate').click(function() {
        var trigger = jQuery(this).closest(LbwpFormEditor.Core.validFieldSelector);
        if (confirm(LbwpFormEditor.Text.confirmDuplicate)) {
          LbwpFormEditor.Form.duplicateFieldByKey(
            LbwpFormEditor.Form.getKey(trigger)
          );
        }
			});
		}, function () {
			jQuery(this).find('.delete, .duplicate').remove();
		});
	},

	/**
	 * When a specific item (whoose key is given as parameter) is deleted
	 * we need to delete all conditions using that key as a source
	 * @param key
	 */
	deleteLinkedConditions : function(key)
	{
		// Loop trough the conditions of every field
		var params = null;
		var conditions = null;
		var changes = false;

		for (var itemId in LbwpFormEditor.Data.Items) {
			params = LbwpFormEditor.Data.Items[itemId].params;
			for (var paramId in params) {
				if (params[paramId].key == "conditions" && params[paramId].value != "") {
					conditions = LbwpFormEditor.Core.decodeObjectString(params[paramId].value);
					if (conditions.length > 0) {
						changes = false;
						for (var conditionId in conditions) {
							if (conditions[conditionId].field === key) {
								conditions.splice(conditionId, 1);
								changes = true;
							}
						}
						// Save back the new conditions array if it changed
						if (changes) {
							LbwpFormEditor.Data.Items[itemId].params[paramId].value = LbwpFormEditor.Core.encodeObjectString(conditions);
						}
					}
				}
			}
		}

		// Make sure to update the local data
		LbwpFormEditor.Core.updateJsonField();
	},

	/**
	 * Get the key of an element
	 * @param element
	 * @returns {*}
	 */
	getKey : function(element)
	{
		return LbwpFormEditor.Core.handleKey(element.find("label").attr("for"));
	},

	/**
	 * Handle CTRL Key multi selection
	 * @param isSelected is the current selected element already in the selection
	 * @param selectedId the id of the currently selected element
	 */
	handleCtrlSelection : function(isSelected, selectedId)
	{
		// Select or unselect, but don't do anything if the last item would be unselected
		if (isSelected && LbwpFormEditor.Form.fieldSelection.length > 1) {
			// Unselect it, as clicked and already in selection
			LbwpFormEditor.Form.fieldSelection = LbwpFormEditor.Form.fieldSelection.filter(function(elementId) {
				return selectedId != elementId;
			});
		} else if (!isSelected) {
			LbwpFormEditor.Form.fieldSelection.push(selectedId);
		}
	},

	/**
	 * Handle multi selection width Shift key
	 * @param isSelected is the current selected element already in the selection
	 * @param selectedId the id of the currently selected elementd
	 */
	handleShiftSelection : function(isSelected, selectedId)
	{
		var current = 0, i = 0;
		var smallest = 10000, largest = 0;

		// Add the current selection if not already present
		if (!isSelected) {
			LbwpFormEditor.Form.fieldSelection.push(selectedId);
		}

		// Calculate the smallest and largest item id
		for (i in LbwpFormEditor.Form.fieldSelection) {
			current = LbwpFormEditor.Form.fieldSelection[i];
			if (current < smallest) smallest = current;
			if (current > largest) largest = current;
		}

		// Flush the array to rebuild it from smallest to largest
		LbwpFormEditor.Form.fieldSelection = [];
		for (i = smallest; i <= largest; i++) {
			LbwpFormEditor.Form.fieldSelection.push(i);
		}
	},

	/**
	 * Checks new order in form
	 */
	checkOrder: function ()
	{
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
				};
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
   * Duplicates the field by a key, by adding another same item beneath it
   * @param key
   */
  duplicateFieldByKey : function(key)
  {
    var newJson = {};
    var newKey = '';
    var prefix = '';
    var underlinePos = 0;
    var duplicate = null;

    // Go trough all current items
    jQuery.each(LbwpFormEditor.Data.Items, function(id, item) {
      // First, surely add the item back to the new json
      newJson[id] = item;
      // but if the id and key matches, add a duplicate copy with new key
      if (id == key) {
      	prefix = key;
      	underlinePos = key.indexOf('_');
      	if (underlinePos >= 0) {
      		prefix = prefix.substr(0, underlinePos);
				}
        newKey = prefix + "_" + Date.now();
        duplicate = JSON.parse(JSON.stringify(item));
        // Find the id field to change it with the new key
        for (var i in duplicate['params']) {
          if (duplicate['params'][i].key == 'id') {
            duplicate['params'][i].value = newKey
          }
          if (duplicate['params'][i].key == 'feldname') {
            duplicate['params'][i].value += ' (Kopie)';
          }
        }
        // Now add the duplicate under the new key
        newJson[newKey] = duplicate;
      }
    });

    // Finally add back and save
    LbwpFormEditor.Data.Items = newJson;
    LbwpFormEditor.Core.updateJsonField();
    LbwpFormEditor.Form.loadAjax(key, LbwpFormEditor.Form.loadEdits);
  },

	/**
	 * Duplicate all fields that are in the selection below the last field of the selection
	 */
	duplicateFieldSelection : function()
	{
		if (!confirm(LbwpFormEditor.Text.confirmMultiDuplicate)) {
			return;
		}

		var newJson = {};
		var newKey = '';
		var prefix = '';
		var underlinePos = 0;
		var duplicate = null;
		var duplicates = [];
		var id, element, key;

		// First, duplicate all the items by their key
		for (i in LbwpFormEditor.Form.fieldSelection) {
			id = LbwpFormEditor.Form.fieldSelection[i];
			element = jQuery('[data-editor-id=' + id + ']');
			key = LbwpFormEditor.Form.getKey(element);
			// Get the element by key, and make a duplicate
			jQuery.each(LbwpFormEditor.Data.Items, function(id, item) {
				// but if the id and key matches, add a duplicate copy with new key
				if (id == key) {
					prefix = key;
					underlinePos = key.indexOf('_');
					if (underlinePos >= 0) {
						prefix = prefix.substr(0, underlinePos);
					}
					newKey = prefix + "_" + Date.now();
					duplicate = JSON.parse(JSON.stringify(item));
					// Find the id field to change it with the new key
					for (var i in duplicate['params']) {
						if (duplicate['params'][i].key == 'id') {
							duplicate['params'][i].value = newKey
						}
						if (duplicate['params'][i].key == 'feldname') {
							duplicate['params'][i].value += ' (Kopie)';
						}
					}
					// Now add the duplicate under the new key
					duplicates[newKey] = duplicate;
				}
			});
		}

		// The last key is where we're gonna insert the duplicates
		jQuery.each(LbwpFormEditor.Data.Items, function(id, item) {
			// First, surely add the existing item back to the new json
			newJson[id] = item;
			// when we reach the last key of the selection, insert the duplicates
			if (id == key) {
				for (newKey in duplicates) {
					newJson[newKey] = duplicates[newKey];
				}
			}
		});

		// Finally add back and save
		LbwpFormEditor.Data.Items = newJson;
		LbwpFormEditor.Core.updateJsonField();
		LbwpFormEditor.Form.loadAjax(key, LbwpFormEditor.Form.loadEdits);
	},

	/**
	 * Delete all fields that are in the selection
	 */
	deleteFieldSelection : function()
	{
		if (!confirm(LbwpFormEditor.Text.confirmMultiDelete)) {
			return;
		}

		for (i in LbwpFormEditor.Form.fieldSelection) {
			id = LbwpFormEditor.Form.fieldSelection[i];
			var element = jQuery('[data-editor-id=' + id + ']');
			var key = LbwpFormEditor.Form.getKey(element);
			LbwpFormEditor.Form.deleteLinkedConditions(key);
			element.remove();
		}

		// This rebuilds the json, missing the deleted elements
		LbwpFormEditor.Form.checkOrder();
	},

	/**
	 *
	 * @param current
	 * @param func
	 */
	loadAjax: function (current, func)
	{
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
				jQuery("[for=" + current + "]").closest(".forms-item").addClass("selected");
				func != undefined && (func(current))
			}
		});
	},

	/**
	 * Load all settings related to current field
	 */
	loadEdits: function (key)
	{
		var html = "";
		var fields = LbwpFormEditor.Data.Items[key].params;
		// Try selecting, special case for zipcity
		jQuery("[for=" + key + "]").closest(".forms-item").addClass("selected");
		if (LbwpFormEditor.Data.Items[key].key == 'zipcity') {
			jQuery("[for=" + key + "-zip]").closest(".forms-item").addClass("selected");
		}

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
				case "line":
					editField = LbwpFormEditor.Form.getLineHtml();
					break;
			}

			var ref = fields[fieldKey].has_field_selection == "1" ? '<a title="' + LbwpFormEditor.Text.useFromFieldHeading + '" class="ref dashicons dashicons-admin-generic"></a>' : "";
			var optin = fields[fieldKey].optin == 1 ? '<span class="edit-optin dashicons dashicons-edit" data-key="' + fields[fieldKey].key + '"></span>' : "";
			var help = fields[fieldKey].help != undefined ? '<span class="helpText">' + fields[fieldKey].help + '</span><span class="help dashicons dashicons-editor-help"></span>' : "";
			html += '<div class="lbwp-editField" data-key="' + fields[fieldKey].key + '">' + editField + optin + help + ref + '</div>';
		}

		LbwpFormEditor.Form.editEvents(html, key);
		LbwpFormEditor.Form.loadConditions(key);
	},

	/**
	 * Load fields and conditions that are the same for multi selection to be mass edited
	 */
	loadMultiEdits : function()
	{
		var i = 0, cdi = 0, id = 0;
		var key = '', element = null;

		// First of all, load a default text into the field element
		LbwpFormEditor.Form.loadMultiFieldEditor();

		// Multiple selected, mark them all as selected
		for (i in LbwpFormEditor.Form.fieldSelection) {
			id = LbwpFormEditor.Form.fieldSelection[i];
			element = jQuery('[data-editor-id=' + id + ']');
			key = LbwpFormEditor.Form.getKey(element);
			element.addClass('selected');
		}

		// From the last key, get comparable reference conditions
		var compareConditions = LbwpFormEditor.Form.getConditions(key);
		var similarConditions = LbwpFormEditor.Form.getSimilarConditions(compareConditions);
		var html = "<p>" + LbwpFormEditor.Text.itemMultiConditionText + "</p>";

		// Add the interface parts for similar conditons, if given
		if (similarConditions.length > 0) {
			// Show currently created conditions
			for (i in similarConditions) {
				html += LbwpFormEditor.Form.getConditionRow(
					similarConditions[i].field,
					similarConditions[i].operator,
					similarConditions[i].value,
					similarConditions[i].action
				);
			}
		}

		html += '<div class="field-condition-container"></div>';
		html += '<a href="#" class="addCond button">' + LbwpFormEditor.Text.conditionAdd + '</a>';
		jQuery("#editor-form-tab .field-conditions").html(html);
		// Attach the events to work with conditions
		LbwpFormEditor.Form.conditionEvents();
	},

	/**
	 * Get similar conditions in current selection
	 * @param compareConditions
	 */
	getSimilarConditions : function(compareConditions)
	{
		var similarConditions = [];
		var comparableConditions = [];
		var matches = 0;
		var i = 0, c = 0, x = 0, id = 0;
		var key = '', element = null;

		// Only proceed if there is something to compare
		if (compareConditions.length > 0) {
			// Loop trough each condition, comparing it with every condition of every selected field
			for (c in compareConditions) {
				// Assume the condition is found in every field
				matches = 0;
				for (i in LbwpFormEditor.Form.fieldSelection) {
					id = LbwpFormEditor.Form.fieldSelection[i];
					key = LbwpFormEditor.Form.getKey(jQuery('[data-editor-id=' + id + ']'));
					comparableConditions = LbwpFormEditor.Form.getConditions(key);
					// Loop trough each condition and compare
					for (x in comparableConditions) {
						if (LbwpFormEditor.Form.compareConditions(compareConditions[c], comparableConditions[x])) {
							matches++;
						}
					}
				}

				// After searching, the number of matches must match the number of fields
				if (matches == LbwpFormEditor.Form.fieldSelection.length) {
					similarConditions.push(compareConditions[c]);
				}
			}
		}

		return similarConditions;
	},

	/**
	 * Get similar conditions in current selection
	 * @param compareConditions
	 */
	getNonSimilarConditions : function(compareConditions)
	{
		var nonSimilarConditions = [];
		var comparableConditions = [];
		var matches = 0;
		var i = 0, c = 0, x = 0, id = 0;
		var key = '', element = null;

		// Only proceed if there is something to compare
		if (compareConditions.length > 0) {
			// Loop trough each condition, comparing it with every condition of every selected field
			for (c in compareConditions) {
				// Assume the condition is found in every field
				matches = 0;
				for (i in LbwpFormEditor.Form.fieldSelection) {
					id = LbwpFormEditor.Form.fieldSelection[i];
					key = LbwpFormEditor.Form.getKey(jQuery('[data-editor-id=' + id + ']'));
					comparableConditions = LbwpFormEditor.Form.getConditions(key);
					// Loop trough each condition and compare
					for (x in comparableConditions) {
						if (LbwpFormEditor.Form.compareConditions(compareConditions[c], comparableConditions[x])) {
							matches++;
						}
					}
				}

				// After searching, the number of matches must match the number of fields
				if (matches != LbwpFormEditor.Form.fieldSelection.length) {
					nonSimilarConditions.push(compareConditions[c]);
				}
			}
		}

		return nonSimilarConditions;
	},

	/**
	 *
	 * @param c1
	 * @param c2
	 */
	compareConditions : function(c1, c2)
	{
		return (
			c1.value == c2.value &&
			c1.action == c2.action &&
			c1.field == c2.field &&
			c1.operator == c2.operator
		);
	},

	/**
	 * Display all edited fields in multi select mode
	 */
	loadMultiFieldEditor : function()
	{
		var html = '';

		// Basic entry line, then make a list of all elements
		html += '<p><strong>' + LbwpFormEditor.Text.multiSelectEditFieldsText + '</strong></p><ul>';

		// Get the name of each edited element
		for (i in LbwpFormEditor.Form.fieldSelection) {
			id = LbwpFormEditor.Form.fieldSelection[i];
			element = jQuery('[data-editor-id=' + id + ']');
			key = LbwpFormEditor.Form.getKey(element);
			html += '<li>' + LbwpFormEditor.Data.Items[key].params[0].value + '</li>';
		}

		// Close the list and print
		html += '</ul>';

		// Add further options
		html += '<p><strong>' + LbwpFormEditor.Text.multiSelectFunctions + '</strong></p><ul>';
		html += '<li><a href="javascript:void(0);" class="duplicate-selection">' + LbwpFormEditor.Text.multiSelectFunctionDuplicateAll + '</a></li>';
		html += '<li><a href="javascript:void(0);" class="delete-selection">' + LbwpFormEditor.Text.multiSelectFunctionDeleteAll + '</a></li>';
		html += '</ul>';

		jQuery("#editor-form-tab .field-settings").html(html);

		// Register the multi duplicate callback on the duplicate link
		jQuery("#editor-form-tab .field-settings .duplicate-selection").on('click', LbwpFormEditor.Form.duplicateFieldSelection);
		jQuery("#editor-form-tab .field-settings .delete-selection").on('click', LbwpFormEditor.Form.deleteFieldSelection);
	},

	/**
	 * Gets the conditions of an element, if there are any
	 * @param key
	 */
	getConditions : function(key)
	{
		var params = LbwpFormEditor.Data.Items[key].params;
		for (var i in params) {
			if (params[i].key == "conditions" && params[i].value != "") {
				return LbwpFormEditor.Core.decodeObjectString(params[i].value);
			}
		}

		return [];
	},

	/**
	 * Load all conditions related to current field
	 */
	loadConditions: function(key)
	{
		var params = LbwpFormEditor.Data.Items[key].params;
		var html = "<p>" + LbwpFormEditor.Text.itemConditionText + "</p>";

		// Show currently created conditions
		var hasCond = false;
		var cond = "";
		for (var i in params) {
			if (params[i].key == "conditions" && params[i].value != "") {
				hasCond = true;
				var conditions = LbwpFormEditor.Core.decodeObjectString(params[i].value);
				for (var cdi in conditions) {
					html += LbwpFormEditor.Form.getConditionRow(
						conditions[cdi].field,
						conditions[cdi].operator,
						conditions[cdi].value,
						conditions[cdi].action
					);
				}
				break;
			}
		}

		html += '<div class="field-condition-container"></div>';
		html += '<a href="#" class="addCond button">' + LbwpFormEditor.Text.conditionAdd + '</a>';
		jQuery("#editor-form-tab .field-conditions").html(html);

		// Attach the events to work with conditions
		LbwpFormEditor.Form.conditionEvents();
	},

	/**
	 * Return a condition row
	 */
	getConditionRow: function (field, operator, value, action)
	{
		var key = '', selected = '';
		// Begin the table and add the selection of fields
		var html = '\
			<table class="field-condition">\
				<tr class="condition-field">\
					<td>' + LbwpFormEditor.Text.itemConditionField + '</td>\
					<td><select class="conditionField">';
		for (key in LbwpFormEditor.Data.Items) {
			selected = key == field ? "selected" : "";
			if (LbwpFormEditor.Data.Items[key].key != 'required-note') {
				html += '<option value="' + key + '"' + selected + '>' + LbwpFormEditor.Data.Items[key].params[0].value + '</option>';
			}
		}
		html += '\
			</select></td>\
			<td><span class="delete-condition dashicons dashicons-trash"></span></td>\
		</tr>';

		// Show the possible condition operators
		html += '\
			<tr class="condition-operator">\
				<td>' + LbwpFormEditor.Text.itemConditionType + '</td>\
				<td><select class="conditionOperator">';
		for (key in LbwpFormEditor.Form.fieldConditionOperators) {
			selected = key == operator ? "selected" : "";
			html += '<option value="' + key + '"' + selected + '>' + LbwpFormEditor.Form.fieldConditionOperators[key] + '</option>';
		}
		html += '</select></td>\
				<td></td>\
			</tr>\
		';

		// Show the current condition value
		html += '\
			<tr class="condition-value">\
				<td>' + LbwpFormEditor.Text.itemConditionValue + '</td>\
				<td><input type="text" class="conditionValue" placeholder="' + LbwpFormEditor.Text.itemConditionValuePlaceholder + '" value="' + value + '"></td>\
				<td></td>\
			</tr>\
		';

		// Show the possible actions
		html += '\
			<tr class="condition-operator">\
				<td>' + LbwpFormEditor.Text.itemConditionAction + '</td>\
				<td><select class="conditionAction">';
		for (key in LbwpFormEditor.Form.fieldConditionActions) {
			selected = key == action ? "selected" : "";
			html += '<option value="' + key + '"' + selected + '>' + LbwpFormEditor.Form.fieldConditionActions[key] + '</option>';
		}
		html += '</select></td>\
				<td></td>\
			</tr>\
		';

		// Close the table
		html += '</table>';

		return html;
	},

	/**
	 * Sets all events related to conditions
	 */
	conditionEvents: function ()
	{
		// adds a new Condition
		jQuery(".field-conditions .addCond").off('click').on('click', function (e) {
			e.preventDefault();
			jQuery(".field-conditions .field-condition-container").append(LbwpFormEditor.Form.getConditionRow("", "", "", ""));
			LbwpFormEditor.Form.conditionEvents();
			LbwpFormEditor.Form.saveFieldConditions();
		});

		// Deletes a condition
		jQuery(".field-conditions .delete-condition").off('click').on('click', function (e) {
			e.preventDefault();
			var check = confirm(LbwpFormEditor.Text.confirmDelete);
			if (check) jQuery(this).closest('table.field-condition').remove();
			LbwpFormEditor.Form.saveFieldConditions();
		});

		// Save whole condition array on keyup in any field of a condition
		jQuery(".field-conditions table input").off('keyup').on('keyup', function () {
			// Clear previous timeout if there is
			if (LbwpFormEditor.Form.keyupTimeoutId > 0) {
				clearTimeout(LbwpFormEditor.Form.keyupTimeoutId);
			}
			LbwpFormEditor.Form.keyupTimeoutId = setTimeout(function() {
				LbwpFormEditor.Form.saveFieldConditions();
			}, 350);
		});

		// Save whole condition array on change in any field of a condition
		jQuery(".field-conditions table select, .field-conditions table input").off('change').on('change', function () {
			LbwpFormEditor.Form.saveFieldConditions();
		});
	},

	/**
	 * This works both in single and multi selection mode
	 * Save the field conditions to our json array
	 */
	saveFieldConditions : function()
	{
		if (LbwpFormEditor.Form.fieldSelection.length == 1) {
			LbwpFormEditor.Form.saveConditionsSingle();
		} else {
			LbwpFormEditor.Form.saveConditionsMulti();
		}
	},

	/**
	 * Saves all conditions on a single selected field
	 */
	saveConditionsSingle : function()
	{
		var element = jQuery('[data-editor-id=' + LbwpFormEditor.Form.fieldSelection[0] + ']');
		var key = LbwpFormEditor.Form.getKey(element);
		var params = LbwpFormEditor.Data.Items[key].params;
		var conditions = [];

		jQuery('#editor-form-tab .field-condition').each(function() {
			var table = jQuery(this);
			// Get the variables for this conditions
			conditions.push({
				'field' : table.find('.conditionField').val(),
				'operator' : table.find('.conditionOperator').val(),
				'value' : table.find('.conditionValue').val(),
				'action' : table.find('.conditionAction').val()
			});
		});

		// Search for the correct index to save to
		for (var i in params) {
			if (params[i].key == "conditions") break;
		}

		// Remove eventual duplicates
		conditions = LbwpFormEditor.Form.removeDuplicateConditions(conditions);
		// Make sure the .value of each condition does not contain "-chars
		for (var j in conditions) {
			conditions[j].value = conditions[j].value.replace(/"/g, '');
		}

		LbwpFormEditor.Data.Items[key].params[i].value = LbwpFormEditor.Core.encodeObjectString(conditions);
		LbwpFormEditor.Core.updateJsonField();
	},

	/**
	 * Saves similar conditions on all selected fields, but keeps non similar intact
	 */
	saveConditionsMulti : function()
	{
		var i = 0, j = 0, t = 0, si = 0, c = null;
		var nonSimilarConditions = [], conditions = [];
		var saveableConditions = [];
		var element, key, params, remove;

		// Get the similar conditions to save (all visible)
		var similarConditions = [];
		jQuery('#editor-form-tab .field-condition').each(function() {
			var table = jQuery(this);
			// Get the variables for this conditions
			similarConditions.push({
				'field' : table.find('.conditionField').val(),
				'operator' : table.find('.conditionOperator').val(),
				'value' : table.find('.conditionValue').val(),
				'action' : table.find('.conditionAction').val()
			});
		});

		// Now loop trough each of our fields saving the non-similar and similar conditions
		for (i in LbwpFormEditor.Form.fieldSelection) {
			element = jQuery('[data-editor-id=' + LbwpFormEditor.Form.fieldSelection[i] + ']');
			key = LbwpFormEditor.Form.getKey(element);
			params = LbwpFormEditor.Data.Items[key].params;

			// Search for the correct index to get data from and save to
			for (si in params) {
				if (params[si].key == "conditions") break;
			}

			// Calculate the previous, non similar conditions
			nonSimilarConditions = [];
			if (params[si].value.length > 0) {
				conditions = LbwpFormEditor.Core.decodeObjectString(params[si].value);
				nonSimilarConditions = LbwpFormEditor.Form.getNonSimilarConditions(conditions);
			}

			// Now we have everything to save it back to our array and update it
			conditions = [];
			for (j in nonSimilarConditions)
				conditions.push(nonSimilarConditions[j]);
			for (j in similarConditions)
				conditions.push(similarConditions[j]);

			// Remove eventual duplicates
			conditions = LbwpFormEditor.Form.removeDuplicateConditions(conditions);

			// Save to a temporary variable - we save at the end, because nonSimilar would make problem
			saveableConditions.push({
				"key" : key,
				"index" : parseInt(si),
				"conditions" : LbwpFormEditor.Core.encodeObjectString(conditions)
			});
		}

		// If there is something to save, save it
		if (saveableConditions.length > 0) {
			for (i in saveableConditions) {
				c = saveableConditions[i];
				LbwpFormEditor.Data.Items[c['key']].params[c['index']].value = c['conditions'];
			}
			// After all is done, save the main json field
			LbwpFormEditor.Core.updateJsonField();
		}
	},

	/**
	 * Removes duplicate conditions from the array
	 * @param conditions
	 */
	removeDuplicateConditions : function (conditions)
	{
		var i = 0, j = 0;
		var uniqueConditions = [];

		for (i in conditions) {
			var exists = false;
			for (j in uniqueConditions) {
				if (LbwpFormEditor.Form.compareConditions(conditions[i], uniqueConditions[j])) {
					exists = true;
				}
			}
			// Add to unique conditions if not exists
			if (!exists) {
				uniqueConditions.push(conditions[i]);
			}
		}

		return uniqueConditions;
	},

	/**
	 * Returns html for a textfield
	 */
	getTextfield: function(field)
	{
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
	getDropdown: function (field)
	{
		var html = "";
		html += "<label>" + field.name + "</label>";
		html += '<select>';

		for (var key in field.values) {
			idKey = key.replace('$$', '');
			selected = field.value == idKey ? "selected" : "";
			html += '<option value="' + idKey + '" ' + selected + '>' + field.values[key] + '</option>'
		}

		html += '</select>'
		return html;
	},

	/**
	 * Returns html for radio buttons
	 */
	getRadioButtons: function (field)
	{
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
	getTextfieldArray: function (field)
	{
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
	getTextarea: function (field)
	{
		var html = "";
		var editorLink = "";
		var placeholder = (typeof(field.placeholder) == 'string') ? field.placeholder : '';
		// If editor is useable, activate the link to it
		if (typeof(field.editor) != 'undefined' && field.editor) {
			editorLink = ' <a href="#editor" class="editor-trigger"><span class="dashicons dashicons-edit"></span>Im Editor bearbeiten</a>';
		}
		// Return html
		html += "<label>" + field.name + editorLink + "</label>";
		html += '<textarea id="textarea_' + (LbwpFormEditor.Form.internalId++) + '" placeholder="' + placeholder + '">' + field.value + '</textarea>';
		return html;
	},

	/**
	 * Returns very simple line html
	 */
	getLineHtml : function()
	{
		return '<hr class="form-action-line">';
	},

	/**
	 * Adds an input field to the textfieldArray
	 */
	addInputfield: function (e)
	{
		// Clone element and flush its inputs value
		var newElement = jQuery(e).prev().clone();
		newElement.find('input').val('');
		jQuery(e).before(newElement);
		LbwpFormEditor.Form.editEvents('', '');
	},

	/**
	 * All events related to the edit fields
	 */
	editEvents: function (html, key)
	{
		var time = 100;

		// add html to field-settings, if given
		if (typeof(html) == 'string' && html.length > 0) {
			jQuery("#editor-form-tab .field-settings").html(html);
		}

		jQuery("#editor-form-tab .postbox").accordion({heightStyle: "content"});
		jQuery("#editor-form-tab .hndle-conditions").show();

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

			// To not lose fokus on settings for the zipcity field
			if(current.indexOf('zipcity_') != -1){
				current += '-zip';
			}

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

		// Handle reference box (Same code for actions and items)
		LbwpFormEditor.Action.addRefBoxEvents();
		// If finished, register events for textare editors
		LbwpFormFieldEditor.handleEditorLink();
	},

	onAfterHtmlUpdate : function()
	{
		// Add an icon to all that is invisible and a class to make it blurry
		jQuery('[data-init-invisible=1]').each(function() {
			var container = jQuery(this).closest('.default-container');
			container.closest('.forms-item').addClass('field-invisible');
			container.append('<span class="dashicons dashicons-hidden icon-invisible"></span>');
		});

		// Add an internal selection id to every field, this makes multiselect easier and faster
		var internalId = 1;
		jQuery("#editor-form-tab .lbwp-form " + LbwpFormEditor.Core.validFieldSelector).each(function() {
			jQuery(this).attr('data-editor-id', internalId++);
		})
	}
};
