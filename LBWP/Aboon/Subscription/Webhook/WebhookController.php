<?php

namespace LBWP\Aboon\Subscription\Webhook;

use LBWP\Aboon\Subscription\Admin\AdminScreen;
use LBWP\Aboon\Subscription\Api\ChargeLog;
use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\Api\TransactionApi;
use LBWP\Aboon\Subscription\Helper;
use LBWP\Aboon\Subscription\Order\OrderLinker;
use LBWP\Aboon\Subscription\PostType;
use LBWP\Module\General\Cms\SystemLog;
use Payrexx\Models\Response\Transaction;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Receives Payrexx webhooks. The bundled SDK has no signature verification, so every payload is
 * treated as an untrusted pointer only: we always re-fetch the authoritative record by id using
 * our own stored credentials before acting on it, never trusting a status value from the payload.
 * @package LBWP\Aboon\Subscription\Webhook
 * @author Michael Sebel <michael@comotive.ch>
 */
class WebhookController
{
  /**
   * Registers the public REST route Payrexx calls.
   * @return void
   */
  public function register(): void
  {
    add_action('rest_api_init', function () {
      register_rest_route('lbwp-subscription/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => [$this, 'handle'],
        'permission_callback' => '__return_true',
      ]);
    });
  }

  /**
   * Handles an incoming webhook call.
   * @param WP_REST_Request $request the incoming request
   * @return WP_REST_Response always a 200, Payrexx expects a quick acknowledgement
   */
  public function handle(WP_REST_Request $request): WP_REST_Response
  {
    $payload = $request->get_json_params();
    if (!is_array($payload)) {
      return new WP_REST_Response(['status' => 'ignored'], 200);
    }

    $transactionId = $this->extractId($payload, 'transaction');
    if ($transactionId !== null) {
      $this->handleTransactionEvent($transactionId);
    }

    $subscriptionId = $this->extractId($payload, 'subscription');
    if ($subscriptionId !== null) {
      $this->handleSubscriptionEvent($subscriptionId);
    }

    return new WP_REST_Response(['status' => 'ok'], 200);
  }

  /**
   * Re-fetches a transaction and, if it's a confirmed first payment or renewal, updates our
   * records accordingly.
   * @param string $transactionId the Payrexx transaction id from the (untrusted) payload
   * @return void
   */
  protected function handleTransactionEvent(string $transactionId): void
  {
    $transaction = (new TransactionApi())->getOne($transactionId);
    if ($transaction === null || $transaction->getStatus() !== Transaction::CONFIRMED) {
      return;
    }

    $referenceId = (string) $transaction->getReferenceId();
    $orderId = (int) $referenceId;
    if ($orderId <= 0) {
      return;
    }

    $order = wc_get_order($orderId);
    if ($order === false) {
      return;
    }

    if (!$order->is_paid()) {
      $this->handleFirstPayment($order, $transaction, $referenceId);
      return;
    }

    $this->handleRenewal($order, $transaction);
  }

  /**
   * Finalises the first payment: discovers the newly created Payrexx subscription id via the
   * reference id, then hands off to OrderLinker.
   * @param \WC_Order $order the order being confirmed
   * @param Transaction $transaction the confirmed transaction
   * @param string $referenceId the reference id (our order id) used to look up the subscription
   * @return void
   */
  protected function handleFirstPayment(\WC_Order $order, Transaction $transaction, string $referenceId): void
  {
    $subscription = (new SubscriptionApi())->findByReferenceId($referenceId);
    if ($subscription === null) {
      SystemLog::add('AboonSubscription', 'error', 'Confirmed transaction ' . $transaction->getId() . ' for order ' . $order->get_id() . ' but no matching Payrexx subscription was found.');
      return;
    }

    (new OrderLinker())->handleConfirmedFirstPayment(
      $order->get_id(),
      (string) $transaction->getId(),
      $subscription->getPspSubscriptionId(),
      (string) $transaction->getPspId(),
      (int) $transaction->getAmount(),
      (string) $transaction->getCurrency()
    );
  }

  /**
   * Appends a renewal charge log row for an already-active subscription.
   * @param \WC_Order $order the originating order (used only to find the linked subscription)
   * @param Transaction $transaction the confirmed renewal transaction
   * @return void
   */
  protected function handleRenewal(\WC_Order $order, Transaction $transaction): void
  {
    $subscriptionPostId = $this->findSubscriptionPostByOrderId($order->get_id());
    if ($subscriptionPostId === null) {
      return;
    }

    $alreadyLogged = (bool) get_transient('lbwp_sub_txn_' . $transaction->getId());
    if ($alreadyLogged) {
      return;
    }

    (new ChargeLog())->append($subscriptionPostId, [
      'order_id' => $order->get_id(),
      'payrexx_transaction_id' => (string) $transaction->getId(),
      'amount' => (int) $transaction->getAmount(),
      'currency' => (string) $transaction->getCurrency(),
      'status' => 'confirmed',
      'type' => ChargeLog::TYPE_RENEWAL,
    ]);
    set_transient('lbwp_sub_txn_' . $transaction->getId(), 1, WEEK_IN_SECONDS);

    $payrexxSubscriptionId = (string) get_field('payrexx_subscription_id', $subscriptionPostId);
    $subscription = (new SubscriptionApi())->getOne($payrexxSubscriptionId);
    if ($subscription !== null) {
      update_field('next_pay_date', $subscription->getNextPayDate(), $subscriptionPostId);
    }
  }

  /**
   * Re-fetches a subscription and updates our mapped status, notifying the admin on definitive failure.
   * @param string $subscriptionId the Payrexx subscription id from the (untrusted) payload
   * @return void
   */
  protected function handleSubscriptionEvent(string $subscriptionId): void
  {
    $subscription = (new SubscriptionApi())->getOne($subscriptionId);
    if ($subscription === null) {
      return;
    }

    $subscriptionPostId = $this->findSubscriptionPostByPayrexxId($subscriptionId);
    if ($subscriptionPostId === null) {
      return;
    }

    $internalStatus = Helper::mapPayrexxStatus($subscription->getStatus());
    update_field('subscription_status', $internalStatus, $subscriptionPostId);
    update_field('next_pay_date', $subscription->getNextPayDate(), $subscriptionPostId);

    if ($internalStatus === Helper::STATUS_FAILED_FINAL) {
      AdminScreen::moveToDraftAndNotifyAdmin($subscriptionPostId);
    }
  }

  /**
   * Finds the lbwp-subscription post linked to a Payrexx subscription id.
   * @param string $payrexxSubscriptionId the Payrexx subscription id
   * @return int|null the post id, or null if not found
   */
  protected function findSubscriptionPostByPayrexxId(string $payrexxSubscriptionId): ?int
  {
    $posts = get_posts([
      'post_type' => PostType::SLUG,
      'post_status' => ['publish', 'draft'],
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => 'payrexx_subscription_id', 'value' => $payrexxSubscriptionId, 'compare' => '='],
      ],
    ]);

    return $posts[0] ?? null;
  }

  /**
   * Finds the lbwp-subscription post linked to a WooCommerce order id.
   * @param int $orderId the order id
   * @return int|null the post id, or null if not found
   */
  protected function findSubscriptionPostByOrderId(int $orderId): ?int
  {
    $posts = get_posts([
      'post_type' => PostType::SLUG,
      'post_status' => ['publish', 'draft'],
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => 'linked_order_id', 'value' => $orderId, 'compare' => '='],
      ],
    ]);

    return $posts[0] ?? null;
  }

  /**
   * Best-effort extraction of an entity id from a Payrexx webhook payload. Payrexx's exact
   * payload shape is not documented in the bundled SDK; this supports the commonly seen
   * {"transaction":{"id":...}} / {"subscription":{"id":...}} shapes and should be re-verified
   * against real webhook deliveries during implementation.
   * @param array<string,mixed> $payload the decoded JSON payload
   * @param string $entity "transaction" or "subscription"
   * @return string|null the id, or null if not present
   */
  protected function extractId(array $payload, string $entity): ?string
  {
    $value = $payload[$entity]['id'] ?? $payload['data'][$entity]['id'] ?? null;
    return $value !== null ? (string) $value : null;
  }
}
