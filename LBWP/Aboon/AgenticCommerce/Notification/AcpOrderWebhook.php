<?php

namespace LBWP\Aboon\AgenticCommerce\Notification;

use LBWP\Aboon\AgenticCommerce\Enum\Acp;
use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\OrderFactory;
use LBWP\Aboon\AgenticCommerce\Pricing;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\Settings;
use LBWP\Aboon\AgenticCommerce\Shipping;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Pushes ACP order lifecycle events (order_create / order_update with the full Order object) to
 * the OpenAI webhook, signed with Merchant-Signature (t=<ts>,v1=<hmac-sha256 hex over ts.body>).
 * @package LBWP\Aboon\AgenticCommerce\Notification
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AcpOrderWebhook
{
  public const string META_CREATED_SENT = '_agentic_webhook_created';
  public const string META_TRACKING = '_agentic_tracking';
  /**
   * @var array WooCommerce status => ACP order status
   */
  public const array STATUS_MAP = [
    'processing' => 'confirmed',
    'on-hold' => 'manual_review',
    'completed' => 'fulfilled',
    'cancelled' => 'canceled',
    'refunded' => 'canceled',
    'failed' => 'canceled',
  ];

  /**
   * Registers the order hooks.
   * @return void
   */
  public function register(): void
  {
    add_action('aboon_agentic_acp_order_created', [$this, 'onCreated'], 10, 2);
    add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 4);
    add_action('woocommerce_order_refunded', [$this, 'onRefunded'], 10, 2);
    add_action('aboon_agentic_order_shipped', [$this, 'onShipped'], 10, 2);
  }

  /**
   * Sends order_create right after completion, followed by the current status when it differs.
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
    self::send('order_create', $order, 'created');
    $current = self::mapStatus($order);
    if ($current !== '' && $current !== 'created') {
      self::send('order_update', $order, $current);
    }
  }

  /**
   * Sends order_update on status changes of agent orders.
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
    $status = self::mapStatus($order, $to);
    if ($status === '') {
      return;
    }
    self::send('order_update', $order, $status);
  }

  /**
   * Sends order_update with adjustments after a refund.
   * @param int $orderId order id
   * @param int $refundId refund id
   * @return void
   */
  public function onRefunded(int $orderId, int $refundId): void
  {
    $order = wc_get_order($orderId);
    if (!$order instanceof WC_Order || !self::applies($order) || $order->get_meta(self::META_CREATED_SENT) !== '1') {
      return;
    }
    self::send('order_update', $order, self::mapStatus($order) ?: 'confirmed');
  }

  /**
   * Stores tracking data and sends the shipped status.
   * @param int $orderId order id
   * @param array|string $tracking ['number' => …, 'carrier' => …, 'url' => …] or tracking number
   * @return void
   */
  public function onShipped(int $orderId, array|string $tracking = []): void
  {
    $order = wc_get_order($orderId);
    if (!$order instanceof WC_Order || !self::applies($order)) {
      return;
    }
    $tracking = is_string($tracking) ? ['number' => $tracking] : $tracking;
    $order->update_meta_data(self::META_TRACKING, wp_json_encode(array_map('sanitize_text_field', array_map('strval', $tracking))));
    $order->update_meta_data('_agentic_shipped_at', (string) time());
    $order->save_meta_data();
    if ($order->get_meta(self::META_CREATED_SENT) === '1') {
      self::send('order_update', $order, 'shipped');
    }
  }

  /**
   * Whether an order belongs to ACP and a webhook URL is configured.
   * @param WC_Order $order the order
   * @return bool applies
   */
  public static function applies(WC_Order $order): bool
  {
    return $order->get_meta(Session::META_PROTOCOL) === 'acp' && Settings::acpWebhookUrl() !== '';
  }

  /**
   * Maps the WooCommerce status to an ACP order status (filterable).
   * @param WC_Order $order the order
   * @param string $status WooCommerce status without prefix, empty for the order's current one
   * @return string ACP status or empty when nothing should be sent
   */
  public static function mapStatus(WC_Order $order, string $status = ''): string
  {
    $status = $status !== '' ? $status : $order->get_status();
    $mapped = self::STATUS_MAP[$status] ?? '';
    if ($mapped === 'confirmed' && $order->get_meta('_agentic_shipped_at') !== '') {
      $mapped = 'shipped';
    }
    return (string) apply_filters('aboon_agentic_acp_order_status', $mapped, $order, $status);
  }

  /**
   * Builds and queues an event.
   * @param string $type order_create|order_update
   * @param WC_Order $order the order
   * @param string $status ACP order status
   * @return void
   */
  public static function send(string $type, WC_Order $order, string $status): void
  {
    $payload = ['type' => $type, 'data' => self::orderPayload($order, $status)];
    Queue::send(Queue::CHANNEL_ACP, $order->get_id(), $type . ':' . $status, $payload);
  }

  /**
   * Delivers a queued job (called by the queue, signs at delivery time).
   * @param array $data row data with payload
   * @return array ok, status, error
   */
  public static function deliver(array $data): array
  {
    $url = Settings::acpWebhookUrl();
    $secret = Settings::acpWebhookSecret();
    if ($url === '') {
      return ['ok' => true, 'status' => 0, 'error' => ''];
    }
    $body = wp_json_encode($data['payload'] ?? []);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $headers = [
      'Content-Type' => 'application/json',
      'Merchant-Signature' => 't=' . $timestamp . ',v1=' . $signature,
      'Timestamp' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
      'Request-Id' => 'ord-' . ($data['order_id'] ?? 0) . '-' . $timestamp,
      'User-Agent' => Settings::sellerName() . ' ACP Webhook',
    ];
    $response = wp_remote_post($url, ['timeout' => 10, 'headers' => $headers, 'body' => $body]);
    if (is_wp_error($response)) {
      return ['ok' => false, 'status' => 0, 'error' => $response->get_error_message()];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => $status >= 300 ? 'HTTP ' . $status . ' ' . substr((string) wp_remote_retrieve_body($response), 0, 200) : ''];
  }

  /**
   * Builds the full ACP Order object for an order.
   * @param WC_Order $order the order
   * @param string $status ACP order status
   * @return array Order payload
   */
  public static function orderPayload(WC_Order $order, string $status): array
  {
    $currency = $order->get_currency();
    $lineItems = [];
    $lineIds = [];
    $fulfilled = in_array($status, ['shipped', 'fulfilled'], true);
    foreach ($order->get_items('line_item') as $item) {
      if (!$item instanceof WC_Order_Item_Product) {
        continue;
      }
      $product = $item->get_product() ?: null;
      $quantity = (int) $item->get_quantity();
      $lineIds[] = 'li_' . $item->get_id();
      $lineItems[] = [
        'id' => 'li_' . $item->get_id(),
        'title' => $item->get_name(),
        'product_id' => (string) ($item->get_variation_id() ?: $item->get_product_id()),
        'url' => $product ? (string) $product->get_permalink() : '',
        'quantity' => ['ordered' => $quantity, 'current' => $status === 'canceled' ? 0 : $quantity, 'fulfilled' => $fulfilled ? $quantity : 0],
        'unit_price' => Pricing::unitAmount($item, false, $currency),
        'subtotal' => Money::toMinor((float) $item->get_subtotal(), $currency),
        'status' => $status === 'canceled' ? 'removed' : ($fulfilled ? 'fulfilled' : 'processing'),
      ];
    }
    $tracking = json_decode((string) $order->get_meta(self::META_TRACKING), true);
    $tracking = is_array($tracking) ? $tracking : [];
    $fulfillment = [
      'id' => 'ful_' . $order->get_id(),
      'type' => Shipping::isDigitalOnly($order) ? 'digital' : (str_starts_with(self::shippingMethodId($order), 'local_pickup') ? 'pickup' : 'shipping'),
      'status' => $status === 'fulfilled' ? 'delivered' : ($status === 'shipped' ? 'shipped' : ($status === 'canceled' ? 'canceled' : 'processing')),
      'line_items' => array_map(fn($id) => ['line_item_id' => $id], $lineIds),
    ];
    if (!empty($tracking['number'])) {
      $fulfillment['tracking_number'] = (string) $tracking['number'];
    }
    if (!empty($tracking['carrier']) || Settings::carrierName() !== '') {
      $fulfillment['carrier'] = (string) ($tracking['carrier'] ?? Settings::carrierName());
    }
    if (!empty($tracking['url'])) {
      $fulfillment['tracking_url'] = (string) $tracking['url'];
    }
    if ($order->get_shipping_country() !== '') {
      $fulfillment['destination'] = [
        'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
        'line_one' => $order->get_shipping_address_1(),
        'line_two' => $order->get_shipping_address_2(),
        'city' => $order->get_shipping_city(),
        'state' => $order->get_shipping_state(),
        'country' => $order->get_shipping_country(),
        'postal_code' => $order->get_shipping_postcode(),
      ];
    }
    $adjustments = [];
    $refunded = 0;
    foreach ($order->get_refunds() as $refund) {
      $amount = Money::toMinor((float) $refund->get_amount(), $currency);
      $refunded += $amount;
      $adjustments[] = [
        'id' => 'adj_' . $refund->get_id(),
        'type' => 'refund',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : time()),
        'status' => 'completed',
        'amount' => $amount,
        'currency' => strtolower($currency),
        'description' => (string) $refund->get_reason(),
      ];
    }
    $totals = array_map(fn($row) => ['type' => $row['type'], 'display_text' => Acp::TOTAL_TEXT[$row['type']] ?? $row['type'], 'amount' => (int) $row['amount']], Pricing::sessionTotals($order, false));
    if ($refunded > 0) {
      $totals[] = ['type' => 'amount_refunded', 'display_text' => 'Rückerstattet', 'amount' => $refunded];
    }
    $payload = [
      'type' => 'order',
      'id' => (string) $order->get_id(),
      'checkout_session_id' => (string) $order->get_meta(Session::META_SESSION_ID),
      'order_number' => $order->get_order_number(),
      'permalink_url' => OrderFactory::permalink($order),
      'status' => $status,
      'confirmation' => ['confirmation_number' => $order->get_order_number(), 'confirmation_email_sent' => true],
      'line_items' => $lineItems,
      'fulfillments' => [$fulfillment],
      'adjustments' => $adjustments,
      'totals' => $totals,
    ];
    return (array) apply_filters('aboon_agentic_acp_order_payload', $payload, $order, $status);
  }

  /**
   * Returns the method id of the first shipping line.
   * @param WC_Order $order the order
   * @return string method id or empty
   */
  protected static function shippingMethodId(WC_Order $order): string
  {
    foreach ($order->get_items('shipping') as $item) {
      return (string) $item->get_method_id();
    }
    return '';
  }
}
