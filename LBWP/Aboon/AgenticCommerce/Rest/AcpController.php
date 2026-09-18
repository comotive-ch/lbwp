<?php

namespace LBWP\Aboon\AgenticCommerce\Rest;

use LBWP\Aboon\AgenticCommerce\Catalog;
use LBWP\Aboon\AgenticCommerce\Enum\Acp;
use LBWP\Aboon\AgenticCommerce\Enum\Message;
use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\OrderFactory;
use LBWP\Aboon\AgenticCommerce\Payment\Handlers;
use LBWP\Aboon\AgenticCommerce\Payment\StripeSharedToken;
use LBWP\Aboon\AgenticCommerce\Pricing;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\SessionStore;
use LBWP\Aboon\AgenticCommerce\Settings;
use LBWP\Aboon\AgenticCommerce\Shipping;
use LBWP\Aboon\AgenticCommerce\Signature;
use WP_Error;
use WP_REST_Request;

/**
 * OpenAI ACP checkout endpoints (spec 2026-04-17): create, get, update, complete and cancel a
 * checkout session, with request/response mapping between the ACP wire format and the shared
 * session library.
 * @package LBWP\Aboon\AgenticCommerce\Rest
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AcpController extends Controller
{
  public const string NAMESPACE = 'acp/v1';
  public const string PROTOCOL = 'acp';

  /**
   * @return string protocol
   */
  protected function protocol(): string
  {
    return self::PROTOCOL;
  }

  /**
   * @return string namespace
   */
  protected function namespace(): string
  {
    return self::NAMESPACE;
  }

  /**
   * @return array routes
   */
  protected function routes(): array
  {
    $id = '/checkout_sessions/(?P<id>[\w-]+)';
    return [
      ['methods' => 'POST', 'route' => '/checkout_sessions', 'endpoint' => 'createSession'],
      ['methods' => 'GET', 'route' => $id, 'endpoint' => 'getSession'],
      ['methods' => 'POST', 'route' => $id, 'endpoint' => 'updateSession'],
      ['methods' => 'POST', 'route' => $id . '/complete', 'endpoint' => 'completeSession'],
      ['methods' => 'POST', 'route' => $id . '/cancel', 'endpoint' => 'cancelSession'],
    ];
  }

  /**
   * Bearer token check plus optional HMAC signature verification.
   * @param WP_REST_Request $request the request
   * @return true|WP_Error result
   */
  public function authenticate(WP_REST_Request $request): true|WP_Error
  {
    $expected = Settings::acpApiKey();
    $token = $this->bearerToken($request);
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
      return new WP_Error('invalid_token', __('Ungültiger API-Key.', 'lbwp'), ['status' => 401]);
    }
    $secret = Settings::acpSignatureSecret();
    if ($secret === '') {
      return true;
    }
    $valid = Signature::verifyHmac((string) $request->get_body(), (string) $request->get_header('timestamp'), (string) $request->get_header('signature'), $secret);
    return $valid ? true : new WP_Error('invalid_signature', __('Ungültige Signatur.', 'lbwp'), ['status' => 401]);
  }

  /**
   * Validates the API-Version header (missing is tolerated, unknown is rejected).
   * @param WP_REST_Request $request the request
   * @return array|null error triple
   */
  protected function checkVersion(WP_REST_Request $request): ?array
  {
    $version = trim((string) ($request->get_header('api_version') ?? ''));
    $supported = array_unique([Settings::acpApiVersion(), Settings::ACP_VERSION]);
    if ($version === '' || in_array($version, $supported, true)) {
      return null;
    }
    return [400, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_UNSUPPORTED_VERSION, __('API-Version wird nicht unterstützt.', 'lbwp'), '', ['supported_versions' => array_values($supported)]), []];
  }

  /**
   * @inheritDoc
   */
  protected function errorBody(string $type, string $code, string $message, string $param = '', array $extra = []): array
  {
    $body = ['type' => $type, 'code' => $code, 'message' => $message];
    if ($param !== '') {
      $body['param'] = $param;
    }
    return array_merge($body, $extra);
  }

  /**
   * @return int 422 for ACP idempotency conflicts
   */
  protected function conflictStatus(): int
  {
    return 422;
  }

  /**
   * @inheritDoc
   */
  protected function platform(WP_REST_Request $request): string
  {
    return substr(sanitize_text_field((string) ($request->get_header('user_agent') ?? 'openai')), 0, 120);
  }

  /**
   * POST /checkout_sessions
   * @param Session|null $session always null
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array [status, body, headers]
   */
  protected function createSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $currency = (string) ($json['currency'] ?? Money::currency());
    if (!Money::isSupported($currency)) {
      return [400, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_INVALID, __('Währung wird nicht unterstützt.', 'lbwp'), '$.currency'), []];
    }
    $items = $json['line_items'] ?? $json['items'] ?? null;
    if (!is_array($items) || count($items) === 0) {
      return [400, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_MISSING, __('line_items fehlen.', 'lbwp'), '$.line_items'), []];
    }
    $key = sanitize_text_field((string) $request->get_header('idempotency_key'));
    $session = SessionStore::create(self::PROTOCOL, $currency, self::PROTOCOL . ':' . $key, $this->platform($request));
    $session->set('version', Settings::acpApiVersion());
    $session->set('lang', $this->requestLanguage($request));
    $session->set('locale', sanitize_text_field((string) ($json['locale'] ?? '')));
    if (isset($json['capabilities']) && is_array($json['capabilities'])) {
      $session->set('capabilities', $json['capabilities']);
    }
    $session->setLineItems($this->mapItems($items));
    if (!$session->hasItems()) {
      $session->getOrder()->delete(true);
      return [400, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_OUT_OF_STOCK, __('Keiner der Artikel ist verfügbar.', 'lbwp'), '$.line_items'), []];
    }
    $this->applyCommon($session, $json);
    $session->recalculate();
    $session->refreshStatus();
    $session->save();
    $this->createdSession = $session;
    return [201, $this->render($session), []];
  }

  /**
   * GET /checkout_sessions/{id}
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function getSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    return [200, $this->render($session), []];
  }

  /**
   * POST /checkout_sessions/{id}
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function updateSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $status = $session->getStatus();
    if (in_array($status, [Session::STATUS_COMPLETED, Session::STATUS_CANCELED], true)) {
      return [405, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_INVALID, __('Session ist abgeschlossen.', 'lbwp')), []];
    }
    if ($status === Session::STATUS_COMPLETING) {
      $session->addMessage(Message::warning(Message::INVALID, __('Zahlung läuft, Änderungen sind nicht möglich.', 'lbwp')));
      return [200, $this->render($session), []];
    }
    if (isset($json['line_items']) && is_array($json['line_items'])) {
      $session->setLineItems($this->mapItems($json['line_items']));
    }
    if (isset($json['selected_fulfillment_options']) && is_array($json['selected_fulfillment_options'])) {
      $first = $json['selected_fulfillment_options'][0] ?? [];
      $session->selectFulfillmentOption((string) ($first['option_id'] ?? $first['id'] ?? ''));
    }
    if (isset($json['fulfillment_option_id'])) {
      $session->selectFulfillmentOption((string) $json['fulfillment_option_id']);
    }
    $this->applyCommon($session, $json);
    $session->recalculate();
    $session->refreshStatus();
    $session->save();
    return [200, $this->render($session), []];
  }

  /**
   * POST /checkout_sessions/{id}/complete
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function completeSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $status = $session->getStatus();
    if (in_array($status, [Session::STATUS_COMPLETED, Session::STATUS_CANCELED], true)) {
      return [405, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_INVALID, __('Session ist bereits abgeschlossen.', 'lbwp')), []];
    }
    $paymentData = $json['payment_data'] ?? null;
    if (!is_array($paymentData)) {
      return [400, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_MISSING, __('payment_data fehlt.', 'lbwp'), '$.payment_data'), []];
    }
    if (isset($json['buyer']) && is_array($json['buyer'])) {
      $session->setBuyer($this->mapBuyer($json['buyer']));
    }
    if (isset($paymentData['billing_address']) && is_array($paymentData['billing_address'])) {
      $session->setBillingAddress($this->mapAddressIn($paymentData['billing_address']));
    }
    $previousTotal = (int) $session->get('last_total', -1);
    $session->recalculate();
    $currentTotal = Money::toMinor((float) $session->getOrder()->get_total(), $session->getOrder()->get_currency());
    if ($previousTotal >= 0 && $previousTotal !== $currentTotal) {
      $session->addMessage(Message::error(Message::PRICE_CHANGED, __('Der Gesamtbetrag hat sich geändert, bitte prüfen.', 'lbwp'), '$.totals'));
    }
    if (!$session->isReadyForPayment()) {
      $session->set('status', Session::STATUS_INCOMPLETE);
      $session->save();
      return [200, $this->render($session), []];
    }
    $handlerId = sanitize_text_field((string) ($paymentData['handler_id'] ?? ''));
    // Legacy payment_data shape (token + provider) from earlier spec versions
    if ($handlerId === '' && ($paymentData['provider'] ?? '') === 'stripe') {
      $handlerId = StripeSharedToken::ID;
    }
    $handler = Handlers::find(self::PROTOCOL, $handlerId);
    if ($handler === null) {
      $handlers = Handlers::forProtocol(self::PROTOCOL);
      $handler = count($handlers) === 1 && $handlerId === '' ? $handlers[0] : null;
    }
    if ($handler === null) {
      return [400, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_INVALID, __('Unbekannter Zahlungs-Handler.', 'lbwp'), '$.payment_data.handler_id'), []];
    }
    $session->set('status', Session::STATUS_READY);
    $session->markCompleting();
    $result = $handler->charge($session->getOrder(), $paymentData);
    if (empty($result['success'])) {
      $session->markReady();
      $code = !empty($result['requires_action']) ? Message::REQUIRES_3DS : Message::PAYMENT_DECLINED;
      $session->addMessage(Message::error($code, (string) ($result['message'] ?: __('Zahlung abgelehnt.', 'lbwp')), '$.payment_data'));
      Log::paymentError($handler->getId(), (string) ($result['error_code'] ?? ''), ['session' => $session->getId()]);
      return [200, $this->render($session), []];
    }
    $order = OrderFactory::finalize($session, (string) ($result['transaction_id'] ?? ''), $handler->getId(), $handler->getTitle(self::PROTOCOL), !empty($result['captured']), [
      'platform' => $this->platform($request),
      'order_notes' => (string) ($json['order_notes'] ?? $session->get('order_notes', '')),
      'marketing_consents' => $json['marketing_consents'] ?? [],
      'risk_signals' => $json['risk_signals'] ?? [],
    ]);
    $session->markCompleted($order);
    do_action('aboon_agentic_acp_order_created', $order, $session);
    return [200, $this->render($session), []];
  }

  /**
   * POST /checkout_sessions/{id}/cancel
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function cancelSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $status = $session->getStatus();
    if (in_array($status, [Session::STATUS_COMPLETED, Session::STATUS_COMPLETING], true)) {
      return [405, $this->errorBody(Acp::ERROR_INVALID_REQUEST, Acp::CODE_INVALID, __('Session kann nicht mehr abgebrochen werden.', 'lbwp')), []];
    }
    if ($status !== Session::STATUS_CANCELED) {
      if (isset($json['intent_trace'])) {
        Log::info('ACP cancel intent trace', ['session' => $session->getId(), 'intent_trace' => $json['intent_trace']]);
      }
      $session->cancel();
    }
    return [200, $this->render($session), []];
  }

  /**
   * Applies buyer, fulfillment details, coupons and notes if present.
   * @param Session $session the session
   * @param array $json body
   * @return void
   */
  protected function applyCommon(Session $session, array $json): void
  {
    if (isset($json['buyer']) && is_array($json['buyer'])) {
      $session->setBuyer($this->mapBuyer($json['buyer']));
    }
    if (isset($json['fulfillment_details']) && is_array($json['fulfillment_details'])) {
      $details = $json['fulfillment_details'];
      $address = is_array($details['address'] ?? null) ? $details['address'] : $details;
      $mapped = $this->mapAddressIn($address);
      $mapped['name'] = (string) ($details['name'] ?? $address['name'] ?? '');
      $mapped['email'] = (string) ($details['email'] ?? '');
      $mapped['phone'] = (string) ($details['phone_number'] ?? '');
      $session->setFulfillmentAddress($mapped);
    } elseif (isset($json['fulfillment_address']) && is_array($json['fulfillment_address'])) {
      $session->setFulfillmentAddress($this->mapAddressIn($json['fulfillment_address']));
    }
    if (isset($json['coupons']) && is_array($json['coupons'])) {
      $session->setCoupons($json['coupons']);
    }
    if (isset($json['order_notes'])) {
      $session->set('order_notes', sanitize_textarea_field((string) $json['order_notes']));
    }
  }

  /**
   * Maps ACP line items to neutral [id, quantity].
   * @param array $items request items
   * @return array items
   */
  protected function mapItems(array $items): array
  {
    $mapped = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $id = $item['item']['id'] ?? $item['id'] ?? '';
      $mapped[] = ['id' => (string) $id, 'quantity' => (int) ($item['quantity'] ?? 0)];
    }
    return $mapped;
  }

  /**
   * Maps an ACP buyer object.
   * @param array $buyer buyer
   * @return array neutral buyer
   */
  protected function mapBuyer(array $buyer): array
  {
    $mapped = [];
    foreach (['first_name', 'last_name', 'email'] as $key) {
      if (isset($buyer[$key])) {
        $mapped[$key] = (string) $buyer[$key];
      }
    }
    if (isset($buyer['phone_number'])) {
      $mapped['phone'] = (string) $buyer['phone_number'];
    }
    return $mapped;
  }

  /**
   * Maps an ACP address to the neutral shape (same keys in ACP).
   * @param array $address address
   * @return array neutral address
   */
  protected function mapAddressIn(array $address): array
  {
    return [
      'name' => (string) ($address['name'] ?? ''),
      'line_one' => (string) ($address['line_one'] ?? ''),
      'line_two' => (string) ($address['line_two'] ?? ''),
      'city' => (string) ($address['city'] ?? ''),
      'state' => (string) ($address['state'] ?? ''),
      'country' => (string) ($address['country'] ?? ''),
      'postal_code' => (string) ($address['postal_code'] ?? ''),
    ];
  }

  /**
   * Renders the ACP checkout session object.
   * @param Session $session the session
   * @return array response body
   */
  public function render(Session $session): array
  {
    $order = $session->getOrder();
    $currency = strtolower($order->get_currency());
    $status = Acp::STATUS_MAP[$session->getStatus()] ?? Acp::STATUS_NOT_READY;
    $lineItems = [];
    $itemIds = [];
    foreach ($session->getLineItems() as $line) {
      $item = $line['item'];
      $product = $line['product'];
      $itemIds[] = $line['item_id'];
      $entry = [
        'id' => 'li_' . $item->get_id(),
        'item' => ['id' => $line['item_id']],
        'quantity' => (int) $item->get_quantity(),
        'name' => $item->get_name(),
        'description' => $product ? Catalog::description($product, 300) : '',
        'images' => $product && Catalog::imageUrl($product) !== '' ? [Catalog::imageUrl($product)] : [],
        'unit_amount' => Pricing::unitAmount($item, false, $order->get_currency()),
        'availability_status' => $product ? Catalog::availabilityStatus($product) : Catalog::OUT_OF_STOCK,
        'max_quantity_per_order' => $product ? Catalog::maxQuantity($product) : 1,
        'sku' => $product ? $product->get_sku() : '',
        'product_id' => (string) $item->get_product_id(),
        'totals' => $this->withDisplayText(Pricing::lineTotals($item, false, $order->get_currency())),
      ];
      if ($product && Catalog::availableQuantity($product) !== null) {
        $entry['available_quantity'] = Catalog::availableQuantity($product);
      }
      if ($item->get_variation_id() > 0) {
        $entry['variant_id'] = (string) $item->get_variation_id();
      }
      $note = $product ? Catalog::note($product) : '';
      if ($note !== '') {
        $entry['disclosures'] = [$note];
      }
      $lineItems[] = $entry;
    }
    $body = [
      'id' => $session->getId(),
      'protocol' => ['version' => (string) $session->get('version', Settings::acpApiVersion())],
      'capabilities' => [
        'payment' => ['handlers' => Handlers::declarations(self::PROTOCOL)],
        'interventions' => ['supported' => [], 'required' => [], 'enforcement' => 'none'],
      ],
      'status' => $status,
      'currency' => $currency,
      'line_items' => $lineItems,
      'fulfillment_options' => $this->renderOptions((array) $session->get('fulfillment_options', [])),
      'selected_fulfillment_options' => [],
      'totals' => $this->withDisplayText(Pricing::sessionTotals($order, false)),
      'messages' => $this->renderMessages($session->getMessages()),
      'links' => $this->renderLinks(),
    ];
    $buyer = (array) $session->get('buyer', []);
    if (count(array_filter($buyer)) > 0) {
      $body['buyer'] = array_filter([
        'first_name' => $buyer['first_name'] ?? '',
        'last_name' => $buyer['last_name'] ?? '',
        'email' => $buyer['email'] ?? '',
        'phone_number' => $buyer['phone'] ?? '',
      ], 'strlen');
    }
    $fulfillment = (array) $session->get('fulfillment', []);
    if (($fulfillment['country'] ?? '') !== '') {
      $body['fulfillment_details'] = array_filter([
        'name' => $fulfillment['name'] ?? '',
        'email' => $fulfillment['email'] ?? ($buyer['email'] ?? ''),
        'phone_number' => $fulfillment['phone'] ?? '',
        'address' => [
          'name' => $fulfillment['name'] ?? '',
          'line_one' => $fulfillment['line_one'] ?? '',
          'line_two' => $fulfillment['line_two'] ?? '',
          'city' => $fulfillment['city'] ?? '',
          'state' => $fulfillment['state'] ?? '',
          'country' => $fulfillment['country'] ?? '',
          'postal_code' => $fulfillment['postal_code'] ?? '',
        ],
      ], fn($v) => is_array($v) || strlen((string) $v) > 0);
    }
    $selected = $session->getSelectedOption();
    if ($selected !== null) {
      $body['selected_fulfillment_options'] = [['type' => $selected['type'], 'option_id' => $selected['id'], 'item_ids' => $itemIds]];
    }
    if ($session->getStatus() === Session::STATUS_COMPLETED) {
      $body['order'] = [
        'id' => (string) $order->get_id(),
        'checkout_session_id' => $session->getId(),
        'order_number' => $order->get_order_number(),
        'permalink_url' => OrderFactory::permalink($order),
        'confirmation' => ['confirmation_number' => $order->get_order_number(), 'confirmation_email_sent' => true],
      ];
    }
    $session->set('last_total', Money::toMinor((float) $order->get_total(), $order->get_currency()));
    return $body;
  }

  /**
   * Adds display_text to a neutral totals list.
   * @param array $totals neutral totals
   * @return array ACP totals
   */
  protected function withDisplayText(array $totals): array
  {
    return array_map(fn($row) => ['type' => $row['type'], 'display_text' => Acp::TOTAL_TEXT[$row['type']] ?? $row['type'], 'amount' => (int) $row['amount']], $totals);
  }

  /**
   * Renders fulfillment options.
   * @param array $options neutral options
   * @return array ACP options
   */
  protected function renderOptions(array $options): array
  {
    $rendered = [];
    foreach ($options as $option) {
      $entry = [
        'type' => $option['type'] === Shipping::TYPE_DIGITAL ? 'digital' : 'shipping',
        'id' => (string) $option['id'],
        'title' => (string) $option['title'],
        'description' => (string) ($option['description'] ?? ''),
        'totals' => [['type' => 'total', 'display_text' => Acp::TOTAL_TEXT[Pricing::FULFILLMENT], 'amount' => (int) ($option['amount_net'] ?? $option['amount'])]],
      ];
      if ($option['type'] !== Shipping::TYPE_DIGITAL) {
        $entry['carrier'] = (string) ($option['carrier'] ?? '');
        $entry['earliest_delivery_time'] = (string) $option['earliest_delivery_time'];
        $entry['latest_delivery_time'] = (string) $option['latest_delivery_time'];
        if ((int) ($option['tax'] ?? 0) > 0) {
          $entry['totals'][] = ['type' => 'tax', 'display_text' => Acp::TOTAL_TEXT[Pricing::TAX], 'amount' => (int) $option['tax']];
        }
      }
      $rendered[] = $entry;
    }
    return $rendered;
  }

  /**
   * Renders neutral messages in ACP format.
   * @param array $messages neutral messages
   * @return array ACP messages
   */
  protected function renderMessages(array $messages): array
  {
    $rendered = [];
    foreach ($messages as $message) {
      [$severity, $resolution] = Acp::MESSAGE_META[$message['code']] ?? ['medium', 'requires_buyer_input'];
      $entry = [
        'type' => $message['level'] === Message::LEVEL_ERROR ? 'error' : ($message['level'] === Message::LEVEL_WARNING ? 'warning' : 'info'),
        'code' => $message['code'],
        'severity' => $message['level'] === Message::LEVEL_ERROR ? $severity : 'low',
        'resolution' => empty($message['blocking']) ? 'recoverable' : $resolution,
        'content_type' => 'plain',
        'content' => $message['content'],
      ];
      if (!empty($message['path'])) {
        $entry['param'] = $message['path'];
      }
      $rendered[] = $entry;
    }
    return $rendered;
  }

  /**
   * Renders the policy links.
   * @return array links
   */
  protected function renderLinks(): array
  {
    $links = [];
    foreach (Settings::links() as $key => $url) {
      $links[] = ['type' => Acp::LINK_TYPES[$key] ?? $key, 'url' => $url];
    }
    return $links;
  }
}
