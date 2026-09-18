<?php

namespace LBWP\Aboon\AgenticCommerce\Payment;

use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Aboon\AgenticCommerce\Settings;

/**
 * Minimal Stripe REST client for PaymentIntent creation (form encoded, bearer auth, idempotent).
 * Only the calls needed by the agentic handlers are implemented, no SDK dependency.
 * @package LBWP\Aboon\AgenticCommerce\Payment
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class StripeApi
{
  public const string ENDPOINT = 'https://api.stripe.com/v1/';
  public const int TIMEOUT = 20;

  /**
   * Creates a PaymentIntent.
   * @param string $protocol acp|ucp (selects the secret key)
   * @param array $params PaymentIntent parameters (nested arrays allowed)
   * @param string $idempotencyKey Stripe idempotency key
   * @return array ok, intent (array), error_code, decline_code, message
   */
  public static function createPaymentIntent(string $protocol, array $params, string $idempotencyKey): array
  {
    return self::post($protocol, 'payment_intents', $params, $idempotencyKey);
  }

  /**
   * Executes a POST request against the Stripe API.
   * @param string $protocol acp|ucp
   * @param string $path API path relative to /v1/
   * @param array $params parameters
   * @param string $idempotencyKey idempotency key
   * @return array ok, intent, error_code, decline_code, message
   */
  public static function post(string $protocol, string $path, array $params, string $idempotencyKey): array
  {
    $secret = Settings::stripeSecretKey($protocol);
    if ($secret === '') {
      return self::failure('configuration', '', 'Stripe secret key not configured');
    }
    if (Settings::isTestMode() && !str_starts_with($secret, 'sk_test_')) {
      return self::failure('configuration', '', 'Test mode active but a live Stripe key is configured');
    }
    $headers = [
      'Authorization' => 'Bearer ' . $secret,
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Idempotency-Key' => $idempotencyKey,
    ];
    $version = (string) apply_filters('aboon_agentic_stripe_version', '', $protocol);
    if ($version !== '') {
      $headers['Stripe-Version'] = $version;
    }
    $response = wp_remote_post(self::ENDPOINT . ltrim($path, '/'), [
      'timeout' => self::TIMEOUT,
      'headers' => $headers,
      'body' => http_build_query($params, '', '&'),
    ]);
    if (is_wp_error($response)) {
      Log::paymentError('stripe', 'HTTP error: ' . $response->get_error_message(), ['path' => $path]);
      return self::failure('network_error', '', $response->get_error_message());
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $json = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($json)) {
      return self::failure('invalid_response', '', 'Unexpected response from Stripe (' . $status . ')');
    }
    if ($status >= 400 || isset($json['error'])) {
      $error = (array) ($json['error'] ?? []);
      Log::paymentError('stripe', (string) ($error['message'] ?? 'unknown'), [
        'path' => $path,
        'status' => $status,
        'code' => $error['code'] ?? '',
        'decline_code' => $error['decline_code'] ?? '',
        'type' => $error['type'] ?? '',
      ]);
      return self::failure((string) ($error['code'] ?? $error['type'] ?? 'stripe_error'), (string) ($error['decline_code'] ?? ''), (string) ($error['message'] ?? ''));
    }
    return ['ok' => true, 'intent' => $json, 'error_code' => '', 'decline_code' => '', 'message' => ''];
  }

  /**
   * Builds a failure result.
   * @param string $code error code
   * @param string $declineCode decline code
   * @param string $message message
   * @return array result
   */
  protected static function failure(string $code, string $declineCode, string $message): array
  {
    return ['ok' => false, 'intent' => [], 'error_code' => $code, 'decline_code' => $declineCode, 'message' => $message];
  }

  /**
   * Maps a PaymentIntent result to the handler charge result.
   * @param array $result result of createPaymentIntent()
   * @return array success, transaction_id, captured, error_code, message, requires_action, action
   */
  public static function toChargeResult(array $result): array
  {
    if (empty($result['ok'])) {
      $code = $result['error_code'];
      $requiresAction = in_array($code, ['authentication_required', 'card_authentication_required'], true);
      return [
        'success' => false,
        'transaction_id' => '',
        'captured' => false,
        'error_code' => $code,
        'message' => $requiresAction ? __('Die Zahlung erfordert eine zusätzliche Authentifizierung (3-D Secure), die hier nicht unterstützt wird.', 'lbwp') : __('Die Zahlung wurde abgelehnt.', 'lbwp'),
        'requires_action' => $requiresAction,
        'action' => [],
      ];
    }
    $intent = $result['intent'];
    $status = (string) ($intent['status'] ?? '');
    $id = (string) ($intent['id'] ?? '');
    if ($status === 'requires_action') {
      return ['success' => false, 'transaction_id' => $id, 'captured' => false, 'error_code' => 'requires_action', 'message' => __('3-D Secure wird nicht unterstützt.', 'lbwp'), 'requires_action' => true, 'action' => (array) ($intent['next_action'] ?? [])];
    }
    if (!in_array($status, ['succeeded', 'requires_capture', 'processing'], true)) {
      return ['success' => false, 'transaction_id' => $id, 'captured' => false, 'error_code' => 'intent_' . $status, 'message' => __('Die Zahlung wurde abgelehnt.', 'lbwp'), 'requires_action' => false, 'action' => []];
    }
    return ['success' => true, 'transaction_id' => $id, 'captured' => $status === 'succeeded', 'error_code' => '', 'message' => '', 'requires_action' => false, 'action' => []];
  }
}
