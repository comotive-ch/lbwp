<?php

namespace LBWP\Aboon\Subscription\Order;

use LBWP\Aboon\Subscription\Api\ChargeLog;
use LBWP\Aboon\Subscription\Api\PayrexxClient;
use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\Email\CancellationEmail;
use LBWP\Aboon\Subscription\Gateway\PayrexxSubscriptionGateway;
use LBWP\Aboon\Subscription\Helper;
use LBWP\Aboon\Subscription\PostType;
use LBWP\Aboon\Subscription\SubscriptionHelper;
use LBWP\Module\General\Cms\SystemLog;
use Payrexx\Models\Request\Gateway;
use Payrexx\Models\Response\Gateway as GatewayResponse;
use Payrexx\PayrexxException;

/**
 * Creates and links lbwp-subscription posts to WooCommerce orders: starts the Payrexx checkout
 * for the regular flow, and finalises the subscription once the webhook confirms payment.
 * @package LBWP\Aboon\Subscription\Order
 * @author Michael Sebel <michael@comotive.ch>
 */
class OrderLinker
{
  /**
   * Builds the Payrexx hosted page for a subscription order's first payment. The order stays
   * pending/on-hold until the webhook confirms the charge; nothing is marked paid here.
   * @param \WC_Order $order the order to charge (must contain exactly one subscription product)
   * @return string|null the hosted page URL to redirect the customer to, or null on failure
   */
  public function startCheckout(\WC_Order $order): ?string
  {
    $productId = $this->getSubscriptionProductId($order);
    if ($productId === null) {
      SystemLog::add('AboonSubscription', 'error', 'Order ' . $order->get_id() . ' has no subscription product, cannot start Payrexx checkout.');
      return null;
    }

    $recurrence = (string) get_field('subscription_recurrence', $productId);
    $interval = Helper::recurrenceToIso8601($recurrence);

    try {
      $gateway = new Gateway();
      $gateway->setAmount((int) round($order->get_total() * 100));
      $gateway->setCurrency($order->get_currency());
      $gateway->setPurpose(get_the_title($productId));
      $gateway->setReferenceId((string) $order->get_id());
      $gateway->setSubscriptionState(true);
      $gateway->setSubscriptionInterval($interval);
      $gateway->setSubscriptionPeriod($interval);
      $gateway->setSubscriptionCancellationInterval('P0D');
      $gateway->setSuccessRedirectUrl($order->get_checkout_order_received_url());
      $gateway->setFailedRedirectUrl(wc_get_checkout_url());

      $lookAndFeel = PayrexxClient::getLookAndFeelProfile();
      if ($lookAndFeel !== null) {
        $gateway->setLookAndFeelProfile($lookAndFeel);
      }

      /** @var GatewayResponse $response */
      $response = PayrexxClient::getInstance()->create($gateway);
      return $response->getLink();
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx create(Gateway) for order ' . $order->get_id() . ' failed: ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Finalises a first payment after the webhook has re-fetched and confirmed it server-side.
   * Marks the order paid and creates the linked subscription post with an "initial" charge row.
   * @param int $orderId the WooCommerce order id
   * @param string $transactionId the confirmed Payrexx transaction id
   * @param string $payrexxSubscriptionId the Payrexx subscription id created alongside the payment
   * @param string $pspId the Payrexx PSP-on-file id, needed for later one-off charges
   * @param int $amountCents the confirmed charge amount in cents
   * @param string $currency the charge currency
   * @return void
   */
  public function handleConfirmedFirstPayment(int $orderId, string $transactionId, string $payrexxSubscriptionId, string $pspId, int $amountCents, string $currency): void
  {
    $order = wc_get_order($orderId);
    if (!$order instanceof \WC_Order || $order->is_paid()) {
      return;
    }

    $order->payment_complete($transactionId);

    $subscriptionId = $this->createSubscriptionPost($order, $payrexxSubscriptionId, $pspId);

    (new ChargeLog())->append($subscriptionId, [
      'order_id' => $orderId,
      'payrexx_transaction_id' => $transactionId,
      'amount' => $amountCents,
      'currency' => $currency,
      'status' => 'confirmed',
      'type' => ChargeLog::TYPE_INITIAL,
    ]);
  }

  /**
   * Creates the lbwp-subscription post linked to an order, following the draft-then-meta-then-
   * publish pattern so that transition_post_status hooks see complete meta.
   * @param \WC_Order $order the linked order
   * @param string $payrexxSubscriptionId the Payrexx subscription id
   * @param string $pspId the Payrexx PSP-on-file id
   * @param string $status the initial internal status
   * @return int the created subscription post id
   */
  public function createSubscriptionPost(\WC_Order $order, string $payrexxSubscriptionId, string $pspId, string $status = 'active'): int
  {
    $productId = $this->getSubscriptionProductId($order);
    $recurrence = $productId !== null ? (string) get_field('subscription_recurrence', $productId) : 'month';
    $quantity = $productId !== null ? $this->getSubscriptionItemQuantity($order, $productId) : 1;

    // Reuse an existing draft if the admin already created one via createLinkedSubscriptionDraft()
    // (the no-initial-payment path), instead of creating a duplicate subscription post.
    $postId = $this->findExistingDraftForOrder($order->get_id());
    if ($postId === null) {
      $postId = wp_insert_post([
        'post_type' => PostType::SLUG,
        'post_status' => 'draft',
        'post_title' => sprintf(__('Abo #%d', 'lbwp'), $order->get_id()),
      ]);
    }

    update_field('subscription_status', $status, $postId);
    update_field('payrexx_subscription_id', $payrexxSubscriptionId, $postId);
    update_field('payrexx_psp_id', $pspId, $postId);
    update_field('linked_order_id', $order->get_id(), $postId);
    update_field('recurrence_snapshot', $recurrence, $postId);
    update_field('current_quantity', $quantity, $postId);
    update_post_meta($postId, '_lbwp_sub_customer_id', $order->get_customer_id());

    wp_update_post([
      'ID' => $postId,
      'post_status' => 'publish',
    ]);

    return $postId;
  }

  /**
   * Creates a WooCommerce order in "on-hold" status for a subscription product, without charging
   * anything. Used by the admin to set up a subscription before the customer has paid, so a
   * payment-method link can be sent afterwards (the first real charge happens once completed).
   * @param int $userId the customer's WordPress user id
   * @param int $productId the subscription product id
   * @param int $quantity the quantity (relevant for seat/license products)
   * @return \WC_Order the created order
   */
  public function createAwaitingPaymentOrder(int $userId, int $productId, int $quantity = 1): \WC_Order
  {
    $order = wc_create_order(['customer_id' => $userId]);
    $order->add_product(wc_get_product($productId), $quantity);
    $order->set_payment_method(PayrexxSubscriptionGateway::GATEWAY_ID);
    $order->calculate_totals();
    $order->set_status('on-hold');
    $order->save();

    return $order;
  }

  /**
   * Creates the linked subscription post for an admin-created, not-yet-paid order. Stays a draft
   * (status "awaiting_first_payment") until the customer completes the emailed payment link and
   * the webhook confirms the first charge via handleConfirmedFirstPayment().
   * @param \WC_Order $order the awaiting-payment order
   * @return int the created subscription post id
   */
  public function createLinkedSubscriptionDraft(\WC_Order $order): int
  {
    $productId = $this->getSubscriptionProductId($order);
    $recurrence = $productId !== null ? (string) get_field('subscription_recurrence', $productId) : 'month';
    $quantity = $productId !== null ? $this->getSubscriptionItemQuantity($order, $productId) : 1;

    $postId = wp_insert_post([
      'post_type' => PostType::SLUG,
      'post_status' => 'draft',
      'post_title' => sprintf(__('Abo #%d', 'lbwp'), $order->get_id()),
    ]);

    update_field('subscription_status', Helper::STATUS_AWAITING_FIRST_PAYMENT, $postId);
    update_field('linked_order_id', $order->get_id(), $postId);
    update_field('recurrence_snapshot', $recurrence, $postId);
    update_field('current_quantity', $quantity, $postId);
    update_post_meta($postId, '_lbwp_sub_customer_id', $order->get_customer_id());

    return $postId;
  }

  /**
   * Cancels a subscription on Payrexx and locally, then notifies admin and customer. Shared by
   * both the admin backend action and the customer-facing My Account action.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return bool true if the subscription was cancelled
   */
  public function cancelSubscription(int $subscriptionPostId): bool
  {
    $payrexxSubscriptionId = (string) get_field('payrexx_subscription_id', $subscriptionPostId);
    if ($payrexxSubscriptionId === '' || !(new SubscriptionApi())->cancel($payrexxSubscriptionId)) {
      return false;
    }

    // Only a definitive payment failure moves the WordPress post status to draft;
    // a clean cancellation stays published, just with an updated internal status.
    update_field('subscription_status', Helper::STATUS_CANCELLED, $subscriptionPostId);

    (new CancellationEmail())->trigger($subscriptionPostId);

    return true;
  }

  /**
   * Finds an existing draft subscription post already linked to an order (created ahead of time
   * by createLinkedSubscriptionDraft()), so payment confirmation can publish it instead of
   * creating a duplicate.
   * @param int $orderId the order id
   * @return int|null the draft post id, or null if none exists
   */
  protected function findExistingDraftForOrder(int $orderId): ?int
  {
    $posts = get_posts([
      'post_type' => PostType::SLUG,
      'post_status' => 'draft',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => 'linked_order_id', 'value' => $orderId, 'compare' => '='],
      ],
    ]);

    return $posts[0] ?? null;
  }

  /**
   * Finds the (single) subscription product line item's product id in an order.
   * @param \WC_Order $order the order
   * @return int|null the product id, or null if none found
   */
  protected function getSubscriptionProductId(\WC_Order $order): ?int
  {
    foreach ($order->get_items() as $item) {
      $productId = (int) $item->get_product_id();
      if (SubscriptionHelper::isSubscriptionProduct($productId)) {
        return $productId;
      }
    }

    return null;
  }

  /**
   * Returns the quantity ordered for the subscription product line item.
   * @param \WC_Order $order the order
   * @param int $productId the subscription product id
   * @return int the quantity
   */
  protected function getSubscriptionItemQuantity(\WC_Order $order, int $productId): int
  {
    foreach ($order->get_items() as $item) {
      if ((int) $item->get_product_id() === $productId) {
        return (int) $item->get_quantity();
      }
    }

    return 1;
  }
}
