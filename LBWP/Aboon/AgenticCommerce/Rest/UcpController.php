<?php

namespace LBWP\Aboon\AgenticCommerce\Rest;

use LBWP\Aboon\AgenticCommerce\Catalog;
use LBWP\Aboon\AgenticCommerce\Enum\Message;
use LBWP\Aboon\AgenticCommerce\Enum\Ucp;
use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\OrderFactory;
use LBWP\Aboon\AgenticCommerce\Payment\Handlers;
use LBWP\Aboon\AgenticCommerce\Pricing;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\SessionStore;
use LBWP\Aboon\AgenticCommerce\Settings;
use LBWP\Aboon\AgenticCommerce\Shipping;
use LBWP\Aboon\AgenticCommerce\Signature;
use WP_Error;
use WP_REST_Request;

/**
 * Google UCP checkout endpoints (profile 2026-04-08): create, get, update (full replacement),
 * complete (Google Pay credential) and cancel, plus the business profile document.
 * @package LBWP\Aboon\AgenticCommerce\Rest
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class UcpController extends Controller
{
  public const string NAMESPACE = 'ucp/v1';
  public const string PROTOCOL = 'ucp';
  public const string TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
  public const string METHOD_ID = 'method_1';
  public const string GROUP_ID = 'group_1';
  public const string DESTINATION_ID = 'dest_1';
  public const int TOKEN_CACHE_TTL = 300;

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
    $id = '/checkout-sessions/(?P<id>[\w-]+)';
    return [
      ['methods' => 'POST', 'route' => '/checkout-sessions', 'endpoint' => 'createSession'],
      ['methods' => 'GET', 'route' => $id, 'endpoint' => 'getSession'],
      ['methods' => 'PUT', 'route' => $id, 'endpoint' => 'updateSession'],
      ['methods' => 'POST', 'route' => $id . '/complete', 'endpoint' => 'completeSession'],
      ['methods' => 'POST', 'route' => $id . '/cancel', 'endpoint' => 'cancelSession'],
    ];
  }

  /**
   * Google bearer verification (tokeninfo) or a static caller token, then optional RFC 9421.
   * @param WP_REST_Request $request the request
   * @return true|WP_Error result
   */
  public function authenticate(WP_REST_Request $request): true|WP_Error
  {
    $token = $this->bearerToken($request);
    if ($token === '') {
      return new WP_Error('missing_token', __('Authorization fehlt.', 'lbwp'), ['status' => 401]);
    }
    if (!$this->isStaticCaller($token) && !$this->verifyGoogleToken($token)) {
      return new WP_Error('invalid_token', __('Ungültiges Zugriffstoken.', 'lbwp'), ['status' => 401]);
    }
    if (!Settings::ucpRequireSignature()) {
      $this->logSignatureOutcome($request);
      return true;
    }
    $error = '';
    if (!Signature::verifyRfc9421($request, $this->platformKeys($request), $error)) {
      return new WP_Error('invalid_signature', __('Ungültige Signatur.', 'lbwp') . ' (' . $error . ')', ['status' => 401]);
    }
    return true;
  }

  /**
   * Verifies a signature when present and logs failures without enforcing them.
   * @param WP_REST_Request $request the request
   * @return void
   */
  protected function logSignatureOutcome(WP_REST_Request $request): void
  {
    if ((string) $request->get_header('signature') === '') {
      return;
    }
    $error = '';
    if (!Signature::verifyRfc9421($request, $this->platformKeys($request), $error)) {
      Log::authFailure(self::PROTOCOL, 'signature_not_enforced:' . $error, ['path' => $request->get_route()]);
    }
  }

  /**
   * Accepts "token:<secret>" lines in the allowed callers as static bearer tokens.
   * @param string $token bearer token
   * @return bool true when it matches a configured static token
   */
  protected function isStaticCaller(string $token): bool
  {
    foreach (Settings::ucpAllowedCallers() as $line) {
      if (str_starts_with($line, 'token:') && hash_equals(substr($line, 6), $token)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Verifies a Google issued token against tokeninfo and the allowed callers (cached).
   * @param string $token the bearer token
   * @return bool true when valid
   */
  protected function verifyGoogleToken(string $token): bool
  {
    $cacheKey = 'gtoken_' . hash('sha256', $token);
    if (wp_cache_get($cacheKey, SessionStore::CACHE_GROUP) !== false) {
      return true;
    }
    $allowed = array_filter(Settings::ucpAllowedCallers(), fn($l) => !str_starts_with($l, 'token:'));
    if (count($allowed) === 0) {
      Log::authFailure(self::PROTOCOL, 'no_allowed_callers_configured');
      return false;
    }
    $info = $this->tokenInfo('id_token', $token);
    if ($info === null || isset($info['error'])) {
      $info = $this->tokenInfo('access_token', $token);
    }
    if (!is_array($info) || isset($info['error'])) {
      Log::authFailure(self::PROTOCOL, 'tokeninfo_rejected', ['token' => Log::mask($token)]);
      return false;
    }
    $issuer = (string) ($info['iss'] ?? 'https://accounts.google.com');
    if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
      Log::authFailure(self::PROTOCOL, 'invalid_issuer', ['iss' => $issuer]);
      return false;
    }
    $expires = (int) ($info['exp'] ?? 0);
    if ($expires > 0 && $expires < time()) {
      Log::authFailure(self::PROTOCOL, 'token_expired');
      return false;
    }
    $email = strtolower((string) ($info['email'] ?? ''));
    $audience = (string) ($info['aud'] ?? '');
    $azp = (string) ($info['azp'] ?? '');
    $emailOk = $email !== '' && in_array($email, array_map('strtolower', $allowed), true) && (($info['email_verified'] ?? 'true') === 'true' || ($info['email_verified'] ?? true) === true);
    $audienceOk = $audience !== '' && (in_array($audience, $allowed, true) || $audience === Settings::ucpEndpointUrl() || in_array($azp, $allowed, true));
    if (!$emailOk && !$audienceOk) {
      Log::authFailure(self::PROTOCOL, 'caller_not_allowed', ['email' => $email, 'aud' => $audience]);
      return false;
    }
    $ttl = $expires > 0 ? min($expires - time(), self::TOKEN_CACHE_TTL) : self::TOKEN_CACHE_TTL;
    wp_cache_set($cacheKey, ['email' => $email], SessionStore::CACHE_GROUP, max(30, $ttl));
    return true;
  }

  /**
   * Calls Google's tokeninfo endpoint.
   * @param string $param id_token|access_token
   * @param string $token the token
   * @return array|null decoded response
   */
  protected function tokenInfo(string $param, string $token): ?array
  {
    $response = wp_remote_get(self::TOKENINFO_URL . '?' . $param . '=' . rawurlencode($token), ['timeout' => 3]);
    if (is_wp_error($response)) {
      return null;
    }
    $json = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($json) ? $json : null;
  }

  /**
   * Resolves the platform's signing keys from the UCP-Agent profile.
   * @param WP_REST_Request $request the request
   * @return array JWKs
   */
  protected function platformKeys(WP_REST_Request $request): array
  {
    $profile = $this->platform($request);
    return $profile === '' ? [] : Signature::fetchJwks($profile);
  }

  /**
   * UCP carries the version in the body; nothing to validate on headers.
   * @param WP_REST_Request $request the request
   * @return array|null null
   */
  protected function checkVersion(WP_REST_Request $request): ?array
  {
    return null;
  }

  /**
   * @inheritDoc
   */
  protected function errorBody(string $type, string $code, string $message, string $param = '', array $extra = []): array
  {
    $entry = ['type' => 'error', 'code' => $code, 'content_type' => 'plain', 'content' => $message, 'severity' => Ucp::SEVERITY_UNRECOVERABLE];
    if ($param !== '') {
      $entry['path'] = $param;
    }
    return array_merge([
      'ucp' => ['version' => Settings::ucpVersion(), 'status' => 'error'],
      'messages' => [$entry],
      'continue_url' => $this->continueUrl(),
    ], $extra);
  }

  /**
   * @return int 409 for UCP idempotency conflicts
   */
  protected function conflictStatus(): int
  {
    return 409;
  }

  /**
   * Extracts the profile URL from the UCP-Agent header.
   * @param WP_REST_Request $request the request
   * @return string profile url or empty
   */
  protected function platform(WP_REST_Request $request): string
  {
    $header = (string) ($request->get_header('ucp_agent') ?? '');
    if (preg_match('/profile="([^"]+)"/', $header, $m)) {
      return esc_url_raw($m[1]);
    }
    return '';
  }

  /**
   * POST /checkout-sessions
   * @param Session|null $session always null
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array [status, body, headers]
   */
  protected function createSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $items = $json['line_items'] ?? null;
    if (!is_array($items) || count($items) === 0) {
      return [400, $this->errorBody('invalid_request', Message::MISSING, __('line_items fehlen.', 'lbwp'), '$.line_items'), []];
    }
    $key = sanitize_text_field((string) $request->get_header('idempotency_key'));
    $session = SessionStore::create(self::PROTOCOL, Money::currency(), self::PROTOCOL . ':' . $key, $this->platform($request));
    $session->set('version', Settings::ucpVersion());
    $session->set('lang', $this->contextLanguage($json, $request));
    $session->setLineItems($this->mapItems($items));
    if (!$session->hasItems()) {
      $session->getOrder()->delete(true);
      return [200, $this->errorBody('invalid_request', Message::OUT_OF_STOCK, __('Keiner der Artikel ist verfügbar.', 'lbwp'), '$.line_items'), []];
    }
    $this->applyCommon($session, $json, true);
    $this->finishRequest($session);
    $this->createdSession = $session;
    return [201, $this->render($session), []];
  }

  /**
   * GET /checkout-sessions/{id}
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
   * PUT /checkout-sessions/{id} (full replacement of the provided sections)
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function updateSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $status = $session->getStatus();
    if (in_array($status, [Session::STATUS_COMPLETED, Session::STATUS_CANCELED], true)) {
      return [405, $this->errorBody('invalid_request', Message::INVALID, __('Session ist abgeschlossen.', 'lbwp')), []];
    }
    if ($status === Session::STATUS_COMPLETING) {
      $session->addMessage(Message::warning(Message::INVALID, __('Zahlung läuft, Änderungen sind nicht möglich.', 'lbwp')));
      return [200, $this->render($session), []];
    }
    if (isset($json['line_items']) && is_array($json['line_items'])) {
      $session->setLineItems($this->mapItems($json['line_items']));
      if (!$session->hasItems()) {
        $session->recalculate();
        $session->set('status', Session::STATUS_INCOMPLETE);
        $session->save();
        return [200, $this->render($session), []];
      }
    }
    $this->applyCommon($session, $json, false);
    $this->finishRequest($session);
    return [200, $this->render($session), []];
  }

  /**
   * POST /checkout-sessions/{id}/complete
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function completeSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $status = $session->getStatus();
    if (in_array($status, [Session::STATUS_COMPLETED, Session::STATUS_CANCELED], true)) {
      return [405, $this->errorBody('invalid_request', Message::INVALID, __('Session ist bereits abgeschlossen.', 'lbwp')), []];
    }
    $instrument = $this->selectedInstrument($json);
    if ($instrument === null) {
      return [400, $this->errorBody('invalid_request', Message::MISSING, __('payment.instruments fehlt.', 'lbwp'), '$.payment.instruments'), []];
    }
    if (isset($json['buyer']) && is_array($json['buyer'])) {
      $session->setBuyer($this->mapBuyer($json['buyer']));
    }
    if (isset($instrument['billing_address']) && is_array($instrument['billing_address'])) {
      $billing = $this->mapAddressIn($instrument['billing_address']);
      $session->setBillingAddress($billing);
      if (($billing['email'] ?? '') !== '' && empty($session->get('buyer')['email'])) {
        $session->setBuyer(['email' => $billing['email']]);
      }
    }
    $previousTotal = (int) $session->get('last_total', -1);
    $session->recalculate();
    $this->requireBuyer($session);
    $currentTotal = Money::toMinor((float) $session->getOrder()->get_total(), $session->getOrder()->get_currency());
    if ($previousTotal >= 0 && $previousTotal !== $currentTotal) {
      $session->addMessage(Message::error(Message::PRICE_CHANGED, __('Der Gesamtbetrag hat sich geändert, bitte prüfen.', 'lbwp'), '$.totals'));
    }
    if (!$session->isReadyForPayment()) {
      $session->set('status', Session::STATUS_INCOMPLETE);
      $session->save();
      return [200, $this->render($session), []];
    }
    $handlerId = sanitize_text_field((string) ($instrument['handler_id'] ?? ''));
    $handler = Handlers::find(self::PROTOCOL, $handlerId);
    if ($handler === null) {
      $handlers = Handlers::forProtocol(self::PROTOCOL);
      $handler = count($handlers) === 1 && (Settings::isTestMode() || $handlerId === '') ? $handlers[0] : null;
    }
    if ($handler === null) {
      $session->addMessage(Message::error(Message::INVALID, __('Unbekannter Zahlungs-Handler.', 'lbwp'), '$.payment.instruments[0].handler_id'));
      $session->save();
      return [200, $this->renderUnrecoverable($session), []];
    }
    $signals = (array) ($json['signals'] ?? []);
    $instrument['signals'] = $signals;
    $session->set('status', Session::STATUS_READY);
    $session->markCompleting();
    $result = $handler->charge($session->getOrder(), $instrument);
    if (empty($result['success'])) {
      $session->markReady();
      $code = !empty($result['requires_action']) ? Message::REQUIRES_3DS : Message::PAYMENT_DECLINED;
      $session->addMessage(Message::error($code, (string) ($result['message'] ?: __('Zahlung abgelehnt.', 'lbwp')), '$.payment.instruments[0]'));
      Log::paymentError($handler->getId(), (string) ($result['error_code'] ?? ''), ['session' => $session->getId()]);
      return [200, $this->render($session), []];
    }
    $order = OrderFactory::finalize($session, (string) ($result['transaction_id'] ?? ''), $handler->getId(), $handler->getTitle(self::PROTOCOL), !empty($result['captured']), [
      'platform' => $this->platform($request) ?: 'google',
      'order_notes' => (string) $session->get('order_notes', ''),
      'risk_signals' => $signals,
      'buyer_ip' => (string) ($signals['dev.ucp.buyer_ip'] ?? $signals['com.google.ip_address'] ?? ''),
      'user_agent' => (string) ($signals['dev.ucp.user_agent'] ?? ''),
    ]);
    $session->set('payment_instrument', $this->publicInstrument($instrument));
    $session->markCompleted($order);
    do_action('aboon_agentic_ucp_order_created', $order, $session);
    return [200, $this->render($session), []];
  }

  /**
   * POST /checkout-sessions/{id}/cancel
   * @param Session|null $session the session
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return array triple
   */
  protected function cancelSession(?Session $session, array $json, WP_REST_Request $request): array
  {
    $status = $session->getStatus();
    if (in_array($status, [Session::STATUS_COMPLETED, Session::STATUS_COMPLETING], true)) {
      return [405, $this->errorBody('invalid_request', Message::INVALID, __('Session kann nicht mehr abgebrochen werden.', 'lbwp')), []];
    }
    if ($status !== Session::STATUS_CANCELED) {
      $session->cancel();
    }
    return [200, $this->render($session), []];
  }

  /**
   * Applies buyer, fulfillment, discounts and notes of a create/update body.
   * @param Session $session the session
   * @param array $json body
   * @param bool $create whether this is the create request
   * @return void
   */
  protected function applyCommon(Session $session, array $json, bool $create): void
  {
    if (isset($json['buyer']) && is_array($json['buyer'])) {
      $session->setBuyer($this->mapBuyer($json['buyer']));
    }
    if (isset($json['fulfillment']) && is_array($json['fulfillment'])) {
      $this->applyFulfillment($session, $json['fulfillment']);
    }
    $codes = $json['discounts']['codes'] ?? $json['coupons'] ?? null;
    if (is_array($codes)) {
      $session->setCoupons($codes);
    }
    if (isset($json['order_notes']) || isset($json['notes'])) {
      $session->set('order_notes', sanitize_textarea_field((string) ($json['order_notes'] ?? $json['notes'])));
    }
    if (isset($json['context']) && is_array($json['context'])) {
      $session->set('context', $json['context']);
    }
  }

  /**
   * Applies the fulfillment block (first method, selected destination, selected option).
   * @param Session $session the session
   * @param array $fulfillment fulfillment block
   * @return void
   */
  protected function applyFulfillment(Session $session, array $fulfillment): void
  {
    $method = null;
    foreach ((array) ($fulfillment['methods'] ?? []) as $candidate) {
      if (is_array($candidate)) {
        $method = $candidate;
        break;
      }
    }
    if ($method === null) {
      return;
    }
    $destinations = (array) ($method['destinations'] ?? []);
    $selectedId = (string) ($method['selected_destination_id'] ?? '');
    $destination = null;
    foreach ($destinations as $candidate) {
      if (is_array($candidate) && ($selectedId === '' || (string) ($candidate['id'] ?? '') === $selectedId)) {
        $destination = $candidate;
        break;
      }
    }
    if ($destination !== null) {
      $address = $this->mapAddressIn($destination);
      $address['id'] = (string) ($destination['id'] ?? '');
      $session->setFulfillmentAddress($address);
      if (($address['email'] ?? '') !== '' && empty($session->get('buyer')['email'])) {
        $session->setBuyer(['email' => $address['email']]);
      }
    }
    foreach ((array) ($method['groups'] ?? []) as $group) {
      if (is_array($group) && !empty($group['selected_option_id'])) {
        $session->selectFulfillmentOption((string) $group['selected_option_id']);
        break;
      }
    }
    if (!empty($method['selected_option_id'])) {
      $session->selectFulfillmentOption((string) $method['selected_option_id']);
    }
  }

  /**
   * Recalculates, applies the UCP buyer requirement, refreshes the status and saves.
   * @param Session $session the session
   * @return void
   */
  protected function finishRequest(Session $session): void
  {
    $session->recalculate();
    $this->requireBuyer($session);
    $session->refreshStatus();
    $session->save();
  }

  /**
   * Adds a blocking message when the buyer e-mail is missing (required before complete).
   * @param Session $session the session
   * @return void
   */
  protected function requireBuyer(Session $session): void
  {
    $buyer = (array) $session->get('buyer', []);
    if (empty($buyer['email']) || !is_email($buyer['email'])) {
      $session->addMessage(Message::error(Message::MISSING, __('E-Mail-Adresse des Käufers fehlt.', 'lbwp'), '$.buyer.email'));
    }
  }

  /**
   * Picks the selected (or first) payment instrument.
   * @param array $json complete body
   * @return array|null instrument
   */
  protected function selectedInstrument(array $json): ?array
  {
    $instruments = $json['payment']['instruments'] ?? null;
    if (!is_array($instruments) || count($instruments) === 0) {
      return null;
    }
    foreach ($instruments as $instrument) {
      if (is_array($instrument) && !empty($instrument['selected'])) {
        return $instrument;
      }
    }
    $first = reset($instruments);
    return is_array($first) ? $first : null;
  }

  /**
   * Strips credentials from an instrument for echoing.
   * @param array $instrument instrument
   * @return array public fields
   */
  protected function publicInstrument(array $instrument): array
  {
    return array_intersect_key($instrument, array_flip(['id', 'handler_id', 'type', 'selected', 'display', 'billing_address']));
  }

  /**
   * Determines the session language from context.language or Accept-Language.
   * @param array $json body
   * @param WP_REST_Request $request request
   * @return string language slug or empty
   */
  protected function contextLanguage(array $json, WP_REST_Request $request): string
  {
    $lang = strtolower(substr((string) ($json['context']['language'] ?? ''), 0, 2));
    if ($lang !== '' && \LBWP\Util\Multilang::isActive()) {
      return $lang;
    }
    return $this->requestLanguage($request);
  }

  /**
   * Maps UCP line items to neutral [id, quantity].
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
      $mapped[] = ['id' => (string) ($item['item']['id'] ?? $item['id'] ?? ''), 'quantity' => (int) ($item['quantity'] ?? 0)];
    }
    return $mapped;
  }

  /**
   * Maps a UCP buyer object.
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
    if (isset($buyer['phone_number']) || isset($buyer['phone'])) {
      $mapped['phone'] = (string) ($buyer['phone_number'] ?? $buyer['phone']);
    }
    return $mapped;
  }

  /**
   * Maps a schema.org style UCP address to the neutral shape.
   * @param array $address address
   * @return array neutral address (plus email/phone when present)
   */
  protected function mapAddressIn(array $address): array
  {
    $name = trim((string) ($address['full_name'] ?? $address['name'] ?? trim(((string) ($address['first_name'] ?? '')) . ' ' . ((string) ($address['last_name'] ?? '')))));
    return [
      'name' => $name,
      'line_one' => (string) ($address['street_address'] ?? ''),
      'line_two' => (string) ($address['extended_address'] ?? ''),
      'city' => (string) ($address['address_locality'] ?? ''),
      'state' => (string) ($address['address_region'] ?? ''),
      'country' => (string) ($address['address_country'] ?? ''),
      'postal_code' => (string) ($address['postal_code'] ?? ''),
      'email' => (string) ($address['email'] ?? ''),
      'phone' => (string) ($address['phone_number'] ?? ''),
    ];
  }

  /**
   * Maps a neutral address to the UCP shape.
   * @param array $address neutral address
   * @param string $id destination id
   * @return array UCP address
   */
  protected function mapAddressOut(array $address, string $id = ''): array
  {
    [$first, $last] = Session::splitName((string) ($address['name'] ?? ''));
    $out = array_filter([
      'id' => $id,
      'first_name' => $first,
      'last_name' => $last,
      'street_address' => (string) ($address['line_one'] ?? ''),
      'extended_address' => (string) ($address['line_two'] ?? ''),
      'address_locality' => (string) ($address['city'] ?? ''),
      'address_region' => (string) ($address['state'] ?? ''),
      'postal_code' => (string) ($address['postal_code'] ?? ''),
      'address_country' => (string) ($address['country'] ?? ''),
      'phone_number' => (string) ($address['phone'] ?? ''),
    ], 'strlen');
    return $out;
  }

  /**
   * @return string URL the buyer is handed to for manual continuation
   */
  protected function continueUrl(): string
  {
    $url = function_exists('wc_get_checkout_url') ? (string) wc_get_checkout_url() : '';
    return (string) apply_filters('aboon_agentic_ucp_continue_url', $url !== '' ? $url : get_bloginfo('url'));
  }

  /**
   * Renders the unrecoverable error shape with the session's messages.
   * @param Session $session the session
   * @return array body
   */
  protected function renderUnrecoverable(Session $session): array
  {
    $messages = $this->renderMessages($session->getMessages());
    foreach ($messages as &$message) {
      $message['severity'] = Ucp::SEVERITY_UNRECOVERABLE;
    }
    return ['ucp' => ['version' => Settings::ucpVersion(), 'status' => 'error'], 'messages' => $messages, 'continue_url' => $this->continueUrl()];
  }

  /**
   * Renders the UCP checkout resource.
   * @param Session $session the session
   * @return array body
   */
  public function render(Session $session): array
  {
    $order = $session->getOrder();
    $currency = strtoupper($order->get_currency());
    $inclusive = Pricing::isTaxInclusiveShop();
    $status = Ucp::STATUS_MAP[$session->getStatus()] ?? Ucp::STATUS_INCOMPLETE;
    $lineItems = [];
    $lineIds = [];
    foreach ($session->getLineItems() as $line) {
      $item = $line['item'];
      $product = $line['product'];
      $lineId = 'li_' . $item->get_id();
      $lineIds[] = $lineId;
      $entry = [
        'id' => $lineId,
        'item' => array_filter([
          'id' => $line['item_id'],
          'title' => $item->get_name(),
          'price' => Pricing::unitAmount($item, $inclusive, $order->get_currency()),
          'image_url' => $product ? Catalog::imageUrl($product) : '',
          'url' => $product ? (string) $product->get_permalink() : '',
        ], fn($v) => $v !== ''),
        'quantity' => (int) $item->get_quantity(),
        'totals' => $this->lineTotals($item, $inclusive, $order->get_currency()),
      ];
      $lineItems[] = $entry;
    }
    $body = [
      'ucp' => [
        'version' => (string) $session->get('version', Settings::ucpVersion()),
        'status' => 'success',
        'capabilities' => [
          Ucp::CAP_CHECKOUT => [['version' => Settings::ucpVersion()]],
          Ucp::CAP_FULFILLMENT => [['version' => Settings::ucpVersion()]],
          Ucp::CAP_DISCOUNT => [['version' => Settings::ucpVersion()]],
          Ucp::CAP_ORDER => [['version' => Settings::ucpVersion()]],
        ],
        'payment_handlers' => $this->paymentHandlers(),
      ],
      'id' => $session->getId(),
      'status' => $status,
      'currency' => $currency,
      'line_items' => $lineItems,
      'totals' => $this->totals(Pricing::sessionTotals($order, $inclusive), $inclusive),
      'messages' => $this->renderMessages($session->getMessages()),
      'links' => $this->renderLinks(),
      'continue_url' => $this->continueUrl(),
    ];
    $buyer = (array) $session->get('buyer', []);
    if (count(array_filter($buyer)) > 0) {
      $body['buyer'] = array_filter([
        'email' => $buyer['email'] ?? '',
        'first_name' => $buyer['first_name'] ?? '',
        'last_name' => $buyer['last_name'] ?? '',
        'phone_number' => $buyer['phone'] ?? '',
      ], 'strlen');
    }
    $body['fulfillment'] = $this->renderFulfillment($session, $lineIds);
    $instrument = $session->get('payment_instrument');
    if (is_array($instrument) && count($instrument) > 0) {
      $body['payment'] = ['instruments' => [$instrument]];
    }
    if ($session->getStatus() === Session::STATUS_COMPLETED) {
      $body['order'] = [
        'id' => (string) $order->get_id(),
        'label' => '#' . $order->get_order_number(),
        'permalink_url' => OrderFactory::permalink($order),
      ];
    }
    $session->set('last_total', Money::toMinor((float) $order->get_total(), $order->get_currency()));
    return $body;
  }

  /**
   * Renders the fulfillment block (single method, single group).
   * @param Session $session the session
   * @param array $lineIds line item ids
   * @return array fulfillment block
   */
  protected function renderFulfillment(Session $session, array $lineIds): array
  {
    $options = (array) $session->get('fulfillment_options', []);
    $selected = $session->getSelectedOption();
    $digital = count($options) === 1 && ($options[0]['type'] ?? '') === Shipping::TYPE_DIGITAL;
    $method = [
      'id' => self::METHOD_ID,
      'type' => $digital ? 'digital' : 'shipping',
      'line_item_ids' => $lineIds,
    ];
    $fulfillment = (array) $session->get('fulfillment', []);
    if (($fulfillment['country'] ?? '') !== '') {
      $destinationId = (string) ($fulfillment['id'] ?? '') ?: self::DESTINATION_ID;
      $method['destinations'] = [$this->mapAddressOut($fulfillment, $destinationId)];
      $method['selected_destination_id'] = $destinationId;
    }
    $renderedOptions = [];
    foreach ($options as $option) {
      $renderedOptions[] = [
        'id' => (string) $option['id'],
        'title' => (string) $option['title'],
        'description' => (string) ($option['description'] ?? ''),
        'totals' => [['type' => 'total', 'amount' => (int) $option['amount']]],
      ];
    }
    $group = ['id' => self::GROUP_ID, 'line_item_ids' => $lineIds, 'options' => $renderedOptions];
    if ($selected !== null) {
      $group['selected_option_id'] = (string) $selected['id'];
    }
    $method['groups'] = [$group];
    return ['methods' => [$method]];
  }

  /**
   * Renders line item totals (subtotal + total, tax when exclusive).
   * @param \WC_Order_Item_Product $item the item
   * @param bool $inclusive tax inclusive display
   * @param string $currency currency
   * @return array totals
   */
  protected function lineTotals(\WC_Order_Item_Product $item, bool $inclusive, string $currency): array
  {
    $totals = [];
    foreach (Pricing::lineTotals($item, $inclusive, $currency) as $row) {
      if (in_array($row['type'], [Pricing::SUBTOTAL, Pricing::TOTAL, Pricing::TAX, Pricing::ITEMS_DISCOUNT], true)) {
        $totals[] = ['type' => $row['type'] === Pricing::ITEMS_DISCOUNT ? 'discount' : $row['type'], 'amount' => (int) $row['amount']];
      }
    }
    return $totals;
  }

  /**
   * Renders session totals with display texts (tax folded into subtotal in inclusive shops).
   * @param array $totals neutral totals
   * @param bool $inclusive tax inclusive display
   * @return array UCP totals
   */
  protected function totals(array $totals, bool $inclusive): array
  {
    $rendered = [];
    foreach ($totals as $row) {
      if (in_array($row['type'], [Pricing::ITEMS_BASE, Pricing::ITEMS_DISCOUNT], true)) {
        continue;
      }
      $text = Ucp::TOTAL_TEXT[$row['type']] ?? $row['type'];
      if ($inclusive && $row['type'] === Pricing::SUBTOTAL) {
        $text .= ' (inkl. MwSt.)';
      }
      $rendered[] = ['type' => $row['type'], 'display_text' => $text, 'amount' => (int) $row['amount']];
    }
    return $rendered;
  }

  /**
   * Renders neutral messages in UCP format.
   * @param array $messages neutral messages
   * @return array UCP messages
   */
  protected function renderMessages(array $messages): array
  {
    $rendered = [];
    foreach ($messages as $message) {
      $entry = [
        'type' => $message['level'] === Message::LEVEL_ERROR ? 'error' : ($message['level'] === Message::LEVEL_WARNING ? 'warning' : 'info'),
        'code' => $message['code'],
        'content_type' => 'plain',
        'content' => $message['content'],
        'severity' => empty($message['blocking']) ? Ucp::SEVERITY_RECOVERABLE : ($message['code'] === Message::PRICE_CHANGED ? Ucp::SEVERITY_BUYER_REVIEW : Ucp::SEVERITY_RECOVERABLE),
      ];
      if (!empty($message['path'])) {
        $entry['path'] = $message['path'];
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
      $links[] = ['type' => Ucp::LINK_TYPES[$key] ?? $key, 'url' => $url, 'title' => Ucp::LINK_TITLES[$key] ?? $key];
    }
    return $links;
  }

  /**
   * Builds the payment_handlers block from the configured handlers.
   * @return array handler family => declarations
   */
  public function paymentHandlers(): array
  {
    $handlers = [];
    foreach (Handlers::forProtocol(self::PROTOCOL) as $handler) {
      $family = $handler->getId() === 'deferred' ? 'ch.comotive.deferred' : Ucp::HANDLER_GOOGLE_PAY;
      $handlers[$family][] = $handler->getDeclaration(self::PROTOCOL);
    }
    return $handlers;
  }

  /**
   * Builds the public business profile served at /.well-known/ucp.
   * @return array profile
   */
  public function buildProfile(): array
  {
    $version = Settings::ucpVersion();
    $base = 'https://ucp.dev/' . $version;
    $capability = fn(string $name, array $extra = []) => array_merge([
      'version' => $version,
      'spec' => $base . '/specification/' . $name,
      'schema' => $base . '/schemas/shopping/' . $name . '.json',
    ], $extra);
    $profile = [
      'ucp' => [
        'version' => $version,
        'services' => [
          Ucp::SERVICE => [[
            'version' => $version,
            'spec' => $base . '/specification/overview',
            'transport' => 'rest',
            'schema' => $base . '/services/shopping/rest.openapi.json',
            'endpoint' => Settings::ucpEndpointUrl(),
          ]],
        ],
        'capabilities' => [
          Ucp::CAP_CHECKOUT => [$capability('checkout')],
          Ucp::CAP_FULFILLMENT => [$capability('fulfillment', ['extends' => Ucp::CAP_CHECKOUT])],
          Ucp::CAP_DISCOUNT => [$capability('discount', ['extends' => Ucp::CAP_CHECKOUT])],
          Ucp::CAP_ORDER => [$capability('order')],
        ],
        'payment_handlers' => $this->paymentHandlers(),
      ],
      'signing_keys' => [],
    ];
    $jwk = Signature::publicJwk(Settings::ucpSigningKey());
    if (count($jwk) > 0) {
      $jwk['kid'] = Settings::ucpSigningKid() !== '' ? Settings::ucpSigningKid() : $jwk['kid'];
      $profile['signing_keys'][] = $jwk;
    }
    return (array) apply_filters('aboon_agentic_ucp_profile', $profile);
  }
}
