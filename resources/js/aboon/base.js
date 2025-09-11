/**
 * Base aboon functions
 * @author Mirko Baffa <mirko@comotive.ch>
 */
var AboonJS  = {
	// Load on dom loaded
	initialize : function(){
		AboonJS.setUpsellsQty();
		AboonJS.handleTermsLink();
	},

	/**
	 * Cart indicator class
	 */
	indicatorClass : '.number-indicator',

	/**
	 * Open the terms link in a new tab
	 */
	handleTermsLink : function(){
		if(jQuery('.woocommerce-checkout').length > 0){
			window.setInterval(function(){
				var termsLink = jQuery('.woocommerce-terms-and-conditions-link');

				if(termsLink.length > 0){
					termsLink.off('click');
					termsLink.attr('class', '');
				}
			}, 1000);
		}
	},

	/**
	 * Updates the header cart icon on changes in the cart
	 */
	setupCartIconHandle : function(){
		// Update the quantity on update
		jQuery(document.body).on('updated_cart_totals', function(event){
			var cartInputs = jQuery('.woocommerce-cart-form__contents .product-quantity input');
			var totalQty = 0;
			console.log(cartInputs);

			jQuery.each(cartInputs, function(){
				var qty = Number(jQuery(this).val());
				totalQty += qty;
			});

			AboonJS.updateCartIcon(totalQty);
		});

		// If the last element in the cart is removed
		jQuery(document.body).on('updated_wc_div', function(event){
			var cartItems = jQuery('.cart_item');

			if(cartItems.length <= 0){
				jQuery('header .shop-cart ' + AboonJS.indicatorClass).remove();
			}
		});

		// Add event to filter items onload
		/*
		jQuery(document.body).on('lbwp-products-loaded', function(){
			// Add event on every add-to-cart button
			var atcBtns = jQuery('.lbwp-wc-product .btn-add-to-cart');
			jQuery.each(atcBtns, function(){
				var btn = jQuery(this);

				btn.click(function(){
					var qtyInput = btn.closest('.lbwp-wc-product').find('input[name="quantity"]');

					AboonJS.updateCartIcon(Number(qtyInput.val()), true);
				});
			});
		});
		 */
	},

	/**
	 * Update the cart icon html
	 * @param {int} quantity the quantity to set/add
	 * @param {bool} add if true, the quantity will bi added to the current number else it overrides the number
	 */
	updateCartIcon : function(quantity, add){
		var headerIcons = jQuery('header .shop-cart');
		var qty = quantity;
		console.log(quantity, add);

			// Banholzer: There are two cart icons because of the header effect
		jQuery.each(headerIcons, function(i){
			var headerIcon = jQuery(this).find(AboonJS.indicatorClass);

			if(add === true && headerIcon.length > 0){
				qty = quantity + Number(headerIcon.text());
			}

			if(qty > 0){
				if(headerIcon.length > 0){
					headerIcon.text(qty);
				}else{
					headerIcons.eq(i).append('<span class="' + AboonJS.indicatorClass.substring(1) + '">' + qty + '</span>');
				}
			}else{
				if(headerIcon.length > 0){
					headerIcon.remove();
				}
			}
		});
	},

	/**
	 * Remove the add to cart messages (for non ajax buttons)
	 */
	removeAddToCartMessages : function(item){
		setTimeout(function() {
			var msgClass = 'body > .wc-block-components-notice-banner';

			if(jQuery('body').hasClass('single-product')){
				msgClass += ', main .wc-block-components-notice-banner';
			}

			jQuery(msgClass).fadeOut(function() {
				jQuery(this).remove();
			});
		}, 4750)
	},

	/**
	 * Set the quantity to the upsells add-to-cart-buttons
	 */
	setUpsellsQty : function(){
		var upsells = jQuery('.upsells');

		if(upsells.length > 0){
			var inputs = upsells.find('input[name="quantity"]');

			jQuery.each(inputs, function(){
				var input = jQuery(this);
				var addToCart = input.closest('.product-footer').find('.btn-add-to-cart');
				var baseHref = addToCart.attr('href');

				input.on('input', function(){
					addToCart.attr('href', baseHref + '&quantity=' + input.val());
				});
			});
		}
	}
}

jQuery(function() {
	AboonJS.initialize();
});