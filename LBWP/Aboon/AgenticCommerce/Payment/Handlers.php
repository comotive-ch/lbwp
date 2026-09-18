<?php

namespace LBWP\Aboon\AgenticCommerce\Payment;

use LBWP\Aboon\AgenticCommerce\Settings;

/**
 * Builds the list of payment handlers declared for a protocol and resolves a handler by id.
 * Test mode always yields the deferred handler so no live PSP is ever called.
 * @package LBWP\Aboon\AgenticCommerce\Payment
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Handlers
{
  /**
   * Returns the handlers for a protocol.
   * @param string $protocol acp|ucp
   * @return PaymentHandler[] handlers
   */
  public static function forProtocol(string $protocol): array
  {
    if (Settings::isTestMode()) {
      return [new DeferredPayment()];
    }
    if ($protocol === 'ucp') {
      $handlers = [];
      if (Settings::stripeSecretKey('ucp') !== '') {
        $handlers[] = new StripeGooglePay();
      }
      return (array) apply_filters('aboon_agentic_payment_handlers', $handlers, $protocol);
    }
    $handlers = [];
    if (Settings::acpPaymentHandler() === 'stripe' && Settings::stripeSecretKey('acp') !== '') {
      $handlers[] = new StripeSharedToken();
    } else {
      $handlers[] = new DeferredPayment();
    }
    return (array) apply_filters('aboon_agentic_payment_handlers', $handlers, $protocol);
  }

  /**
   * Finds a handler by id.
   * @param string $protocol acp|ucp
   * @param string $handlerId id from the request
   * @return PaymentHandler|null handler
   */
  public static function find(string $protocol, string $handlerId): ?PaymentHandler
  {
    foreach (self::forProtocol($protocol) as $handler) {
      if ($handler instanceof PaymentHandler && $handler->getId() === $handlerId) {
        return $handler;
      }
    }
    return null;
  }

  /**
   * Returns the declarations of all handlers of a protocol.
   * @param string $protocol acp|ucp
   * @return array declarations
   */
  public static function declarations(string $protocol): array
  {
    return array_values(array_map(fn(PaymentHandler $h) => $h->getDeclaration($protocol), array_filter(self::forProtocol($protocol), fn($h) => $h instanceof PaymentHandler)));
  }
}
