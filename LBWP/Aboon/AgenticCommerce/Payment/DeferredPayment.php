<?php

namespace LBWP\Aboon\AgenticCommerce\Payment;

use LBWP\Aboon\AgenticCommerce\Settings;
use WC_Order;

/**
 * Handler without a PSP call: used in test mode and for invoice / payment terms pilots. The
 * order is finalised without capture and lands in the configured "without capture" status.
 * @package LBWP\Aboon\AgenticCommerce\Payment
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class DeferredPayment implements PaymentHandler
{
  public const string ID = 'deferred';

  /**
   * @return string handler id
   */
  public function getId(): string
  {
    return self::ID;
  }

  /**
   * @param string $protocol acp|ucp
   * @return string title
   */
  public function getTitle(string $protocol): string
  {
    if (Settings::isTestMode()) {
      return 'Agentic Checkout (Testmodus)';
    }
    return $protocol === 'ucp' ? 'Google (Rechnung)' : 'ChatGPT (Rechnung)';
  }

  /**
   * @param string $protocol acp|ucp
   * @return array declaration
   */
  public function getDeclaration(string $protocol): array
  {
    if ($protocol === 'ucp') {
      return [
        'id' => self::ID,
        'version' => Settings::ucpVersion(),
        'config' => ['type' => 'deferred', 'payment_terms' => ['net_30']],
      ];
    }
    return [
      'id' => self::ID,
      'name' => 'dev.acp.deferred',
      'display_name' => __('Kauf auf Rechnung', 'lbwp'),
      'version' => Settings::acpApiVersion(),
      'requires_delegate_payment' => false,
      'requires_pci_compliance' => false,
      'config' => ['payment_terms' => ['net_15', 'net_30', 'net_60', 'net_90'], 'environment' => Settings::isTestMode() ? 'test' : 'production'],
      'display_order' => 1,
    ];
  }

  /**
   * Accepts the payment without capture.
   * @param WC_Order $order the order
   * @param array $paymentData payment data
   * @return array result
   */
  public function charge(WC_Order $order, array $paymentData): array
  {
    $terms = sanitize_text_field((string) ($paymentData['payment_terms'] ?? ''));
    return [
      'success' => true,
      'transaction_id' => '',
      'captured' => false,
      'error_code' => '',
      'message' => $terms !== '' ? 'payment_terms=' . $terms : '',
      'requires_action' => false,
      'action' => [],
    ];
  }
}
