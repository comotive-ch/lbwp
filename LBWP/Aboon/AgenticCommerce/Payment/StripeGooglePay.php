<?php

namespace LBWP\Aboon\AgenticCommerce\Payment;

use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\Settings;
use WC_Order;

/**
 * UCP handler "com.google.pay": receives a Google Pay PAYMENT_GATEWAY token (gateway "stripe"),
 * extracts the Stripe token id and charges it through a PaymentIntent.
 * @package LBWP\Aboon\AgenticCommerce\Payment
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class StripeGooglePay implements PaymentHandler
{
  /**
   * @return string handler id (stable per merchant configuration)
   */
  public function getId(): string
  {
    return Settings::ucpHandlerId();
  }

  /**
   * @param string $protocol acp|ucp
   * @return string payment method title
   */
  public function getTitle(string $protocol): string
  {
    return 'Google Pay (Stripe)';
  }

  /**
   * @param string $protocol acp|ucp
   * @return array handler declaration for the UCP profile / session
   */
  public function getDeclaration(string $protocol): array
  {
    return [
      'id' => $this->getId(),
      'version' => Settings::ucpVersion(),
      'spec' => 'https://developers.google.com/merchant/ucp/guides/gpay-payment-handler',
      'config' => [
        'api_version' => 2,
        'api_version_minor' => 0,
        'environment' => Settings::isStripeTestKey('ucp') ? 'TEST' : 'PRODUCTION',
        'merchant_info' => [
          'merchant_name' => Settings::sellerName(),
          'merchant_id' => Settings::ucpGooglePayMerchantId(),
          'merchant_origin' => (string) wp_parse_url(get_bloginfo('url'), PHP_URL_HOST),
        ],
        'allowed_payment_methods' => [[
          'type' => 'CARD',
          'parameters' => [
            'allowed_auth_methods' => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
            'allowed_card_networks' => Settings::ucpCardNetworks(),
          ],
          'tokenization_specification' => [
            'type' => 'PAYMENT_GATEWAY',
            'parameters' => ['gateway' => Settings::ucpGateway(), 'gatewayMerchantId' => Settings::ucpGatewayMerchantId()],
          ],
        ]],
      ],
    ];
  }

  /**
   * Charges the Google Pay token.
   * @param WC_Order $order the order
   * @param array $paymentData UCP payment instrument (credential.token JSON string, signals)
   * @return array charge result
   */
  public function charge(WC_Order $order, array $paymentData): array
  {
    $credential = $paymentData['credential'] ?? $paymentData['instrument']['credential'] ?? [];
    $raw = $credential['token'] ?? '';
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    $tokenId = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
    if ($tokenId === '' && is_string($raw) && str_starts_with($raw, 'tok_')) {
      $tokenId = $raw;
    }
    if ($tokenId === '') {
      return ['success' => false, 'transaction_id' => '', 'captured' => false, 'error_code' => 'missing_token', 'message' => __('Google Pay Token fehlt oder ist ungültig.', 'lbwp'), 'requires_action' => false, 'action' => []];
    }
    $sessionId = (string) $order->get_meta(Session::META_SESSION_ID);
    $signals = (array) ($paymentData['signals'] ?? []);
    $params = [
      'amount' => Money::toMinor((float) $order->get_total(), $order->get_currency()),
      'currency' => strtolower($order->get_currency()),
      'confirm' => 'true',
      'capture_method' => Settings::captureMethod(),
      'payment_method_data' => ['type' => 'card', 'card' => ['token' => $tokenId]],
      'payment_method_types' => ['card'],
      'description' => sprintf('%s – Bestellung %s', Settings::sellerName(), $order->get_order_number()),
      'metadata' => ['order_id' => (string) $order->get_id(), 'checkout_session_id' => $sessionId, 'protocol' => 'ucp'],
    ];
    if (!empty($signals['dev.ucp.buyer_ip'])) {
      $params['metadata']['buyer_ip'] = sanitize_text_field((string) $signals['dev.ucp.buyer_ip']);
    }
    if ($order->get_billing_email() !== '') {
      $params['receipt_email'] = $order->get_billing_email();
    }
    $params = (array) apply_filters('aboon_agentic_stripe_intent_params', $params, $order, 'ucp');
    return StripeApi::toChargeResult(StripeApi::createPaymentIntent('ucp', $params, $sessionId . '-capture'));
  }
}
