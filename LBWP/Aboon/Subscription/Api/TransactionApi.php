<?php

namespace LBWP\Aboon\Subscription\Api;

use LBWP\Module\General\Cms\SystemLog;
use Payrexx\Models\Request\Transaction;
use Payrexx\Models\Response\Transaction as TransactionResponse;
use Payrexx\PayrexxException;

/**
 * Wraps every Payrexx SDK call related to the Transaction resource (fetch, refund). No other
 * class talks to \Payrexx\Models\Request\Transaction directly.
 * @package LBWP\Aboon\Subscription\Api
 * @author Michael Sebel <michael@comotive.ch>
 */
class TransactionApi
{
  /**
   * Fetches the authoritative transaction record from Payrexx by id.
   * @param string $transactionId the Payrexx transaction id
   * @return TransactionResponse|null the transaction, or null on failure (logged)
   */
  public function getOne(string $transactionId): ?TransactionResponse
  {
    try {
      $transaction = new Transaction();
      $transaction->setId($transactionId);
      return PayrexxClient::getInstance()->getOne($transaction);
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx getOne(Transaction) failed: ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Refunds a transaction, fully or partially.
   * @param string $transactionId the Payrexx transaction id
   * @param int|null $partialAmountCents amount to refund in cents, or null for a full refund
   * @return TransactionResponse|null the refunded transaction, or null on failure (logged)
   */
  public function refund(string $transactionId, ?int $partialAmountCents = null): ?TransactionResponse
  {
    try {
      $transaction = new Transaction();
      $transaction->setId($transactionId);
      if ($partialAmountCents !== null) {
        $transaction->setAmount($partialAmountCents);
      }
      return PayrexxClient::getInstance()->refund($transaction);
    } catch (PayrexxException $exception) {
      SystemLog::add('AboonSubscription', 'error', 'Payrexx refund(Transaction) failed: ' . $exception->getMessage());
      return null;
    }
  }

  /**
   * Checks whether a transaction can still be refunded (fully or partially).
   * @param string $transactionId the Payrexx transaction id
   * @return bool true if refundable
   */
  public function isRefundable(string $transactionId): bool
  {
    $transaction = $this->getOne($transactionId);
    if ($transaction === null) {
      return false;
    }

    return $transaction->getRefundable() === true || $transaction->getPartiallyRefundable() === true;
  }
}
