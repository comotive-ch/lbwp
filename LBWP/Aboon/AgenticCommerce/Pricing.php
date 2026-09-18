<?php

namespace LBWP\Aboon\AgenticCommerce;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Recalculates taxes and totals on a draft order and renders protocol neutral total lists.
 * WooCommerce stores line totals net plus tax separately, so an inclusive rendering (UCP in
 * tax inclusive markets) and a net + tax rendering (ACP) are both derivable.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Pricing
{
  public const string ITEMS_BASE = 'items_base_amount';
  public const string ITEMS_DISCOUNT = 'items_discount';
  public const string SUBTOTAL = 'subtotal';
  public const string DISCOUNT = 'discount';
  public const string FULFILLMENT = 'fulfillment';
  public const string TAX = 'tax';
  public const string FEE = 'fee';
  public const string TOTAL = 'total';

  /**
   * Recalculates taxes (for the order's address) and totals, then saves the order.
   * @param WC_Order $order the draft order
   * @return void
   */
  public static function refresh(WC_Order $order): void
  {
    $order->set_prices_include_tax(get_option('woocommerce_prices_include_tax') === 'yes');
    $order->calculate_taxes(self::taxLocation($order));
    $order->calculate_totals(false);
  }

  /**
   * Determines the tax location for an order (shipping, billing, shop base).
   * @param WC_Order $order the order
   * @return array country, state, postcode, city
   */
  public static function taxLocation(WC_Order $order): array
  {
    if ($order->get_shipping_country() !== '') {
      return [
        'country' => $order->get_shipping_country(),
        'state' => $order->get_shipping_state(),
        'postcode' => $order->get_shipping_postcode(),
        'city' => $order->get_shipping_city(),
      ];
    }
    if ($order->get_billing_country() !== '') {
      return [
        'country' => $order->get_billing_country(),
        'state' => $order->get_billing_state(),
        'postcode' => $order->get_billing_postcode(),
        'city' => $order->get_billing_city(),
      ];
    }
    return [
      'country' => WC()->countries->get_base_country(),
      'state' => WC()->countries->get_base_state(),
      'postcode' => WC()->countries->get_base_postcode(),
      'city' => WC()->countries->get_base_city(),
    ];
  }

  /**
   * @return bool whether the shop configures prices including tax
   */
  public static function isTaxInclusiveShop(): bool
  {
    return get_option('woocommerce_prices_include_tax') === 'yes';
  }

  /**
   * Renders the order totals as neutral list [['type' => …, 'amount' => minor int], …].
   * @param WC_Order $order the order
   * @param bool $taxInclusiveDisplay fold taxes into the amounts and omit the tax entry
   * @return array totals list
   */
  public static function sessionTotals(WC_Order $order, bool $taxInclusiveDisplay): array
  {
    $currency = $order->get_currency();
    $base = 0.0;
    $discount = 0.0;
    foreach ($order->get_items('line_item') as $item) {
      if (!$item instanceof WC_Order_Item_Product) {
        continue;
      }
      $base += (float) $item->get_subtotal() + ($taxInclusiveDisplay ? (float) $item->get_subtotal_tax() : 0);
      $discount += ((float) $item->get_subtotal() - (float) $item->get_total())
        + ($taxInclusiveDisplay ? ((float) $item->get_subtotal_tax() - (float) $item->get_total_tax()) : 0);
    }
    $fulfillment = (float) $order->get_shipping_total() + ($taxInclusiveDisplay ? (float) $order->get_shipping_tax() : 0);
    $fee = 0.0;
    foreach ($order->get_fees() as $feeItem) {
      $fee += (float) $feeItem->get_total() + ($taxInclusiveDisplay ? (float) $feeItem->get_total_tax() : 0);
    }
    $totals = [
      self::ITEMS_BASE => Money::toMinor($base, $currency),
      self::ITEMS_DISCOUNT => Money::toMinor($discount, $currency),
    ];
    $totals[self::SUBTOTAL] = $totals[self::ITEMS_BASE] - $totals[self::ITEMS_DISCOUNT];
    $totals[self::DISCOUNT] = $totals[self::ITEMS_DISCOUNT];
    $totals[self::FULFILLMENT] = Money::toMinor($fulfillment, $currency);
    $totals[self::FEE] = Money::toMinor($fee, $currency);
    $totals[self::TAX] = $taxInclusiveDisplay ? 0 : Money::toMinor((float) $order->get_total_tax(), $currency);
    $totals[self::TOTAL] = Money::toMinor((float) $order->get_total(), $currency);
    // Absorb rounding differences so the protocol invariant holds exactly
    $delta = $totals[self::TOTAL] - ($totals[self::SUBTOTAL] + $totals[self::FULFILLMENT] + $totals[self::FEE] + $totals[self::TAX]);
    if ($delta !== 0 && abs($delta) <= 2) {
      $target = $taxInclusiveDisplay ? self::SUBTOTAL : self::TAX;
      $totals[$target] += $delta;
      if ($target === self::SUBTOTAL) {
        $totals[self::ITEMS_BASE] += $delta;
      }
    }
    $list = [];
    foreach ($totals as $type => $amount) {
      if ($type === self::TAX && $taxInclusiveDisplay) {
        continue;
      }
      if (in_array($type, [self::FEE, self::DISCOUNT, self::ITEMS_DISCOUNT], true) && $amount === 0) {
        continue;
      }
      $list[] = ['type' => $type, 'amount' => $amount];
    }
    return $list;
  }

  /**
   * Renders the totals of a single line item.
   * @param WC_Order_Item_Product $item the item
   * @param bool $taxInclusiveDisplay fold taxes into the amounts
   * @param string $currency order currency
   * @return array totals list
   */
  public static function lineTotals(WC_Order_Item_Product $item, bool $taxInclusiveDisplay, string $currency): array
  {
    $subtotal = (float) $item->get_subtotal() + ($taxInclusiveDisplay ? (float) $item->get_subtotal_tax() : 0);
    $total = (float) $item->get_total() + ($taxInclusiveDisplay ? (float) $item->get_total_tax() : 0);
    $list = [
      ['type' => self::ITEMS_BASE, 'amount' => Money::toMinor($subtotal, $currency)],
    ];
    $discount = Money::toMinor($subtotal - $total, $currency);
    if ($discount !== 0) {
      $list[] = ['type' => self::ITEMS_DISCOUNT, 'amount' => $discount];
    }
    $list[] = ['type' => self::SUBTOTAL, 'amount' => Money::toMinor($total, $currency)];
    if (!$taxInclusiveDisplay) {
      $list[] = ['type' => self::TAX, 'amount' => Money::toMinor((float) $item->get_total_tax(), $currency)];
    }
    $list[] = ['type' => self::TOTAL, 'amount' => Money::toMinor($total + ($taxInclusiveDisplay ? 0 : (float) $item->get_total_tax()), $currency)];
    return $list;
  }

  /**
   * Returns the unit price of a line item in minor units.
   * @param WC_Order_Item_Product $item the item
   * @param bool $taxInclusiveDisplay include tax
   * @param string $currency order currency
   * @return int unit amount
   */
  public static function unitAmount(WC_Order_Item_Product $item, bool $taxInclusiveDisplay, string $currency): int
  {
    $quantity = max(1, (int) $item->get_quantity());
    $subtotal = (float) $item->get_subtotal() + ($taxInclusiveDisplay ? (float) $item->get_subtotal_tax() : 0);
    return Money::toMinor($subtotal / $quantity, $currency);
  }
}
