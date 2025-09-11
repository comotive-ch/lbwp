<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Base\Component;
use LBWP\Util\ArrayManipulation;

/**
 * Simple possibility to have bulk prices
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class BulkPricing extends Component
{
  /**
   * @var string the field used for price data access, can be filtered for other currencies/prices from external devs
   */
  protected $priceField = '';

  /**
   * Add config fields
   */
  public function setup()
  {
    parent::setup();
    // Add actions that need to be added early on
    add_action('acf/init', array($this, 'addConfigFields'));
  }

  /**
   * Add aboon general backend library
   */
  public function adminAssets()
  {
    wp_enqueue_script('lbwp-aboon-backend');
  }

  /**
   * Initialize the component
   */
  public function init()
  {
    $this->priceField = apply_filters('aboon_bulk_price_field_id', 'price');
    // It can be that a customer cannot have bulk prices at all
    if (apply_filters('aboon_bulk_pricing_disallowed', false)) {
      return;
    }

    // Handle display and price of bulk priced products
    if (apply_filters('aboon_bulk_pricing_show_in_tab', false)) {
      add_filter('woocommerce_product_tabs', array($this, 'maybeDisplayBulkPricesInTab'), 100);
    } else {
      add_action('woocommerce_single_product_summary', array($this, 'maybeDisplayBulkPricesAtTop'), 25);
    }

    // Filters to actually change the price by item qty
    if (apply_filters('aboon_bulk_price_show_instead_info', true)) {
      add_filter('woocommerce_get_item_data', array($this, 'showActiveBulkPrice'), 10, 2);
    }
    add_action('woocommerce_before_calculate_totals', array($this, 'overrideWithBulkPrice'), 100, 1);
    add_action('save_post_product', array($this, 'flushCachesOnProductSave'));
    // Push info into our own product blocks
    add_filter('lbwp-wc_product-label', array($this, 'blockShowInfo'), 10, 2);
    add_filter('lbwp-wc_product-price', array($this, 'blockShowPrice'), 10, 2);
  }

  /**
   * Flushes relevant caches on saving a product in backend
   */
  public function flushCachesOnProductSave()
  {
    wp_cache_delete('getBulkPricingData', 'Aboon');
  }

  /**
   * @param $tabs
   * @return mixed
   */
  public function maybeDisplayBulkPricesInTab($tabs)
  {
    $html = $this->maybeDisplayBulkPrices();
    if (strlen($html) > 0) {
      $tabs[] = array(
        'title' => __('Staffelpreise', 'beroea-shop'),
        'priority' => 10,
        'callback' => function () use ($html) {
          echo $html;
        }
      );
    }

    return $tabs;
  }

  /**
   * Display bulk prices at top, if given
   */
  public function maybeDisplayBulkPricesAtTop()
  {
    $html = $this->maybeDisplayBulkPrices();
    if (strlen($html) > 0) {
      echo $html;
    }
  }

  /**
   * Cached list with less info on every available contract
   */
  protected function getBulkPricingData()
  {
    $bulkprices = wp_cache_get('getBulkPricingData', 'Aboon');
    if (!is_array($bulkprices)) {
      $bulkprices = array();
      $fields = apply_filters('aboon_bulk_price_field_list', array('articles', 'price'));
      // We need get posts as wc_get_products doesn't support meta query
      $raw = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'has-bulkprices',
            'value' => '"1"', // unfortunately a serialized string by acf
            'compare' => 'LIKE'
          )
        )
      ));

      foreach ($raw as $item) {
        $bulkprices[$item->ID] = array(
          'id' => $item->ID,
          'list' => $this->getRepeaterField($item->ID, 'bulk-price-list', $fields)
        );
      }

      // Save those contracts for another call
      wp_cache_set('getBulkPricingData', $bulkprices, 'Aboon', 86400);
    }

    return $bulkprices;
  }

  /**
   * @param $id
   * @param $name
   * @param $fields
   * @return array
   */
  protected function getRepeaterField($id, $name, $fields)
  {
    $list = array();
    $items = intval(get_post_meta($id, $name, true));
    if ($items > 0) {
      for ($i = 0; $i < $items; $i++) {
        $item = array();
        foreach ($fields as $key) {
          $item[$key] = get_post_meta($id, $name . '_' . $i . '_' . $key, true);
        }
        $list[] = $item;
      }
    }

    return $list;
  }

  /**
   * @param string $label
   * @param \WC_Product $product
   * @return string maybe changed label
   */
  public function blockShowInfo($label, $product)
  {
    $list = $this->getBulkPricingData();
    $id = $product->get_id();
    if (isset($list[$id]) && count($list[$id]['list']) > 0) {
      return __('Staffelpreise', 'lbwp');
    }

    return $label;
  }

  /**
   * @param string $price
   * @param \WC_Product $product
   * @return string maybe changed price label
   */
  public function blockShowPrice($price, $product)
  {
    $list = $this->getBulkPricingData();
    $id = $product->get_id();
    if (isset($list[$id]) && count($list[$id]['list']) > 0) {
      $price = sprintf(__('ab %s'), $price);
    }

    return $price;
  }

  /**
   * Display bulk price infos if there are
   */
  public function maybeDisplayBulkPrices()
  {
    global $post;
    $html = '';
    $list = $this->getBulkPricingData();
    if (isset($list[$post->ID]) && count($list[$post->ID]['list']) > 0) {
      foreach ($list[$post->ID]['list'] as $pricing) {
        $html .= '
          <li>
            <span>' . sprintf(__('Ab %s Stück', 'lbwp'), $pricing['articles']) . '</span>
            <span data-price="' . $pricing[$this->priceField] . '" data-bulk-number="' . $pricing['articles'] . '">' . wc_price($pricing[$this->priceField]) . '</span>
          </li>
        ';
      }

      $html = '
        <div class="bulk-pricing-container">
          <h4>' . __('Staffelpreise verfügbar', 'lbwp') . '</h4>
          <ul>' . $html . '</ul>
        </div>
      ';
    }

    return $html;
  }

  /**
   * @param \WC_Cart $cart
   */
  public function overrideWithBulkPrice($cart)
  {
    $data = $this->getBulkPricingData();
    foreach ($cart->get_cart() as $item) {
      if (isset($data[$item['product_id']])) {
        $price = apply_filters('aboon_get_bulk_original_price', $item['data']->get_price(), $item['product_id']);
        $instead = $this->getInsteadPrice($data[$item['product_id']]['list'], $item['quantity'], $price);
        $item['data']->set_price($instead);
      }
    }
  }

  /**
   * Adds visible meta information about the choosen contract
   * @param array $itemData
   * @param array $cartItem
   * @return array eventually added visible contract duration
   */
  public function showActiveBulkPrice($itemData, $cartItem)
  {
    $list = $this->getBulkPricingData();
    if (isset($list[$cartItem['product_id']])) {
      $product = wc_get_product($cartItem['product_id']);
      $before = apply_filters('woocommerce_product_get_price', $product->get_price(), $product);
      $instead = $this->getInsteadPrice($list[$cartItem['product_id']]['list'], $cartItem['quantity'], $before);
      if ($before != $instead) {
        $itemData[] = array(
          'key' => __('Staffelpreis', 'lbwp'),
          'value' => sprintf(__('%s statt %s'), wc_price($instead), wc_price($before))
        );
      }
    }

    return $itemData;
  }

  /**
   * @param $list
   * @param $quantity
   * @param $price
   * @return mixed
   */
  protected function getInsteadPrice($list, $quantity, $price)
  {
    foreach ($list as $pricing) {
      if ($quantity >= $pricing['articles'] && $pricing[$this->priceField] < $price) {
        $price = $pricing[$this->priceField];
      }
    }

    return $price;
  }

  /**
   * Adds fields to config a product as addon for other products
   */
  public function addConfigFields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_60427f1337381',
      'title' => 'Staffelpreise definieren',
      'fields' => array(
        array(
          'key' => 'field_60427f1cd0bf2',
          'label' => 'Staffelpreise aktivieren',
          'name' => 'has-bulkprices',
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
            1 => 'Aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_60427fb5d0bf3',
          'label' => 'Mengen und Stückpreise',
          'name' => 'bulk-price-list',
          'type' => 'repeater',
          'instructions' => 'Nenne immer Anzahl Artikel und den dazugehörigen Stückpreis ab dieser Menge.',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_60427f1cd0bf2',
                'operator' => '==',
                'value' => '1',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'collapsed' => '',
          'min' => 0,
          'max' => 0,
          'layout' => 'table',
          'button_label' => '',
          'sub_fields' => apply_filters('aboon_bulk_price_subfields', array(
            array(
              'key' => 'field_60427ff6d0bf4',
              'label' => 'Ab Anzahl Artikel',
              'name' => 'articles',
              'type' => 'number',
              'instructions' => '',
              'required' => 1,
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
            array(
              'key' => 'field_6042800ad0bf5',
              'label' => 'Stückpreis',
              'name' => 'price',
              'type' => 'number',
              'instructions' => '',
              'required' => 1,
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
          )),
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
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }
}