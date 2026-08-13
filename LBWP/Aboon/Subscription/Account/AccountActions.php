<?php

namespace LBWP\Aboon\Subscription\Account;

use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\Order\LicenseQuantity;
use LBWP\Aboon\Subscription\Order\OrderLinker;
use LBWP\Aboon\Subscription\PostType;
use LBWP\Aboon\Subscription\SubscriptionHelper;
use LBWP\Aboon\Subscription\Upgrade\UpgradeHandler;

/**
 * Handles the customer-facing actions on a subscription's My Account detail page: change
 * payment method, self-cancel (if allowed), increase seat quantity (if allowed), upgrade.
 * @package LBWP\Aboon\Subscription\Account
 * @author Michael Sebel <michael@comotive.ch>
 */
class AccountActions
{
  public const string ACTION_CHANGE_PAYMENT_METHOD = 'change_payment_method';
  public const string ACTION_CANCEL = 'cancel';
  public const string ACTION_INCREASE_QUANTITY = 'increase_quantity';
  public const string ACTION_UPGRADE = 'upgrade';

  /**
   * Hooks the early request handling, so actions can redirect before any output is sent.
   * @return void
   */
  public function register(): void
  {
    add_action('template_redirect', [$this, 'maybeHandleAction']);
  }

  /**
   * Dispatches a posted subscription action, if present and valid.
   * @return void
   */
  public function maybeHandleAction(): void
  {
    if (!isset($_POST['lbwp_sub_action'], $_POST['subscription_id']) || !is_user_logged_in()) {
      return;
    }

    $subscriptionPostId = (int) $_POST['subscription_id'];
    if (!$this->userOwnsSubscription($subscriptionPostId) || get_post_type($subscriptionPostId) !== PostType::SLUG) {
      return;
    }

    check_admin_referer('lbwp_sub_account_action_' . $subscriptionPostId);

    switch ($_POST['lbwp_sub_action']) {
      case self::ACTION_CHANGE_PAYMENT_METHOD:
        $this->changePaymentMethod($subscriptionPostId);
        break;
      case self::ACTION_CANCEL:
        $this->cancelSubscription($subscriptionPostId);
        break;
      case self::ACTION_INCREASE_QUANTITY:
        $this->increaseQuantity($subscriptionPostId, (int) ($_POST['new_quantity'] ?? 0));
        break;
      case self::ACTION_UPGRADE:
        $this->upgradeSubscription($subscriptionPostId, (int) ($_POST['target_product_id'] ?? 0));
        break;
    }
  }

  /**
   * Redirects the customer straight to a freshly generated Payrexx payment-method link.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  protected function changePaymentMethod(int $subscriptionPostId): void
  {
    $link = (new SubscriptionApi())->buildPaymentMethodUpdateLink((string) $subscriptionPostId);
    if ($link !== null) {
      wp_redirect($link);
      exit;
    }

    $this->redirectBackWithNotice($subscriptionPostId, __('Der Link konnte nicht erstellt werden. Bitte versuche es später erneut.', 'lbwp'), 'error');
  }

  /**
   * Cancels the subscription, if the product allows customer self-cancellation.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  protected function cancelSubscription(int $subscriptionPostId): void
  {
    if (!$this->cancelAllowed($subscriptionPostId)) {
      $this->redirectBackWithNotice($subscriptionPostId, __('Dieses Abonnement kann nicht selbst gekündigt werden.', 'lbwp'), 'error');
      return;
    }

    (new OrderLinker())->cancelSubscription($subscriptionPostId);
    $this->redirectBackWithNotice($subscriptionPostId, __('Das Abonnement wurde gekündigt.', 'lbwp'), 'success');
  }

  /**
   * Increases a subscription's billed quantity, if the product allows resizing.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param int $newQuantity the requested new quantity
   * @return void
   */
  protected function increaseQuantity(int $subscriptionPostId, int $newQuantity): void
  {
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    $productId = $order !== null ? $this->firstProductId($order) : 0;

    if ($productId === 0 || !get_field('allow_subscription_resize', $productId)) {
      $this->redirectBackWithNotice($subscriptionPostId, __('Die Menge kann für dieses Abonnement nicht angepasst werden.', 'lbwp'), 'error');
      return;
    }

    $success = (new LicenseQuantity())->increase($subscriptionPostId, $newQuantity);
    $this->redirectBackWithNotice(
      $subscriptionPostId,
      $success ? __('Die Menge wurde für die nächste Zahlung angepasst.', 'lbwp') : __('Die Menge konnte nicht angepasst werden.', 'lbwp'),
      $success ? 'success' : 'error'
    );
  }

  /**
   * Upgrades the subscription to another product, if it is a valid, configured upgrade target.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param int $targetProductId the product to upgrade to
   * @return void
   */
  protected function upgradeSubscription(int $subscriptionPostId, int $targetProductId): void
  {
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    $productId = $order !== null ? $this->firstProductId($order) : 0;
    $allowedTargets = (array) get_field('subscription_upgrade_products', $productId);

    if (!in_array($targetProductId, array_map('intval', $allowedTargets), true)) {
      $this->redirectBackWithNotice($subscriptionPostId, __('Ungültiges Upgrade-Produkt.', 'lbwp'), 'error');
      return;
    }

    $success = (new UpgradeHandler())->upgrade($subscriptionPostId, $targetProductId);
    $this->redirectBackWithNotice(
      $subscriptionPostId,
      $success ? __('Das Upgrade wurde durchgeführt.', 'lbwp') : __('Das Upgrade konnte nicht durchgeführt werden.', 'lbwp'),
      $success ? 'success' : 'error'
    );
  }

  /**
   * Checks whether a subscription's product allows customer self-cancellation.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return bool true if allowed
   */
  protected function cancelAllowed(int $subscriptionPostId): bool
  {
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    $productId = $order !== null ? $this->firstProductId($order) : 0;
    return $productId > 0 && (bool) get_field('allow_subscription_cancel', $productId);
  }

  /**
   * Checks that the current user owns the order linked to a subscription.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return bool true if the current user is the subscription's customer
   */
  protected function userOwnsSubscription(int $subscriptionPostId): bool
  {
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    return $order !== null && (int) $order->get_customer_id() === get_current_user_id();
  }

  /**
   * Returns the first (subscription) product id referenced by an order.
   * @param \WC_Order $order the order
   * @return int the product id, or 0 if none found
   */
  protected function firstProductId(\WC_Order $order): int
  {
    foreach ($order->get_items() as $item) {
      return (int) $item->get_product_id();
    }

    return 0;
  }

  /**
   * Redirects back to the subscription's My Account detail page with a WooCommerce notice.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param string $message the notice message
   * @param string $type the notice type ("success" or "error")
   * @return void
   */
  protected function redirectBackWithNotice(int $subscriptionPostId, string $message, string $type): void
  {
    wc_add_notice($message, $type);
    wp_safe_redirect(wc_get_account_endpoint_url(AccountEndpoint::ENDPOINT . '/' . $subscriptionPostId));
    exit;
  }
}
