<?php

namespace LBWP\Aboon\Gateway;

/**
 * Provides a "cash on the hand" Payment Gateway.
 * @class       Cash
 * @extends     CashBase
 * @package     WooCommerce\Classes\Payment
 */
class Cash extends CashBase
{
  /**
   * Constructor for the gateway.
   */
  public function __construct()
  {
    $this->id = 'cash';
    $this->method_title = __('Barzahlung (Physisch)', 'woocommerce');
    $this->method_description = __('Erlaubt es, Barzahlungen an der Kasse entgegen zu nehmen', 'woocommerce');

    parent::__construct();
  }
}
