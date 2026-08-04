<?php

namespace LBWP\Aboon\Subscription\Order;

use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\SubscriptionHelper;

/**
 * Handles quantity increases for seat/license-style subscription products (e.g. "per user"
 * licenses): recomputes the full billed amount from the line total (never the raw unit price,
 * which would drop the quantity multiplication) and pushes it to Payrexx for the next renewal.
 * @package LBWP\Aboon\Subscription\Order
 * @author Michael Sebel <michael@comotive.ch>
 */
class LicenseQuantity
{
  /**
   * Increases the billed quantity of a subscription, effective from its next renewal. Only
   * increases are allowed; the requested quantity is always validated server-side.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param int $newQuantity the requested new quantity
   * @return bool true on success
   */
  public function increase(int $subscriptionPostId, int $newQuantity): bool
  {
    $currentQuantity = (int) get_field('current_quantity', $subscriptionPostId);
    if ($newQuantity <= $currentQuantity) {
      return false;
    }

    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    $item = $order !== null ? $this->getSubscriptionItem($order) : null;
    if ($order === null || $item === null) {
      return false;
    }

    $product = $item->get_product();
    if (!$product instanceof \WC_Product) {
      return false;
    }

    $unitPrice = (float) $product->get_price();
    $newAmountCents = (int) round($unitPrice * $newQuantity * 100);

    $payrexxSubscriptionId = (string) get_field('payrexx_subscription_id', $subscriptionPostId);
    if ($payrexxSubscriptionId === '' || !(new SubscriptionApi())->updateAmount($payrexxSubscriptionId, $newAmountCents)) {
      return false;
    }

    update_field('current_quantity', $newQuantity, $subscriptionPostId);

    // Keep the linked order's line item in sync so the order stays an accurate record of the
    // currently billed terms, not just the terms at initial signup.
    $item->set_quantity($newQuantity);
    $item->save();
    $order->calculate_totals();
    $order->save();

    return true;
  }

  /**
   * Finds the order's subscription product line item.
   * @param \WC_Order $order the order
   * @return \WC_Order_Item_Product|null the item, or null if none found
   */
  protected function getSubscriptionItem(\WC_Order $order): ?\WC_Order_Item_Product
  {
    foreach ($order->get_items() as $item) {
      if ($item instanceof \WC_Order_Item_Product) {
        return $item;
      }
    }

    return null;
  }
}
