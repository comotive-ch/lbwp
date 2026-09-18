<?php

namespace LBWP\Aboon\AgenticCommerce\Enum;

use LBWP\Aboon\AgenticCommerce\Pricing;
use LBWP\Aboon\AgenticCommerce\Session;

/**
 * Google UCP (profile version 2026-04-08) wire vocabulary.
 * @package LBWP\Aboon\AgenticCommerce\Enum
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Ucp
{
  public const string STATUS_INCOMPLETE = 'incomplete';
  public const string STATUS_READY = 'ready_for_complete';
  public const string STATUS_IN_PROGRESS = 'complete_in_progress';
  public const string STATUS_COMPLETED = 'completed';
  public const string STATUS_CANCELED = 'canceled';

  public const string SEVERITY_RECOVERABLE = 'recoverable';
  public const string SEVERITY_BUYER_INPUT = 'requires_buyer_input';
  public const string SEVERITY_BUYER_REVIEW = 'requires_buyer_review';
  public const string SEVERITY_UNRECOVERABLE = 'unrecoverable';

  public const string SERVICE = 'dev.ucp.shopping';
  public const string CAP_CHECKOUT = 'dev.ucp.shopping.checkout';
  public const string CAP_FULFILLMENT = 'dev.ucp.shopping.fulfillment';
  public const string CAP_DISCOUNT = 'dev.ucp.shopping.discount';
  public const string CAP_ORDER = 'dev.ucp.shopping.order';
  public const string HANDLER_GOOGLE_PAY = 'com.google.pay';

  /**
   * @var array neutral session status => UCP status
   */
  public const array STATUS_MAP = [
    Session::STATUS_INCOMPLETE => self::STATUS_INCOMPLETE,
    Session::STATUS_READY => self::STATUS_READY,
    Session::STATUS_COMPLETING => self::STATUS_IN_PROGRESS,
    Session::STATUS_COMPLETED => self::STATUS_COMPLETED,
    Session::STATUS_CANCELED => self::STATUS_CANCELED,
  ];

  /**
   * @var array neutral total type => German display text (tax exclusive)
   */
  public const array TOTAL_TEXT = [
    Pricing::ITEMS_BASE => 'Artikel',
    Pricing::ITEMS_DISCOUNT => 'Rabatt',
    Pricing::SUBTOTAL => 'Zwischentotal',
    Pricing::DISCOUNT => 'Rabatt',
    Pricing::FULFILLMENT => 'Versand',
    Pricing::TAX => 'MwSt.',
    Pricing::FEE => 'Gebühren',
    Pricing::TOTAL => 'Total',
  ];

  /**
   * @var array settings link key => UCP link type
   */
  public const array LINK_TYPES = [
    'terms' => 'terms_of_service',
    'privacy' => 'privacy_policy',
    'returns' => 'return_policy',
    'shipping' => 'shipping_policy',
    'contact' => 'contact',
  ];

  /**
   * @var array settings link key => German title
   */
  public const array LINK_TITLES = [
    'terms' => 'AGB',
    'privacy' => 'Datenschutz',
    'returns' => 'Rückgabe',
    'shipping' => 'Versand',
    'contact' => 'Kontakt',
  ];
}
