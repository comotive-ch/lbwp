function setActions(){
  var btns = jQuery('.attaching-actions a');
  var modal = jQuery('#modal-assignable-orders');

  jQuery.each(btns, function(){
    var btn = jQuery(this);
    btn.click(function(){
      var data = btn.attr('href').replace('#', '').split('_');
      var action = data[0];
      var id = data[1];
      var data = {};

      switch(action){
        case 'link':
          modal.show();
          data.action = 'getOrders';
          data.amount = btn.data('amount');

          jQuery.post(
            ajaxData.url,
            data,
            function (response) {
              modal.find('.modal-orders').append(response);
              var assignBtns = jQuery('.asign-button');
              jQuery.each(assignBtns, function(){
                var aBtn = jQuery(this);
                aBtn.click(function(){
                  var oId = aBtn.data('assign');
                  modal.hide();
                  modal.find('.modal-orders').empty();

                  jQuery.post(
                    ajaxData.url,
                    {
                      action: 'assignPayment',
                      oId: oId,
                      pId: id
                    },
                    function (response) {
                      btn.parent().empty().html(response);
                    }
                  );
                });
              });
            }
          )


          break;

        case 'delete':
          data.action = 'deletePaymentRow';
          data.pid = id;

          var confDel = confirm('Eintrag wirklich löschen?');
          if(confDel){
            jQuery.post(
              ajaxData.url,
              data,
              function (response) {
                if(response){
                  jQuery('tr[data-id="' + id + '"]').remove();
                }
              }
            );
          }

          break;
      }
    });
  });

  var closeModal = jQuery('#modal-assignable-orders .close-button').click(function(){
    modal.hide();
    modal.find('.modal-orders').empty();
  });
}

jQuery(function() {
  setActions();
});