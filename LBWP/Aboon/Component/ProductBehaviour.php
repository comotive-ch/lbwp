<?php

namespace LBWP\Aboon\Component;

use LBWP\Core;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\ArrayManipulation;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Module\Forms\Core as FormCore;

/**
 * Provide and register product behaviour functions
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class ProductBehaviour extends ACFBase
{
  /**
   * Option types for auto completion of subs orders
   */
  const RENEWAL_SUBSCRIPTION_OPT_TYPE = 1;
  const NEWLY_ADDED_SUBSCRIPTION_OPT_TYPE = 2;
  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    add_action('wp_head', array($this, 'handleProductSingleDisplaySettings'));
    add_action('wp', array($this, 'handleProductAutoBehaviour'));
		add_filter('wcs_view_subscription_actions', array($this, 'removeSubscriptionCancelButton'), 100, 2);
    add_filter('woocommerce_after_order_notes', array($this, 'addProductFormFields'));
    add_filter('woocommerce_checkout_create_order_line_item', array($this, 'addProductFormsItemMetaData'), 10, 1);
    add_filter('woocommerce_after_checkout_validation', array($this, 'addProductFormsValidation'), 50, 2);
    // Make autocompletes for subscriptions by setting them needs_processing=false
    if (count(ArrayManipulation::forceArray(get_option('options_auto-complete-subs'))) > 0) {
      add_filter('woocommerce_order_item_needs_processing', array($this, 'forceSubscriptionProcessing'), 50, 3);
    }
  }

  /**
   * @param array $data
   * @param array $errors
   * @return array
   */
  public function addProductFormsValidation($data, $errors)
  {
    // Print forms if given for every product that has them
    foreach (WC()->cart->get_cart() as $item) {
      $formId = intval(get_field('product-lbwp-form-id', $item['product_id']));
      if ($formId > 0) {
        // Get the form component to gather data from POST fields
        $form = FormCore::getInstance()->getFormHandler();
        // Run the shortcode to have the items available
        do_shortcode('[lbwp:formular id="' . $formId . '"]');
        foreach ($form->getCurrentItems() as $formItem) {
          if ($formItem->get('pflichtfeld') == 'ja' && strlen($formItem->getValue()) == 0) {
            $errors->add('formular', sprintf(__('%s ist ein Pflichtfeld.', 'woocommerce'), '<strong>' . $formItem->get('feldname') . '</strong>'));
          }
        }
      }
    }

    return $data;
  }

  /**
   * @param \WC_Order_Item_Product $item
   * @return void
   */
  public function addProductFormsItemMetaData($item) {
    // See if the product has a form
    $productId = $item->get_product_id();
    $formId = intval(get_field('product-lbwp-form-id', $productId));

    if ($formId > 0) {
      // Get the form component to gather data from POST fields
      $form = FormCore::getInstance()->getFormHandler();
      // Run the shortcode to have the items available
      do_shortcode('[lbwp:formular id="' . $formId . '"]');
      foreach ($form->getCurrentItems() as $formItem) {
        $item->add_meta_data($formItem->get('feldname'), $formItem->getValue(), true);
      }
    }

    return $item;
  }

  /**
   * @return void
   */
  public function addProductFormFields()
  {
    $hasForms = false;
    // Print forms if given for every product that has them
    foreach (WC()->cart->get_cart() as $item) {
      $formId = intval(get_field('product-lbwp-form-id', $item['product_id']));
      if ($formId > 0) {
        $title = get_field('product-form-title', $item['product_id']);
        if (strlen($title) > 0) {
          echo '<h3>' . $title . '</h3>';
        }
        $html = do_shortcode('[lbwp:formular id="' . $formId . '"]');
        // Replace the form tags with a div, so it can be styled
        $html = str_replace('<form', '<div', $html);
        $html = str_replace('</form>', '</div>', $html);
        echo $html;
        $hasForms = true;
      }
    }

    // Print some css to hide things, if forms are displayed
    if ($hasForms) {
      echo '
        <style>
          .forms-item-wrapper.send-button { display: none; }
        </style>
      ';
    }
  }

  /**
   * @param bool $needsProcessing
   * @param \WC_Product $product
   * @param int $orderId
   * @return bool
   */
  public function forceSubscriptionProcessing($needsProcessing, $product, $orderId)
  {
    // Check if product is a subscription and if yes get the order for processing below
    $types = ArrayManipulation::forceArray(get_option('options_auto-complete-subs'));
    $isSubscription = $product->get_type() == 'subscription' || $product->get_type() == 'variable-subscription';
    $isRenewal = ($isSubscription) ? intval(get_post_meta($orderId, '_subscription_renewal', true)) > 0 : false;

    // No processing needed, if order is a renewal
    if ($isSubscription && $isRenewal && in_array(self::RENEWAL_SUBSCRIPTION_OPT_TYPE, $types)) {
      return false;
    }

    // No processing needed, if order is a newly added subscription
    if ($isSubscription && !$isRenewal && in_array(self::NEWLY_ADDED_SUBSCRIPTION_OPT_TYPE, $types)) {
      return false;
    }

    // If not return the already calculated state
    return $needsProcessing;
  }

  /**
   * Handles some cart automation and redirect logic for single products
   * @throws \Exception
   */
  public function handleProductAutoBehaviour()
  {
    if (is_singular('product')) {
      $cart = ArrayManipulation::forceArray(get_field('cart-behaviour'));
      $redirect = get_field('redirect-behaviour');

      // Make sure to avoid cache, if carts needs to be modified
      if (!is_array($cart) || count($cart) > 0) {
        HTMLCache::avoidCache();
      }

      // Get the cart instance
      $instance = WC()->cart;
      if ($instance instanceof \WC_Cart) {
        // Flush the cart if needed
        if (in_array('flush', $cart)) {
          $instance->empty_cart();
        }
        // Add the current product to cart, if necessary
        if (in_array('auto-add', $cart) && $instance instanceof \WC_Cart) {
          $instance->add_to_cart(get_the_ID(), 1);
        }
      }

      // Redirect if needed after cart modification
      if ($redirect == 'cart') {
        header('Location: ' . wc_get_cart_url(), null, 302);
        exit;
      }
      if ($redirect == 'checkout') {
        header('Location: ' . wc_get_checkout_url(), null, 302);
        exit;
      }
    }
  }

  /**
   * Hides various single template features when needed
   */
  public function handleProductSingleDisplaySettings()
  {
    if (is_singular('product')) {
      $settings = get_field('hide-options');
      if (is_array($settings)) {
        $css = '';
        if (in_array('purchase', $settings)) {
          $css .= 'form.cart { display: none; } ';
        }
        if (in_array('similar', $settings)) {
          $css .= '.related.products { display: none; } ';
        }
        if (in_array('price', $settings)) {
          $css .= '.summary .price { display: none; } ';
        }
        echo '<style type="text/css">' . $css . '</style>';
      }
    }
  }
	
	/**
	 * Removes the cancel subscription button from the user interface
	 *
	 * @param  array $actions the subscription actions
	 * @param  object $subscription the subscription object
	 * @return array the actions
	 */
	public function removeSubscriptionCancelButton($actions, $subscription){
		$items = $subscription->get_items();
		
		foreach($items as $item){
			$subId = $item->get_data()['product_id'];
			$isCancallable = empty(get_field('cancellation', $subId));

			if(!$isCancallable){
				unset($actions['cancel']);
			}
		}

		return $actions;
	}

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_5f216262aa8b7',
      'title' => 'Darstellungsoptionen',
      'fields' => array(
        array(
          'key' => 'field_5f216297711cf',
          'label' => 'Ausblenden von',
          'name' => 'hide-options',
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
            'purchase' => 'Bestellmöglichkeiten',
            'price' => 'Preis(e)',
            'similar' => 'Ähnliche Produkte',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_5d197a9b28071',
          'label' => 'Unterzeile',
          'name' => 'subtitle',
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
          'key' => 'field_66decfcc22445',
          'label' => 'Formular wählen',
          'name' => 'product-lbwp-form-id',
          'aria-label' => '',
          'type' => 'post_object',
          'instructions' => 'Damit können an der Kasse zusätzliche Informationen abgefragt werden.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'lbwp-form',
          ),
          'post_status' => array(
            0 => 'publish',
          ),
          'taxonomy' => '',
          'return_format' => 'id',
          'multiple' => 0,
          'allow_null' => 1,
          'bidirectional' => 0,
          'ui' => 1,
          'bidirectional_target' => array(
          ),
        ),
        array(
          'key' => 'field_66ded04c22446',
          'label' => 'Formular Überschrift',
          'name' => 'product-form-title',
          'aria-label' => '',
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
          'maxlength' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
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

    acf_add_local_field_group(array(
      'key' => 'group_5f9c0c600d75d',
      'title' => 'Verhalten steuern',
      'fields' => array(
        array(
          'key' => 'field_5f9c0c7d7e80a',
          'label' => 'Schneller Kauf',
          'name' => 'cart-behaviour',
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
            'auto-add' => 'Automatisch in den Warenkorb',
            'flush' => 'Warenkorb vorher leeren',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_5f9c0d097e80b',
          'label' => 'Weiterleitung',
          'name' => 'redirect-behaviour',
          'type' => 'radio',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'none' => 'Keine Weiterleitung',
            'cart' => 'Direkt zum Warenkorb',
            'checkout' => 'Direkt zur Kasse',
          ),
          'allow_null' => 0,
          'other_choice' => 0,
          'default_value' => '',
          'layout' => 'vertical',
          'return_format' => 'value',
          'save_other_choice' => 0,
        ),
        array(
          'key' => 'field_6130d09648bfd',
          'label' => 'Kündigung',
          'name' => 'cancellation',
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
            'not-cancellable' => 'Produkt kann nicht gekündigt werden',
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
          'key' => 'field_8430d09699bfd',
          'label' => 'Sichtbarkeit',
          'name' => 'product-visibility',
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
            'hide-search' => 'In Suche/Ergebnissen ausschliessen',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
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
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}
} 