/**
 * Watchlist functions
 * @author Mirko Baffa <mirko@comotive.ch>
 */
 var Watchlist  = {
	lists : {},

	localLists : JSON.parse(localStorage.getItem('lbwp-watchlists')),

	/**
	 * Initialize the watchlist(s)
	 */
	initialize : function(){
		Watchlist.initializeLists();
		Watchlist.handleMenus();
		Watchlist.closeMenu();
		Watchlist.handleFilterBtns();
		Watchlist.displayLocalList();
		Watchlist.checkWatchlistDiff();
		Watchlist.handleLocalItems();
		Watchlist.handleAccountLists();
		Watchlist.changeActiveList();
		Watchlist.handleQtyInList();
		Watchlist.handleIndicator();
	},

	/**
	 * Initializes the watchlists
	 */
	initializeLists : function(){
		Watchlist.lists = JSON.parse(watchlistData.userLists);
		
		if(Watchlist.localLists === null){
			Watchlist.localLists = Watchlist.lists;
			localStorage.setItem('lbwp-watchlists', watchlistData.userLists);
		}

		watchlistData.loggedInMode = watchlistData.loggedInMode == 1;
	},

	/**
	 * Handle the watchlists menu
	 */
	handleMenus : function(){
		var menus = jQuery('.lbwp-watchlist__menu');
		
		if(menus.length > 0){
			jQuery.each(menus, function(){
				var menu = jQuery(this);
				menu.find('.lbwp-watchlist__icon').click(function(){
					menu.toggleClass('open');
				});

				menu.find('.lbwp-watchlist__close').click(function(){
					menu.removeClass('open');
				});

				/* Not sure if needed
				jQuery('main').click(function(){
					menus.removeClass('open');
				});*/
			});
		}
	},
	
	/**
	 * Add click events to the add to list button
	 */
	handleAddButtons : function(){
		var btns = jQuery('.lbwp-add-to-watchlist');

		if(btns.length > 0){
			jQuery.each(btns, function(){
				var btn = jQuery(this);

				btn.click(function(){
					Watchlist.updateListItem(btn);
				});
			});
		}
	},

	/**
	 * Add events to filter buttons
	 */
	handleFilterBtns : function(){
		if(jQuery('.wp-block-product-filter').length > 0){
			jQuery(document.body).on('lbwp-products-loaded', function(){
				Watchlist.handleAddButtons();
			});
		}else{
			Watchlist.handleAddButtons();
		}
	},

	/**
	 * AJAX call to add products to the list
	 * @param {DOM Element|int} theButton the button node or the product id
	 */
	updateListItem : function(theButton, listId){
		var isButton = isNaN(Number(theButton));
		var pId = isButton ? Number(theButton.attr('data-product-id')) : Number(theButton);

		if(watchlistData.loggedInMode){
			jQuery.ajax({
				type : 'POST',
				url : watchlistData.ajaxUrl,
				data : {
					action : 'updateListItem',
					product : pId,
					listId : listId
				},
				success : function(response){
					if(isButton){
						theButton.toggleClass('in-list');
					}
					Watchlist.updateMenuHtml();
				}
			});
		}else{
			var prodIndex = Watchlist.localLists.list_0.products.indexOf(pId);
			if(prodIndex !== -1){
				Watchlist.localLists.list_0.products.splice(prodIndex, 1);
			}else{
				Watchlist.localLists.list_0.products.push(Number(pId));
			}

			Watchlist.localLists.list_0.date = Date.now();

			if(isButton){
				theButton.toggleClass('in-list');
			}

			localStorage.setItem('lbwp-watchlists', JSON.stringify(Watchlist.localLists));
			Watchlist.updateMenuHtml();
		}
	},

	/**
	 * Update the watchlist menu html
	 */
	updateMenuHtml : function(){
		jQuery.ajax({
			type : 'POST',
			url : watchlistData.ajaxUrl,
			data : {
				action : 'getWatchlistHtml',
				useLocalList : !watchlistData.loggedInMode,
				localWatchlist : JSON.stringify(Watchlist.localLists),
			},
			success : function(response){
				var listing = jQuery('.lbwp-watchlist__listing');
				listing.find('.lbwp-watchlist__list').replaceWith(response);

				Watchlist.setListActions();
				Watchlist.handleIndicator();
			}
		});
	},

	/**
	 * Add the remove and the add-to-cart arcion to the watchlist menu
	 */
	setListActions : function(){
		var removeBtns = jQuery('.lbwp-watchlist__remove');
		var addToCart = jQuery('.lbwp-watchlist__add-to-cart a');
		var accountList = jQuery('.lbwp-watchlist__account-listing');

		if(removeBtns.length > 0){
			jQuery.each(removeBtns, function(){
				var rBtn = jQuery(this);

				rBtn.click(function(){
					var parent = rBtn.closest('.lbwp-watchlist__account-list');
					var list = 'list_0';

					if(accountList.length > 0) {
						if (parent.length > 0) {
							list = parent.attr('data-list-id');
						} else {
							var selectedList = jQuery('.lbwp-watchlist__select select');
							list = selectedList.length > 0 ? 'list_' + selectedList.val() : list;

							var removedProd = jQuery('.lbwp-watchlist__account-list[data-list-id="' + list + '"] .lbwp-watchlist__remove[data-product="' + rBtn.attr('data-product') + '"]');
							console.log(jQuery('.lbwp-watchlist__account-list[data-list-id="' + list + '"]'));
							removedProd.closest('li').remove();
						}
					}

						Watchlist.updateListItem(rBtn.attr('data-product'), list);
						Watchlist.updateAddListBtn(rBtn.attr('data-product'), true);
						rBtn.closest('li').remove();
				});
			});
		}

		if(addToCart.length > 0){
			jQuery.each(addToCart, function(){
				var atcBtn = jQuery(this);
				
				if(atcBtn.attr('href').indexOf('?add-to-cart') !== -1){
					atcBtn.click(function(e){
						e.preventDefault();
						atcBtn.addClass('adding-to-cart');
						jQuery.post(atcBtn.attr('href'), function() {
							// Get the message seperately from backend
							jQuery.post('/wp-json/custom/products/notices/', function(inner) {
								jQuery('body').append(inner.html);
								atcBtn.removeClass('adding-to-cart');

								AboonJS.removeAddToCartMessages();

								// Update cart number
								var qtyInput = atcBtn.closest('.watchlist-product-actions').find('input[type="number"]');;
								var cartNumValue = qtyInput.length > 0 ? Number(qtyInput.val()) : 1;

								// Handle cart stock error
								if(inner.html.indexOf('woocommerce-error') > -1) {
									cartNumValue = cartNumValue - inner.data.error[0].data.addedItems;
								}
								AboonJS.updateCartIcon(cartNumValue, true);
							});
						});

						return false;
					});
				}
			});
		}

		Watchlist.handleQtyInList();
	},

	/**
	 * If not logged in, display the local list (if available)
	 */
	displayLocalList : function(){
		if(!watchlistData.loggedInMode && Watchlist.localLists.list_0.products.length > 0){
			Watchlist.updateMenuHtml();
		}
	},

	/**
	 * Merge the local and the logged-in watchlist
	 */
	checkWatchlistDiff : function(){
		if(watchlistData.loggedInMode){
			jQuery.ajax({
				type : 'POST',
				url : watchlistData.ajaxUrl,
				data : {
					action : 'mergeWatchlists',
					localWatchlist : JSON.stringify(Watchlist.localLists),
				},
				success : function(response){
					// Flush local lists
					Watchlist.localLists.list_0.products = [];
					localStorage.setItem('lbwp-watchlists', JSON.stringify(Watchlist.localLists));
					Watchlist.updateMenuHtml();
				}
			});
		}
	},

	/**
	 * Set active state to watchlist items
	 */
	handleLocalItems : function(){
		if(!watchlistData.loggedInMode){
			jQuery.each(Watchlist.localLists.list_0.products, function(i){
				var productId = Watchlist.localLists.list_0.products[i];
				Watchlist.updateAddListBtn(productId);
			});

			Watchlist.handleLocalFilterItems();
		}
	},

	/**
	 * Handle filter watchlist icon
	 */
	handleLocalFilterItems : function(){
		jQuery('body').on('lbwp-products-loaded', function(){
			Watchlist.handleLocalItems();
		});
	},

	/**
	 * Set events to account watchlists
	 */
	handleAccountLists : function(){
		var renameBtns = jQuery('.lbwp-watchlist__edit-list-name .list-action-button');

		jQuery.each(renameBtns, function(){
			var renameBtn = jQuery(this);

			renameBtn.click(function(){
				renameBtn.siblings('form').toggleClass('open');
			});
		});

		var deleteBtns = jQuery('.lbwp-watchlist__delete-list .list-action-button');
		if(deleteBtns.length > 0){
			jQuery.each(deleteBtns, function(){
				var dBtn = jQuery(this);

				dBtn.click(function(){
					dBtn.parent().toggleClass('open');
				});
			});
		}
	},

	/**
	 * Change the active list
	 */
	changeActiveList : function(){
		var listSelect = jQuery('.lbwp-watchlist__select select');
		
		if(listSelect.length > 0){
			listSelect.on('change', function(){
				var lSelect = jQuery(this);
				lSelect.prop('disabled', true);
				var listing = jQuery('.lbwp-watchlist__listing').find('.lbwp-watchlist__list');
				listing.html('<p class="loading-list">Lädt...</p>');

				jQuery.ajax({
					type : 'POST',
					url : watchlistData.ajaxUrl,
					data : {
						action : 'setActiveWatchlist',
						list : lSelect.val(),
					},
					success : function(response){
						lSelect.prop('disabled', false);
						listing.replaceWith(response);


						// Remove active class from every button
						jQuery('.lbwp-add-to-watchlist.in-list').removeClass('in-list');

						// Then add the active class to the active products
						jQuery.each(jQuery('.lbwp-watchlist__listing .lbwp-watchlist__remove'), function(){
							var productId = jQuery(this).attr('data-product');
							Watchlist.updateAddListBtn(productId);
						});

						// Set remove action again
						Watchlist.setListActions();

						// Reset counter
						Watchlist.handleIndicator();
					}
				});
			});
		}
	},

	/**
	 * Add/remove active class to the add-to-list buttons
	 * @param {int} productId the product id
	 * @param {bool} remove if teh class should be removed
	 */
	updateAddListBtn : function(productId, remove){
		var icons = jQuery('.lbwp-add-to-watchlist[data-product-id="' + productId + '"]');

		if(icons.length > 0){
			jQuery.each(icons, function(){
				if(remove === true){
					jQuery(this).removeClass('in-list');
				}else{
					jQuery(this).addClass('in-list');
				}
			});
		}
	},

	/**
	 * Add quantity parameter to add to cart url
	 */
	handleQtyInList : function(){
		var lists = jQuery('.lbwp-watchlist__list');

		jQuery.each(lists, function(){
			var list = jQuery(this);
			var items = list.find('li.has-qty-input');

			jQuery.each(items, function(){
				var item = jQuery(this);
				var qty = item.find('input[name="quantity"]');
				var link = item.find('.lbwp-watchlist__add-to-cart a');
				var href = link.attr('href');
				
				link.attr('href', href + '&quantity=' + qty.val());

				qty.change(function(){
					link.attr('href', href + '&quantity=' + qty.val());
				});
			});
		});
	},

	/**
	 * Set and Update the items indicator
	 */
	handleIndicator : function(){
		var wlMenus = jQuery('.lbwp-watchlist__menu');
		jQuery.each(wlMenus, function(){
			var wlMenu = jQuery(this);
			var itemsCount = wlMenu.find('.lbwp-watchlist__list li:not(.empty-list-text)').length;

			if(itemsCount > 0){
				if(wlMenu.find('.number-indicator').length == 0){
					wlMenu.find('.lbwp-watchlist__icon').append('<span class="number-indicator"></span>');
				}

				var counter = wlMenu.find('.number-indicator');
				counter.text(itemsCount);
			}else{
				wlMenu.find('.number-indicator').remove();
			}
		});
	},

	/**
	 * Close the watchlist menu when clicked somewhere else
	 */
	closeMenu : function(){
		jQuery(window).click(function(e){
			var target = jQuery(e.target);

			// Close the watchlist menu if the click is outside of it or the clicked element isn't the remove icon
			if(target.closest('.lbwp-watchlist__menu').length === 0 && target.closest('.lbwp-watchlist__remove').length === 0){
				var menus = jQuery('.lbwp-watchlist__menu');

				jQuery.each(menus, function(){
					var menu = jQuery(this);
					if(menu.hasClass('open')){
						menu.find('.lbwp-watchlist__icon').trigger('click');
					}
				});
			}
		});
	}
}

 // Load on dom loaded
jQuery(function() {
	Watchlist.initialize();
});