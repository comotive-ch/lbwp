<?php

namespace LBWP\Aboon\AgenticCommerce\Payment;

use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Aboon\AgenticCommerce\Settings;
use WC_Order;

/**
 * ACP handler "dev.acp.tokenized.card": charges an OpenAI shared payment token (spt_…) through a
 * Stripe PaymentIntent using payment_method_data[shared_payment_granted_token].
 * @package LBWP\Aboon\AgenticCommerce\Payment
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class StripeSharedToken implements PaymentHandler
{
  public const string ID = 'card_tokenized';
  public const string HANDLER_VERSION = '2026-01-22';

  /**
   * @return string handler id
   */
  public function getId(): string
  {
    return self::ID;
  }

  /**
   * @param string $protocol acp|ucp
   * @return string payment method title
   */
  public function getTitle(string $protocol): string
  {
    return 'ChatGPT (Stripe)';
  }

  /**
   * @param string $protocol acp|ucp
   * @return array handler declaration per ACP handler spec
   */
  public function getDeclaration(string $protocol): array
  {
    return [
      'id' => self::ID,
      'name' => 'dev.acp.tokenized.card',
      'display_name' => __('Kreditkarte', 'lbwp'),
      'version' => self::HANDLER_VERSION,
      'spec' => 'https://acp.dev/handlers/tokenized.card',
      'requires_delegate_payment' => true,
      'requires_pci_compliance' => false,
      'psp' => 'stripe',
      'config_schema' => 'https://acp.dev/schemas/handlers/tokenized.card/config.json',
      'instrument_schemas' => ['https://acp.dev/schemas/handlers/tokenized.card/instrument.json'],
      'config' => [
        'merchant_id' => Settings::stripeAccount(),
        'psp' => 'stripe',
        'accepted_brands' => (array) apply_filters('aboon_agentic_accepted_brands', ['visa', 'mastercard', 'amex']),
        'accepted_funding_types' => ['credit', 'debit'],
        'supports_3ds' => false,
        'environment' => Settings::isStripeTestKey('acp') ? 'test' : 'production',
      ],
      'display_order' => 1,
    ];
  }

  /**
   * Charges the shared payment token.
   * @param WC_Order $order the order
   * @param array $paymentData ACP payment_data (instrument.credential.token or legacy token)
   * @return array charge result
   */
  public function charge(WC_Order $order, array $paymentData): array
  {
    $token = sanitize_text_field((string) ($paymentData['instrument']['credential']['token'] ?? $paymentData['token'] ?? ''));
    if ($token === '') {
      return ['success' => false, 'transaction_id' => '', 'captured' => false, 'error_code' => 'missing_token', 'message' => __('Zahlungstoken fehlt.', 'lbwp'), 'requires_action' => false, 'action' => []];
    }
    $sessionId = (string) $order->get_meta(Session::META_SESSION_ID);
    $params = [
      'amount' => Money::toMinor((float) $order->get_total(), $order->get_currency()),
      'currency' => strtolower($order->get_currency()),
      'confirm' => 'true',
      'capture_method' => Settings::captureMethod(),
      'payment_method_data' => ['shared_payment_granted_token' => $token],
      'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
      'description' => sprintf('%s – Bestellung %s', Settings::sellerName(), $order->get_order_number()),
      'metadata' => ['order_id' => (string) $order->get_id(), 'checkout_session_id' => $sessionId, 'protocol' => 'acp'],
    ];
    if ($order->get_billing_email() !== '') {
      $params['receipt_email'] = $order->get_billing_email();
    }
    $params = (array) apply_filters('aboon_agentic_stripe_intent_params', $params, $order, 'acp');
    return StripeApi::toChargeResult(StripeApi::createPaymentIntent('acp', $params, $sessionId . '-capture'));
  }
}
