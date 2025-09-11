Pomo = {
  /**
   * Initialize the Pomo object
   */
  initialize : function (){
    this.setupStringSearch();
    this.bindDeleteButtons();
  },

  /**
   * Setup the pomo string search
   */
  setupStringSearch : function (){
    var searchForm = jQuery('#pomo-rewriter-form');
    var searchField = searchForm.find('#pomo-search');
    var typing;

    // Prevent submit on enter press
    searchForm.keydown(function(e){
      if(e.keyCode === 13){
        e.preventDefault();
      }
    });

    // Do the search
    searchField.keyup(function(){
      jQuery('.search-results').empty();
      clearTimeout(typing);

      typing = setTimeout(function(){
        var val = searchField.val();
        if(val !== '') {
          Pomo.doSearch(val);
        }
      }, 2000);
    });
  },

  /**
   * Run the AJAX search
   * @param search string the search input value
   */
  doSearch : function(search){
    var resContainer = jQuery('.search-results');
    var ajaxData = {
      action : 'searchTranslations',
      searched : search
    }

    jQuery.ajax({
      url: '/wp-admin/admin-ajax.php',
      data: ajaxData,
      type: 'POST',
      success: function (resp) {
        var resHtml = resp.length === 0 ? '<p>Es wurden keine Resultate für ' + search + ' gefunden.' : resp.join('');

        resContainer.html(resHtml);
        Pomo.bindOverrideAddButtons();
      }
    });
  },

  /**
   * Bind the click event to the "add override" buttons
   */
  bindOverrideAddButtons : function(){
    var btns = jQuery('.add-override');
    jQuery.each(btns, function(){
      var btn = jQuery(this);
      btn.click(function(){
        var data = JSON.parse(btn.attr('data-override'));
        // html entieties for js
        var inputName = String('pomo-override[' + data[0] + ']')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
				var inputVal = inputName.replace('pomo-override[', '').slice(0, -1);
        jQuery('.search-results').empty();
        jQuery('#pomo-rewriter-form table tbody').prepend('<tr>' +
          '<td>' + data[1] + '</td>' +
          '<td>' + data[2] + '</td>' +
          '<td>' + data[0] + '</td>' +
          '<td><input type="text" name="' + inputName + '[string]" value=""></td>' +
          '<input type="hidden" name="' + inputName + '[key]" value="' + inputVal + '">' +
          '<td><div class="delete-button"></div></td>' +
        + '</tr>');

        Pomo.bindDeleteButtons();
      });
    });
  },

  /**
   * Bind the delete buttons
   */
  bindDeleteButtons : function(){
    var dBtns = jQuery('.delete-button');
    jQuery.each(dBtns, function(){
      var btn = jQuery(this);
      btn.unbind('click');
      btn.click(function(){
        btn.closest('tr').remove();
      });
    });
  }
}

/**
 * Initialize on loaded
 */
jQuery(function() {
  Pomo.initialize();
});