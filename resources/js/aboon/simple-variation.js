/**
 * Simple product variation features
 * @author Mirko Baffa <mirko@comotive.ch>
 */
 var SVAR  = {
	/**
	 * Change the price by quantity
	 */
	changePriceByQty : simpleVariationSettings.changePriceByQty === 'true',

	/**
	 * Initialize the product variation
	 */
	initialize : function(){
		SVAR.setDynamicPriceSwitch();

		if(SVAR.changePriceByQty){
			SVAR.dynamicQtyPrice();
		}
	},
	
	/**
	 * Change the price tag on change of the product variation
	 */
	setDynamicPriceSwitch : function(){
	
		// Set price data attribute before anything else
		if(jQuery('body.single').length > 0 ){
			var productPrice = jQuery('.summary .price bdi');

			if(productPrice.attr('data-price') === undefined){
				productPrice.attr('data-price', Number(productPrice.text().replace(productPrice.find('span').text(), '')));
			}
		}

		var variationSelect = jQuery('.aboon-product-variant');
		// Product single
		if(variationSelect.length > 0){
			jQuery.each(variationSelect, function(){
				var varSel = jQuery(this);

				if(varSel.hasClass('is-ajax-context')){
					var addToCart = varSel.closest('.lbwp-wc-product').find('a.btn-add-to-cart');
					var baseHref = addToCart.attr('href');
					var qty = varSel.closest('.product-footer').find('input[name="quantity"]')
					var urlAttr = varSel.attr('name');
					
					if(varSel.val() !== null){
						addToCart.attr('href', baseHref + '&' + urlAttr + '=' + varSel.val());
						
						varSel.on('change', function(){
							addToCart.attr('href', baseHref + '&' + urlAttr + '=' + varSel.val());
						});
					}

					var priceTag = varSel.closest('.lbwp-wc-product').find('.product-price  bdi');
					SVAR.changePriceHtmlFilter(varSel, priceTag, qty);
										
					// Change the price on change
					varSel.on('change', function(){
						SVAR.changePriceHtmlFilter(varSel, priceTag, qty);
					});

					/*if(SVAR.changePriceByQty){
						qty.on('change', function(){
							SVAR.changePriceHtmlFilter(varSel, priceTag, qty);
						});
					}*/
				}else{
					var priceTag = jQuery('.summary .price .woocommerce-Price-amount bdi');
					
					// Change the price
					SVAR.changePriceHtml(varSel, priceTag);
					
					// Change the price on change
					varSel.on('change', function(){
						SVAR.changePriceHtml(varSel, priceTag);
					});
				}
			});
		}
	},

	/**
	 * Set the new price tag value (including the currency)
	 * @param {HTML Object} element the input element
	 * @param {HTML Object} priceTag the price tag html element
	 */
	changePriceHtml : function(element, priceTag){
		var allInputs = element.closest('form').find('input:checked, option:selected, input[type="text"]');
		var ogPrice = Number(element.attr('data-price'));
		var priceDiff = 0;
		var currency = priceTag.find('span');

		jQuery.each(allInputs, function(){
			var input = jQuery(this);

			if(input.attr('type') === 'text'){
				if(input.val() === ''){
					return;
				}
			}

			priceDiff += Number(input.attr('data-difference'));
		});

		if(ogPrice === 0){
			priceTag.attr('data-price', ogPrice);
			return false;
		}

		// Additional calculation needed to prevent strange calculation errors
		var newPrice = String((Math.round((ogPrice + priceDiff) * 100) / 100).toFixed(2));

		// Format price 00.00
		newPrice = newPrice.substr(newPrice.indexOf('.') + 1).length < 2 ? newPrice + '0' : newPrice;

		priceTag.attr('data-price', newPrice)
		priceTag.html(currency.prop('outerHTML') + ' ' + newPrice);

		// Change also bulk price
		var bulkPrices = jQuery('.bulk-pricing-container');
		if(bulkPrices.length > 0){
			jQuery.each(bulkPrices.find('.woocommerce-Price-amount'), function(){
				var bulkPrice = Number(jQuery(this).parent().attr('data-price'));
				var bulkPriceTag = jQuery(this).find('bdi');

				var newBulkPrice = String((Math.round((bulkPrice + priceDiff) * 100) / 100).toFixed(2));
				bulkPriceTag.html(currency.prop('outerHTML') + ' ' + newBulkPrice);
			});
		}
	},

	/**
	 * Set the new price tag value (including the currency)
	 * @param {HTML Object} element the input element
	 * @param {HTML Object} priceTag the price tag html element
	 */
	 changePriceHtmlFilter : function(element, priceTag, qtyElem){
		var ogPrice = Number(element.attr('data-price'));
		var priceDiff = Number(element.find('option:selected').attr('data-difference'));
		var currency = priceTag.find('span');

		if(ogPrice === 0 || isNaN(priceDiff)){
			return false;
		}

		// Additional calculation needed to prevent strange calculation errors
		var newPrice = String((Math.round((ogPrice + priceDiff) * 100) / 100).toFixed(2));

		// Multiply price by quantity if setting is set
		if(SVAR.changePriceByQty){
			var qty = Number(element.closest('.product-footer').find('input[name="quantity"]').val());

			if(!isNaN(qty)){
				newPrice = String(((Number(newPrice) * qty * 100) / 100).toFixed(2));
			}
		}

		// Format price 00.00
		newPrice = newPrice.substr(newPrice.indexOf('.') + 1).length < 2 ? newPrice + '0' : newPrice;

		priceTag.attr('data-price', newPrice)
		priceTag.html(currency.prop('outerHTML') + ' ' + newPrice);
	},


	/**
	 * Change the price dynamically by the quantity
	 */
	dynamicQtyPrice : function(){
		var qtyInput = jQuery('.summary .qty');
		 
		if(jQuery('.single').length > 0 && qtyInput.length > 0){
			qtyInput.on('change', function(){
				SVAR.multiplyPriceByQty(qtyInput);
			});

			var inputs = jQuery('.summary').find('input:not(.qty), select');

			jQuery.each(inputs, function(){
				var input = jQuery(this);
				
				input.on('change', function(){
					qtyInput.trigger('change');
				});
			});
		}

		/* 
		Update the price also in the filter view. Doesen't work, needs to be fired when filter content has been displayed.
		
		var filterQty = jQuery('.product-footer input[name="quantity"]');
		if(jQuery('.archive .wp-block-product-filter').length > 0 && filterQty.length > 0){
			filterQty.on('change', function(){
				var parent = filterQty.closest('.lbwp-wc-product__inner');
				var priceTag = parent.finde('.amount bdi');
				var basePrice = Number(priceTag.attr('data-price'));
				var quantity = Number(filterQty.val());
				
				var newPrice = String(((basePrice * quantity * 100) / 100).toFixed(2));
				priceTag.html(priceTag.find('span').prop('outerHTML') + ' ' + newPrice);
			});
		}
		*/
	},

	/**
	 * Multiply the price by the quantity
	 * @param {DOM Element} qtyInput the quantity input
	 */
	multiplyPriceByQty : function(qtyInput){
		var bulkPrices = jQuery('.bulk-pricing-container');
		var priceTag = jQuery('.summary .price bdi');
		var basePrice = Number(priceTag.attr('data-price'));
		var quantity = Number(qtyInput.val());

		// Set bulk prices if they are available
		if(bulkPrices.length > 0){
			var bulkData = bulkPrices.find('.woocommerce-Price-amount').parent();

			jQuery.each(bulkData, function(){
				var data = jQuery(this);
				if(quantity >= Number(data.attr('data-bulk-number'))){
					basePrice = Number(data.text().slice(3));
				}
			});
		}
		
		var newPrice = String(((basePrice * quantity * 100) / 100).toFixed(2));
		priceTag.html(priceTag.find('span').prop('outerHTML') + ' ' + newPrice);
	}
 }

 // Load on dom loaded
jQuery(function() {
	SVAR.initialize();
});