/**
 * JS object to provide automatic fancybox on linked images, grouped fancyboxes
 * on wordpress galleries, configuration features and swiping images on mobile
 * deviced defined by a pixel breakpoint.
 * @author Michael Sebel <michael@comotive.ch>
 */
var SimpleFancyBox = {

	/**
	 * Executed on load to register the fancyness
	 */
	initialize : function()
	{
		// Only proceed if there is a config
		if (typeof(FancyBoxConfig) == 'undefined') {
			return;
		}

		// Decide how to group the fancyboxes
		switch (FancyBoxConfig.grouping) {
			case 'gallery':
				SimpleFancyBox.registerGalleryImages();
				// Only register other images, if configured to
				if (FancyBoxConfig.ifGalleryRegisterAutoImages) {
					SimpleFancyBox.registerStandaloneImages();
				}
				break;
			case 'unlink':
				// First register galleries/images, to then unlink them easily
				SimpleFancyBox.registerGalleryImages();
				SimpleFancyBox.registerStandaloneImages();
				SimpleFancyBox.unlinkImages();
				break;
			case 'automatic':
			default:
				SimpleFancyBox.registerStandaloneImages();
				break;
		}

		// Put fancyiness on those images
		if (SimpleFancyBox.isSwipeOnly()) {
			SimpleFancyBox.registerSwipeOnly();
		} else {
			// Still add helper classes to galleries?
			if (FancyBoxConfig.alwaysAddGalleryItemClasses) {
				SimpleFancyBox.addGalleryHelperClasses();
			}
			SimpleFancyBox.registerFancybox();
		}

		// Fix height, if configured to
		if (FancyBoxConfig.calcFixHeight) {
			SimpleFancyBox.calcAndSetHeight();
		}
	},

	/**
	 * Get the highest gallery item and use this height as fix gallery height
	 */
	calcAndSetHeight : function() {
		jQuery('div.gallery').each(function() {
			var nHeight = 0;
			var galleryWrapper = jQuery(this);
			galleryWrapper.find('.gallery-item').each(function() {
				var nCurrentHeight = jQuery(this).outerHeight(true);
				// Check for highest item
				nHeight = (nCurrentHeight > nHeight) ? nCurrentHeight : nHeight;
			});
			if (nHeight > 0) {
				galleryWrapper.css('height', nHeight + 'px');
			}
		});
	},

	/**
	 * Register all images in a wordpress gallery as their own closed gallery
	 */
	registerGalleryImages : function()
	{
		// Go trough each wordpress gallery
		jQuery('div.gallery').each(function() {
			var galleryWrapper = jQuery(this);
			var galleryId = galleryWrapper.prop('id');
			// Proceed if there is a proper ID
			if (typeof(galleryId) == 'string' && galleryId.length > 0) {
				// Go trough images and register them as group
				galleryWrapper.find('.gallery-item img').each(function() {
					SimpleFancyBox.registerFancyImage(jQuery(this), galleryId);
				});
			}
		})
	},

	/**
	 * Go through every image to create auto fancybox and auto galleries
	 */
	registerStandaloneImages : function()
	{
		jQuery('body img').each(function () {
			if (FancyBoxConfig.automaticImagesAsGroup) {
				SimpleFancyBox.registerFancyImage(jQuery(this), 'automatic-gallery');
			} else {
				var randomId = 'automatic-image-' + Math.floor(Math.random() * (9999 - 1)) + 1;
				SimpleFancyBox.registerFancyImage(jQuery(this), randomId);
			}
		});
	},

	/**
	 * Register a potential image as openable in fancybox while grouping it
	 * @param image the image DOM object candidate
	 * @param group the group name to assign
	 */
	registerFancyImage : function(image, group)
	{
		// Get the parent element
		var parent = image.parent();
		// see if the parent is a link tag
		if (parent.prop('tagName').toLowerCase() == 'a') {
			var href = parent.attr('href');
			if (typeof(href) == 'string') {
				// see if the link is another image
				var ext = href.split('.').pop().toLowerCase();
				if (!parent.hasClass('auto-fancybox') && (ext == 'jpg' || ext == 'jpeg' || ext == 'gif' || ext == 'png')) {
					parent.addClass('auto-fancybox');
					// If there is no grouping attribute, add it as defined
					if (typeof(parent.attr('rel')) == 'undefined') {
						parent.attr('rel', group);
					}
				}
			}
		}
	},

	/**
	 * Register the actual automatic fancybox on all found images and galleries
	 */
	registerFancybox : function()
	{
		jQuery('.auto-fancybox').fancybox({
			padding: FancyBoxConfig.padding,
			margin: FancyBoxConfig.margin,
			maxWidth: FancyBoxConfig.maxWidth,
			beforeShow: SimpleFancyBox.onBeforeShow,
			afterLoad: SimpleFancyBox.onAfterLoad,
			openEffect : FancyBoxConfig.effectOpen,
			closeEffect : FancyBoxConfig.effectClose,
			nextEffect : FancyBoxConfig.effectNext,
			prevEffect : FancyBoxConfig.effectPrev,
			helpers : FancyBoxConfig.helpers
		});
	},

	/**
	 * Logic to add automatic captions depending on type of image and configuration
	 */
	onBeforeShow : function()
	{
		// Try to find caption in classic gallery format
		if (this.title.length == 0) {
			this.title = jQuery(this.element).closest('.gallery-item').find('.wp-caption-text').text();
		}

		// If still no title, try normal wordpress image input
		if (this.title.length == 0) {
			this.title = jQuery(this.element).parent().find('.wp-caption-text').text();
		}

		// Add number of images, if setup and styled for customer
		if (FancyBoxConfig.showNumberOfImages) {
			var text = FancyBoxConfig.textNumberOfImages;
			text = text.replace('{index}', this.index + 1);
			text = text.replace('{total}', this.group.length);
			this.title += '<span class="pagination">' + text + '</span>';
		}
	},

	/**
	 * Register swipe events on the wrapper element, if given
	 */
	onAfterLoad : function()
	{
		// Add swipe event to the wrap, if they are in fact registered
		var fancyboxWrap = jQuery('.fancybox-wrap');
		fancyboxWrap.on('swipeleft', function () {
			jQuery.fancybox.next();
		});
		fancyboxWrap.on('swiperight', function () {
			jQuery.fancybox.prev();
		});
	},

	/**
	 * Register swipe only on galleries and disable all linked auto fancybox links
	 */
	registerSwipeOnly : function()
	{
		if (FancyBoxConfig.swipeOnlyUseFancybox) {
			SimpleFancyBox.registerFancybox();
		} else {
			// Disable the actual links opening the images (because that doesn't make sense)
			jQuery('.auto-fancybox').on('click', function(event) {
				event.preventDefault();
				return false;
			});
		}


		// Add gallery helper classes
		SimpleFancyBox.addGalleryHelperClasses();

		// Go trough each gallery to assign events and classes
		jQuery('div.gallery').each(function() {
			var galleryWrapper = jQuery(this);

			// If needed, add handles and add events
			if (FancyBoxConfig.swipeOnlyAddHandles) {
				SimpleFancyBox.addNavigationHandles(galleryWrapper);
			}

			// Set the index of the current item
			galleryWrapper.data('item', 0);
			// Register Gallery events for this gallery
			galleryWrapper.on('swipeleft', SimpleFancyBox.gallerySwipeLeft);
			galleryWrapper.on('swiperight', SimpleFancyBox.gallerySwipeRight);
			// Only register click, if there are no handles
			if (!FancyBoxConfig.swipeOnlyAddHandles) {
				galleryWrapper.on('click', SimpleFancyBox.gallerySwipeLeft);
			}
		});
	},

	/**
	 * Add helper classes to gallery items
	 */
	addGalleryHelperClasses : function()
	{
		// Go trough each gallery to assign events and classes
		jQuery('div.gallery').each(function() {
			var galleryWrapper = jQuery(this);
			var didFirstClass = false;

			// Set classes on all gallery items initially
			galleryWrapper.find('.gallery-item').each(function() {
				var item = jQuery(this);
				if (!didFirstClass) {
					item.addClass('gallery-item-current');
					didFirstClass = true;
				} else {
					item.addClass('gallery-item-right');
				}
			});
		});
	},

	/**
	 * Add html objects to be designed as handles to navigation instead of swiping only
	 * @param gallery the gallery object
	 */
	addNavigationHandles : function(gallery)
	{
		// Create the objects
		var leftHandle = jQuery('<div class="gallery-handle handle-left"></div>');
		var rightHandle = jQuery('<div class="gallery-handle handle-right"></div>');

		// Add them to the gallery
		gallery.append(rightHandle).append(leftHandle);

		// Positioning the handles of first item
		SimpleFancyBox.calcHandlesPosition(gallery);

		// Add events on the specific items
		gallery.find('.handle-left').on('click', function() {
			jQuery(this).parent().trigger('swiperight');
		});
		gallery.find('.handle-right').on('click', function() {
			jQuery(this).parent().trigger('swipeleft');
		});
	},

	/**
	 * Positioning the handles
	 */
	calcHandlesPosition : function(galleryWrapper,galleryItem)
	{
		// Check for configured handles
		if (!FancyBoxConfig.swipeOnlyAddHandles) {
			return;
		}
		// Do vertically position of current item
		if (FancyBoxConfig.calcModeHandlesVerticalPosition == 'image') {
			SimpleFancyBox.calcHandlesVerticalPosByImageHeight(galleryWrapper,galleryItem);
		}
	},

	/**
	 * Centers the left and right handles vertically to the current image
	 * @param gallery the gallery object
	 * @param item (optional) item to calc, default is current item
	 */
	calcHandlesVerticalPosByImageHeight : function(gallery,item)
	{
		var galleryItem = (typeof(item) == 'undefined') ? gallery.find('.gallery-item-current') : item;
		var itemImage = galleryItem.find('img');
		var handles = gallery.find('.gallery-handle');
		// Read height of handles
		var handleHeight = (handles.length > 0) ? handles.height() : 0;
		// Calculate if image is found
		if (itemImage.length > 0) {
			var imgHeight = itemImage.height();
			var topPos = (imgHeight * 0.5) - (handleHeight * 0.5);
			handles.css('top', topPos + 'px');
		}
	},

	/**
	 * Swiping the gallery left, means the next image from the right comes in
	 */
	gallerySwipeLeft : function()
	{
		var galleryWrapper = jQuery(this);
		var items = galleryWrapper.find('.gallery-item');
		var currentIndex = galleryWrapper.data('item');
		var nextIndex = currentIndex + 1;

		// Don't do anything if there is no next index
		if (items.length == nextIndex) {
			return false;
		}

		// If there is something to swipe, DO it.
		items.each(function(key) {
			var item = jQuery(this);
			// Remove the current class
			item.removeClass('gallery-item-current');

			// Set it left, if before currentIndex
			if (key < nextIndex) {
				item.addClass('gallery-item-left swiped');
			} else if (key == nextIndex) {
				// Positioning the handles of next item
				SimpleFancyBox.calcHandlesPosition(galleryWrapper,item);
				item.addClass('gallery-item-current');
				item.removeClass('gallery-item-right');
			}
		});

		// Set the new index
		galleryWrapper.data('item', nextIndex);
	},

	/**
	 * Swiping the gallery right, means the previous image from the left comes in
	 */
	gallerySwipeRight : function()
	{
		var galleryWrapper = jQuery(this);
		var items = galleryWrapper.find('.gallery-item');
		var currentIndex = galleryWrapper.data('item');
		var previousIndex = currentIndex - 1;

		// Don't do anything if there is no next index
		if (previousIndex < 0) {
			return false;
		}

		// If there is something to swipe, DO it.
		items.each(function(key) {
			var item = jQuery(this);
			// Remove the current class
			item.removeClass('gallery-item-current');

			// Set it left, if before currentIndex
			if (key > previousIndex) {
				item.addClass('gallery-item-right swiped');
			} else if (key == previousIndex) {
				// Positioning the handles of next item
				SimpleFancyBox.calcHandlesPosition(galleryWrapper,item);
				item.addClass('gallery-item-current');
				item.removeClass('gallery-item-left');
			}
		});

		// Set the new index
		galleryWrapper.data('item', previousIndex);
	},

	/**
	 * @return boolean true, if we only want a swipe slide gallery that is not opening a fancy box
	 */
	isSwipeOnly : function()
	{
		if (FancyBoxConfig.swipeOnlyActive) {
			switch (FancyBoxConfig.swipeOnlyDetermination) {
				case 'client':
					// Test if it is a mobile client
					return (/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(a)||/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0,4)));
				case 'width':
					// Test client width
					return (window.innerWidth <= FancyBoxConfig.swipeOnlyBreakpointWidth);
				case 'always':
					return true;
			}
		}

		// No swipe, normal fancybox
		return false;
	},

	/**
	 * Unlink all images from their linked originals
	 */
	unlinkImages : function()
	{
		jQuery('.auto-fancybox')
			.removeAttr('href')
			.removeClass('auto-fancybox');
	}
};

// Run auto fancybox on load
jQuery(function() {
	SimpleFancyBox.initialize();
});