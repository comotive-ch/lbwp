/**
 * Packaging unit features
 * @author Mirko Baffa <mirko@comotive.ch>
 */
var PU  = {
	/**
	 * Initialize the packaging unit
	 */
	initialize : function(){
		PU.setupInputs();
		PU.setCartHook();
	},

	setupInputs : function(){
		var inputs = jQuery('.packaging-unit-input');

		jQuery.each(inputs, function(){
			var input = jQuery(this);
			var pause;

			input.on('input', function(){
				var step = Number(input.attr('step'));
				var value = Number(input.val());
				window.clearTimeout(pause);
				pause = window.setTimeout(function(){
					if(value === 0){
						input.val(step);
					}else if(step > 1 && value%step !== 0){
						value = Math.ceil(value / step) * step;
						input.val(value);
					}
				}, 1000);
			});
		});
	},

	/**
	 * Reset the quantity events after cart update
	 */
	setCartHook : function(){
		jQuery(document.body).on('updated_cart_totals', function(event){
			PU.setupInputs();
		});
	}
}

// Load on dom loaded
jQuery(function() {
	PU.initialize();
});

