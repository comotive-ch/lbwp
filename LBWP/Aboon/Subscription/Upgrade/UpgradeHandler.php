<?php

namespace LBWP\Aboon\Subscription\Upgrade;

use LBWP\Aboon\Subscription\Api\ChargeLog;
use LBWP\Aboon\Subscription\Api\PayrexxClient;
use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\SubscriptionHelper;
use LBWP\Module\General\Cms\SystemLog;
use Payrexx\Models\Request\Gateway;
use Payrexx\Models\Response\Gateway as GatewayResponse;
use Payrexx\PayrexxException;

/**
 * Handles one-directional subscription upgrades: charges the prorated (time-based) price
 * difference once against the customer's stored Payrexx payment method, then swaps the order's
 * product so future renewals bill the new product's price. Downgrades are out of scope.
 * @package LBWP\Aboon\Subscription\Upgrade
 * @author Michael Sebel <michael@comotive.ch>
 */
class UpgradeHandler
{
  /**
   * Returns the product ids a product can be upgraded to.
   * @param int $productId the current product id
   * @return array<int,int> the allowed upgrade target product ids
   */
  public function getAvailableUpgrades(int $productId): array
  {
    return array_map('intval', (array) get_field('subscription_upgrade_products', $productId));
  }

  /**
   * Calculates the prorated price difference for upgrading to another product, based on the
   * remaining days in the current billing period. Downgrades (a cheaper target) are rejected.
   * @param \WC_Order $order the linked order
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param int $targetProductId the product to upgrade to
   * @return int|null the prorated amount in cents, or null if not a valid upgrade
   */
  public function calculateProration(\WC_Order $order, int $subscriptionPostId, int $targetProductId): ?int
  {
    $currentQuantity = max(1, (int) get_field('current_quantity', $subscriptionPostId));
    $currentDailyRate = ((float) $order->get_total()) / $this->periodLengthDays($subscriptionPostId);

    $targetProduct = wc_get_product($targetProductId);
    if (!$targetProduct instanceof \WC_Product) {
      return null;
    }
    $targetDailyRate = ((float) $targetProduct->get_price() * $currentQuantity) / $this->periodLengthDays($subscriptionPostId);

    if ($targetDailyRate <= $currentDailyRate) {
      return null;
    }

    $nextPayDate = (int) get_field('next_pay_date', $subscriptionPostId);
    $remainingDays = max(0, ($nextPayDate - time()) / DAY_IN_SECONDS);

    return (int) round(($targetDailyRate - $currentDailyRate) * $remainingDays * 100);
  }

  /**
   * Performs the upgrade: charges the prorated difference, and only swaps the billed product on
   * confirmed success.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param int $targetProductId the product to upgrade to
   * @return bool true on success
   */
  public function upgrade(int $subscriptionPostId, int $targetProductId): bool
  {
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    if ($order === null) {
      return false;
    }

    $amountCents = $this->calculateProration($order, $subscriptionPostId, $targetProductId);
    if ($amountCents === null || $amountCents <= 0) {
      return false;
    }

    $pspId = (string) get_field('payrexx_psp_id', $subscriptionPostId);
    $transactionId = $this->chargeProratedDifference($pspId, $amountCents, $order->get_currency());
    if ($transactionId === null) {
      return false;
    }

    (new ChargeLog())->append($subscriptionPostId, [
      'order_id' => $order->get_id(),
      'payrexx_transaction_id' => $transactionId,
      'amount' => $amountCents,
      'currency' => $order->get_currency(),
      'status' => 'confirmed',
      'type' => ChargeLog::TYPE_UPGRADE,
    ]);

    $this->swapProductForFutureRenewals($order, $subscriptionPostId, $targetProductId);

    return true;
  }

  /**
   * Charges the one-off prorated amount against the customer's stored Payrexx payment method.
   * Implemented as a Gateway created against the existing PSP id; this mechanism has no direct
   * precedent in the bundled SDK's usage in this codebase and should be re-verified against the
   * live Payrexx docs/dashboard before go-live (see plan's open questions).
   * @param string $pspId the Payrexx PSP-on-file id
   * @param int $amountCents the amount to charge, in cents
   * @param string $currency the charge currency
   * @return string|null the Payrexx transaction id, or null on failure (logged)
   */
  protected function chargeProratedDifference(string $pspId, int $amountCents, string $currency): ?string
  {
    if ($pspId === '') {
      return null;
    }

    try {
      $gateway = new Gateway();
      $gateway->setAmount($amountCents);
      $gateway->setCurrency($currency);
      $gateway->setPurpose(__('Upgrade Abonnement', 'lbwp'));
      $gateway->setPsp((int) $pspId);
      $gateway->setChargeOnAuthorization(true);

      /** @var GatewayResponse $response */
      $response = PayrexxClient::getInstance()->create($gateway);
      $transactionId = $response->getTransactionId();
      return $transactionId !== null ? (string) $transactionId : null;
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx create(Gateway) for upgrade charge failed: ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Swaps the order's product to the upgrade target and updates the Payrexx subscription's
   * amount so future renewals bill the new product's price.
   * @param \WC_Order $order the linked order
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param int $targetProductId the new product id
   * @return void
   */
  protected function swapProductForFutureRenewals(\WC_Order $order, int $subscriptionPostId, int $targetProductId): void
  {
    $targetProduct = wc_get_product($targetProductId);
    $quantity = max(1, (int) get_field('current_quantity', $subscriptionPostId));

    foreach ($order->get_items() as $item) {
      if (!$item instanceof \WC_Order_Item_Product) {
        continue;
      }

      $item->set_product_id($targetProductId);
      $item->set_name($targetProduct->get_name());
      $item->set_subtotal((float) $targetProduct->get_price() * $quantity);
      $item->set_total((float) $targetProduct->get_price() * $quantity);
      $item->save();
      break;
    }

    $order->calculate_totals();
    $order->save();

    $payrexxSubscriptionId = (string) get_field('payrexx_subscription_id', $subscriptionPostId);
    if ($payrexxSubscriptionId !== '') {
      (new SubscriptionApi())->updateAmount($payrexxSubscriptionId, (int) round($order->get_total() * 100));
    }

    update_field('recurrence_snapshot', get_field('subscription_recurrence', $targetProductId), $subscriptionPostId);
  }

  /**
   * Returns the current billing period's length in days, based on the subscription's recurrence.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return int the period length in days
   */
  protected function periodLengthDays(int $subscriptionPostId): int
  {
    $recurrence = (string) get_field('recurrence_snapshot', $subscriptionPostId);
    return $recurrence === 'year' ? 365 : 30;
  }
}
