/*
 * Author: Yves Van Broekhoven & Simon Menke
 * Created at: 2012-07-05
 *
 * Requirements:
 * - jQuery
 * - jQuery UI
 * - Chosen
 *
 * Version: 1.0.0
 */
(function($) {

  $.fn.chosenOrder = function () {
    var $this = this.filter('select[multiple]').first(),
      $chosen = $this.siblings('.chosen-container'),
      $choices = $chosen.find('.chosen-choices li[class!="search-field"]'),

      $options = $($choices.map(function () {
        if (!this) {
          return undefined;
        }
        var index = $(this).find("[data-option-array-index]").data("option-array-index");
        var option = $this.find("option")[index];
        return option;
      }));
    return $options;
  };


  /*
   * Extend jQuery
   */
  $.fn.chosenSortable = function(){
    var $this = this.filter('select[multiple]');

    $this.each(function(){
      var $select = $(this);
      var $chosen = $select.siblings('.chosen-container');

      // On mousedown of choice element,
      // we don't want to display the dropdown list
      $chosen.find('.chosen-choices').bind('mousedown', function(event){
        if (!$(event.target).is('ul') ) {
          event.stopPropagation();
        }
      });

      // Initialize jQuery UI Sortable
      $chosen.find('.chosen-choices').sortable({
        'placeholder' : 'ui-state-highlight',
        'items'       : 'li:not(.search-field)',
        //'update'      : _update,
        'tolerance'   : 'pointer'
      });

      // Intercept form submit & order the chosens
      $select.closest('form').on('submit', function(){
        var $options = $select.chosenOrder();
        $select.children().remove();
        $select.append($options);
      });

    });

  };

}(jQuery));