<?php

namespace LBWP\Aboon\Subscription\Gateway;

use LBWP\Aboon\Subscription\Helper;
use LBWP\Aboon\Subscription\SubscriptionHelper;

/**
 * Enforces that the cart never mixes subscription and non-subscription products, and that our
 * gateway becomes the only available payment method once a subscription product is in the cart.
 * Active independent of whether the gateway itself is enabled.
 * @package LBWP\Aboon\Subscription\Gateway
 * @author Michael Sebel <michael@comotive.ch>
 */
class CartGuard
{
  /**
   * Hooks the cart validation, gateway filtering and checkout notice.
   * @return void
   */
  public function register(): void
  {
    add_filter('woocommerce_add_to_cart_validation', [$this, 'preventMixedCart'], 10, 3);
    add_filter('woocommerce_available_payment_gateways', [$this, 'restrictToSubscriptionGateway']);
    add_filter('woocommerce_cart_needs_shipping', [$this, 'hideShippingForSubscriptionCart']);
    add_action('woocommerce_before_checkout_form', [$this, 'renderRecurrenceNotice']);
  }

  /**
   * Blocks adding a product to the cart if it would mix subscription and non-subscription items.
   * @param bool $valid whether the add-to-cart is currently considered valid
   * @param int $productId the product being added
   * @param int $quantity the quantity being added
   * @return bool the (possibly updated) validity
   */
  public function preventMixedCart(bool $valid, int $productId, int $quantity): bool
  {
    if (!$valid || WC()->cart === null) {
      return $valid;
    }

    $addingSubscription = SubscriptionHelper::isSubscriptionProduct($productId);
    $cartHasNonSubscription = false;
    $cartSubscriptionProductIds = [];

    foreach (WC()->cart->get_cart() as $item) {
      $itemProductId = (int) $item['product_id'];
      if (SubscriptionHelper::isSubscriptionProduct($itemProductId)) {
        $cartSubscriptionProductIds[$itemProductId] = true;
      } else {
        $cartHasNonSubscription = true;
      }
    }

    // A Payrexx subscription is created per order, so only one distinct subscription
    // product (any quantity) may be checked out at a time; other subscription products
    // or plain products cannot be combined with it.
    $mixesTypes = ($addingSubscription && $cartHasNonSubscription) || (!$addingSubscription && count($cartSubscriptionProductIds) > 0);
    $mixesDifferentSubscriptions = $addingSubscription
      && count($cartSubscriptionProductIds) > 0
      && !isset($cartSubscriptionProductIds[$productId]);

    if ($mixesTypes || $mixesDifferentSubscriptions) {
      wc_add_notice(
        __('Abonnement-Produkte können nicht zusammen mit anderen Produkten im Warenkorb kombiniert werden.', 'lbwp'),
        'error'
      );
      return false;
    }

    return $valid;
  }

  /**
   * Reduces the available payment gateways to only our own once a subscription product is in
   * the cart, transparently hiding the separate woo-payrexx-gateway plugin's gateway too.
   * @param array $gateways the available WooCommerce gateways, keyed by gateway id
   * @return array the (possibly filtered) gateways
   */
  public function restrictToSubscriptionGateway(array $gateways): array
  {
    if (is_admin() && !wp_doing_ajax()) {
      return $gateways;
    }

    if (!$this->cartHasSubscriptionProduct()) {
      return $gateways;
    }

    if (!isset($gateways[PayrexxSubscriptionGateway::GATEWAY_ID])) {
      return $gateways;
    }

    return [PayrexxSubscriptionGateway::GATEWAY_ID => $gateways[PayrexxSubscriptionGateway::GATEWAY_ID]];
  }

  /**
   * Shows a short notice on the checkout page about the recurring nature of the cart's content.
   * @return void
   */
  public function renderRecurrenceNotice(): void
  {
    if (!$this->cartHasSubscriptionProduct() || WC()->cart === null) {
      return;
    }

    foreach (WC()->cart->get_cart() as $item) {
      $productId = (int) $item['product_id'];
      if (!SubscriptionHelper::isSubscriptionProduct($productId)) {
        continue;
      }

      $recurrence = (string) get_field('subscription_recurrence', $productId);
      $lineTotal = (int) round(((float) $item['line_total'] + (float) $item['line_tax']) * 100);
      $amount = Helper::formatAmount($lineTotal, get_woocommerce_currency());

      printf(
        '<div class="woocommerce-info lbwp-subscription-recurrence-notice">%s</div>',
        esc_html(sprintf(
          /* translators: 1: formatted amount, 2: recurrence label (e.g. "Monatlich") */
          __('Dieses Produkt wird %2$s mit %1$s belastet.', 'lbwp'),
          $amount,
          Helper::recurrenceLabel($recurrence)
        ))
      );
    }
  }

  /**
   * Treats a cart containing a subscription product as needing no shipping, same as a purely
   * virtual/digital cart, since subscription products can never be mixed with shippable ones.
   * @param bool $needsShipping whether WooCommerce currently thinks the cart needs shipping
   * @return bool the (possibly overridden) value
   */
  public function hideShippingForSubscriptionCart(bool $needsShipping): bool
  {
    if ($this->cartHasSubscriptionProduct()) {
      return false;
    }

    return $needsShipping;
  }

  /**
   * Checks whether the current cart contains a subscription product.
   * @return bool true if a subscription product is in the cart
   */
  protected function cartHasSubscriptionProduct(): bool
  {
    if (WC()->cart === null) {
      return false;
    }

    foreach (WC()->cart->get_cart() as $item) {
      if (SubscriptionHelper::isSubscriptionProduct((int) $item['product_id'])) {
        return true;
      }
    }

    return false;
  }
}
