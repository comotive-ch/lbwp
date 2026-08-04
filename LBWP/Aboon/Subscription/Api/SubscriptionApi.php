<?php

namespace LBWP\Aboon\Subscription\Api;

use LBWP\Module\General\Cms\SystemLog;
use Payrexx\Models\Request\Gateway;
use Payrexx\Models\Request\Subscription;
use Payrexx\Models\Response\Gateway as GatewayResponse;
use Payrexx\Models\Response\Subscription as SubscriptionResponse;
use Payrexx\PayrexxException;

/**
 * Wraps every Payrexx SDK call related to the standalone Subscription resource, plus building
 * the hosted "add/update payment method" page link. No other class talks to
 * \Payrexx\Models\Request\Subscription directly.
 * @package LBWP\Aboon\Subscription\Api
 * @author Michael Sebel <michael@comotive.ch>
 */
class SubscriptionApi
{
  /**
   * Fetches the authoritative subscription record from Payrexx by id. Always re-fetches instead
   * of trusting webhook payloads, since the bundled SDK has no signature verification.
   * @param string $payrexxSubscriptionId the Payrexx subscription id
   * @return SubscriptionResponse|null the subscription, or null on failure (logged)
   */
  public function getOne(string $payrexxSubscriptionId): ?SubscriptionResponse
  {
    try {
      $subscription = new Subscription();
      $subscription->setId($payrexxSubscriptionId);
      return PayrexxClient::getInstance()->getOne($subscription);
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx getOne(Subscription) failed: ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Finds the Payrexx subscription created for a given reference id (our WooCommerce order id),
   * used right after a confirmed first-payment webhook to discover the subscription id, since
   * the Transaction response itself does not carry it.
   * @param string $referenceId the reference id passed when creating the Gateway/checkout
   * @return SubscriptionResponse|null the matching subscription, or null if none found (logged on error)
   */
  public function findByReferenceId(string $referenceId): ?SubscriptionResponse
  {
    try {
      $filter = new Subscription();
      $filter->setReferenceId($referenceId);
      $filter->setLimit(1);
      $filter->setOrderByStartDate('DESC');
      $results = PayrexxClient::getInstance()->getAll($filter);
      return is_array($results) && count($results) > 0 ? $results[0] : null;
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx getAll(Subscription) by referenceId failed: ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Updates the amount that will be billed on the subscription's next renewal (seat resize / upgrade).
   * @param string $payrexxSubscriptionId the Payrexx subscription id
   * @param int $amountCents the new full amount in cents
   * @return bool true on success
   */
  public function updateAmount(string $payrexxSubscriptionId, int $amountCents): bool
  {
    try {
      $subscription = new Subscription();
      $subscription->setId($payrexxSubscriptionId);
      $subscription->setAmount($amountCents);
      PayrexxClient::getInstance()->update($subscription);
      return true;
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx update(Subscription) failed: ' . $exception->getMessage());
      return false;
    }
  }

  /**
   * Cancels the subscription on Payrexx's side.
   * @param string $payrexxSubscriptionId the Payrexx subscription id
   * @return bool true on success
   */
  public function cancel(string $payrexxSubscriptionId): bool
  {
    try {
      $subscription = new Subscription();
      $subscription->setId($payrexxSubscriptionId);
      PayrexxClient::getInstance()->cancel($subscription);
      return true;
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx cancel(Subscription) failed: ' . $exception->getMessage());
      return false;
    }
  }

  /**
   * Builds a hosted Payrexx page that lets the customer add or update their payment method.
   * Implemented as a nominal (CHF 1.00) pre-authorization that is never captured, since the
   * bundled SDK exposes no dedicated "update payment method" page type. This mechanism should
   * be re-verified against the live Payrexx dashboard/docs before go-live.
   * @param string $referenceId our own correlation id (e.g. subscription post id), echoed in webhooks
   * @param string $purpose text shown to the customer on the hosted page
   * @return string|null the hosted page URL, or null on failure (logged)
   */
  public function buildPaymentMethodUpdateLink(string $referenceId, string $purpose = 'Zahlungsmethode hinterlegen'): ?string
  {
    try {
      $gateway = new Gateway();
      $gateway->setAmount(100);
      $gateway->setCurrency(PayrexxClient::getCurrency());
      $gateway->setPurpose($purpose);
      $gateway->setReferenceId($referenceId);
      $gateway->setPreAuthorization(true);
      $gateway->setChargeOnAuthorization(false);

      $lookAndFeel = PayrexxClient::getLookAndFeelProfile();
      if ($lookAndFeel !== null) {
        $gateway->setLookAndFeelProfile($lookAndFeel);
      }

      /** @var GatewayResponse $response */
      $response = PayrexxClient::getInstance()->create($gateway);
      return $response->getLink();
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx create(Gateway) for payment method link failed: ' . $exception->getMessage());
      return null;
    }
  }
}
