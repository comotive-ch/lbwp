<?php

namespace LBWP\Aboon\Subscription;

use LBWP\Aboon\Subscription\Api\ChargeLog;

/**
 * Internal plumbing shared by the subscription feature: recurrence/ISO-8601 mapping, amount
 * formatting and Payrexx status mapping. Not meant as a public developer API, see SubscriptionHelper
 * for that.
 * @package LBWP\Aboon\Subscription
 * @author Michael Sebel <michael@comotive.ch>
 */
class Helper
{
  /**
   * @var array<string,string> internal subscription_status values considered "still active"
   */
  public const array ACTIVE_STATUSES = ['active', 'past_due'];

  /**
   * @var string internal status meaning payment definitively/finally failed after Payrexx dunning
   */
  public const string STATUS_FAILED_FINAL = 'failed_final';

  /**
   * @var string internal status for a cleanly cancelled subscription
   */
  public const string STATUS_CANCELLED = 'cancelled';

  /**
   * @var string internal status for a subscription that is waiting for its first payment
   */
  public const string STATUS_AWAITING_FIRST_PAYMENT = 'awaiting_first_payment';

  /**
   * Converts our recurrence choice value to an ISO-8601 duration usable by the Payrexx SDK.
   * @param string $recurrence one of "month", "year" (or a future extension of the choice list)
   * @return string ISO-8601 duration, e.g. "P1M"
   */
  public static function recurrenceToIso8601(string $recurrence): string
  {
    return match ($recurrence) {
      'year' => 'P1Y',
      'week' => 'P1W',
      default => 'P1M',
    };
  }

  /**
   * Maps a raw Payrexx subscription/gateway status string to our internal, stable vocabulary.
   * The exact Payrexx status vocabulary must be confirmed against the live dashboard/API docs;
   * unmapped values fall back to being treated as active to avoid prematurely cutting off access.
   * @param string $payrexxStatus the raw status as returned by the Payrexx SDK
   * @return string one of the internal status constants
   */
  public static function mapPayrexxStatus(string $payrexxStatus): string
  {
    return match (strtolower($payrexxStatus)) {
      'cancelled', 'canceled' => self::STATUS_CANCELLED,
      'expired', 'uncollectible', 'failed' => self::STATUS_FAILED_FINAL,
      'due', 'overdue', 'past_due' => 'past_due',
      default => 'active',
    };
  }

  /**
   * Formats an amount in Rappen/cents to a human-readable string with currency.
   * @param int $amountCents amount in the smallest currency unit
   * @param string $currency ISO currency code, e.g. "CHF"
   * @return string formatted amount, e.g. "CHF 19.90"
   */
  public static function formatAmount(int $amountCents, string $currency): string
  {
    return $currency . ' ' . number_format($amountCents / 100, 2, '.', "'");
  }

  /**
   * Returns the human-readable German label for a recurrence value.
   * @param string $recurrence one of "month", "year"
   * @return string the label, e.g. "Monatlich"
   */
  public static function recurrenceLabel(string $recurrence): string
  {
    return match ($recurrence) {
      'year' => __('Jährlich', 'lbwp'),
      'week' => __('Wöchentlich', 'lbwp'),
      default => __('Monatlich', 'lbwp'),
    };
  }

  /**
   * Returns the human-readable German label for an internal subscription_status value.
   * @param string $status one of the internal status constants (or "active"/"past_due")
   * @return string the label, e.g. "Aktiv"
   */
  public static function statusLabel(string $status): string
  {
    return match ($status) {
      'active' => __('Aktiv', 'lbwp'),
      'past_due' => __('Zahlung überfällig', 'lbwp'),
      self::STATUS_CANCELLED => __('Gekündigt', 'lbwp'),
      self::STATUS_FAILED_FINAL => __('Zahlung endgültig fehlgeschlagen', 'lbwp'),
      self::STATUS_AWAITING_FIRST_PAYMENT => __('Wartet auf Erstzahlung', 'lbwp'),
      default => $status,
    };
  }

  /**
   * Returns the human-readable German label for a charge log row's type.
   * @param string $type one of the ChargeLog::TYPE_* constants
   * @return string the label, e.g. "Erstzahlung"
   */
  public static function chargeTypeLabel(string $type): string
  {
    return match ($type) {
      ChargeLog::TYPE_INITIAL => __('Erstzahlung', 'lbwp'),
      ChargeLog::TYPE_RENEWAL => __('Verlängerung', 'lbwp'),
      ChargeLog::TYPE_REFUND => __('Rückerstattung', 'lbwp'),
      ChargeLog::TYPE_UPGRADE => __('Upgrade', 'lbwp'),
      default => $type,
    };
  }

  /**
   * Returns the human-readable German label for a charge log row's raw Payrexx status.
   * @param string $status the raw status, e.g. "confirmed", "refunded"
   * @return string the label, e.g. "Bestätigt"
   */
  public static function chargeStatusLabel(string $status): string
  {
    return match (strtolower($status)) {
      'confirmed' => __('Bestätigt', 'lbwp'),
      'refunded' => __('Zurückerstattet', 'lbwp'),
      'partially-refunded', 'partially_refunded' => __('Teilweise zurückerstattet', 'lbwp'),
      default => $status,
    };
  }
}
