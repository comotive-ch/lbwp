/**
 * The backend admin JS which serves various purposes with aboon features
 * @author Michael Sebel <michael@comotive.ch>
 */
var AboonBackend = {
  /**
   * Called on page load, registering events and such
   */
  initialize: function()
  {
    if (jQuery('body.post-type-product')) {
      AboonBackend.handleContractBox();
      AboonBackend.handleBulkPricingBox();
    }
    //  ID of vertrags
    // product-type id of types
  },

  /**
   * Handles visibility of payment contract box
   */
  handleContractBox : function()
  {
    jQuery('#product-type').on('change', function() {
      var prodType = jQuery(this).val();
      if (prodType.indexOf('subscription') === -1) {
        jQuery('#acf-group_602a680b96a9b').hide();
      }
    })
      // And trigger the event initially
      .trigger('change');
  },

  /**
   * Handles visibility of bulk pricing box
   */
  handleBulkPricingBox : function()
  {
    jQuery('#product-type').on('change', function() {
      var prodType = jQuery(this).val();
      if (prodType.indexOf('subscription') >= 0) {
        jQuery('#acf-group_60427f1337381').hide();
      }
    })
      // And trigger the event initially
      .trigger('change');
  }
};

// Run the library on load
jQuery(function () {
    AboonBackend.initialize();
});