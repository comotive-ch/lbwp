<?php

namespace LBWP\Aboon\Gateway;

use LBWP\Core;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\File;

/**
 * Base component fot "cash on the hand" Payment Gateway.
 * @class       Cash
 * @extends     \WC_Payment_Gateway
 * @package     WooCommerce\Classes\Payment
 */
abstract class CashBase extends \WC_Payment_Gateway
{
  /**
   * @var bool declares if the payment method is only for logged in users
   */
  protected $loggedin = false;
  /**
   * @var bool declares if orders should be completed completely
   */
  protected $complete = false;

  public function __construct()
  {
    $this->icon = apply_filters('woocommerce_cash_icon', '');
    $this->has_fields = false;
    $this->supports = array('subscriptions', 'products');

    // Load the settings.
    $this->init_form_fields();
    $this->init_settings();

    // Define user set variables.
    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->loggedin = $this->get_option('loggedin') === 'yes';
    $this->complete = $this->get_option('complete') === 'yes';

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    // Handle when the payment method is available for users
    add_filter('woocommerce_available_payment_gateways', array($this, 'maybeDisableMethod'));
    // Handle that orders are always completed, when set so
    if ($this->complete) {
      add_filter('woocommerce_payment_complete_order_status', array($this, 'completeOrders'), 10, 3);
    }

    if(defined('LOCAL_DEVELOPMENT')){
      add_action('woocommerce_after_checkout_validation', array($this, 'confirmCashPaymentNotice'), 999, 2);
      add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
    }
  }

  /**
   * @param $gateways
   * @return mixed
   */
  public function maybeDisableMethod($gateways)
  {
    if (isset($gateways[$this->id]) && $this->loggedin) {
      if (!current_user_can('manage_woocommerce') && !current_user_can('administator')) {
        unset($gateways[$this->id]);
      }
    }

    return $gateways;
  }

  /**
   * @param string $status
   * @param int $id
   * @param \WC_Order $order
   */
  public function completeOrders($status, $id, $order)
  {
    if ($order->get_payment_method() == $this->id) {
      $status = 'completed';
    }
    return $status;
  }

  /**
   * Initialise Gateway Settings Form Fields.
   */
  public function init_form_fields()
  {
    $this->form_fields = array(
      'enabled' => array(
        'title' => __('Enable/Disable', 'woocommerce'),
        'type' => 'checkbox',
        'label' => __('Physische Barzahlungen erlauben', 'woocommerce'),
        'default' => 'no',
      ),
      'loggedin' => array(
        'title' => __('Verfügbarkeit', 'lbwp'),
        'type' => 'checkbox',
        'label' => __('Barzahlung nur im eingeloggten Zustand als Administator oder Shop Manager anbieten', 'woocommerce'),
        'default' => 'no',
      ),
      'complete' => array(
        'title' => __('Bestellverhalten', 'lbwp'),
        'type' => 'checkbox',
        'label' => __('Bestellungen über diese Zahlungsmethode werden immer als komplett erledigt erfasst', 'woocommerce'),
        'default' => 'no',
      ),
      'title' => array(
        'title' => __('Title', 'woocommerce'),
        'type' => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default' => __('Barzahlung (Bargeld)', 'lbwp'),
        'desc_tip' => true,
      ),
      'description' => array(
        'title' => __('Description', 'woocommerce'),
        'type' => 'textarea',
        'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
        'default' => __('Du zahlst mit Bargeld an der Kasse.', 'lbwp'),
        'desc_tip' => true,
      )
    );
  }


  public function enqueueScripts(){
    $base = $base = File::getResourceUri();
    wp_enqueue_script('aboon-cash-payment-js', $base . '/js/aboon/CashPaymentConfirm.js', array('jquery'), Core::REVISION);
  }

  /**
   * Process the payment and return the result.
   * @param int $orderId Order ID.
   * @return array
   */
  public function process_payment($orderId)
  {
    $order = wc_get_order($orderId);
    if ($order->get_total() > 0) {
      // Always complete payment (and eventually complete completely, when set to do so)
      // Uses registered woocommerce_payment_complete_order_status filter to set completed status
      $order->payment_complete();
    }
    // Remove cart.
    WC()->cart->empty_cart();
    // Return thankyou redirect.
    return array(
      'result' => 'success',
      'redirect' => $this->get_return_url($order),
    );
  }

  public function confirmCashPaymentNotice($posted, $errors){
    if($_POST['payment_method'] !== $this->id){
      return;
    }

    if(isset($_POST['cash_payment_confirm']) && intval($_POST['cash_payment_confirm']) > 0 && isset($errors->errors) && empty($errors->errors)){
      wc_add_notice('{CONFIRM_PAYMENT}','error');
    }
  }
}