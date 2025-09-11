<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Component\ACFBase;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Core;
use LBWP\Util\Strings;

/**
 * Provides simpler variations then the complex crap woocommerce does
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch
 */
class SimpleVariations extends ACFBase
{
  /**
   * @var string the field used for price data access, can be filtered for other currencies/prices from external devs
   */
  protected $priceField = '';
  /**
   * @var bool forces that setVariationCartPrice is rund only once
   */
  protected static $didPriceChange = false;
	/**
	 * Key used in the product variants form
	 */
	const VARIANTS_KEY = 'aboon-product-variant';

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
		$this->priceField = apply_filters('aboon_variations_diff_field_id', 'price-diff');
		add_action('woocommerce_before_add_to_cart_button', array($this, 'addVariantsDropdown'));
		add_action('woocommerce_add_cart_item_data', array($this, 'setCartItemData'), 99, 3);
		add_action('woocommerce_add_order_item_meta', array($this, 'addOrderMeta'), 10, 3);
		add_action('woocommerce_before_calculate_totals', array($this, 'setVariationCartPrice'), 50, 1);
		add_action('woocommerce_checkout_order_processed', array($this, 'reduceVariantStock'), 999, 1);

		// Check if filter is triggered if online payment is canceled
		add_filter('woocommerce_get_item_data', array($this, 'addItemData'), 25, 2);
		add_filter('woocommerce_get_availability', array($this, 'setStockText'), 10, 2);
		add_filter('woocommerce_check_cart_items', array($this, 'checkVariantsCartStock'));
		add_filter('woocommerce_order_again_cart_item_data', array($this, 'setVariantsOrderAgain'), 10, 3);
		add_filter('woocommerce_product_get_stock_status', array($this, 'checkVariationsStock'), 99, 2);
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
			'key' => 'group_6238e1829e7b8',
			'title' => 'Einstellungen zu Produktvarianten',
			'fields' => array(
        array(
          'key' => 'field_60427c1cd0bf2',
          'label' => 'Produktvarianten aktivieren',
          'name' => 'has-variants',
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
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
				array(
					'key' => 'field_7139e19793c5d',
					'label' => 'Konfigurieren der Varianten',
					'name' => 'variants',
					'type' => 'repeater',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => array(
            'field' => 'field_60427c1cd0bf2',
            'operator' => '==',
            'value' => '1',
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
					'sub_fields' => apply_filters('lbwp_aboon_variation_fields', array(
            array(
              'key' => 'field_553e81b093c5e',
              'label' => 'Name',
              'name' => 'name',
              'type' => 'text',
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
            array(
              'key' => 'field_616d38093d6fd',
              'label' => 'Feldtyp',
              'name' => 'type',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'dropdown' => 'Liste',
                'radio' => 'Knopf',
                'text' => 'Text'
              ),
              'default_value' => false,
              'allow_null' => 0,
              'multiple' => 0,
              'ui' => 0,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
						array(
							'key' => 'field_613e81b093c5e',
							'label' => 'Auswahl',
							'name' => 'text',
							'type' => 'text',
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
            array(
              'key' => 'field_613e81c093c5e',
              'label' => 'Preisdifferenz',
              'name' => 'price-diff',
              'type' => 'text',
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
            array(
              'key' => 'field_613e91c093c5e',
              'label' => 'An Lager',
              'name' => 'stock',
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
					)),
				),
        array(
          'key' => 'field_7771e93df4abc',
          'label' => 'Verwendung',
          'name' => '',
          'type' => 'message',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            'field' => 'field_60427c1cd0bf2',
            'operator' => '==',
            'value' => '1',
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => 'Die erste Variante ist immer standardmässig ausgewählt. Die Preisdifferenz kann mit Minuszeichen auch negativ sein. Lagerinformation optional.',
          'new_lines' => 'wpautop',
          'esc_html' => 0,
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

  /**
   * Registers no own blocks
   */
  public function blocks() {}
	
	/**
	 * Enqueue the assets 
	 */
	public function assets(){
		parent::assets();

		$base = File::getResourceUri();
		wp_enqueue_script('simple-variation-js', $base . '/js/aboon/simple-variation.js', array('jquery'), Core::REVISION, true);
		wp_localize_script('simple-variation-js', 'simpleVariationSettings',
				array( 
						'changePriceByQty' => json_encode(apply_filters('aboon_change_price_by_quantity', false)),
				)
		);
	}
	
	/**
	 * Check if product has variants
	 *
	 * @param  \WP_Product|int $product the product object or the product id
	 * @return bool if the product has variants or not
	 */
	public static function hasVariants($product){
		$prId = is_object($product) ? $product->get_id() : $product;
		return !empty(get_field('has-variants', $prId));
	}
	
	/**
	 * Customize the product tabs
	 *
	 * @param  array $tabs all the tabs 
	 * @return array the tabs
	 */
	public function customizeProductTabs($tabs){
		global $product;
		$pId = $product->get_id();
		$customTabs = get_field('tabs', $pId);
		$tabsSettings = get_field('tabs-settings', $pId);

		foreach($customTabs as $cTab){
			$tabs[] = array(
				'title' => $cTab['tab-title'],
				'priority' => 10,
				'callback' => function() use($cTab){
					echo $cTab['tab-content'];
				}
			);
		}

		if(in_array('alphabetical-order', $tabsSettings)){
			ArrayManipulation::sortByStringField($tabs, 'title');
		}

		return $tabs;
	}
	
	/**
	 * Add product variants dropdown to the product single page
	 */
	public function addVariantsDropdown(){
		global $product;
		echo self::getVariantsDropdown($product);
	}
	
	/**
	 * Get the variation dropdown (select element) from the product
	 *
	 * @param  WC_Product $product the product
	 * @param  bool $ajax if is in an ajax context (e.g. the product filter)
	 * @return string the select element HTML string
	 */
	public static function getVariantsDropdown($product, $ajax = false){
		$html = '';

		if(!empty(get_field('has-variants', $product->get_id()))){
			$variants = get_field('variants', $product->get_id());

      if (!is_array($variants) || (count($variants) == 1 && $variants[0]['name'] == '')) {
        return '';
      }

			$variantFields = array();

			foreach($variants as $key => $variant){
				if(!isset($variantFields[$variant['name']])){
					$variantFields[$variant['name']] = array(
						'type' => $variant['type'],
						'options' => array()
					);
				}

				// Filter for customizing the select attributes, use key => value array for building the attributes array
				$variantFields[$variant['name']]['options'][] =  apply_filters('aboon_add_product_variants_attr', array(
					'key' => $key,
					'value' => $variant['text'],
					'data-difference' => $variant['price-diff'],
					'disabled' => (intval($variant['stock']) <= 0)
				), $variant, $product);
			}

			foreach($variantFields as $name => $field){
				$fieldHtml = '<div class="aboon-product-variant-container is-type-' . $field['type'] . '">';
				$inputAttr = '
					name="' . self::VARIANTS_KEY . '-' . sanitize_key($name) . '" 
					class="' . self::VARIANTS_KEY . ($ajax ? ' is-ajax-context' : '') . '" 
					data-price="' . $product->get_price() . '"';
				$theInput = $ajax ? '' : '<p class="variation-label">' . $name . '</p>';

				if($ajax && $field['type'] === 'radio' && count(array_unique(array_column($variants, 'name'))) <= 1){
					$field['type'] = 'select';
				}

				switch($field['type']){
					case 'text':
						// Remove backend text if set
						$options = $field['options'][0];
						unset($options['value']);
						// Ignore stock for text fields
						unset($options['disabled']);

						$theInput .= '
							<div class="variation-input">
								<input type="text" ' . $inputAttr . self::formatDropdownAttributes($options) . '>
								<input type="hidden" 
									value="' . $field['options'][0]['key'] . '"
									name="' . self::VARIANTS_KEY . '-' . sanitize_key($name) . '-row-num">
							</div>';
						break;

          case 'dropdown':
          case 'select': // data error at beroea, can be removed in the future
						$theInput .= '<div class="variation-input"><select ' . $inputAttr . '>';

						foreach($field['options'] as $option){
							$theInput .= '<option ' . self::formatDropdownAttributes($option) . '>' . $option['value'] . '</option>';
						}

						$theInput .= '</select></div>';
						break;

					case 'radio':
						foreach($field['options'] as $key => $option){
							$theInput .= '
							<div class="variation-input">
								<label>
									<input type="radio" ' . $inputAttr . self::formatDropdownAttributes($option) . ($key === 0 ? ' checked' : '') . '>
									' . $option['value'] . '
								</label>
							</div>
							';
						}
						break;
				}

				$fieldHtml .= $theInput . '</div>';
				$html .= $fieldHtml;
			}
		}

		return $html;
	}
	
	/**
	 * Get the dropdown if there is only a variations (as select)
	 *
	 * @param  WC_Product $product the product
	 * @return string the select element HTML string
	 * @return string either an empty string or the dropdown with on variation
	 */
	public static function getOneVariantDropdown($product, $ajax = false){
		if(!empty(get_field('has-variants', $product->get_id()))){
			$variants = get_field('variants', $product->get_id());
			$checkType = array_column($variants, 'type');

			if(in_array('text', $checkType) || in_array('dropdown', $checkType) || in_array('select', $checkType) || count(array_unique(array_column($variants, 'name'))) > 1){
				return '';
			}

			return self::getVariantsDropdown($product, $ajax);
		}
	}
	
	/**
	 * Get a string for the variations input field attributes
	 *
	 * @param  array $attributes attributes formatted like "attribute-name" => "attribute-value"
	 * @return string the input field attributes
	 */
	public static function formatDropdownAttributes($attributes){
		$attr = '';
		foreach($attributes as $attrKey => $attrVal){
			// Special behaviour for disabled attribute
			if($attrKey === 'disabled' && $attrVal === false || $attrKey === 'key'){
				continue;
			}

			if($attrKey === 'value' && $attrVal !== ''){
				$attrVal .= '_' . $attributes['key'];
			}
			
			$attr .= ' ' . $attrKey . '="' . $attrVal . '"';
		}
	
		return $attr;
	}
	
	/**
	 * Set the custom data into the cart item data and into the wc session
	 *
	 * @param  array $cartItemData
	 * @param  int $productId
	 * @param  int $variationId
	 * @return array the cart item data
	 */
	public function setCartItemData($cartItemData, $productId, $variationId){
		$addData = array();
		$fMethod = $_POST;

		if(empty($fMethod) || defined('DOING_AJAX')){
			$fMethod = $_GET;
		}

		$hashString = '';

		foreach(array_keys($fMethod) as $vKey){
			if(stripos($vKey, self::VARIANTS_KEY) !== false && stripos($vKey, 'row-num') === false){
				$value = $fMethod[$vKey];

				if($value === ''){
					continue;
				}
				
				// Add prefix and row number for text fields
				if(isset($fMethod[$vKey . '-row-num'])){
					$value = 'Text: ' . $value . '_' . $fMethod[$vKey . '-row-num'];
					unset($fMethod[$vKey . '-row-num']);
				}

				// Set the custom data into the cart item data
				$cartItemData['customData'][$vKey] = $value;
				
				// Set the custom data into the WC session
				$addData[$vKey] = $value;
				WC()->session->set('customData', $addData);

				$hashString .= $vKey;
			}
		}

		if($hashString !== ''){
			// Set a unique id for the product variation
			$cartItemData['customData']['uniqueKey'] = md5($hashString . $productId);
		}

		return $cartItemData; 
	}
	
	/**
	 * Show the variant in the cart
	 *
	 * @param  array $cData the cart data 
	 * @param  array $cItem the cart item
	 * @return array the cart data
	 */
	public function addItemData($cData, $cItem){
		if(!empty($cItem['customData'])){
			$values = array();
			foreach( $cItem['customData'] as $key => $value ){
				if( $key != 'uniqueKey' ){
					$values[] = self::getVariantStringData($value);
				}
			}
			
			$values = implode( ', ', $values );
			$cData[] = array(
				'name'    => __('Variante', 'lbwp'),
				'display' => $values
			);
		}
		
		return $cData;
	}
	
	/**
	 * Add the product variant in the order meta
	 *
	 * @param  int $itemId
	 * @param  array $cItem
	 * @param  string $cItemKey
	 */
	public function addOrderMeta($itemId, $cItem, $cItemKey){
		if(isset($cItem['customData'])){
			$values = array();
			foreach($cItem['customData'] as $key => $value){
				if($key != 'uniqueKey'){
					$values[$key] = self::getVariantStringData($value);
				}
			}

			// Set the values into a hidden meta
			wc_add_order_item_meta($itemId, '_variant-data', json_encode($values));
			// and also into a visible meta
			$values = implode(', ', $values);
			wc_add_order_item_meta($itemId, 'Variante', $values);
		}
	}
	
	/**
	 * Set the variant price in the cart
	 *
	 * @param  object $cart the woocommerce cart
	 */
	public function setVariationCartPrice($cart){
		// Skip if in backend or ajax call
    if((is_admin() && !defined('DOING_AJAX')) && self::$didPriceChange){
			return;
		}

    self::$didPriceChange = true;

    foreach($cart->get_cart() as $cItem){
			if(!empty(get_field('has-variants', $cItem['product_id']))){
				// Get the variation row
				$variants = get_field('variants', $cItem['product_id']);
				$priceDiff = 0;
        $price = $cItem['data']->get_regular_price();

        if($cItem['data']->is_on_sale()){
          $price = $cItem['data']->get_sale_price();
        }

				$originalPrice = apply_filters('aboon_get_variation_original_price', $price, $cItem['product_id']);

				foreach($cItem['customData'] as $cDataKey => $cData){
					if(stripos($cDataKey, self::VARIANTS_KEY) !== false){
						$index = self::getVariantStringData($cItem['customData'][$cDataKey], true);
						$thePriceDiff = apply_filters('aboon_set_variation_cart_price_difference', 
							floatval($variants[$index][$this->priceField]),
						 	$originalPrice, $variants[$index], $cItem['product_id']
						);
						$priceDiff += $thePriceDiff;
					}
				}

				// Change the price based on the set difference
				$changedPrice = $originalPrice + $priceDiff;
				$cItem['data']->set_price($changedPrice);
			}
		}
	}
	
	/**
	 * Set a custom stock text for variable products
	 *
	 * @param  array $availability array with text (key: availability) and classes (key: class)
	 * @param  WC_Product $product the current product
	 * @return array the availability array
	 */
	public function setStockText($availability, $product){
		if($product->is_in_stock() && !empty(get_field('has-variants'))){
			$availability['availability'] = apply_filters('aboon_product_stock_text', __('Vorrätig', 'aboon'), $product);
		}

		return $availability;
	}
	
	/**
	 * Get the variant text or the variant row number
	 *
	 * @param  string $string
	 * @param  bool $returnRowId 
	 * @return string
	 */
	public static function getVariantStringData($string, $returnRowId = false){
		$cutString = explode('_', $string);

		if($returnRowId){
			return $cutString[count($cutString) - 1];
		}

		unset($cutString[count($cutString) - 1]);

		$cutString = implode('_', $cutString);
		return $cutString;
	}
	
	/**
	 * Check the quantity for variants in the cart
	 *
	 * @return bool true if everything is alright else false
	 */
	public function checkVariantsCartStock(){
		$check = true;
		$cart = WC()->cart;

		// Loop through the cart to check the variants stock quantity
		foreach($cart->cart_contents as $cartKey => $item){
			if(is_array($item['customData'])){
				foreach($item['customData'] as $dataKey => $data){
					if($dataKey === 'uniqueKey'){
						continue;
					}
					
					// Get the index of the variant
					$rowNum = explode('_', $data)[1];
					
					if($rowNum !== null){
						$variant = get_field('variants', $item['product_id'])[intval($rowNum)];
            $variantStock = $variant[apply_filters('aboon_variations_locational_stock', 'stock')];
            $variantStock = $variantStock === '' ? 9999999 : intval($variantStock);

						// Print error if the quantity is to high
						if($item['quantity'] > $variantStock){
              // Only display error if there isn't already one (prevent double error notice from "checkVariationsStock")
              if(!in_array('error', array_keys(wc_get_notices()))) {
                wc_add_notice(
                  sprintf(__('Leider haben wir nicht genügend  „%s - %s“ vorrätig, um Ihre Bestellung auszuführen (%s verfügbar). Wir bitten für eventuell entstandene Unannehmlichkeiten um Entschuldigung. ', 'lbwp'),
                    $item['data']->get_name(),
                    $variant['text'],
                    $variantStock),
                  'error'
                );
              }
							$check = false;
							break;
						}
					}
				}
			}
		}

		return $check;
	}
	
	/**
	 * Fired for every item in the "order-again-list" and sets it's cart data 
	 *
	 * @param  array $cartData the cart data (Default empty)
	 * @param  object $item woocommerce item object 
	 * @param  object $order woocommercer order object
	 * @return array the cart data
	 */
	public function setVariantsOrderAgain($cartData, $item, $order){
		// Get the variation data from the meta
		$variations = (array) json_decode($item->get_meta('_variant-data'));

		// If the variation is available build the cart data with it
		if(!empty($variations)){
			if(!isset($cartData['customData'])){
				$cartData['customData'] = array();
			}
			
			foreach($variations as $key => $variant){
				$index = array_search($variant, array_column(get_field('variants', $item['product_id']), 'text'));

				// Set the variant data. Important: the index of the ACF field is needed
				$cartData['customData'][$key] = $variant . '_' . $index;
				$cartData['customData']['uniqueKey'] = md5($key . $item['product_id']);
			}
		}
		
		// Also set the custom data into the session
		WC()->session->set('customData', $cartData['customData']);

		return $cartData;
	}

	/**
	 * checkVariationsStock
	 *
	 * @param  string $status the stock status. Can be 'instock', 'outofstock' or 'onbackorder'
	 * @param  \WC_Product_Simple $product
	 * @return string the status
	 */
	public function checkVariationsStock($status, $product){
		if(self::hasVariants($product)){
			$status = 'outofstock';
			$variants = get_field('variants', $product->get_id());

			// Check if ANY variant has at least one item on stock. If so, set 'instock' and break
			foreach($variants as $variant){
				$stock = apply_filters('aboon_variant_stock_override', $variant['stock'], $variant, $product);
				if(intval($stock) > 0){
					$status = 'instock';
					break;
				}
			}
		}

		return $status;
	}
	
	/**
	 * Reduce the stock of the variant
	 *
	 * @param  int $orderId the order id
	 * @return void
	 */
	public function reduceVariantStock($orderId){
		if(!$orderId){
			return;
		}

		// Loop through order items
		$order = wc_get_order($orderId);
		foreach($order->get_items() as $item){
			$productId = $item->get_data()['product_id'];

			// If the item has variants, do stuff
			if(self::hasVariants($productId)){
				$variants = get_field('variants', $productId);
				$itemVariants = (array) json_decode(wc_get_order_item_meta($item->get_id(), '_variant-data'));
				
				// Loop through variants
				foreach($variants as $index => $variant){
					if(in_array($variant['text'], $itemVariants)){
						// Find the ordered items variant and get the stock value
						$theStock = apply_filters('aboon_variant_stock_to_reduce_field', 'variants_' . $index . '_stock', $variant, $index);
						$oldStock = get_field($theStock, $productId);
						
						// If the stock field is empty, do not reduce it
						if(Strings::isEmpty($oldStock)){
							continue;
						}

						// Reduce the stock and update the field
						$newStock = intval($oldStock) - $item->get_quantity();
						update_field($theStock, $newStock, $productId);
					}
				}
			}
		}
	}
}