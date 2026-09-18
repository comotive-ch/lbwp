<?php

namespace LBWP\Aboon\AgenticCommerce\Notification;

use LBWP\Aboon\AgenticCommerce\Enum\Ucp;
use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\OrderFactory;
use LBWP\Aboon\AgenticCommerce\Pricing;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\Settings;
use LBWP\Aboon\AgenticCommerce\Shipping;
use LBWP\Aboon\AgenticCommerce\Signature;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Pushes UCP order entities (current-state snapshots) to Google's order event endpoint: created
 * after completion, shipped and delivered afterwards, adjustments on refunds and cancellations.
 * Requests carry the API key, Standard Webhooks headers and an RFC 9421 signature.
 * @package LBWP\Aboon\AgenticCommerce\Notification
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class UcpOrderEvents
{
  public const string META_EVENTS = '_agentic_order_events';
  public const string META_CREATED_SENT = '_agentic_ucp_created';
  public const string GOOGLE_ENDPOINT = 'https://shoppingdataintegration.googleapis.com/v1/webhooks/partners/%s/events/order';

  /**
   * Registers the order hooks.
   * @return void
   */
  public function register(): void
  {
    add_action('aboon_agentic_ucp_order_created', [$this, 'onCreated'], 10, 2);
    add_action('aboon_agentic_order_shipped', [$this, 'onShipped'], 10, 2);
    add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 4);
    add_action('woocommerce_order_refunded', [$this, 'onRefunded'], 10, 2);
  }

  /**
   * Sends the created snapshot.
   * @param WC_Order $order the order
   * @param Session $session the session
   * @return void
   */
  public function onCreated(WC_Order $order, Session $session): void
  {
    if (!self::applies($order)) {
      return;
    }
    $order->update_meta_data(self::META_CREATED_SENT, '1');
    $order->save_meta_data();
    self::push($order, 'created');
  }

  /**
   * Stores tracking data and sends the shipped event.
   * @param int $orderId order id
   * @param array|string $tracking ['number' => …, 'carrier' => …, 'url' => …] or number
   * @return void
   */
  public function onShipped(int $orderId, array|string $tracking = []): void
  {
    $order = wc_get_order($orderId);
    if (!$order instanceof WC_Order || !self::applies($order)) {
      return;
    }
    $tracking = is_string($tracking) ? ['number' => $tracking] : $tracking;
    $order->update_meta_data(AcpOrderWebhook::META_TRACKING, wp_json_encode(array_map('sanitize_text_field', array_map('strval', $tracking))));
    $order->update_meta_data('_agentic_shipped_at', (string) time());
    $order->save_meta_data();
    if ($order->get_meta(self::META_CREATED_SENT) === '1') {
      self::push($order, 'shipped');
    }
  }

  /**
   * Sends delivered on completion and a cancellation adjustment on cancel.
   * @param int $orderId order id
   * @param string $from old status
   * @param string $to new status
   * @param WC_Order $order the order
   * @return void
   */
  public function onStatusChanged(int $orderId, string $from, string $to, WC_Order $order): void
  {
    if (!self::applies($order) || $order->get_meta(self::META_CREATED_SENT) !== '1') {
      return;
    }
    if ($to === 'completed') {
      self::push($order, 'delivered');
    } elseif (in_array($to, ['cancelled', 'failed'], true)) {
      self::push($order, 'canceled');
    }
  }

  /**
   * Sends a refund adjustment.
   * @param int $orderId order id
   * @param int $refundId refund id
   * @return void
   */
  public function onRefunded(int $orderId, int $refundId): void
  {
    $order = wc_get_order($orderId);
    if ($order instanceof WC_Order && self::applies($order) && $order->get_meta(self::META_CREATED_SENT) === '1') {
      self::push($order, 'refunded');
    }
  }

  /**
   * Whether an order belongs to UCP and events can be delivered.
   * @param WC_Order $order the order
   * @return bool applies
   */
  public static function applies(WC_Order $order): bool
  {
    return $order->get_meta(Session::META_PROTOCOL) === 'ucp' && self::endpointUrl() !== '';
  }

  /**
   * Returns the event endpoint (Google partner endpoint by default, filterable).
   * @return string url or empty when not configured
   */
  public static function endpointUrl(): string
  {
    $partner = Settings::ucpPartnerId();
    $url = $partner !== '' ? sprintf(self::GOOGLE_ENDPOINT, rawurlencode($partner)) : '';
    return (string) apply_filters('aboon_agentic_ucp_events_url', $url);
  }

  /**
   * Builds the snapshot for an event and queues it with a monotonic timestamp.
   * @param WC_Order $order the order
   * @param string $event created|shipped|delivered|canceled|refunded
   * @return void
   */
  public static function push(WC_Order $order, string $event): void
  {
    $log = json_decode((string) $order->get_meta(self::META_EVENTS), true);
    $log = is_array($log) ? $log : ['last_ts' => 0, 'events' => []];
    $timestamp = max(time(), (int) ($log['last_ts'] ?? 0) + 1);
    $log['last_ts'] = $timestamp;
    $log['events'][] = ['event' => $event, 'ts' => $timestamp];
    $log['events'] = array_slice($log['events'], -50);
    $order->update_meta_data(self::META_EVENTS, wp_json_encode($log));
    $order->save_meta_data();
    $payload = self::orderEntity($order, $event, $timestamp);
    Queue::send(Queue::CHANNEL_UCP, $order->get_id(), $event, $payload, ['timestamp' => $timestamp, 'webhook_id' => 'evt_' . $order->get_id() . '_' . $timestamp]);
  }

  /**
   * Delivers a queued event with API key, Standard Webhooks headers and RFC 9421 signature.
   * @param array $data row data
   * @return array ok, status, error
   */
  public static function deliver(array $data): array
  {
    $url = self::endpointUrl();
    if ($url === '') {
      return ['ok' => true, 'status' => 0, 'error' => ''];
    }
    $body = wp_json_encode($data['payload'] ?? []);
    $meta = (array) ($data['meta'] ?? []);
    $extra = [
      'UCP-Agent' => 'profile="' . get_bloginfo('url') . '/.well-known/ucp"',
      'Webhook-Id' => (string) ($meta['webhook_id'] ?? ('evt_' . ($data['order_id'] ?? 0) . '_' . time())),
      'Webhook-Timestamp' => (string) ($meta['timestamp'] ?? time()),
    ];
    $headers = Signature::signRfc9421('POST', $url, $body, $extra);
    $apiKey = Settings::ucpEventsApiKey();
    if ($apiKey !== '') {
      $headers['X-Goog-Api-Key'] = $apiKey;
    }
    $response = wp_remote_post($url, ['timeout' => 10, 'headers' => $headers, 'body' => $body]);
    if (is_wp_error($response)) {
      return ['ok' => false, 'status' => 0, 'error' => $response->get_error_message()];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => $status >= 300 ? 'HTTP ' . $status . ' ' . substr((string) wp_remote_retrieve_body($response), 0, 200) : ''];
  }

  /**
   * Builds the UCP order entity (current-state snapshot).
   * @param WC_Order $order the order
   * @param string $event event name
   * @param int $timestamp event timestamp
   * @return array order entity
   */
  public static function orderEntity(WC_Order $order, string $event, int $timestamp): array
  {
    $currency = $order->get_currency();
    $inclusive = Pricing::isTaxInclusiveShop();
    $delivered = $event === 'delivered' || $order->get_status() === 'completed';
    $shipped = $delivered || $event === 'shipped' || $order->get_meta('_agentic_shipped_at') !== '';
    $canceled = $event === 'canceled' || in_array($order->get_status(), ['cancelled', 'failed', 'refunded'], true);
    $lineItems = [];
    $eventLines = [];
    foreach ($order->get_items('line_item') as $item) {
      if (!$item instanceof WC_Order_Item_Product) {
        continue;
      }
      $quantity = (int) $item->get_quantity();
      $total = $canceled ? 0 : $quantity;
      $fulfilled = $delivered && !$canceled ? $quantity : 0;
      $lineId = 'li_' . $item->get_id();
      $eventLines[] = ['id' => $lineId, 'quantity' => $quantity];
      $totals = [];
      foreach (Pricing::lineTotals($item, $inclusive, $currency) as $row) {
        if (in_array($row['type'], [Pricing::SUBTOTAL, Pricing::TOTAL], true)) {
          $totals[] = ['type' => $row['type'], 'amount' => (int) $row['amount']];
        }
      }
      $lineItems[] = [
        'id' => $lineId,
        'item' => ['id' => (string) ($item->get_meta(Session::META_ITEM_ID) ?: $item->get_variation_id() ?: $item->get_product_id()), 'title' => $item->get_name(), 'price' => Pricing::unitAmount($item, $inclusive, $currency)],
        'quantity' => ['original' => $quantity, 'total' => $total, 'fulfilled' => $fulfilled],
        'totals' => $totals,
        'status' => $total === 0 ? 'removed' : ($fulfilled >= $total ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'processing')),
      ];
    }
    $destination = [];
    if ($order->get_shipping_country() !== '') {
      $destination = array_filter([
        'first_name' => $order->get_shipping_first_name(),
        'last_name' => $order->get_shipping_last_name(),
        'street_address' => $order->get_shipping_address_1(),
        'extended_address' => $order->get_shipping_address_2(),
        'address_locality' => $order->get_shipping_city(),
        'address_region' => $order->get_shipping_state(),
        'postal_code' => $order->get_shipping_postcode(),
        'address_country' => $order->get_shipping_country(),
      ], 'strlen');
    }
    $tracking = json_decode((string) $order->get_meta(AcpOrderWebhook::META_TRACKING), true);
    $tracking = is_array($tracking) ? $tracking : [];
    $events = [];
    $log = json_decode((string) $order->get_meta(self::META_EVENTS), true);
    foreach ((array) ($log['events'] ?? []) as $index => $entry) {
      if (!in_array($entry['event'] ?? '', ['shipped', 'delivered'], true)) {
        continue;
      }
      $events[] = array_filter([
        'id' => 'evt_' . $order->get_id() . '_' . $index,
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $entry['ts']),
        'type' => $entry['event'],
        'line_items' => $eventLines,
        'tracking_number' => (string) ($tracking['number'] ?? ''),
        'tracking_url' => (string) ($tracking['url'] ?? ''),
        'description' => $entry['event'] === 'delivered' ? 'Zugestellt' : 'Versandt' . (!empty($tracking['carrier']) ? ' mit ' . $tracking['carrier'] : ''),
      ], fn($v) => is_array($v) || $v !== '');
    }
    $adjustments = [];
    foreach ($order->get_refunds() as $refund) {
      $adjustments[] = [
        'id' => 'adj_' . $refund->get_id(),
        'type' => 'refund',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : $timestamp),
        'status' => 'completed',
        'totals' => [['type' => 'total', 'amount' => -Money::toMinor((float) $refund->get_amount(), $currency)]],
        'description' => (string) $refund->get_reason(),
      ];
    }
    if ($canceled) {
      $adjustments[] = [
        'id' => 'adj_cancel_' . $order->get_id(),
        'type' => 'cancellation',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
        'status' => 'completed',
        'line_items' => array_map(fn($l) => ['id' => $l['id'], 'quantity' => -$l['quantity']], $eventLines),
        'totals' => [['type' => 'total', 'amount' => -Money::toMinor((float) $order->get_total(), $currency)]],
        'description' => 'Bestellung storniert',
      ];
    }
    $totals = [];
    foreach (Pricing::sessionTotals($order, $inclusive) as $row) {
      if (!in_array($row['type'], [Pricing::ITEMS_BASE, Pricing::ITEMS_DISCOUNT], true)) {
        $totals[] = ['type' => $row['type'], 'amount' => (int) $row['amount']];
      }
    }
    $entity = [
      'ucp' => ['version' => Settings::ucpVersion(), 'capabilities' => [Ucp::CAP_ORDER => [['version' => Settings::ucpVersion()]]]],
      'id' => (string) $order->get_id(),
      'checkout_id' => (string) $order->get_meta(Session::META_SESSION_ID),
      'permalink_url' => OrderFactory::permalink($order),
      'currency' => strtoupper($currency),
      'status' => $canceled ? 'canceled' : ($delivered ? 'delivered' : ($shipped ? 'shipped' : 'processing')),
      'line_items' => $lineItems,
      'fulfillment' => [
        'expectations' => [array_filter([
          'id' => 'exp_' . $order->get_id(),
          'line_items' => $eventLines,
          'method_type' => Shipping::isDigitalOnly($order) ? 'digital' : 'shipping',
          'destination' => $destination,
          'description' => sprintf('Lieferung in %1$d–%2$d Werktagen', ...Settings::deliveryDays()),
        ], fn($v) => $v !== [] && $v !== '')],
        'events' => $events,
      ],
      'adjustments' => $adjustments,
      'totals' => $totals,
    ];
    return (array) apply_filters('aboon_agentic_ucp_order_entity', $entity, $order, $event);
  }
}
