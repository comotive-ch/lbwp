<?php

namespace LBWP\Aboon\AgenticCommerce\Payment;

use WC_Order;

/**
 * A payment handler captures (or defers) the payment of a completed checkout session and
 * declares itself in the session response of a protocol.
 * @package LBWP\Aboon\AgenticCommerce\Payment
 * @author Mirko Baffa <mirko@comotive.ch>
 */
interface PaymentHandler
{
  /**
   * @return string handler id used in payment_data.handler_id / instruments[].handler_id
   */
  public function getId(): string;

  /**
   * @param string $protocol acp|ucp
   * @return string payment method title stored on the order
   */
  public function getTitle(string $protocol): string;

  /**
   * @param string $protocol acp|ucp
   * @return array handler declaration for the session response (protocol specific shape)
   */
  public function getDeclaration(string $protocol): array;

  /**
   * Charges the order with the payment data of the complete request.
   * @param WC_Order $order the draft order (totals final)
   * @param array $paymentData protocol payment data (ACP payment_data / UCP instrument)
   * @return array success, transaction_id, captured, error_code, message, requires_action, action
   */
  public function charge(WC_Order $order, array $paymentData): array;
}
