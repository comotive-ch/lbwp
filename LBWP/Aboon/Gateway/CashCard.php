<?php

namespace LBWP\Aboon\Gateway;

/**
 * Provides a "cash on the hand" Payment Gateway.
 * @class       Cash
 * @extends     CashBase
 * @package     WooCommerce\Classes\Payment
 */
class CashCard extends CashBase
{

  /**
   * Constructor for the gateway.
   */
  public function __construct()
  {
    $this->id = 'cash_card';
    $this->method_title = __('Barzahlung (Karte)', 'woocommerce');
    $this->method_description = __('Erlaubt es, Zahlungen mit externem Terminal an der Kasse entgegen zu nehmen', 'woocommerce');

    parent::__construct();
  }

  /**
   * Initialise Gateway Settings Form Fields.
   */
  public function init_form_fields()
  {
    parent::init_form_fields();

    $this->form_fields['enabled']['label'] = __('Kartenzahlungen mit externem Terminal erlauben', 'woocommerce');
    $this->form_fields['title']['default'] = __('Barzahlung (Karte)', 'lbwp');
    $this->form_fields['description']['default'] = __('Du zahlst mit der Karte an der Kasse.', 'lbwp');
  }
}
