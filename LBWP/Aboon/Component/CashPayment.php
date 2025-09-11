<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Base\Component;
use LBWP\Aboon\Gateway\Cash;

/**
 * Component to provide the cash payment method and eventual corresponding UI improvements
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class CashPayment extends Component
{

  /**
   * Few things need to be registered pretty early
   */
  public function setup()
  {
    add_filter('woocommerce_payment_gateways', array($this, 'addPaymentMethod'));
    parent::setup();
  }

  /**
   * Initialize the component
   */
  public function init() {}

  /**
   * @param array $gateways payment gateways
   */
  public function addPaymentMethod($gateways)
  {
    $gateways[] = '\LBWP\Aboon\Gateway\Cash';
    $gateways[] = '\LBWP\Aboon\Gateway\CashCard';
    return $gateways;
  }
}