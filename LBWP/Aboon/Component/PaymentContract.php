<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\Helper\SubscriptionPeriod;
use LBWP\Theme\Base\Component;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use Payrexx\Models\Request\Subscription;

/**
 * Allows to set payment contracts for subscriptions
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class PaymentContract extends Component
{
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
    // Handle display, add, and permanent save of contract information
    add_action('woocommerce_single_product_summary', array($this, 'maybeDisplayContractInfo'), 25);
    add_filter('woocommerce_add_cart_item_data', array($this, 'addContractMetaData'), 10, 3);
    add_filter('woocommerce_get_item_data', array($this, 'showContractDuration'), 10, 2);
    add_action('woocommerce_before_calculate_totals', array($this, 'changeContractPricing'), 10, 1);
    add_action('woocommerce_checkout_create_order_line_item', array($this, 'saveContractMetaData'), 10, 3);
    add_action('woocommerce_review_order_before_payment', array($this, 'maybeForceRecurringPayment'));
    // Handle showing of contract duration in backend / user area
    add_action('woocommerce_after_order_itemmeta', array($this, 'showContractDurationUserside'), 10, 3);
    add_action('woocommerce_order_item_meta_start', array($this, 'showContractDurationUserside'), 10, 3);
    add_filter('wcs_view_subscription_actions', array($this, 'disallowCancellationOnContract'), 200, 2);
    add_action('save_post_product', array($this, 'flushCachesOnProductSave'));
  }

  /**
   * Flushes relevant caches on saving a product in backend
   */
  public function flushCachesOnProductSave()
  {
    wp_cache_delete('getPaymentContractInfo', 'Aboon');
  }

  /**
   * Decide if the box of an addon or an addon upsell for a product will be displayed
   */
  public function maybeDisplayContractInfo()
  {
    global $post;
    $contracts = $this->getPaymentContractList();
    if (isset($contracts[$post->ID])) {
      echo $this->displayPaymentContractInfo($contracts[$post->ID]);
    }
  }

  /**
   * Maybe force recurring payment if a contract says so
   */
  public function maybeForceRecurringPayment()
  {
    $cart = WC()->cart;
    $forceRecurring = false;
    $contracts = $this->getPaymentContractList();
    foreach ($cart->get_cart() as $hash => $item) {
      if (isset($contracts[$item['product_id']]) && $contracts[$item['product_id']]['force-recurring']) {
        $forceRecurring = true; break;
      }
    }

    if ($forceRecurring) {
      echo '
        <script type="text/javascript">
          jQuery(function() {
            aboonGlobalSettings.forceRecurringPayment = true;
          });
        </script>
      ';
    }
  }

  /**
   * Adds contract information to a product, if given
   * @param array $itemData the meta data item that will be filled
   * @param int $productId the product id
   * @param int $variationId the varition id, if given
   * @return array extended meta data with contract
   */
  public function addContractMetaData($itemData, $productId, $variationId)
  {
    $contracts = $this->getPaymentContractList();
    if (isset($contracts[$productId]) && isset($_POST['selectedPaymentContractId'])) {
      $id = $_POST['selectedPaymentContractId'];
      $itemData['payment-contract'] = array(
        'price' => $contracts[$productId]['payment-contracts'][$id]['price'],
        'intervals' => $contracts[$productId]['payment-contracts'][$id]['period'],
        'interval' => $contracts[$productId]['period'],
        'force-recurring' => $contracts[$productId]['force-recurring'] == 1
      );
    }

    return $itemData;
  }

  /**
   * @param \WC_Cart $cart
   */
  public function changeContractPricing($cart)
  {
    $contracts = $this->getPaymentContractList();
    foreach ($cart->get_cart() as $hash => $item) {
      if (isset($contracts[$item['product_id']])) {
        if (isset($item['payment-contract']['price']) && strlen($item['payment-contract']['price']) > 0) {
          $item['data']->set_price($item['payment-contract']['price']);
        }
      }
    }
  }

  /**
   * Saves the contract meta info to the order item permanently
   * @param \WC_Order_Item $item
   * @param string $cartItemKey hash
   * @param array $values fo cart meta values
   */
  public function saveContractMetaData($item, $cartItemKey, $values)
  {
    if (isset($values['payment-contract'])) {
      $item->add_meta_data('_payment-contract',$values['payment-contract']);
    }
  }

  /**
   * Adds visible meta information about the choosen contract
   * @param array $itemData
   * @param array $cartItem
   * @return array eventually added visible contract duration
   */
  public function showContractDuration($itemData, $cartItem)
  {
    if (isset($cartItem['payment-contract']) && $this->isValidContract($cartItem['payment-contract'])) {
      $itemData[] = array(
        'key' => __('Vertragslaufzeit', 'lbwp'),
        'value' => SubscriptionPeriod::getPeriodNameString(
          $cartItem['payment-contract']['intervals'],
          $cartItem['payment-contract']['interval']
        )
      );
    }

    return $itemData;
  }

  /**
   * @param \WC_Order_Item $item
   * @param int $itemId
   */
  public function showContractDurationUserside($itemId, $item, $subscriptionId)
  {
    $contract = $item->get_meta('_payment-contract');
    if ($this->isValidContract($contract)) {
      echo '
        <p>
          <strong>' . __('Vertragslaufzeit', 'lbwp') . ': </strong>
          ' . SubscriptionPeriod::getPeriodNameString($contract['intervals'], $contract['interval']) . '
          ' . $this->getContractEndDate($contract, $subscriptionId, __('bis', 'lbwp') . ' ') . '
        </p>
      ';
    }
  }

  /**
   * @param array of $contract intervals (number) and interval (month, year etc.)
   * @param int $subscriptionId the subscription date
   */
  protected function getContractEndDate($contract, $subscriptionId, $prefix)
  {
    if (wcs_is_subscription($subscriptionId)) {
      $subscription = wcs_get_subscription($subscriptionId);
      $created = $subscription->get_date_created()->getTimestamp();
      $end = SubscriptionPeriod::getEndTimestamp($created, $contract['intervals'], $contract['interval']);
      return $prefix . Date::getTime(Date::EU_DATE, $end);
    }
  }

  /**
   * @param array $actions possible user actions
   * @param \WC_Subscription $subscription
   * @return array of actions, maybe with no possibility to cancel
   */
  public function disallowCancellationOnContract($actions, $subscription)
  {
    $contractEndDates = array();
    // See if the subscription has contract end dates and choose the farthest in the future
    foreach ($subscription->get_items() as $id => $item) {
      $contract = $item->get_meta('_payment-contract');
      if ($this->isValidContract($contract)) {
        $created = $subscription->get_date_created()->getTimestamp();
        $end = SubscriptionPeriod::getEndTimestamp($created, $contract['intervals'], $contract['interval']);
        $contractEndDates[] = $end;
      }
    }

    // Maybe hide the cancel action if there are unmatched contract dates
    if (count($contractEndDates)) {
      // Order them by size (smallest first) and get the largest
      sort($contractEndDates, SORT_NUMERIC);
      $checkedDate = array_pop($contractEndDates);
      // Unset cancel action if date not met within offset
      $offset = apply_filters('aboon_payment_contract_cancel_offset', 86400 * 30, $subscription);
      if (current_time('timestamp') < ($checkedDate - $offset)) {
        unset($actions['cancel']);
      }
    }

    return $actions;
  }

  /**
   * @param array $contract
   * @return bool true if the contract is useable
   */
  protected function isValidContract($contract)
  {
    return is_array($contract) && isset($contract['intervals']) && $contract['intervals'] > 0 && isset($contract['interval']);
  }

  /**
   * @param $contract
   */
  protected function displayPaymentContractInfo($contract)
  {
    $html = '';

    if (count($contract['payment-contracts']) === 1) {
      $number = $contract['period-interval'] * $contract['payment-contracts'][0]['period'];
      $html .= '<p>' . __('Dieses Produkt hat eine fixe Vertragslaufzeit: ');
      $html .= '<strong>' . SubscriptionPeriod::getPeriodNameString($number, $contract['period']) . '</strong></p>';
      $html .= '<input type="hidden" name="payment-contract-id" value="0" />';
    } else if ($contract['display-type'] == 'dropdown') {
      $html .= '<p>' . __('Vertragslaufzeit auswählen: ') . '</p>';
      $html .= '<select name="payment-contract-id">';
      foreach ($contract['payment-contracts'] as $id => $item) {
        $number = $contract['period-interval'] * $item['period'];
        $html .= '<option value="' . $id . '" data-price="' . number_format((float)$item['price'], 2, '.', "'") . '">' . SubscriptionPeriod::getPeriodNameString($number, $contract['period']) . '</option>';
      }
      $html .= '</select>';
    }

    $html .= '
      <script type="text/javascript">
        jQuery(function() {
          jQuery("form.cart").append("<input type=\'hidden\' value=\'0\' name=\'selectedPaymentContractId\' />");
          // preserve the default price
          var price = jQuery(".summary .price > .woocommerce-Price-amount bdi");
          var lastPrice = price.text().split(" ")[1];
          price.attr("data-default-price", lastPrice);
          price.attr("data-last-price", lastPrice);
          jQuery("select[name=payment-contract-id]").on("change", function() {
            var dropdown = jQuery(this);
            jQuery("input[name=selectedPaymentContractId]").val(dropdown.val());
            // Change the visible price in the text node
            var newPrice = dropdown.find("option:selected").data("price");
            var price = jQuery(".summary .price >  .woocommerce-Price-amount bdi");
            var lastPrice = price.attr("data-last-price");
            // Set newPrice to default, if nothing given
            if (newPrice == "0.00") newPrice = price.data("default-price");
            // Replace new price and set new price as last price
            price.text(price.text().replace(lastPrice, newPrice));
            price.attr("data-last-price", newPrice);
          });
        });
      </script>
    ';

    return '<div class="payment-contract-info">' . $html . '</div>';
  }

  /**
   * Cached list with less info on every available contract
   */
  protected function getPaymentContractList()
  {
    $contracts = wp_cache_get('getPaymentContractInfo', 'Aboon');
    if (!is_array($contracts)) {
      $contracts = array();
      // We need get posts as wc_get_products doesn't support meta query
      $raw = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'has-contract',
            'value' => '"1"', // unfortunately a serialized string by acf
            'compare' => 'LIKE'
          )
        )
      ));

      foreach ($raw as $item) {
        $contracts[$item->ID] = array(
          'id' => $item->ID,
          'title' => $item->post_title,
          'period' => get_post_meta($item->ID, '_subscription_period', true),
          'period-interval' => get_post_meta($item->ID, '_subscription_period_interval', true),
          'display-type' => get_post_meta($item->ID, 'contract-display-type', true),
          'force-recurring' => get_post_meta($item->ID, 'force-recurring-payment', true)[0] == 1,
          'payment-contracts' => get_field( 'payment-contracts', $item->ID)
        );
      }

      // Save those contracts for another call
      wp_cache_set('getPaymentContractInfo', $contracts, 'Aboon', 86400);
    }

    return $contracts;
  }

  /**
   * Adds fields to config a product as addon for other products
   */
  public function addConfigFields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_602a680b96a9b',
      'title' => 'Vertragslaufzeiten verwenden',
      'priority' => 'low',
      'fields' => array(
        array(
          'key' => 'field_602a683371e65',
          'label' => 'Vertragslaufzeiten anwenden',
          'name' => 'has-contract',
          'type' => 'checkbox',
          'instructions' => 'Ermöglicht es, zu definieren wie lange der Vertrag über dieses Abonnement gilt. Das Abonnement kann in diesem Fall nicht gekündigt werden bis der Vertrag abgelaufen ist.',
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
          'key' => 'field_602a68a871e66',
          'label' => 'Zahlungsmittel',
          'name' => 'force-recurring-payment',
          'type' => 'checkbox',
          'instructions' => 'Damit kannst du sicherstellen, dass du das Abonnement abrechnen kannst.',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_602a683371e65',
                'operator' => '==',
                'value' => '1'
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Nur Kreditkarten erlauben und automatische Belastung aktivieren',
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
          'key' => 'field_602a694c71e68',
          'label' => 'Laufzeiten',
          'name' => 'payment-contracts',
          'type' => 'repeater',
          'instructions' => 'Wenn du das Feature aktivierst und nur eine Laufzeit angibst wird diese angezeigt und ist nicht änderbar. So kannst du z.b. ein Monatsabo anbieten aber den Kunden für z.b. 6 Monate mindestens verpflichten. Der Vertrag läuft immer in Vielfachen der definierten Laufzeit.',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_602a683371e65',
                'operator' => '==',
                'value' => '1'
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
          'sub_fields' => array(
            array(
              'key' => 'field_602a69ec71e69',
              'label' => 'Periodizität',
              'name' => 'period',
              'type' => 'number',
              'instructions' => 'Bei einem Monatsabo z.b. 3, 6, 12, 24 Monate.',
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
            array(
              'key' => 'field_602a6a1a71e6a',
              'label' => 'Preis',
              'name' => 'price',
              'type' => 'number',
              'instructions' => 'Angepasster Preis pro Abrechnungsperiode.',
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
        ),
        array(
          'key' => 'field_602a68f771e67',
          'label' => 'Darstellungsform',
          'name' => 'contract-display-type',
          'type' => 'select',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_602a683371e65',
                'operator' => '==',
                'value' => '1'
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'dropdown' => 'Dropdown mit Auswahl der Laufzeiten',
            'ruler' => 'Regler um zwischen den Laufzeiten zu wählen (in Entwicklung)',
          ),
          'default_value' => false,
          'allow_null' => 0,
          'multiple' => 0,
          'ui' => 0,
          'return_format' => 'value',
          'ajax' => 0,
          'placeholder' => '',
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
      'menu_order' => 110,
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