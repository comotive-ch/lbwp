if (typeof(LbwpTableEditor) == 'undefined') {
	var LbwpTableEditor = {};
}

/**
 * Global functions library and invoking of sub components
 * @author Michael Hadorn
 */
LbwpTableEditor.Frontend = {
	/**
	 * The id of the current form
	 */
	tableClass: '.responsive-table',

	height: '500px',

	/**
	 * The main initializer, that also loads the sub classes
	 */
	initialize: function () {
    jQuery(LbwpTableEditor.Frontend.tableClass).each(function (i, e) {
			var fixFirstCol = jQuery(e).data('fix-first-col');
			var fixFirstRows = jQuery(e).data('fix-first-rows');

			// init data table
			table = jQuery(e).DataTable({
				searching:			false,
				paging:					false,
				scrollY:        LbwpTableEditor.Frontend.height,
				scrollX:        true,
				scrollCollapse: true,
				info:						false,
				ordering:				false,
				autoWidth: 			true,
				fixedColumns:   {
						leftColumns: fixFirstCol,
				}
			});


			var tableId = jQuery(e).context.id;
			var bodyHeight = jQuery('#' + tableId + '_wrapper .DTFC_RightBodyLiner').css('overflow', 'hidden');

			// remove header divs if no header should be shown (thead must be set first, else fix col is not possible)
			if (fixFirstRows == 0 && fixFirstCol > 0) {
				jQuery(e).closest('.dataTables_wrapper').find('.DTFC_LeftHeadWrapper, .dataTables_scrollHead, .DTFC_RightHeadWrapper').css('display', 'none');
			}
		});

		// register menu
		jQuery('.datatable-maximize').on('click', function (e) {
			var height = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
			var $tableContainer = jQuery(this).closest('.lbwp-table-wrapper');
			var headerRow = $tableContainer.find('.dataTables_scrollHead').css('height');
			// set height minus header and padding
			LbwpTableEditor.Frontend.toggleFullview(e, this, height-60-parseInt(headerRow));
		});
		jQuery('.datatable-exit-minimize').on('click', function (e) {
			LbwpTableEditor.Frontend.toggleFullview(e, this, LbwpTableEditor.Frontend.height);
		});

		// register scrolling features
		jQuery('.dataTables_scrollBody').addClass('dragscroll');
		dragscroll.reset();
	},

	/**
	 * toggle fullview
	 * @param e
	 * @param obj
	 * @param height
	 */
	toggleFullview : function (e, obj, height) {
		e.preventDefault();
		var $tableContainer = jQuery(obj).closest('.lbwp-table-wrapper');
		$tableContainer.toggleClass('maximised');
		jQuery('body').toggleClass('body-maximised');

		$tableContainer.find('.datatable-maximize').toggle();
		$tableContainer.find('.datatable-exit-minimize').toggle();

		$tableContainer.find('.dataTables_scrollBody, .DTFC_LeftBodyLiner, .DTFC_RightBodyLiner').css('max-height', height);

		// avoid rendering fails (wrong width)
		jQuery(window).trigger('resize');
	},
};

// Loading the editor on load (eh..)
jQuery(function () {
	LbwpTableEditor.Frontend.initialize();
});
