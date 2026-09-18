<?php

namespace LBWP\Aboon\AgenticCommerce\Enum;

use LBWP\Aboon\AgenticCommerce\Pricing;
use LBWP\Aboon\AgenticCommerce\Session;

/**
 * OpenAI ACP (spec 2026-04-17) wire vocabulary. Kept in parity with the spec enums shipped in
 * WooCommerce core (src/Internal/Agentic/Enums) without depending on that internal namespace.
 * @package LBWP\Aboon\AgenticCommerce\Enum
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Acp
{
  public const string STATUS_NOT_READY = 'not_ready_for_payment';
  public const string STATUS_READY = 'ready_for_payment';
  public const string STATUS_IN_PROGRESS = 'complete_in_progress';
  public const string STATUS_COMPLETED = 'completed';
  public const string STATUS_CANCELED = 'canceled';
  public const string STATUS_AUTH_REQUIRED = 'authentication_required';

  public const string ERROR_INVALID_REQUEST = 'invalid_request';
  public const string ERROR_REQUEST_NOT_IDEMPOTENT = 'request_not_idempotent';
  public const string ERROR_PROCESSING = 'processing_error';
  public const string ERROR_SERVICE_UNAVAILABLE = 'service_unavailable';

  public const string CODE_INVALID = 'invalid';
  public const string CODE_MISSING = 'missing';
  public const string CODE_UNSUPPORTED_VERSION = 'unsupported_api_version';
  public const string CODE_IDEMPOTENCY_REQUIRED = 'idempotency_key_required';
  public const string CODE_IDEMPOTENCY_CONFLICT = 'idempotency_key_conflict';
  public const string CODE_IDEMPOTENCY_IN_FLIGHT = 'idempotency_key_in_flight';
  public const string CODE_RATE_LIMITED = 'rate_limited';
  public const string CODE_OUT_OF_STOCK = 'out_of_stock';
  public const string CODE_PAYMENT_DECLINED = 'payment_declined';

  /**
   * @var array neutral session status => ACP status
   */
  public const array STATUS_MAP = [
    Session::STATUS_INCOMPLETE => self::STATUS_NOT_READY,
    Session::STATUS_READY => self::STATUS_READY,
    Session::STATUS_COMPLETING => self::STATUS_IN_PROGRESS,
    Session::STATUS_COMPLETED => self::STATUS_COMPLETED,
    Session::STATUS_CANCELED => self::STATUS_CANCELED,
  ];

  /**
   * @var array neutral total type => German display text
   */
  public const array TOTAL_TEXT = [
    Pricing::ITEMS_BASE => 'Zwischentotal',
    Pricing::ITEMS_DISCOUNT => 'Rabatt',
    Pricing::SUBTOTAL => 'Zwischentotal',
    Pricing::DISCOUNT => 'Rabatt',
    Pricing::FULFILLMENT => 'Versand',
    Pricing::TAX => 'MwSt.',
    Pricing::FEE => 'Gebühren',
    Pricing::TOTAL => 'Total',
  ];

  /**
   * @var array settings link key => ACP link type
   */
  public const array LINK_TYPES = [
    'terms' => 'terms_of_use',
    'privacy' => 'privacy_policy',
    'returns' => 'return_policy',
    'shipping' => 'shipping_policy',
    'contact' => 'contact_us',
  ];

  /**
   * @var array neutral message code => [severity, resolution]
   */
  public const array MESSAGE_META = [
    Message::MISSING => ['medium', 'requires_buyer_input'],
    Message::INVALID => ['medium', 'requires_buyer_input'],
    Message::OUT_OF_STOCK => ['high', 'requires_buyer_review'],
    Message::LOW_STOCK => ['low', 'recoverable'],
    Message::QUANTITY_EXCEEDED => ['medium', 'requires_buyer_input'],
    Message::PAYMENT_DECLINED => ['high', 'requires_buyer_input'],
    Message::REQUIRES_3DS => ['high', 'requires_buyer_input'],
    Message::COUPON_INVALID => ['low', 'requires_buyer_input'],
    Message::COUPON_EXPIRED => ['low', 'requires_buyer_input'],
    Message::MINIMUM_NOT_MET => ['medium', 'requires_buyer_input'],
    Message::MAXIMUM_EXCEEDED => ['high', 'requires_buyer_review'],
    Message::REGION_RESTRICTED => ['high', 'requires_buyer_input'],
    Message::PRICE_CHANGED => ['medium', 'requires_buyer_review'],
  ];
}
