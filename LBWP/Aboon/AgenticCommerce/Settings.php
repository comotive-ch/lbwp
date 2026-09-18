<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Theme\Component\ACFBase;

/**
 * Typed accessors for every agentic commerce option. Reads the raw ACF option rows so it works
 * before acf/init (theme component gating) and applies the "aboon_agentic_setting" filter so a
 * client theme can override any value in code.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Settings
{
  /**
   * @var string default ACP API version
   */
  public const string ACP_VERSION = '2026-04-17';
  /**
   * @var string default UCP profile version
   */
  public const string UCP_VERSION = '2026-04-08';
  /**
   * @var string prefix for all option names
   */
  protected const string PREFIX = 'agentic-';

  /**
   * Reads a raw option value with the theme override filter applied.
   * @param string $name option name without the "options_" prefix
   * @param mixed $default default when the option is empty
   * @return mixed the value
   */
  public static function raw(string $name, mixed $default = ''): mixed
  {
    $value = ACFBase::option($name);
    if ($value === false || $value === null || $value === '') {
      $value = $default;
    }
    return apply_filters('aboon_agentic_setting', $value, $name);
  }

  /**
   * Reads a checkbox option ("Aktivieren" style).
   * @param string $name option name
   * @return bool true when checked
   */
  public static function flag(string $name): bool
  {
    $value = self::raw($name, []);
    if (is_bool($value)) {
      return $value;
    }
    return is_array($value) && isset($value[0]) && (int) $value[0] === 1;
  }

  /**
   * Reads a string option.
   * @param string $name option name
   * @param string $default default value
   * @return string the value
   */
  public static function string(string $name, string $default = ''): string
  {
    $value = self::raw($name, $default);
    return is_scalar($value) ? trim((string) $value) : $default;
  }

  /**
   * Reads a numeric option.
   * @param string $name option name
   * @param float $default default value
   * @return float the value
   */
  public static function number(string $name, float $default = 0): float
  {
    $value = self::raw($name, $default);
    return is_numeric($value) ? (float) $value : $default;
  }

  /**
   * Reads a list option (multi select, checkbox, taxonomy).
   * @param string $name option name
   * @return array list of scalar values
   */
  public static function list(string $name): array
  {
    $value = self::raw($name, []);
    if (is_string($value) && strlen($value) > 0) {
      $value = [$value];
    }
    return is_array($value) ? array_values(array_filter(array_map('strval', $value), 'strlen')) : [];
  }

  /**
   * @return bool whether the ACP component is active
   */
  public static function isAcpActive(): bool
  {
    return self::flag(self::PREFIX . 'acp-active');
  }

  /**
   * @return bool whether the UCP component is active
   */
  public static function isUcpActive(): bool
  {
    return self::flag(self::PREFIX . 'ucp-active');
  }

  /**
   * @return bool whether test mode is on (no PSP calls, orders on hold)
   */
  public static function isTestMode(): bool
  {
    return self::flag(self::PREFIX . 'test-mode');
  }

  /**
   * @return string "id" or "sku"
   */
  public static function itemIdSource(): string
  {
    return self::string(self::PREFIX . 'item-id-source', 'id') === 'sku' ? 'sku' : 'id';
  }

  /**
   * @return array product category term ids, empty for all
   */
  public static function eligibleCategories(): array
  {
    return array_map('intval', self::list(self::PREFIX . 'eligible-categories'));
  }

  /**
   * @return array ISO 3166-1 alpha-2 codes; empty means all WooCommerce shipping countries
   */
  public static function allowedCountries(): array
  {
    $countries = array_map('strtoupper', self::list(self::PREFIX . 'allowed-countries'));
    if (count($countries) > 0 || !function_exists('WC')) {
      return $countries;
    }
    return array_keys((array) WC()->countries->get_shipping_countries());
  }

  /**
   * @return float hard cap for order totals in shop currency, 0 for none
   */
  public static function maxOrderTotal(): float
  {
    return self::number(self::PREFIX . 'max-order-total', 0);
  }

  /**
   * @return string order status used when no PSP capture happened
   */
  public static function statusWithoutCapture(): string
  {
    $status = self::string(self::PREFIX . 'status-without-capture', 'on-hold');
    return in_array($status, ['on-hold', 'pending', 'processing'], true) ? $status : 'on-hold';
  }

  /**
   * @return array [min, max] delivery days
   */
  public static function deliveryDays(): array
  {
    $min = max(0, (int) self::number(self::PREFIX . 'delivery-days-min', 2));
    $max = max($min, (int) self::number(self::PREFIX . 'delivery-days-max', 5));
    return [$min, $max];
  }

  /**
   * @return string carrier name announced in fulfillment options
   */
  public static function carrierName(): string
  {
    return self::string(self::PREFIX . 'carrier-name', '');
  }

  /**
   * @return array|null ['title' => string, 'amount' => float] or null when disabled
   */
  public static function shippingFallback(): ?array
  {
    $title = self::string(self::PREFIX . 'shipping-fallback-title');
    if ($title === '') {
      return null;
    }
    return ['title' => $title, 'amount' => self::number(self::PREFIX . 'shipping-fallback-amount', 0)];
  }

  /**
   * Resolves the configured policy links.
   * @return array keys terms, privacy, returns, shipping, contact => absolute URL (missing keys omitted)
   */
  public static function links(): array
  {
    $links = [];
    foreach (['terms', 'privacy', 'returns', 'shipping', 'contact'] as $key) {
      $value = self::string(self::PREFIX . 'links_' . $key);
      if ($value === '') {
        continue;
      }
      $url = is_numeric($value) ? get_permalink((int) $value) : $value;
      if (is_string($url) && $url !== '') {
        $links[$key] = $url;
      }
    }
    return $links;
  }

  /**
   * @return string seller name (falls back to the blog name)
   */
  public static function sellerName(): string
  {
    return self::string(self::PREFIX . 'seller-name', get_bloginfo('name'));
  }

  /**
   * @return bool whether every request should be traced to the system log
   */
  public static function logRequests(): bool
  {
    return self::flag(self::PREFIX . 'log-requests');
  }

  /**
   * @return string the bearer token OpenAI must send
   */
  public static function acpApiKey(): string
  {
    return self::string('acp-api-key');
  }

  /**
   * @return string optional HMAC secret for inbound ACP signatures
   */
  public static function acpSignatureSecret(): string
  {
    return self::string('acp-signature-secret');
  }

  /**
   * @return string newest supported ACP API version
   */
  public static function acpApiVersion(): string
  {
    return self::string('acp-api-version', self::ACP_VERSION);
  }

  /**
   * @return string OpenAI webhook URL for order events
   */
  public static function acpWebhookUrl(): string
  {
    return self::string('acp-webhook-url');
  }

  /**
   * @return string HMAC secret for outbound ACP webhooks
   */
  public static function acpWebhookSecret(): string
  {
    return self::string('acp-webhook-secret');
  }

  /**
   * @return string "stripe" or "deferred"
   */
  public static function acpPaymentHandler(): string
  {
    return self::string('acp-payment-handler', 'deferred') === 'stripe' ? 'stripe' : 'deferred';
  }

  /**
   * Resolves the Stripe secret key for a protocol: own field first, then the official Stripe
   * gateway plugin settings (test key while its test mode is on).
   * @param string $protocol acp|ucp
   * @return string the secret key or empty
   */
  public static function stripeSecretKey(string $protocol): string
  {
    $key = self::string($protocol . '-stripe-secret-key');
    if ($key !== '') {
      return $key;
    }
    $stripe = get_option('woocommerce_stripe_settings');
    if (!is_array($stripe)) {
      return '';
    }
    $testMode = isset($stripe['testmode']) && $stripe['testmode'] === 'yes';
    return trim((string) ($stripe[$testMode ? 'test_secret_key' : 'secret_key'] ?? ''));
  }

  /**
   * @return bool whether the resolved Stripe key is a test key
   */
  public static function isStripeTestKey(string $protocol): bool
  {
    return str_starts_with(self::stripeSecretKey($protocol), 'sk_test_');
  }

  /**
   * @return string Stripe account id used as merchant id in ACP handler config
   */
  public static function stripeAccount(): string
  {
    return self::string('acp-stripe-account-id');
  }

  /**
   * @return string "automatic" or "manual"
   */
  public static function captureMethod(): string
  {
    return self::string('acp-capture-method', 'automatic') === 'manual' ? 'manual' : 'automatic';
  }

  /**
   * @return bool whether the nightly ACP feed is generated
   */
  public static function acpFeedActive(): bool
  {
    return self::flag('acp-feed-active');
  }

  /**
   * @return array ISO 3166-1 alpha-2 target countries for the ACP feed
   */
  public static function acpFeedTargetCountries(): array
  {
    $raw = self::string('acp-feed-target-countries', 'CH');
    return array_values(array_filter(array_map(fn($c) => strtoupper(trim($c)), explode(',', $raw)), 'strlen'));
  }

  /**
   * @return string public ACP feed URL on the CDN
   */
  public static function acpFeedUrl(): string
  {
    return get_bloginfo('url') . '/assets/lbwp-cdn/' . ASSET_KEY . '/files/shop/acp-feed.tsv';
  }

  /**
   * @return string base URL registered with OpenAI
   */
  public static function acpEndpointUrl(): string
  {
    return untrailingslashit(rest_url('acp/v1'));
  }

  /**
   * @return string UCP profile version
   */
  public static function ucpVersion(): string
  {
    return self::string('ucp-version', self::UCP_VERSION);
  }

  /**
   * @return string Google partner id for order events
   */
  public static function ucpPartnerId(): string
  {
    return self::string('ucp-partner-id');
  }

  /**
   * @return string Google API key for order events
   */
  public static function ucpEventsApiKey(): string
  {
    return self::string('ucp-events-api-key');
  }

  /**
   * @return array allowed caller e-mails / aud values, one per line in the option
   */
  public static function ucpAllowedCallers(): array
  {
    $lines = preg_split('/[\r\n,]+/', self::string('ucp-allowed-callers'));
    return array_values(array_filter(array_map('trim', (array) $lines), 'strlen'));
  }

  /**
   * @return bool whether RFC 9421 signatures are enforced on inbound UCP requests
   */
  public static function ucpRequireSignature(): bool
  {
    return self::flag('ucp-require-signature');
  }

  /**
   * @return string key id published in the profile
   */
  public static function ucpSigningKid(): string
  {
    return self::string('ucp-signing-kid');
  }

  /**
   * @return string EC private key PEM
   */
  public static function ucpSigningKey(): string
  {
    return self::string('ucp-signing-key');
  }

  /**
   * @return string Google Pay merchant id
   */
  public static function ucpGooglePayMerchantId(): string
  {
    return self::string('ucp-google-pay-merchant-id');
  }

  /**
   * @return string PSP receiving the Google Pay token (only "stripe" supported)
   */
  public static function ucpGateway(): string
  {
    return 'stripe';
  }

  /**
   * @return string gateway merchant id (Stripe account id)
   */
  public static function ucpGatewayMerchantId(): string
  {
    return self::string('ucp-gateway-merchant-id');
  }

  /**
   * @return array allowed card networks in Google Pay notation
   */
  public static function ucpCardNetworks(): array
  {
    $networks = self::list('ucp-card-networks');
    return count($networks) > 0 ? $networks : ['VISA', 'MASTERCARD'];
  }

  /**
   * @return string endpoint published in the UCP profile
   */
  public static function ucpEndpointUrl(): string
  {
    return untrailingslashit(rest_url('ucp/v1'));
  }

  /**
   * @return string stable Google Pay handler id derived from the merchant configuration
   */
  public static function ucpHandlerId(): string
  {
    return 'gpay_' . substr(hash('sha256', self::ucpGooglePayMerchantId() . '|' . self::ucpGatewayMerchantId() . '|' . get_bloginfo('url')), 0, 16);
  }
}
