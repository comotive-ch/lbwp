class CashPaymentConfirm{
  constructor() {
    this.checkoutForm = jQuery('form.checkout');

    if(this.checkoutForm != null){
      this.insertConfirmHiddenField();
      this.preventSubmitUntilConfirm();
    }
  }

  insertConfirmHiddenField(){
    this.checkoutForm.on('checkout_place_order', ()=>{
      if(jQuery('input[name="cash_payment_confirm"]').length === 0){
        this.checkoutForm.append('<input type="hidden" name="cash_payment_confirm" value="1">');
      }
    });
  }

  preventSubmitUntilConfirm(){
    jQuery('body').on('checkout_error', (e) => {
      let errorNotice = jQuery('.woocommerce-error');
      if(errorNotice.find('li').first().text().trim() === '{CONFIRM_PAYMENT}'){
        e.preventDefault();
        errorNotice.remove();

        jQuery('body').append('<div class="aboon-cash-payment-confirm"><div class="aboon-cash-payment-confirm__inner">' +
          '<header class="aboon-cash-payment-confirm__header"><a href="#">Bezahlvorgang abbrechen</a></header>' +
          '<div class="aboon-cash-payment-confirm__content">' +
          '<h1>Barzahlung</h1>' +
          '<p>Der Kunde zahlt folgenden Betrag in bar:</p>' +
          '<div class="aboon-cash-payment-confirm__amount">' + jQuery('.order-total bdi').text() + '</div>' +
          '</div>' +
          '<footer class="aboon-cash-payment-confirm__footer"><div class="aboon-cash-payment-confirm__button btn btn--primary">Kunde hat bezahlt</div></footer>' +
          '</div></div>');

        jQuery('.aboon-cash-payment-confirm__header a').on('click', (e) => {
          e.preventDefault();
          jQuery('.aboon-cash-payment-confirm').remove();
        });

        jQuery('.aboon-cash-payment-confirm__button').on('click', () => {
          jQuery('.aboon-cash-payment-confirm').remove();
          jQuery('input[name="cash_payment_confirm"]').val(0);
        });
      }
    });
  }
}

document.addEventListener('readystatechange', (event) => {
  if (document.readyState === "complete") {
    new CashPaymentConfirm();
  }
});