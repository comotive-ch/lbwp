<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Component\ACFBase;

/**
 * Provides the depot functionality for products
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch
 */
class DepositFee extends ACFBase{
  /**
   * @var Object the Depot class object
   */
  protected static $instance;

  /**
   * Name of the depot fee
   */
  public $naming = array(
    's' => 'Depot',
    'p' => 'Depots'
  );

  /**
   * Initialize the watchlist component, which is nice
   */
  public function init()
  {
    self::$instance = $this;

    $this->setNaming();

    add_filter('woocommerce_get_price_html', array($this, 'addDepositPriceText'), 999, 2);
    add_filter('woocommerce_cart_item_price', array($this, 'addCartPriceText'), 10, 2);
    add_action('woocommerce_cart_calculate_fees', array($this, 'calcCartFees'));
  }

  /**
   * Adds field settings
   */
  public function fields(){
    acf_add_local_field_group(array(
      'key' => 'group_6272626018061',
      'title' => 'Depot',
      'fields' => array(
        array(
          'key' => 'field_62726271e1865',
          'label' => 'Betrag',
          'name' => 'deposit-fee',
          'type' => 'number',
          'instructions' => 'Leer lassen für kein Depot',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'min' => '',
          'max' => '',
          'step' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'product',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'side',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}

  /**
   * Get the instance of the watchlist
   *
   * @return Object
   */
  public static function getInstance(){
    return self::$instance;
  }

  /**
   * Set custom names for "Depot"
   */
  private function setNaming(){
    // Firstly check if the watchlist has a custom name
    $settings = get_field('depot-settings', 'option');
    if(!empty($settings['rename'])){
      $this->naming['s'] = $settings['name-singular'];
      $this->naming['p'] = $settings['name-plural'];
    }

    // Make the names translatable
    $this->naming['s'] = __($this->naming['s'], 'lbwp');
    $this->naming['p'] = __($this->naming['p'], 'lbwp');
  }

  /**
   * Get the deposit fee of a product
   * @param $product int|object either the product-id or the object
   * @return false|float the deposit fee or false if the product doesn't have one
   */
  public static function getDepositFee($product){
    if(is_object($product)){
      $product = $product->get_id();
    }

    $dFee = floatval(get_field('deposit-fee', $product));

    return $dFee > 0 ? $dFee : false;
  }

  /**
   * Get the product deposit fee text (html)
   * @param $fee float the deposit fee
   * @return string the html
   */
  public static function getDepositText($fee){
    $text = '<div class="deposit-fee">' .
      sprintf(
        __('(zzgl. %s %s)', 'lbwp'),
        number_format($fee, 2),
        self::$instance->naming['s']
      ) . '</div>';
    return apply_filters('aboon_deposit_fee_text', $text);
  }

  /**
   * Add the deposit fee text to the price (single page)
   * @param $price
   * @param $product
   * @return void
   */
  public function addDepositPriceText($price, $product){
    $fee = self::getDepositFee($product);
    if(is_product() && $fee !== false){
      $price .= self::getDepositText($fee);
    }

    return $price;
  }

  /**
   * Calculate the cart total deposit fees
   * @return void
   */
  public function calcCartFees(){
    $cart = WC()->cart;

    $fees = array();

    foreach($cart->get_cart() as $item){
      $fee = self::getDepositFee($item['product_id']);

      if($fee !== false){
        $fees[] = $fee * $item['quantity'];
      }
    }

    if(!empty($fees)){
      $cart->add_fee($this->naming['s'], array_sum($fees));
    }
  }

  /**
   * Add text to the cart price
   * @param $price string the (formated) cart price
   * @return string the price html
   */
  public function addCartPriceText($price, $cartItem){
    $fee = self::getDepositFee($cartItem['product_id']);
    if(is_cart() && $fee !== false){
      $price .= self::getDepositText($fee);
    }

    return $price;
  }
}