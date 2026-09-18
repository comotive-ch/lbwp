<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Helper\WooCommerce\Util;
use LBWP\Util\WordPress;
use WC_Order;

/**
 * Creates and finds checkout sessions (checkout-draft orders), owns the idempotency records and
 * the in-flight lock. Durable in the database, never transient-only.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class SessionStore
{
  public const string META_IDEMPOTENCY = '_agentic_idempotency';
  public const string META_IDEMPOTENCY_CREATE = '_agentic_idempotency_create';
  public const string META_INFLIGHT = '_agentic_inflight';
  public const string CACHE_GROUP = 'agentic';
  public const int LOCK_TTL = 30;
  public const int IDEMPOTENCY_ENTRIES = 20;
  /**
   * @var array statuses a session order may have
   */
  public const array STATUSES = ['wc-checkout-draft', 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];

  /**
   * Creates a new session (draft order) for a protocol.
   * @param string $protocol acp|ucp
   * @param string $currency ISO 4217
   * @param string $createKey idempotency key of the create request (protocol prefixed)
   * @param string $platform calling platform identifier
   * @return Session the session (state not yet saved)
   */
  public static function create(string $protocol, string $currency, string $createKey, string $platform = ''): Session
  {
    $order = wc_create_order([
      'status' => 'checkout-draft',
      'created_via' => 'agentic_' . $protocol,
      'currency' => strtoupper($currency),
    ]);
    $order->update_meta_data(Session::META_PROTOCOL, $protocol);
    $order->update_meta_data(Session::META_SESSION_ID, $protocol . '_' . bin2hex(random_bytes(16)));
    $order->update_meta_data(self::META_INFLIGHT, '');
    $order->update_meta_data(self::META_IDEMPOTENCY_CREATE, $createKey);
    if ($platform !== '') {
      $order->update_meta_data(Session::META_PLATFORM, sanitize_text_field($platform));
    }
    $order->set_prices_include_tax(Pricing::isTaxInclusiveShop());
    $order->save();
    $session = new Session($order);
    $session->set('protocol', $protocol);
    $session->set('status', Session::STATUS_INCOMPLETE);
    $session->set('created', time());
    return $session;
  }

  /**
   * Finds a session by its public id.
   * @param string $sessionId public id
   * @return Session|null the session
   */
  public static function find(string $sessionId): ?Session
  {
    $sessionId = sanitize_text_field($sessionId);
    if ($sessionId === '' || !preg_match('/^(acp|ucp)_[a-f0-9]{32}$/', $sessionId)) {
      return null;
    }
    $order = self::findOrder(Session::META_SESSION_ID, $sessionId);
    return $order === null ? null : new Session($order);
  }

  /**
   * Finds the session created by an idempotency key (create endpoint replay).
   * @param string $createKey protocol prefixed key
   * @param int $limit number of candidates
   * @return Session[] sessions ordered by id ascending
   */
  public static function findByIdempotencyKey(string $createKey, int $limit = 1): array
  {
    $orders = wc_get_orders([
      'type' => 'shop_order',
      'limit' => $limit,
      'orderby' => 'ID',
      'order' => 'ASC',
      'return' => 'objects',
      'status' => self::STATUSES,
      'meta_key' => self::META_IDEMPOTENCY_CREATE,
      'meta_value' => $createKey,
    ]);
    return array_map(fn(WC_Order $o) => new Session($o), array_values(array_filter((array) $orders, fn($o) => $o instanceof WC_Order)));
  }

  /**
   * Meta lookup of a session order.
   * @param string $metaKey meta key
   * @param string $metaValue meta value
   * @return WC_Order|null the order
   */
  protected static function findOrder(string $metaKey, string $metaValue): ?WC_Order
  {
    $orders = wc_get_orders([
      'type' => 'shop_order',
      'limit' => 1,
      'return' => 'objects',
      'status' => self::STATUSES,
      'meta_key' => $metaKey,
      'meta_value' => $metaValue,
    ]);
    foreach ((array) $orders as $order) {
      if ($order instanceof WC_Order) {
        return $order;
      }
    }
    return null;
  }

  /**
   * Acquires the in-flight lock of a session (object cache + atomic DB claim).
   * @param WC_Order $order the order
   * @return bool true when acquired
   */
  public static function lock(WC_Order $order): bool
  {
    $now = time();
    if (!wp_cache_add('inflight_' . $order->get_id(), $now, self::CACHE_GROUP, self::LOCK_TTL)) {
      return false;
    }
    $db = WordPress::getDb();
    [$table, $column] = self::metaTable();
    $affected = $db->query($db->prepare(
      "UPDATE {$table} SET meta_value = %s WHERE {$column} = %d AND meta_key = %s AND (meta_value = '' OR CAST(meta_value AS UNSIGNED) < %d)",
      (string) $now, $order->get_id(), self::META_INFLIGHT, $now - self::LOCK_TTL
    ));
    if ($affected === 1) {
      return true;
    }
    // Row may be missing for legacy sessions: insert once, then claim
    $exists = (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = %d AND meta_key = %s", $order->get_id(), self::META_INFLIGHT));
    if ($exists === 0) {
      $db->insert($table, [$column => $order->get_id(), 'meta_key' => self::META_INFLIGHT, 'meta_value' => (string) $now]);
      return true;
    }
    wp_cache_delete('inflight_' . $order->get_id(), self::CACHE_GROUP);
    return false;
  }

  /**
   * Releases the in-flight lock.
   * @param WC_Order $order the order
   * @return void
   */
  public static function unlock(WC_Order $order): void
  {
    $db = WordPress::getDb();
    [$table, $column] = self::metaTable();
    $db->query($db->prepare("UPDATE {$table} SET meta_value = '' WHERE {$column} = %d AND meta_key = %s", $order->get_id(), self::META_INFLIGHT));
    wp_cache_delete('inflight_' . $order->get_id(), self::CACHE_GROUP);
  }

  /**
   * Returns the meta table and id column for orders (HPOS aware).
   * @return array [table, id column]
   */
  protected static function metaTable(): array
  {
    $db = WordPress::getDb();
    if (Util::isHposActive()) {
      return [$db->prefix . 'wc_orders_meta', 'order_id'];
    }
    return [$db->postmeta, 'post_id'];
  }

  /**
   * Reads the stored idempotency record of an endpoint + key.
   * @param WC_Order $order the order
   * @param string $recordKey "<endpoint>:<key>"
   * @return array|null record with body_sha, status, response, headers, time
   */
  public static function getIdempotencyRecord(WC_Order $order, string $recordKey): ?array
  {
    $map = json_decode((string) $order->get_meta(self::META_IDEMPOTENCY), true);
    return is_array($map) && isset($map[$recordKey]) && is_array($map[$recordKey]) ? $map[$recordKey] : null;
  }

  /**
   * Stores an idempotency record (keeps the newest entries only).
   * @param WC_Order $order the order
   * @param string $recordKey "<endpoint>:<key>"
   * @param array $record body_sha, status, response, headers, time
   * @return void
   */
  public static function putIdempotencyRecord(WC_Order $order, string $recordKey, array $record): void
  {
    $map = json_decode((string) $order->get_meta(self::META_IDEMPOTENCY), true);
    $map = is_array($map) ? $map : [];
    $map[$recordKey] = $record;
    uasort($map, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
    $map = array_slice($map, 0, self::IDEMPOTENCY_ENTRIES, true);
    $order->update_meta_data(self::META_IDEMPOTENCY, wp_json_encode($map));
    $order->save_meta_data();
  }

  /**
   * Deletes agentic draft sessions untouched for two days (safety net besides WooCommerce's own
   * draft cleanup, which depends on Action Scheduler).
   * @return void
   */
  public static function cleanupExpired(): void
  {
    $orders = wc_get_orders([
      'type' => 'shop_order',
      'limit' => 100,
      'return' => 'objects',
      'status' => 'wc-checkout-draft',
      'date_modified' => '<' . (time() - 2 * DAY_IN_SECONDS),
      'meta_key' => Session::META_PROTOCOL,
      'meta_compare' => 'EXISTS',
    ]);
    foreach ((array) $orders as $order) {
      if ($order instanceof WC_Order) {
        $order->delete(true);
      }
    }
  }
}
