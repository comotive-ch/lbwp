<?php

namespace LBWP\Aboon\Subscription\Api;

use LBWP\Util\Date;
use LBWP\Util\WordPress;

/**
 * Owns the payment/charge log custom table (one row per initial payment, renewal, refund or
 * upgrade charge). A custom table is used instead of an ACF repeater since the log is
 * high-cardinality and needs indexed, set-based queries (see plan §3).
 * @package LBWP\Aboon\Subscription\Api
 * @author Michael Sebel <michael@comotive.ch>
 */
class ChargeLog
{
  public const string TYPE_INITIAL = 'initial';
  public const string TYPE_RENEWAL = 'renewal';
  public const string TYPE_REFUND = 'refund';
  public const string TYPE_UPGRADE = 'upgrade';

  /**
   * @var string option key storing the currently installed table schema version
   */
  protected const string SCHEMA_VERSION_OPTION = 'lbwp_subscription_charges_schema_version';

  /**
   * @var int bump this to trigger a dbDelta() re-run on the charge-log table
   */
  protected const int SCHEMA_VERSION = 1;

  /**
   * Creates or upgrades the charge-log table, version-gated so dbDelta only runs after a bump.
   * @return void
   */
  public function register(): void
  {
    if ((int) get_option(self::SCHEMA_VERSION_OPTION, 0) === self::SCHEMA_VERSION) {
      return;
    }

    $db = WordPress::getDb();
    $table = $this->tableName();
    $charset = $db->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      subscription_id BIGINT UNSIGNED NOT NULL,
      order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      payrexx_transaction_id VARCHAR(64) NOT NULL DEFAULT '',
      amount INT NOT NULL DEFAULT 0,
      currency VARCHAR(8) NOT NULL DEFAULT '',
      status VARCHAR(32) NOT NULL DEFAULT '',
      type VARCHAR(16) NOT NULL DEFAULT 'renewal',
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY subscription_id (subscription_id),
      KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
  }

  /**
   * Appends one row to the charge log.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param array{order_id?:int,payrexx_transaction_id?:string,amount?:int,currency?:string,status?:string,type?:string} $row the row data
   * @return int the inserted row id
   */
  public function append(int $subscriptionPostId, array $row): int
  {
    $db = WordPress::getDb();
    $db->insert($this->tableName(), [
      'subscription_id' => $subscriptionPostId,
      'order_id' => (int) ($row['order_id'] ?? 0),
      'payrexx_transaction_id' => (string) ($row['payrexx_transaction_id'] ?? ''),
      'amount' => (int) ($row['amount'] ?? 0),
      'currency' => (string) ($row['currency'] ?? ''),
      'status' => (string) ($row['status'] ?? ''),
      'type' => (string) ($row['type'] ?? self::TYPE_RENEWAL),
      'created_at' => Date::getTime(Date::SQL_DATETIME, time()),
    ]);

    return (int) $db->insert_id;
  }

  /**
   * Returns all charge log rows for a subscription, newest first.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return array<int,array<string,mixed>> the rows
   */
  public function getForSubscription(int $subscriptionPostId): array
  {
    $db = WordPress::getDb();
    $table = $this->tableName();

    return $db->get_results($db->prepare('
      SELECT * FROM ' . $table . ' WHERE subscription_id = %d ORDER BY created_at DESC
    ', $subscriptionPostId), ARRAY_A);
  }

  /**
   * Marks a charge log row as refunded (fully or partially).
   * @param int $rowId the charge log row id
   * @param string $status the new status, e.g. "refunded" or "partially-refunded"
   * @return void
   */
  public function markRefunded(int $rowId, string $status): void
  {
    $db = WordPress::getDb();
    $db->update($this->tableName(), ['status' => $status], ['id' => $rowId]);
  }

  /**
   * @return string the fully prefixed table name
   */
  protected function tableName(): string
  {
    return WordPress::getDb()->prefix . 'lbwp_subscription_charges';
  }
}
