/**
 * Helper library to provide backend functionality of sortable types theme feature
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpSortableTypes = {

	/**
	 * Some settings for saving images
	 */
	saveItemsPerPackage : 10,
	timeBetweenSaves : 500,
	textIsSaving : 'Sortierung wird gespeichert...',
	textSavedSortSuccess : 'Sortierung wurde gespeichert!',
	textSaveSort : 'Sortierung speichern',

	/**
	 * Initialize the library
	 */
	initialize : function()
	{
		LbwpSortableTypes.handleSorting();
		LbwpSortableTypes.handleSaving();
	},

	/**
	 * Does an ajax save to actually reorder the items
	 */
	handleSaving : function()
	{
		jQuery('.save-item-order').click(function() {
			var button = jQuery(this);
			var packages = [], pack = [];
			var confirmedPackages = 0, sentPackages = 0;
			var data = { 'action' : 'save_post_type_order' };
			button.text(LbwpSortableTypes.textIsSaving);

			jQuery('.attachments li').each(function() {
				var element = jQuery(this);
				if (pack.length == LbwpSortableTypes.saveItemsPerPackage) {
					packages.push(pack);
					pack = [];
				}

				pack.push({
					'id' : element.data('id'),
					'order' : element.data('order')
				});
			});

			// If there's an unpushed pack, push it
			if (pack.length > 0) {
				packages.push(pack);
			}

			// Push the packages to the server
			for (var id in packages) {
				setTimeout(function() {
					var id = sentPackages++;
					data.packages = packages[id];
					jQuery.post(ajaxurl, data, function(response) {
						if (++confirmedPackages == packages.length) {
							LbwpSortableTypes.resetSaveButton();
						}
					});
				}, LbwpSortableTypes.timeBetweenSaves * (parseInt(id)+1));
			}
		});
	},

	/**
	 * Reset the save button back to its state
	 */
	resetSaveButton : function()
	{
		var button = jQuery('.save-item-order');
		button.text(LbwpSortableTypes.textSavedSortSuccess);
		// Reset the button text in a few seconds
		setTimeout(function() {
			button.text(LbwpSortableTypes.textSaveSort);
		}, 3000);
	},

	/**
	 * Handle the multi sorting here
	 */
	handleSorting : function()
	{
		jQuery('.attachments').multisortable({
			items: 'li',
			selectedClass: 'selected',
			helper : function(e, item) {
				jQuery('.attachments').addClass('is-dragging');
				return item;
			},
			stop : function() {
				jQuery('.attachments').removeClass('is-dragging');
				// Reset the order
				jQuery('.attachments li').each(function(index) {
					jQuery(this).data('order', index+1);
				});
			}
		});
	}
};


jQuery(function() {
	LbwpSortableTypes.initialize();
});