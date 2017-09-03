/**
 * Helper library to provide backend functionality of sortable types theme feature
 * @author Michael Sebel <michael@comotive.ch>
 */
var LbwpSortableTypes = {

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
			jQuery('.attachments li').each(function() {
				var element = jQuery(this);
				console.log(element.data('order'));
				console.log(element.data('id'));
			});
		});
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
				LbwpSortableTypes.unselectAll();
			},
		});
	},

	/**
	 * Unselects everything after dragging
	 */
	unselectAll : function()
	{
		setTimeout(function() {
			jQuery('.attachments li').removeClass('selected');
		}, 250);
	}

};


jQuery(function() {
	LbwpSortableTypes.initialize();
});