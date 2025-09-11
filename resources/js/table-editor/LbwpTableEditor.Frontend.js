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
	scrollableTable: '.table-scrollable',
	height: '550px',

	/**
	 * The main initializer, that also loads the sub classes
	 */
	initialize: function () {
    jQuery(LbwpTableEditor.Frontend.scrollableTable + ' table').each(function (i, e) {
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

			var tableId = jQuery(e).attr('id');
			var bodyHeight = jQuery('#' + tableId + '_wrapper .DTFC_RightBodyLiner').css('overflow', 'hidden');

			// remove header divs if no header should be shown (thead must be set first, else fix col is not possible)
			if (fixFirstRows == 0 && fixFirstCol > 0) {
				jQuery(e).closest('.dataTables_wrapper').find('.DTFC_LeftHeadWrapper, .dataTables_scrollHead, .DTFC_RightHeadWrapper').css('display', 'none');
			}
		});

		// register menu (toggle fullview)
		jQuery('.datatable-top-menu a').on('click', function (e) {
			LbwpTableEditor.Frontend.toggleFullview(e, this);
		});

		// register scrolling features for non touch devices
		if (!LbwpTableEditor.Frontend.isTouchDevice()) {
			Draggable.create('.dataTables_scrollBody', {
				type: "scroll",
				edgeResistance: 1,
				lockAxis: true
			});
		}
	},

	/**
	 * toggle fullview
	 * @param e event
	 * @param obj the element that fired the event
	 */
	toggleFullview : function (e, obj) {
		e.preventDefault();
		var $tableContainer = jQuery(obj).closest('.lbwp-table-wrapper');
		// Toggle classes and links
		$tableContainer.toggleClass('maximised');
		jQuery('body').toggleClass('body-maximised');
		$tableContainer.find('.datatable-top-menu a').toggle();
	},

	/**
	 * isTouchDevice: not the best way to detect a touch devices, but shoud works for most browsers/devices, perhaps we have to improve here once
	 * @returns {boolean|*}
	 */
	isTouchDevice : function() {
  	return 'ontouchstart' in window        // works on most browsers
      || navigator.maxTouchPoints;       // works on IE10/11 and Surface
	}
};

// Loading the editor on load (eh..)
jQuery(function () {
	LbwpTableEditor.Frontend.initialize();
});
