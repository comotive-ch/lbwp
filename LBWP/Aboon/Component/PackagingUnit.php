<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;
use LBWP\Util\WordPress;
use LBWP\Core;

/**
 * Filters possibilites to sell prducts in specified packaging units
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class PackagingUnit extends ACFBase
{
  /**
   * Initialize filters
   */
  public function init()
  {
    // Testing function and cache var_dump(self::getPackagingUnit(44088));
    add_action('acf/save_post', array($this, 'reloadCachesOnProductSave'));

    // Set product min quantity and step (also handling ajax add to cart)
    add_filter('woocommerce_quantity_input_args', array($this, 'adjustQuantityInputs'), 10, 2);
    add_filter('woocommerce_add_to_cart_quantity', array($this, 'adjustQuantityAjax'), 10, 2);

    // Last check the item quantity
    add_filter('woocommerce_before_calculate_totals', array($this, 'checkoutQuantityCheck'));

    // Add Unit text to the standard template
    add_filter('woocommerce_after_add_to_cart_form', array($this, 'addPackagingUnitText'), 10, 0);
  }

  /**
   * @param int $productId the product id
   * @return int defaults to 1 if none is given, thus not changing functionality 1 or larger
   */
  public static function getPackagingUnit($productId)
  {
    $vpeList = self::getCachedPackagingUnitList();
    return isset($vpeList[$productId]) ? $vpeList[$productId] : 1;
  }

  /**
   * @return array pairs of productId => vpe, for products with vpe > 1
   */
  public static function getCachedPackagingUnitList($force = false)
  {
    $list = wp_cache_get('vpeList', 'PackagingUnit');
    if (is_array($list) && !$force) {
      return $list;
    }

    // Build the list
    $list = array();
    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT post_id, meta_value FROM ' . $db->postmeta . '
      WHERE meta_key = "_vpe" AND meta_value > 1
    ');

    foreach ($raw as $item) {
      $list[intval($item->post_id)] = intval($item->meta_value);
    }

    wp_cache_set('vpeList', $list, 'PackagingUnit', 86400);
    return $list;
  }

  /**
   * Rebuilds cache for packaging unit list on product save
   */
  public function reloadCachesOnProductSave()
  {
    self::getCachedPackagingUnitList(true);
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_5fc16262aa8b7',
      'title' => 'Verpackungseinheit',
      'fields' => array(
        array(
          'key' => 'field_5f197a9b28071',
          'label' => 'Wird nur in folgender VPE verkauft',
          'name' => '_vpe',
          'type' => 'number',
          'instructions' => '',
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
          'maxlength' => '',
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
      'description' => 'für Produkte',
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks()
  {
  }

  /**
   * Enqueue the JS script
   */
  public function assets()
  {
    parent::assets();

    $base = File::getResourceUri();
    wp_enqueue_script('packaging-unit-js', $base . '/js/aboon/packaging-unit.js', array('jquery'), Core::REVISION, true);
  }

  /**
   * Customize the quantity input args
   * @param array $arfs with all the input arguments
   * @param object $product the current product
   * @return array the
   */
  public function adjustQuantityInputs($args, $product)
  {
    $getArgs = self::getInputArgs($product);
    $getArgs['classes'] = array_merge($getArgs['classes'], $args['classes']);
    $args = array_merge($args, $getArgs);

    if (!is_cart()) {
      $args['input_value'] = $getArgs['min_value'];
    }

    return $args;
  }


  /**
   * Adjust the quantity added to the cart (via ajax)
   *
   * @param int $qty the quantity
   * @param int $product id
   * @return int quantity
   */
  public function adjustQuantityAjax($qty, $productId)
  {
    $pUnit = self::getPackagingUnit($productId);
    if ($qty < $pUnit) {
      return $pUnit;
    }

    return $qty;
  }

  /**
   * Get the arguments for the input tag
   *
   * @param object $product the woocommerce product
   * @return array the arguments
   */
  public static function getInputArgs($product)
  {
    $pUnit = self::getPackagingUnit($product->get_id());
    $args = array(
      'min_value' => $pUnit,
      'step' => $pUnit,
      'classes' => $pUnit > 1 ? array('packaging-unit-input') : array()
    );

    return $args;
  }

  /**
   * Last check the product quantity
   *
   * @param string $text the quantity text
   * @param array $order the order row
   * @return string the quantity text
   */
  public function checkoutQuantityCheck($cart)
  {
    foreach ($cart->get_cart() as $hash => $item) {
      $pUnit = self::getPackagingUnit($item['data']->get_id());

      if ($item['quantity'] % $pUnit !== 0) {
        $qty = ceil($item['quantity'] / $pUnit) * $pUnit;
        $cart->set_quantity($hash, $qty);
        SystemLog::add('PackagingUnit', 'debug', 'changed quantity', array(
          'qty_before' => $item['quantity'],
          'qty_after' => $qty,
          'qty_after_exact' => ($item['quantity'] / $pUnit) * $pUnit,
          'unit' => $pUnit,
          'item_id' => $item['data']->get_id(),
          'hash' => $hash,
        ));
      }
    }

    return $cart;
  }

  /**
   * Get the packaging unit text/html
   *
   * @param object $prod the product
   * @return string|void return the html string or empty if unit is smaller then 2
   */
  public function getPackagingUnitText($prod = null)
  {
    global $product;
    $prod = $prod === null ? $product : $prod;
    $pUnit = self::getPackagingUnit($prod->get_id());

    if ($pUnit > 1) {
      return '
				<div class="product-vpe-text">
					<p>' . sprintf(__('VPE: %s Stück', 'lbwp'), $pUnit) . '</p>
				</div>
			';
    }
  }

  /**
   * Add unit text to the templates
   */
  public function addPackagingUnitText()
  {
    if (is_product()) {
      echo $this->getPackagingUnitText();
    }
  }

  /**
   * Currently disabled as not used anymore
   * readd with: add_filter('woocommerce_cart_item_name', array($this, 'addPackagingUnitTextCart'), 10, 3);
   * Add the text underneath the product name in the cart
   */
  public function addPackagingUnitTextCart($name, $item, $key)
  {
    if (is_cart()) {
      $getUnitText = $this->getPackagingUnitText($item['data']);

      if ($getUnitText !== null) {
        $name .= $getUnitText;
      }
    }

    return $name;
  }
}