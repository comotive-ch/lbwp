/**
 * Class to replace every textarea in widgets with an editor
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpWidgetEditor = {
	/**
	 * @var string the textarea selector (takes all areas in widgets)
	 */
	textareaSelector : '.widget-liquid-right .widget-inside textarea',
	/**
	 * @var string selector to get all used/active widgets
	 */
	widgetCountSelector : '.widget-liquid-right .widget:not(.ui-draggable)',
	/**
	 * @var int number of active widgets
	 */
	widgetCount : 0,
	/**
	 * On loading of the page
	 */
	initialize : function()
	{
		LbwpWidgetEditor.prepareTextareas();
		LbwpWidgetEditor.handleEditLink();
		LbwpWidgetEditor.handleWidgetSave();
		LbwpWidgetEditor.handleNewWidget();
	},

	/**
	 * Create containers to display editor content, and hide the sidebars
	 */
	prepareTextareas : function()
	{
		// Go trough all areas
		jQuery(LbwpWidgetEditor.textareaSelector).each(function() {
			var textarea = jQuery(this);

			// Leave loop item right, if there is no ID or no editor is desired or it already has one
			if (
				typeof(textarea.attr('id')) == 'undefined' ||
				textarea.attr('id').length == 0 ||
				textarea.hasClass('no-editor-widget')
			) {
				return true;
			}

			// Hide the textarea
			textarea.hide();

			// Add an event, to update content if area changes
			textarea.off('change').on('change', LbwpWidgetEditor.onAreaChange);

			// Add the container element, if not already there
			if (textarea.parent().find('.editor-widget-container').length == 0) {
				// Create a new html element below that will display the content
				var html = '\
					<div class="editor-widget-container" data-area-id="' + textarea.attr('id') + '">\
						Vorschau des Inhalts | <a class="edit-with-tinymce">Inhalt Bearbeiten</a>\
						<div id="' + textarea.attr('id') + '-content" class="editor-widget-content"></div>\
					</div>\
				';

				// Add that html after the textarea to later be filled
				textarea.after(html);
				textarea.trigger('change');
			}
		});
	},

	/**
	 * Handle the editor links to fire up the editor. We need to do this every second, because
	 * WP js doesn't fire an event upon adding a new widget, that needs this event too
	 */
	handleEditLink : function()
	{
		jQuery('.edit-with-tinymce').off('click').on('click', function() {
			var link = jQuery(this);
			var textareaId = '#' + link.closest('.editor-widget-container').data('area-id');
			LbwpWidgetEditor.showEditor(textareaId);
		});

		jQuery('.editor-widget-container').off('click').on('click', function() {
			var textareaId = '#' + jQuery(this).data('area-id');
			LbwpWidgetEditor.showEditor(textareaId);
		});
	},

	/**
	 * Show the editor, load the content and register the closing buttons to
	 * correctly insert the contentb back into the area and hence, updating the
	 * displayed preview content of the widget.
	 */
	showEditor : function(textareaId)
	{
		var container = jQuery('#widgetEditorContainer');

		// Display background and close on click to the background
		jQuery('.media-modal-backdrop-editor').show().on('click', LbwpWidgetEditor.hideEditor);
		// Bring the editor to view, by setting its bottom value
		container.css('bottom', 0);

		// Load data into the editor from textarea
		LbwpWidgetEditor.loadFromTextarea(textareaId);

		// Assign events to the buttons
		container.find('.widget-editor-save').data('save-to-textarea', textareaId);
		// When the save button is pressed
		container.find('.widget-editor-save')
			.off('click')
			.on('click', LbwpWidgetEditor.saveEditorContent);
		// When only the close button is pressed (nothing is saved)
		container.find('.widget-editor-close')
			.off('click')
			.on('click', LbwpWidgetEditor.hideEditor);
	},

	/**
	 * Loads text from the selectable textarea into the editor
	 * @param selector selector to a single textarea
	 */
	loadFromTextarea : function(selector)
	{
		LbwpWidgetEditor.setEditorContent(jQuery(selector).val());
	},

	/**
	 * Set the editor content to tinymce and textarea
	 * @param html
	 */
	setEditorContent : function(html)
	{
		if (jQuery('.mce-container').is(':visible')) {
			tinyMCE.activeEditor.setContent(html);
		} else {
			jQuery('#widgetEditor').val(html)
		}
	},

	/**
	 * Get the editor content from tinymce or text mode
	 */
	getEditorContent : function()
	{
		if (jQuery('.mce-container').is(':visible')) {
			return tinyMCE.activeEditor.getContent();
		} else {
			return jQuery('#widgetEditor').val();
		}
	},

	/**
	 * Takes the editor content and saves it back to the original textarea,
	 * which triggers the preview, since we registered and event for that
	 */
	saveEditorContent : function()
	{
		// Save back to textarea and update the preview
		var destination = jQuery(jQuery(this).data('save-to-textarea'));
		var html = LbwpWidgetEditor.getEditorContent();
		destination.html(html).trigger('change');
		// Hide yo wife, hide yo editor
		LbwpWidgetEditor.hideEditor();
	},

	/**
	 * Hides the editor and modal background
	 */
	hideEditor : function()
	{
		jQuery('.media-modal-backdrop-editor').hide().off('click');
		// Bring the editor to view, by setting its bottom value
		jQuery('#widgetEditorContainer').css('bottom', '-10000px');
	},

	/**
	 * Fires, when a textarea is changed by the editor
	 */
	onAreaChange : function()
	{
		var textarea = jQuery(this);
		var container = jQuery('#' + textarea.attr('id') + '-content');
		var newHtml = textarea.val();

		// Show if there is no text yet
		if (typeof(newHtml) == 'undefined' || newHtml.length == 0) {
			newHtml = 'Es wurde noch kein Text definiert.';
		}

		// Tell the user if the content can't be displayed
		if (LbwpWidgetEditor.isDisallowedContent(newHtml)) {
			newHtml = 'Vorschau kann nicht dargestellt werden (IFrame Inhalt).';
		}

		// Insert new html or bogus text
		container.html(newHtml);
		// Prevent links from being actually clicked
		container.find('a').off('click').on('click', function(e) {
			e.preventDefault();
			return true;
		});
	},

	/**
	 * Do not load disallowed content like iframes
	 * @param html
	 * @returns true, if the content is not allowed to be displayed because things could break
	 */
	isDisallowedContent : function(html)
	{
		return (
			html.indexOf('<iframe') >= 0 ||
			html.indexOf('<object') >= 0 ||
			html.indexOf('<script') >= 0
		);
	},

	/**
	 * When a widget is saved, they reload it via ajax, which means, we need to do all
	 * our stuff again, but just on the saved widget, because it would break otherwise
	 */
	handleWidgetSave : function()
	{
		jQuery('.widget-control-save').off('click').on('click', function() {
			var buttonParent = jQuery(this).parent();
			LbwpWidgetEditor.saveInterval = setInterval(function() {
				if (buttonParent.find('.spinner').css('visibility') == 'hidden') {
					LbwpWidgetEditor.prepareTextareas();
					LbwpWidgetEditor.handleEditLink();
					clearInterval(LbwpWidgetEditor.saveInterval);
				}
			}, 1);
		});
	},

	/**
	 * Counts the number of widgets in sidebars, and if it goes up, it reassigns
	 * all the events, to make sure everything works on newly created widgets
	 */
	handleNewWidget : function()
	{
		LbwpWidgetEditor.widgetCount = jQuery(LbwpWidgetEditor.widgetCountSelector).length;

		// Check every half second
		setInterval(function() {
			var currentCount = jQuery(LbwpWidgetEditor.widgetCountSelector).length;
			if (currentCount != LbwpWidgetEditor.widgetCount) {
				LbwpWidgetEditor.prepareTextareas();
				LbwpWidgetEditor.handleEditLink();
				LbwpWidgetEditor.handleWidgetSave();

				// Save the current count for the next widget
				LbwpWidgetEditor.widgetCount = currentCount;
			}
		}, 500);
	}
};

// Initialize on load
jQuery(function() {
	LbwpWidgetEditor.initialize();
});