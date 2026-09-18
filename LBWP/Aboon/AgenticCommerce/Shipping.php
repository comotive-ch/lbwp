<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Aboon\AgenticCommerce\Enum\Message;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Shipping_Rate;
use WC_Tax;

/**
 * Enumerates fulfillment options from the WooCommerce shipping zones for a draft order and
 * applies the chosen one as shipping line. Runs without a customer cart: a session and an empty
 * cart are initialised because WC_Shipping and the free shipping method depend on them.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Shipping
{
  public const string TYPE_SHIPPING = 'shipping';
  public const string TYPE_DIGITAL = 'digital';
  public const string DIGITAL_ID = 'digital';
  public const string FALLBACK_ID = 'agentic_fallback';
  /**
   * @var array|null package being calculated (for the free shipping filter)
   */
  protected static ?array $currentPackage = null;

  /**
   * Returns the neutral fulfillment options for an order.
   * @param WC_Order $order the draft order
   * @param array $messages receives neutral messages (missing address, region restricted)
   * @return array options list
   */
  public static function options(WC_Order $order, array &$messages = []): array
  {
    if (self::isDigitalOnly($order)) {
      return [self::digitalOption()];
    }
    $destination = self::destination($order);
    if ($destination['country'] === '') {
      $messages[] = Message::error(Message::MISSING, __('Lieferadresse fehlt.', 'lbwp'), '$.fulfillment_details.address');
      return [];
    }
    if (!in_array($destination['country'], Settings::allowedCountries(), true)) {
      $messages[] = Message::error(Message::REGION_RESTRICTED, __('Lieferung in dieses Land ist nicht möglich.', 'lbwp'), '$.fulfillment_details.address.country');
      return [];
    }
    $options = [];
    foreach (self::calculateRates($order, $destination) as $rate) {
      $options[] = self::rateToOption($rate, $order->get_currency());
    }
    if (count($options) === 0) {
      $fallback = Settings::shippingFallback();
      if ($fallback === null) {
        $messages[] = Message::error(Message::REGION_RESTRICTED, __('Für diese Adresse ist keine Versandoption verfügbar.', 'lbwp'), '$.fulfillment_details.address');
      } else {
        $options[] = self::fallbackOption($fallback, $order->get_currency());
      }
    }
    return (array) apply_filters('aboon_agentic_fulfillment_options', $options, $order);
  }

  /**
   * Applies a fulfillment option to the order as shipping line.
   * @param WC_Order $order the draft order
   * @param array $option the neutral option (from options())
   * @return bool true when applied
   */
  public static function apply(WC_Order $order, array $option): bool
  {
    foreach ($order->get_items('shipping') as $itemId => $item) {
      $order->remove_item($itemId);
    }
    if (($option['type'] ?? '') === self::TYPE_DIGITAL) {
      return true;
    }
    $shipping = new WC_Order_Item_Shipping();
    if (($option['id'] ?? '') === self::FALLBACK_ID) {
      $location = Pricing::taxLocation($order);
      $location['tax_class'] = '';
      $rates = WC_Tax::find_shipping_rates($location);
      $shipping->set_method_title((string) $option['title']);
      $shipping->set_method_id(self::FALLBACK_ID);
      $shipping->set_total((string) ($option['cost'] ?? 0));
      $shipping->set_taxes(['total' => WC_Tax::calc_shipping_tax((float) ($option['cost'] ?? 0), $rates)]);
    } else {
      $rate = new WC_Shipping_Rate(
        (string) $option['id'],
        (string) $option['title'],
        (float) ($option['cost'] ?? 0),
        (array) ($option['taxes'] ?? []),
        (string) ($option['method_id'] ?? ''),
        (int) ($option['instance_id'] ?? 0)
      );
      $shipping->set_shipping_rate($rate);
    }
    $order->add_item($shipping);
    return true;
  }

  /**
   * Whether every item of the order is virtual / does not need shipping.
   * @param WC_Order $order the order
   * @return bool true when nothing needs shipping
   */
  public static function isDigitalOnly(WC_Order $order): bool
  {
    $items = $order->get_items('line_item');
    if (count($items) === 0) {
      return false;
    }
    foreach ($items as $item) {
      $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
      if ($product && $product->needs_shipping()) {
        return false;
      }
    }
    return true;
  }

  /**
   * Finds an option by id inside an options list.
   * @param array $options options list
   * @param string $optionId id
   * @return array|null the option
   */
  public static function find(array $options, string $optionId): ?array
  {
    foreach ($options as $option) {
      if ((string) ($option['id'] ?? '') === $optionId) {
        return $option;
      }
    }
    return null;
  }

  /**
   * Builds the destination array from the order addresses.
   * @param WC_Order $order the order
   * @return array destination for a shipping package
   */
  protected static function destination(WC_Order $order): array
  {
    $prefix = $order->get_shipping_country() !== '' ? 'shipping' : 'billing';
    return [
      'country' => strtoupper((string) $order->{"get_{$prefix}_country"}()),
      'state' => (string) $order->{"get_{$prefix}_state"}(),
      'postcode' => (string) $order->{"get_{$prefix}_postcode"}(),
      'city' => (string) $order->{"get_{$prefix}_city"}(),
      'address' => (string) $order->{"get_{$prefix}_address_1"}(),
      'address_1' => (string) $order->{"get_{$prefix}_address_1"}(),
      'address_2' => (string) $order->{"get_{$prefix}_address_2"}(),
    ];
  }

  /**
   * Runs the WooCommerce zone calculation for a manually built package.
   * @param WC_Order $order the order
   * @param array $destination destination array
   * @return WC_Shipping_Rate[] rates
   */
  protected static function calculateRates(WC_Order $order, array $destination): array
  {
    self::ensureCart();
    $contents = [];
    $cost = 0.0;
    $subtotal = 0.0;
    foreach ($order->get_items('line_item') as $itemId => $item) {
      $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
      if (!$product || !$product->needs_shipping()) {
        continue;
      }
      $contents['agentic_' . $itemId] = [
        'key' => 'agentic_' . $itemId,
        'product_id' => $item->get_product_id(),
        'variation_id' => $item->get_variation_id(),
        'variation' => [],
        'quantity' => (int) $item->get_quantity(),
        'data' => $product,
        'data_hash' => wc_get_cart_item_data_hash($product),
        'line_total' => (float) $item->get_total(),
        'line_subtotal' => (float) $item->get_subtotal(),
        'line_tax' => (float) $item->get_total_tax(),
        'line_subtotal_tax' => (float) $item->get_subtotal_tax(),
      ];
      $cost += (float) $item->get_total();
      $subtotal += (float) $item->get_subtotal();
    }
    $package = [
      'contents' => $contents,
      'contents_cost' => $cost,
      'applied_coupons' => $order->get_coupon_codes(),
      'user' => ['ID' => 0],
      'destination' => $destination,
      'cart_subtotal' => $subtotal,
    ];
    $package = (array) apply_filters('aboon_agentic_shipping_package', $package, $order);
    $key = 'agentic_' . $order->get_id();
    self::$currentPackage = $package;
    WC()->session->set('shipping_for_package_' . $key, null);
    add_filter('woocommerce_shipping_free_shipping_is_available', [self::class, 'freeShippingFromPackage'], 10, 3);
    try {
      $result = WC()->shipping()->calculate_shipping_for_package($package, $key);
    } finally {
      remove_filter('woocommerce_shipping_free_shipping_is_available', [self::class, 'freeShippingFromPackage'], 10);
      WC()->session->set('shipping_for_package_' . $key, null);
      self::$currentPackage = null;
    }
    return is_array($result) && isset($result['rates']) ? array_values($result['rates']) : [];
  }

  /**
   * Initialises a WooCommerce session and an empty cart without sending cookies.
   * @return void
   */
  protected static function ensureCart(): void
  {
    add_filter('woocommerce_set_cookie_enabled', '__return_false');
    if (!WC()->session) {
      WC()->initialize_session();
    }
    if (!WC()->cart) {
      wc_load_cart();
    }
  }

  /**
   * Re-evaluates the free shipping requirements from the package instead of WC()->cart.
   * @param bool $available WooCommerce's cart based verdict
   * @param array $package the package
   * @param object $method the free shipping method instance
   * @return bool availability
   */
  public static function freeShippingFromPackage(bool $available, array $package, object $method): bool
  {
    $requires = (string) ($method->requires ?? '');
    if ($requires === '') {
      return true;
    }
    $hasCoupon = false;
    foreach ((array) ($package['applied_coupons'] ?? []) as $code) {
      $coupon = new WC_Coupon($code);
      if ($coupon->get_id() > 0 && $coupon->get_free_shipping()) {
        $hasCoupon = true;
        break;
      }
    }
    $total = (float) ($package['cart_subtotal'] ?? 0);
    if (($method->ignore_discounts ?? 'no') === 'no') {
      $total = (float) ($package['contents_cost'] ?? $total);
    }
    if (Pricing::isTaxInclusiveShop()) {
      foreach ((array) ($package['contents'] ?? []) as $content) {
        $total += (float) ($content[($method->ignore_discounts ?? 'no') === 'no' ? 'line_tax' : 'line_subtotal_tax'] ?? 0);
      }
    }
    $hasMinAmount = $total >= (float) ($method->min_amount ?? 0);
    return match ($requires) {
      'min_amount' => $hasMinAmount,
      'coupon' => $hasCoupon,
      'both' => $hasMinAmount && $hasCoupon,
      'either' => $hasMinAmount || $hasCoupon,
      default => true,
    };
  }

  /**
   * Maps a WooCommerce rate to a neutral option.
   * @param WC_Shipping_Rate $rate the rate
   * @param string $currency order currency
   * @return array option
   */
  protected static function rateToOption(WC_Shipping_Rate $rate, string $currency): array
  {
    $taxes = array_map('floatval', (array) $rate->get_taxes());
    $tax = array_sum($taxes);
    [$min, $max] = Settings::deliveryDays();
    return [
      'type' => self::TYPE_SHIPPING,
      'id' => $rate->get_id(),
      'title' => wp_strip_all_tags($rate->get_label()),
      'description' => sprintf(__('Lieferung in %1$d–%2$d Werktagen', 'lbwp'), $min, $max),
      'carrier' => Settings::carrierName(),
      'method_id' => $rate->get_method_id(),
      'instance_id' => (int) $rate->get_instance_id(),
      'cost' => (float) $rate->get_cost(),
      'taxes' => $taxes,
      'amount' => Money::toMinor((float) $rate->get_cost() + (Pricing::isTaxInclusiveShop() ? $tax : 0), $currency),
      'amount_net' => Money::toMinor((float) $rate->get_cost(), $currency),
      'tax' => Money::toMinor($tax, $currency),
      'earliest_delivery_time' => gmdate('Y-m-d\TH:i:s\Z', time() + $min * DAY_IN_SECONDS),
      'latest_delivery_time' => gmdate('Y-m-d\TH:i:s\Z', time() + $max * DAY_IN_SECONDS),
    ];
  }

  /**
   * Builds the fallback option from settings.
   * @param array $fallback ['title' => …, 'amount' => float]
   * @param string $currency order currency
   * @return array option
   */
  protected static function fallbackOption(array $fallback, string $currency): array
  {
    [$min, $max] = Settings::deliveryDays();
    return [
      'type' => self::TYPE_SHIPPING,
      'id' => self::FALLBACK_ID,
      'title' => $fallback['title'],
      'description' => sprintf(__('Lieferung in %1$d–%2$d Werktagen', 'lbwp'), $min, $max),
      'carrier' => Settings::carrierName(),
      'method_id' => self::FALLBACK_ID,
      'instance_id' => 0,
      'cost' => (float) $fallback['amount'],
      'taxes' => [],
      'amount' => Money::toMinor((float) $fallback['amount'], $currency),
      'amount_net' => Money::toMinor((float) $fallback['amount'], $currency),
      'tax' => 0,
      'earliest_delivery_time' => gmdate('Y-m-d\TH:i:s\Z', time() + $min * DAY_IN_SECONDS),
      'latest_delivery_time' => gmdate('Y-m-d\TH:i:s\Z', time() + $max * DAY_IN_SECONDS),
    ];
  }

  /**
   * Builds the digital (no shipping) option.
   * @return array option
   */
  protected static function digitalOption(): array
  {
    return [
      'type' => self::TYPE_DIGITAL,
      'id' => self::DIGITAL_ID,
      'title' => __('Digitale Lieferung', 'lbwp'),
      'description' => __('Sofort nach Zahlungseingang verfügbar', 'lbwp'),
      'carrier' => '',
      'method_id' => self::DIGITAL_ID,
      'instance_id' => 0,
      'cost' => 0.0,
      'taxes' => [],
      'amount' => 0,
      'amount_net' => 0,
      'tax' => 0,
      'earliest_delivery_time' => gmdate('Y-m-d\TH:i:s\Z'),
      'latest_delivery_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
  }
}
