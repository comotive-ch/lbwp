<?php

namespace LBWP\Aboon\AgenticCommerce;

use OpenSSLAsymmetricKey;
use WP_REST_Request;

/**
 * Signing and verification primitives for both protocols: HMAC-SHA256 (ACP), RFC 9421 HTTP
 * message signatures with ES256 (UCP), RFC 9530 content digests and EC P-256 key handling.
 * ES256 signatures from OpenSSL are DER encoded; the wire format needs raw R||S, both directions
 * are converted here.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Signature
{
  /**
   * @var int accepted clock skew in seconds for timestamps
   */
  public const int MAX_SKEW = 300;
  /**
   * @var string RFC 9421 algorithm identifier for ES256
   */
  public const string ALG = 'ecdsa-p256-sha256';
  /**
   * @var string DER prefix of a SubjectPublicKeyInfo for an uncompressed P-256 point
   */
  protected const string P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
  /**
   * @var string JWKS transient prefix
   */
  protected const string JWKS_TRANSIENT = 'agentic_jwks_';

  /**
   * Returns the string that is HMAC signed for ACP. Kept in one place so a change of the signed
   * payload definition (body only vs. timestamp + body) is a one-line change.
   * @param string $timestamp the Timestamp header value
   * @param string $body raw request body
   * @return string the signed string
   */
  public static function hmacSignedString(string $timestamp, string $body): string
  {
    return $body;
  }

  /**
   * Creates a base64 HMAC-SHA256 signature over the raw body.
   * @param string $body raw body
   * @param string $secret shared secret
   * @param string $timestamp optional timestamp included per hmacSignedString()
   * @return string base64 signature
   */
  public static function signHmac(string $body, string $secret, string $timestamp = ''): string
  {
    return base64_encode(hash_hmac('sha256', self::hmacSignedString($timestamp, $body), $secret, true));
  }

  /**
   * Verifies an ACP HMAC signature and its timestamp freshness.
   * @param string $body raw body
   * @param string $timestamp Timestamp header (unix seconds or RFC 3339)
   * @param string $header Signature header value (base64, optionally hex)
   * @param string $secret shared secret
   * @return bool true when valid and fresh
   */
  public static function verifyHmac(string $body, string $timestamp, string $header, string $secret): bool
  {
    if ($secret === '' || $header === '') {
      return false;
    }
    $ts = is_numeric($timestamp) ? (int) $timestamp : (int) strtotime($timestamp);
    if ($ts <= 0 || abs(time() - $ts) > self::MAX_SKEW) {
      return false;
    }
    $expected = self::signHmac($body, $secret, $timestamp);
    $expectedHex = bin2hex(base64_decode($expected));
    return hash_equals($expected, trim($header)) || hash_equals($expectedHex, strtolower(trim($header)));
  }

  /**
   * Builds an RFC 9530 Content-Digest header value over the raw body.
   * @param string $body raw body bytes
   * @return string e.g. sha-256=:base64:
   */
  public static function contentDigest(string $body): string
  {
    return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
  }

  /**
   * Generates an ES256 (P-256) key pair.
   * @return array [private PEM, public JWK array, kid]
   */
  public static function generateKeyPair(): array
  {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
      return ['', [], ''];
    }
    $pem = '';
    openssl_pkey_export($key, $pem);
    $jwk = self::publicJwk($pem);
    return [$pem, $jwk, $jwk['kid'] ?? ''];
  }

  /**
   * Derives the public JWK (with RFC 7638 thumbprint as kid) from a private key PEM.
   * @param string $pem private key PEM
   * @return array JWK or empty array on failure
   */
  public static function publicJwk(string $pem): array
  {
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
      return [];
    }
    $details = openssl_pkey_get_details($key);
    if (!isset($details['ec']['x'], $details['ec']['y'])) {
      return [];
    }
    $x = self::base64url(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT));
    $y = self::base64url(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT));
    $thumbprint = self::base64url(hash('sha256', '{"crv":"P-256","kty":"EC","x":"' . $x . '","y":"' . $y . '"}', true));
    return ['kty' => 'EC', 'crv' => 'P-256', 'x' => $x, 'y' => $y, 'kid' => $thumbprint, 'use' => 'sig', 'alg' => 'ES256'];
  }

  /**
   * Converts a P-256 JWK into an OpenSSL public key.
   * @param array $jwk the JWK
   * @return OpenSSLAsymmetricKey|false the key
   */
  public static function jwkToPublicKey(array $jwk): OpenSSLAsymmetricKey|false
  {
    if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') {
      return false;
    }
    $x = self::base64urlDecode((string) ($jwk['x'] ?? ''));
    $y = self::base64urlDecode((string) ($jwk['y'] ?? ''));
    if (strlen($x) !== 32 || strlen($y) !== 32) {
      return false;
    }
    $der = hex2bin(self::P256_SPKI_PREFIX) . "\x04" . $x . $y;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
  }

  /**
   * Signs a signature base with ES256 and returns the raw R||S signature.
   * @param string $base signature base
   * @param string $pem private key PEM
   * @return string 64 raw bytes or empty on failure
   */
  public static function signEs256(string $base, string $pem): string
  {
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
      return '';
    }
    $der = '';
    if (!openssl_sign($base, $der, $key, OPENSSL_ALGO_SHA256)) {
      return '';
    }
    return self::derToRaw($der);
  }

  /**
   * Verifies a raw R||S ES256 signature against a signature base.
   * @param string $base signature base
   * @param string $raw 64 raw bytes
   * @param array $jwk public JWK
   * @return bool true when valid
   */
  public static function verifyEs256(string $base, string $raw, array $jwk): bool
  {
    $key = self::jwkToPublicKey($jwk);
    if ($key === false || strlen($raw) !== 64) {
      return false;
    }
    return openssl_verify($base, self::rawToDer($raw), $key, OPENSSL_ALGO_SHA256) === 1;
  }

  /**
   * Converts a DER ECDSA signature into raw R||S (32 bytes each).
   * @param string $der DER bytes
   * @return string 64 raw bytes or empty on malformed input
   */
  public static function derToRaw(string $der): string
  {
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
      return '';
    }
    $offset = 2;
    if (ord($der[1]) & 0x80) {
      $offset += ord($der[1]) & 0x7f;
    }
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
      if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
        return '';
      }
      $length = ord($der[$offset + 1]);
      $value = substr($der, $offset + 2, $length);
      $offset += 2 + $length;
      $value = ltrim($value, "\0");
      if (strlen($value) > 32) {
        return '';
      }
      $parts[] = str_pad($value, 32, "\0", STR_PAD_LEFT);
    }
    return $parts[0] . $parts[1];
  }

  /**
   * Converts a raw R||S signature into DER.
   * @param string $raw 64 raw bytes
   * @return string DER bytes
   */
  public static function rawToDer(string $raw): string
  {
    $encode = function (string $int): string {
      $int = ltrim($int, "\0");
      if ($int === '' || (ord($int[0]) & 0x80)) {
        $int = "\0" . $int;
      }
      return "\x02" . chr(strlen($int)) . $int;
    };
    $body = $encode(substr($raw, 0, 32)) . $encode(substr($raw, 32, 32));
    return "\x30" . chr(strlen($body)) . $body;
  }

  /**
   * Builds the RFC 9421 signature base.
   * @param array $components ordered component names (e.g. "@method", "content-digest")
   * @param array $values component name => value
   * @param string $params the signature params string (component list + ;created=...;keyid=...)
   * @return string the base
   */
  public static function buildSignatureBase(array $components, array $values, string $params): string
  {
    $lines = [];
    foreach ($components as $name) {
      $lines[] = '"' . $name . '": ' . ($values[$name] ?? '');
    }
    $lines[] = '"@signature-params": ' . $params;
    return implode("\n", $lines);
  }

  /**
   * Serializes a component list plus params into the Signature-Input inner value.
   * @param array $components ordered component names
   * @param int $created creation timestamp
   * @param string $kid key id
   * @param string $tag application tag
   * @return string e.g. ("@method" "@path");created=1;keyid="k";alg="ecdsa-p256-sha256";tag="ucp"
   */
  public static function signatureParams(array $components, int $created, string $kid, string $tag = 'ucp'): string
  {
    $list = '(' . implode(' ', array_map(fn($c) => '"' . $c . '"', $components)) . ')';
    // UCP derives the algorithm from the JWK; "alg" is not part of the parameters
    return $list . ';created=' . $created . ';keyid="' . $kid . '"';
  }

  /**
   * Signs an outbound request per RFC 9421 with the configured UCP key.
   * @param string $method HTTP method
   * @param string $url absolute URL
   * @param string $body raw body
   * @param array $extraHeaders header name => value to include and cover (e.g. UCP-Agent, Webhook-Id)
   * @return array headers to send (Content-Digest, Content-Type, Signature-Input, Signature + extras)
   */
  public static function signRfc9421(string $method, string $url, string $body, array $extraHeaders = []): array
  {
    $pem = Settings::ucpSigningKey();
    $kid = Settings::ucpSigningKid();
    $parts = wp_parse_url($url);
    $headers = ['Content-Type' => 'application/json', 'Content-Digest' => self::contentDigest($body)];
    foreach ($extraHeaders as $name => $value) {
      $headers[$name] = $value;
    }
    $values = [
      '@method' => strtoupper($method),
      '@authority' => strtolower((string) ($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . $parts['port'] : ''),
      '@path' => (string) ($parts['path'] ?? '/'),
    ];
    $components = ['@method', '@authority', '@path'];
    if (!empty($parts['query'])) {
      $values['@query'] = '?' . $parts['query'];
      $components[] = '@query';
    }
    foreach ($headers as $name => $value) {
      $lower = strtolower($name);
      $components[] = $lower;
      $values[$lower] = trim((string) $value);
    }
    $params = self::signatureParams($components, time(), $kid);
    $raw = $pem === '' ? '' : self::signEs256(self::buildSignatureBase($components, $values, $params), $pem);
    if ($raw === '') {
      return $headers;
    }
    $headers['Signature-Input'] = 'sig1=' . $params;
    $headers['Signature'] = 'sig1=:' . base64_encode($raw) . ':';
    return $headers;
  }

  /**
   * Verifies an inbound RFC 9421 signature (ES256) against a JWKS.
   * @param WP_REST_Request $request the request
   * @param array $jwks list of JWKs (keys array)
   * @param string $error receives a short failure reason
   * @return bool true when valid
   */
  public static function verifyRfc9421(WP_REST_Request $request, array $jwks, string &$error = ''): bool
  {
    $input = (string) $request->get_header('signature_input');
    $signature = (string) $request->get_header('signature');
    if ($input === '' || $signature === '') {
      $error = 'missing_signature_headers';
      return false;
    }
    if (!preg_match('/^\s*([\w-]+)=\((.*?)\)(.*)$/s', $input, $m)) {
      $error = 'malformed_signature_input';
      return false;
    }
    $label = $m[1];
    $components = array_map(fn($c) => trim($c, '" '), preg_split('/\s+/', trim($m[2])) ?: []);
    $paramString = $m[3];
    $params = self::parseParams($paramString);
    if (!preg_match('/(?:^|,)\s*' . preg_quote($label, '/') . '=:([A-Za-z0-9+\/=]+):/', $signature, $sm)) {
      $error = 'signature_label_mismatch';
      return false;
    }
    $created = (int) ($params['created'] ?? 0);
    if ($created <= 0 || abs(time() - $created) > self::MAX_SKEW) {
      $error = 'signature_expired';
      return false;
    }
    if (isset($params['expires']) && (int) $params['expires'] < time()) {
      $error = 'signature_expired';
      return false;
    }
    $method = strtoupper($request->get_method());
    $required = ['@method', '@authority', '@path'];
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
      $required[] = 'content-digest';
    }
    foreach ($required as $name) {
      if (!in_array($name, $components, true)) {
        $error = 'missing_component_' . ltrim($name, '@');
        return false;
      }
    }
    $body = (string) $request->get_body();
    $digest = (string) $request->get_header('content_digest');
    if (in_array('content-digest', $components, true) && !hash_equals(self::contentDigest($body), trim($digest))) {
      $error = 'content_digest_mismatch';
      return false;
    }
    $values = self::requestComponentValues($request, $components);
    if ($values === null) {
      $error = 'missing_component_value';
      return false;
    }
    $jwk = self::findKey($jwks, (string) ($params['keyid'] ?? ''));
    if ($jwk === null) {
      $error = 'unknown_keyid';
      return false;
    }
    $base = self::buildSignatureBase($components, $values, '(' . $m[2] . ')' . $paramString);
    if (!self::verifyEs256($base, base64_decode($sm[1]), $jwk)) {
      $error = 'invalid_signature';
      return false;
    }
    return true;
  }

  /**
   * Resolves RFC 9421 component values from a request.
   * @param WP_REST_Request $request the request
   * @param array $components component names
   * @return array|null name => value, null when a covered header is missing
   */
  protected static function requestComponentValues(WP_REST_Request $request, array $components): ?array
  {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $values = [];
    foreach ($components as $name) {
      switch ($name) {
        case '@method':
          $values[$name] = strtoupper($request->get_method());
          break;
        case '@authority':
          $values[$name] = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $request->get_header('host')));
          break;
        case '@path':
          $values[$name] = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '/');
          break;
        case '@query':
          $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
          $values[$name] = '?' . $query;
          break;
        case '@target-uri':
          $values[$name] = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . $uri;
          break;
        default:
          $value = $request->get_header(str_replace('-', '_', $name));
          if ($value === null) {
            return null;
          }
          $values[$name] = trim((string) $value);
      }
    }
    return $values;
  }

  /**
   * Parses ";key=value;key2="value"" signature params.
   * @param string $params raw params string
   * @return array key => value (quotes stripped)
   */
  protected static function parseParams(string $params): array
  {
    $result = [];
    foreach (array_filter(explode(';', $params)) as $pair) {
      $pos = strpos($pair, '=');
      if ($pos === false) {
        continue;
      }
      $result[trim(substr($pair, 0, $pos))] = trim(substr($pair, $pos + 1), '" ');
    }
    return $result;
  }

  /**
   * Finds a JWK by kid (first key when kid is empty and exactly one key exists).
   * @param array $jwks list of keys
   * @param string $kid key id
   * @return array|null the key
   */
  public static function findKey(array $jwks, string $kid): ?array
  {
    foreach ($jwks as $jwk) {
      if (is_array($jwk) && ($jwk['kid'] ?? '') === $kid) {
        return $jwk;
      }
    }
    if ($kid === '' && count($jwks) === 1 && is_array($jwks[0])) {
      return $jwks[0];
    }
    return null;
  }

  /**
   * Fetches the signing keys of a platform profile (UCP-Agent header) with a one hour cache.
   * @param string $profileUrl https URL of the platform profile
   * @param bool $force bypass the cache
   * @return array list of JWKs
   */
  public static function fetchJwks(string $profileUrl, bool $force = false): array
  {
    if (!str_starts_with($profileUrl, 'https://') || !wp_http_validate_url($profileUrl)) {
      return [];
    }
    $transient = self::JWKS_TRANSIENT . md5($profileUrl);
    $cached = $force ? false : get_transient($transient);
    if (is_array($cached)) {
      return $cached;
    }
    $response = wp_remote_get($profileUrl, ['timeout' => 3, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      return [];
    }
    $json = json_decode((string) wp_remote_retrieve_body($response), true);
    $keys = $json['signing_keys'] ?? $json['ucp']['signing_keys'] ?? $json['keys'] ?? [];
    if (is_array($keys) && isset($keys['keys'])) {
      $keys = $keys['keys'];
    }
    $keys = is_array($keys) ? array_values($keys) : [];
    set_transient($transient, $keys, HOUR_IN_SECONDS);
    return $keys;
  }

  /**
   * Base64url encodes without padding.
   * @param string $data raw bytes
   * @return string encoded
   */
  public static function base64url(string $data): string
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  /**
   * Base64url decodes.
   * @param string $data encoded
   * @return string raw bytes
   */
  public static function base64urlDecode(string $data): string
  {
    $padded = strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4);
    return (string) base64_decode($padded);
  }
}
