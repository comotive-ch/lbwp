<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\Base\Shop;
use LBWP\Theme\Component\ACFBase;

/**
 * Simple Preorder functionality
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch
 */
class Preorder extends ACFBase
{
  const META_KEY = 'aboon-preorder';

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    // Add text / notices
    add_filter('woocommerce_cart_item_name', array($this, 'addNoticeToCart'), 10, 3);
    add_filter('woocommerce_product_backorders_allowed', array($this, 'backordersAllowed'), 999, 2);
    add_filter('woocommerce_single_product_image_thumbnail_html', array($this, 'addPreorderBubble'), 10, 2);
    add_action('woocommerce_get_price_html', array($this, 'addPreorderNotice'), 10, 2);

    // Override stock status(es)
    add_filter('woocommerce_product_is_in_stock', array($this, 'setBackorderStatus'), 999, 2);
    add_filter('woocommerce_product_get_stock_status', array($this, 'setBackorderStatus'), 999, 2);
    add_filter('woocommerce_product_variation_get_stock_status', array($this, 'setBackorderStatus'), 999, 2);
    add_filter('woocommerce_product_get_backorders', array($this, 'setBackorderToYes'), 999, 2);
    add_filter('woocommerce_product_variation_get_backorders', array($this, 'setBackorderToYes'), 999, 2);
  }

  /**
   * Adds field settings
   */
  public function fields(){
    acf_add_local_field_group( array(
      'key' => 'group_6666a9edee4a1',
      'title' => 'Vorbestellung',
      'fields' => array(
        array(
          'key' => 'field_6666a9eea1f66',
          'label' => '',
          'name' => 'is-preorderable',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Vorbestellbares Produkt',
          ),
          'default_value' => array(
          ),
          'return_format' => 'value',
          'allow_custom' => 0,
          'layout' => 'vertical',
          'toggle' => 0,
          'save_custom' => 0,
          'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
        ),
        array(
          'key' => 'field_6666aa45a1f67',
          'label' => 'Voraussichtliches Lieferdatum',
          'name' => 'delivery-date',
          'aria-label' => '',
          'type' => 'date_picker',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6666a9eea1f66',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'display_format' => 'd.m.Y',
          'return_format' => 'U',
          'first_day' => 1,
        ),
        array(
          'key' => 'field_6666aa74a1f68',
          'label' => 'Weitere Hinweise (wird nach Lieferdatum angezeigt)',
          'name' => 'notice',
          'aria-label' => '',
          'type' => 'textarea',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6666a9eea1f66',
                'operator' => '!=empty',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'rows' => 3,
          'placeholder' => '',
          'new_lines' => '',
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
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ) );
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}

  /**
   * Override backend settings
   * @param $allowed bool
   * @param $productId
   * @return bool
   */
  public function backordersAllowed($allowed, $productId){
    return self::isAvailable($productId) || $allowed;
  }

  /**
   * @param $status
   * @return string
   */
  public function setBackorderStatus($status, $product){
    if(self::isAvailable($product->get_id())){
      return 'onbackorder';
    }

    return $status;
  }

  /**
   * IMPORTANT: WooCommerce wants to have the string «yes» here, not a boolean. Otherwise it will not work.
   * @param $status
   * @return string
   */
  public function setBackorderToYes($status, $product){
    if(self::isAvailable($product->get_id())){
      return 'yes';
    }

    return $status;
  }

  /**
   * @param $productId
   * @return bool
   */
  public static function isAvailable($productId){
    $preorder = get_field('is-preorderable', $productId);
    $date = intval(get_field('delivery-date', $productId)) + 60 * 60 * 24;

    if($date < time()){
      return false;
    }

    if(is_array($preorder) && in_array(1, $preorder)){
      return true;
    }

    return false;
  }

  /**
   * @param $html
   * @param $product
   * @return mixed|string
   */
  public function addPreorderNotice($html, $product){
    $productId = $product->get_id();
    if(self::isAvailable($productId) && is_product()){
      $html .= $this->getPreorderNoticeHtml($productId);
    }

    return $html;
  }

  /**
   * @param $html
   * @param $attachment_id
   * @return mixed|string
   */
  public function addPreorderBubble($html, $attachment_id){
    $productId = get_post_meta($attachment_id, '_product_id', true);

    if(self::isAvailable($productId)){
      $html .= '<div class="preorder-bubble">' . __('Vorbestellung', 'lbwp') . '</div>';
    }

    return $html;
  }

  /**
   * @param $name
   * @param $cartItem
   * @param $cartItemKey
   * @return mixed|string
   */
  public function addNoticeToCart($name, $cartItem, $cartItemKey){
    $productId = $cartItem['product_id'];

    if(self::isAvailable($productId)){
      $name .= $this->getPreorderNoticeHtml($productId);
    }

    return $name;
  }

  /**
   * @param $productId
   * @return string
   */
  private function getPreorderNoticeHtml($productId){
    $deliveryDate = get_field('delivery-date', $productId);
    $notice = get_field('notice', $productId);

    $html = '<div class="preorder-notice">';
    $html .= '<p class="preorder-notice__date">' . __('Voraussichtliches Lieferdatum:', 'lbwp') . ' ' . date_i18n('d.m.Y', $deliveryDate);
    if($notice){
      $html .= '<br><span class="preorder-notice__text">' . $notice . '</span>';
    }
    $html .= '</p></div>';

    return $html;
  }
}