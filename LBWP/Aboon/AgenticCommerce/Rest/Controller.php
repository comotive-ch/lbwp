<?php

namespace LBWP\Aboon\AgenticCommerce\Rest;

use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\SessionStore;
use LBWP\Module\Frontend\HTMLCache;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Abstract REST controller shared by ACP and UCP: route registration, cache avoidance, rate
 * limiting, version and body validation, idempotency with replay/conflict detection, in-flight
 * locking, error envelopes and request logging. Endpoint methods receive the (optional) session,
 * the decoded body and the request and return [status, body, headers].
 * @package LBWP\Aboon\AgenticCommerce\Rest
 * @author Mirko Baffa <mirko@comotive.ch>
 */
abstract class Controller
{
  public const int RATE_LIMIT_PER_MINUTE = 60;
  /**
   * @var Session|null session created by the create endpoint of the current request
   */
  protected ?Session $createdSession = null;

  /**
   * @return string acp|ucp
   */
  abstract protected function protocol(): string;

  /**
   * @return string REST namespace, e.g. acp/v1
   */
  abstract protected function namespace(): string;

  /**
   * @return array list of ['methods' => 'POST', 'route' => '/…', 'endpoint' => 'methodName']
   */
  abstract protected function routes(): array;

  /**
   * Authenticates a request.
   * @param WP_REST_Request $request the request
   * @return true|WP_Error true when authenticated
   */
  abstract public function authenticate(WP_REST_Request $request): true|WP_Error;

  /**
   * Validates the protocol version headers.
   * @param WP_REST_Request $request the request
   * @return array|null [status, body, headers] error triple or null when fine
   */
  abstract protected function checkVersion(WP_REST_Request $request): ?array;

  /**
   * Builds the protocol error body.
   * @param string $type error type
   * @param string $code error code
   * @param string $message message
   * @param string $param JSONPath or empty
   * @param array $extra additional fields
   * @return array body
   */
  abstract protected function errorBody(string $type, string $code, string $message, string $param = '', array $extra = []): array;

  /**
   * @return int HTTP status for an idempotency key reused with a different body
   */
  abstract protected function conflictStatus(): int;

  /**
   * @return string calling platform identifier taken from the request headers
   */
  abstract protected function platform(WP_REST_Request $request): string;

  /**
   * Registers the routes on rest_api_init and the error envelope filter.
   * @return void
   */
  public function register(): void
  {
    add_action('rest_api_init', [$this, 'registerRoutes']);
    add_filter('rest_request_after_callbacks', [$this, 'wrapErrors'], 10, 3);
  }

  /**
   * Registers all routes of the controller.
   * @return void
   */
  public function registerRoutes(): void
  {
    foreach ($this->routes() as $route) {
      $endpoint = $route['endpoint'];
      register_rest_route($this->namespace(), $route['route'], [
        'methods' => $route['methods'],
        'callback' => fn(WP_REST_Request $request) => $this->handle($request, $endpoint),
        'permission_callback' => [$this, 'permission'],
        'args' => [],
      ]);
    }
  }

