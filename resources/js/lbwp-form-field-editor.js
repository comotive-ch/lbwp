/**
 * Class to replace textareas in form editor with a WP editor
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpFormFieldEditor = {

	/**
	 * Handle the editor links to fire up the editor
	 */
	handleEditorLink : function()
	{
		jQuery('.editor-trigger').off('click').on('click', function() {
			var link = jQuery(this);
			var textareaId = '#' + link.closest('.lbwp-editField').find('textarea').attr('id');
			setTimeout(function() {
				LbwpFormFieldEditor.showEditor(textareaId);
			}, 300);
		});

		jQuery('.editor-form-field-container').off('click').on('click', function() {
			var textareaId = '#' + jQuery(this).data('area-id');
			setTimeout(function() {
				LbwpFormFieldEditor.showEditor(textareaId);
			}, 300);
		});
	},

	/**
	 * Show the editor, load the content and register the closing buttons to
	 * correctly insert the contentb back into the area and hence, updating the
	 * displayed preview content of the form field item.
	 */
	showEditor : function(textareaId)
	{
		var container = jQuery('#formFieldEditorContainer');

		// Display background and close on click to the background
		jQuery('.media-modal-backdrop-editor').show().on('click', LbwpFormFieldEditor.hideEditor);
		// Bring the editor to view, by setting its bottom value
		container.css('bottom', 0);

		// Load data into the editor from textarea
		LbwpFormFieldEditor.loadFromTextarea(textareaId);

		// Assign events to the buttons
		container.find('.form-field-editor-save').data('save-to-textarea', textareaId);
		// When the save button is pressed
		container.find('.form-field-editor-save')
			.off('click')
			.on('click', LbwpFormFieldEditor.saveEditorContent);
		// When only the close button is pressed (nothing is saved)
		container.find('.form-field-editor-close')
			.off('click')
			.on('click', LbwpFormFieldEditor.hideEditor);
	},

	/**
	 * Loads text from the selectable textarea into the editor
	 * @param selector selector to a single textarea
	 */
	loadFromTextarea : function(selector)
	{
		LbwpFormFieldEditor.setEditorContent(jQuery(selector).val());
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
			jQuery('#formFieldEditor').val(html)
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
			return jQuery('#formFieldEditor').val();
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
		var html = LbwpFormFieldEditor.getEditorContent();
		// Super hacky solution to make shortcodes work
		html = html.replace(/\[/g, '&lceil;');
		html = html.replace(/]/g, '&rfloor;');
		destination.val(html).trigger('change');
		// Hide yo wife, hide yo editor
		LbwpFormFieldEditor.hideEditor();
	},

	/**
	 * Hides the editor and modal background
	 */
	hideEditor : function()
	{
		jQuery('.media-modal-backdrop-editor').hide().off('click');
		// Bring the editor to view, by setting its bottom value
		jQuery('#formFieldEditorContainer').css('bottom', '-10000px');
	}
};