  /**
   * Permission callback: authenticates and logs failures.
   * @param WP_REST_Request $request the request
   * @return true|WP_Error result
   */
  public function permission(WP_REST_Request $request): true|WP_Error
  {
    HTMLCache::avoidCache();
    $result = $this->authenticate($request);
    if ($result instanceof WP_Error) {
      Log::authFailure($this->protocol(), $result->get_error_code(), ['path' => $request->get_route(), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    }
    return $result;
  }

  /**
   * Rewrites WP_Error responses of our namespace into the protocol error envelope.
   * @param mixed $response the response
   * @param array $handler the handler
   * @param WP_REST_Request $request the request
   * @return mixed response
   */
  public function wrapErrors(mixed $response, array $handler, WP_REST_Request $request): mixed
  {
    if (!$response instanceof WP_Error || !str_starts_with($request->get_route(), '/' . $this->namespace())) {
      return $response;
    }
    $data = (array) $response->get_error_data();
    $status = (int) ($data['status'] ?? 500);
    $code = $status === 401 ? 'unauthorized' : ($status === 404 ? 'not_found' : 'invalid');
    return $this->respond($status, $this->errorBody($status >= 500 ? 'processing_error' : 'invalid_request', $code, $response->get_error_message()), [], $request);
  }

  /**
   * Handles a request end to end.
   * @param WP_REST_Request $request the request
   * @param string $endpoint endpoint method name
   * @return WP_REST_Response response
   */
  public function handle(WP_REST_Request $request, string $endpoint): WP_REST_Response
  {
    $started = microtime(true);
    HTMLCache::avoidCache();
    nocache_headers();
    $method = strtoupper($request->get_method());
    $isWrite = $method !== 'GET';
    $sessionId = sanitize_text_field((string) $request->get_param('id'));
    $key = sanitize_text_field((string) $request->get_header('idempotency_key'));
    $finish = function (int $status, array $body, array $headers = []) use ($request, $started, $sessionId, $key, $method) {
      Log::request($this->protocol(), $method, $request->get_route(), $sessionId, $key, $status, $started);
      return $this->respond($status, $body, $headers, $request);
    };
    if (!$this->withinRateLimit($sessionId !== '' ? $sessionId : sha1((string) $request->get_header('authorization'))) ) {
      return $finish(429, $this->errorBody('invalid_request', 'rate_limited', __('Zu viele Anfragen.', 'lbwp')));
    }
    $versionError = $this->checkVersion($request);
    if ($versionError !== null) {
      return $finish(...$versionError);
    }
    $raw = (string) $request->get_body();
    $json = [];
    if (trim($raw) !== '') {
      $json = json_decode($raw, true);
      if (!is_array($json)) {
        return $finish(400, $this->errorBody('invalid_request', 'invalid', __('Ungültiger JSON-Body.', 'lbwp')));
      }
    }
    if ($isWrite && $key === '') {
      return $finish(400, $this->errorBody('invalid_request', 'idempotency_key_required', __('Idempotency-Key Header fehlt.', 'lbwp')));
    }
    $session = null;
    if ($sessionId !== '') {
      $session = SessionStore::find($sessionId);
      if ($session === null || $session->getProtocol() !== $this->protocol()) {
        return $finish(404, $this->errorBody('invalid_request', 'not_found', __('Checkout-Session nicht gefunden.', 'lbwp')));
      }
    }
    $bodySha = hash('sha256', $raw);
    $recordKey = $endpoint . ':' . $key;
    if ($session === null) {
      return $this->handleCreate($request, $endpoint, $json, $key, $bodySha, $recordKey, $finish);
    }
    $order = $session->getOrder();
    if (!SessionStore::lock($order)) {
      return $finish(409, $this->errorBody('invalid_request', 'idempotency_key_in_flight', __('Eine Anfrage für diese Session wird gerade verarbeitet.', 'lbwp')));
    }
    try {
      if ($isWrite) {
        $record = SessionStore::getIdempotencyRecord($order, $recordKey);
        if ($record !== null) {
          return $this->replayOrConflict($record, $bodySha, $finish);
        }
      }
      [$status, $body, $headers] = $this->$endpoint($session, $json, $request);
      if ($isWrite) {
        SessionStore::putIdempotencyRecord($session->getOrder(), $recordKey, $this->record($bodySha, $status, $body, $headers));
      }
      return $finish($status, $body, $headers);
    } catch (Throwable $e) {
      Log::exception($e, ['endpoint' => $endpoint, 'session' => $sessionId]);
      return $finish(500, $this->errorBody('processing_error', 'invalid', __('Interner Fehler.', 'lbwp')));
    } finally {
      SessionStore::unlock($order);
    }
  }

  /**
   * Handles the session-less create endpoint with create-key idempotency and duplicate cleanup.
   * @param WP_REST_Request $request the request
   * @param string $endpoint endpoint method
   * @param array $json decoded body
   * @param string $key idempotency key
   * @param string $bodySha body hash
   * @param string $recordKey record key
   * @param callable $finish response finisher
   * @return WP_REST_Response response
   */
  protected function handleCreate(WP_REST_Request $request, string $endpoint, array $json, string $key, string $bodySha, string $recordKey, callable $finish): WP_REST_Response
  {
    $createKey = $this->protocol() . ':' . $key;
    $lockKey = 'inflight_create_' . sha1($createKey);
    if (!wp_cache_add($lockKey, 1, SessionStore::CACHE_GROUP, SessionStore::LOCK_TTL)) {
      return $finish(409, $this->errorBody('invalid_request', 'idempotency_key_in_flight', __('Eine Anfrage mit diesem Schlüssel wird gerade verarbeitet.', 'lbwp')));
    }
    try {
      $existing = SessionStore::findByIdempotencyKey($createKey);
      if (count($existing) > 0) {
        $record = SessionStore::getIdempotencyRecord($existing[0]->getOrder(), $recordKey);
        if ($record !== null) {
          return $this->replayOrConflict($record, $bodySha, $finish);
        }
      }
      $this->createdSession = null;
      [$status, $body, $headers] = $this->$endpoint(null, $json, $request);
      if ($this->createdSession === null) {
        return $finish($status, $body, $headers);
      }
      $created = $this->createdSession;
      $candidates = SessionStore::findByIdempotencyKey($createKey, 2);
      if (count($candidates) > 1 && $candidates[0]->getOrder()->get_id() !== $created->getOrder()->get_id()) {
        $created->getOrder()->delete(true);
        $record = SessionStore::getIdempotencyRecord($candidates[0]->getOrder(), $recordKey);
        if ($record !== null) {
          return $this->replayOrConflict($record, $bodySha, $finish);
        }
      }
      SessionStore::putIdempotencyRecord($created->getOrder(), $recordKey, $this->record($bodySha, $status, $body, $headers));
      return $finish($status, $body, $headers);
    } catch (Throwable $e) {
      Log::exception($e, ['endpoint' => $endpoint]);
      return $finish(500, $this->errorBody('processing_error', 'invalid', __('Interner Fehler.', 'lbwp')));
    } finally {
      wp_cache_delete($lockKey, SessionStore::CACHE_GROUP);
    }
  }

  /**
   * Replays a stored response or answers with the conflict status.
   * @param array $record stored record
   * @param string $bodySha hash of the current body
   * @param callable $finish response finisher
   * @return WP_REST_Response response
   */
  protected function replayOrConflict(array $record, string $bodySha, callable $finish): WP_REST_Response
  {
    if (hash_equals((string) ($record['body_sha'] ?? ''), $bodySha)) {
      $headers = (array) ($record['headers'] ?? []);
      $headers['Idempotent-Replayed'] = 'true';
      return $finish((int) ($record['status'] ?? 200), (array) ($record['response'] ?? []), $headers);
    }
    return $finish($this->conflictStatus(), $this->errorBody('invalid_request', 'idempotency_key_conflict', __('Idempotency-Key wurde bereits mit einem anderen Body verwendet.', 'lbwp')));
  }

  /**
   * Builds an idempotency record.
   * @param string $bodySha body hash
   * @param int $status status
   * @param array $body response body
   * @param array $headers response headers
   * @return array record
   */
  protected function record(string $bodySha, int $status, array $body, array $headers): array
  {
    return ['body_sha' => $bodySha, 'status' => $status, 'response' => $body, 'headers' => $headers, 'time' => time()];
  }

  /**
   * Builds the REST response with protocol headers.
   * @param int $status HTTP status
   * @param array $body body
   * @param array $headers additional headers
   * @param WP_REST_Request $request the request
   * @return WP_REST_Response response
   */
  protected function respond(int $status, array $body, array $headers, WP_REST_Request $request): WP_REST_Response
  {
    $response = new WP_REST_Response($body, $status);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    foreach (['idempotency_key' => 'Idempotency-Key', 'request_id' => 'Request-Id'] as $in => $out) {
      $value = $request->get_header($in);
      if ($value !== null && $value !== '') {
        $response->header($out, sanitize_text_field((string) $value));
      }
    }
    foreach ($headers as $name => $value) {
      $response->header($name, (string) $value);
    }
    return $response;
  }

  /**
   * Counts requests per principal and minute in the object cache.
   * @param string $principal session id or hashed credential
   * @return bool true when within the limit
   */
  protected function withinRateLimit(string $principal): bool
  {
    $limit = (int) apply_filters('aboon_agentic_rate_limit', self::RATE_LIMIT_PER_MINUTE, $this->protocol());
    if ($limit <= 0) {
      return true;
    }
    $cacheKey = 'rl_' . $this->protocol() . '_' . $principal . '_' . floor(time() / 60);
    wp_cache_add($cacheKey, 0, SessionStore::CACHE_GROUP, 120);
    $count = wp_cache_incr($cacheKey, 1, SessionStore::CACHE_GROUP);
    return $count === false || $count <= $limit;
  }

  /**
   * Reads the bearer token with fallbacks for servers stripping the Authorization header.
   * @param WP_REST_Request $request the request
   * @return string token or empty
   */
  protected function bearerToken(WP_REST_Request $request): string
  {
    $header = (string) ($request->get_header('authorization') ?? '');
    if ($header === '') {
      $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    if ($header === '' && function_exists('getallheaders')) {
      foreach ((array) getallheaders() as $name => $value) {
        if (strtolower((string) $name) === 'authorization') {
          $header = (string) $value;
        }
      }
    }
    if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
      return '';
    }
    return $m[1];
  }

  /**
   * Extracts a language slug from Accept-Language when Multilang knows it.
   * @param WP_REST_Request $request the request
   * @return string slug or empty
   */
  protected function requestLanguage(WP_REST_Request $request): string
  {
    $header = (string) ($request->get_header('accept_language') ?? '');
    if ($header === '' || !\LBWP\Util\Multilang::isActive()) {
      return '';
    }
    $first = strtolower(trim(explode(',', $header)[0]));
    $slug = substr(explode(';', $first)[0], 0, 2);
    foreach ((array) \LBWP\Util\Multilang::getAllLanguages() as $lang) {
      $langSlug = is_object($lang) ? ($lang->slug ?? '') : (is_array($lang) ? ($lang['slug'] ?? '') : (string) $lang);
      if ($langSlug === $slug) {
        return $slug;
      }
    }
    return '';
  }
}
