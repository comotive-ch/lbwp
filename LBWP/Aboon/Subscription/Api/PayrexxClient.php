<?php

namespace LBWP\Aboon\Subscription\Api;

use LBWP\Aboon\Subscription\Gateway\PayrexxSubscriptionGateway;
use Payrexx\Payrexx;

/**
 * Builds and caches the Payrexx SDK client from the gateway's stored settings. This is the only
 * class in the plugin that reads the raw gateway option or instantiates \Payrexx\Payrexx directly.
 * @package LBWP\Aboon\Subscription\Api
 * @author Michael Sebel <michael@comotive.ch>
 */
class PayrexxClient
{
  /**
   * @var Payrexx|null cached client instance for the current request
   */
  protected static ?Payrexx $instance = null;

  /**
   * Returns a ready-to-use Payrexx SDK client, built from the gateway's settings.
   * @return Payrexx the SDK client
   */
  public static function getInstance(): Payrexx
  {
    if (self::$instance instanceof Payrexx) {
      return self::$instance;
    }

    self::requireSdk();
    $settings = self::getSettings();

    self::$instance = new Payrexx(
      (string) ($settings['instance_name'] ?? ''),
      (string) ($settings['api_secret'] ?? ''),
      '',
      (string) ($settings['api_base_domain'] ?? 'payrexx.com')
    );

    return self::$instance;
  }

  /**
   * Loads the bundled Payrexx SDK autoloader, once per request.
   * @return void
   */
  public static function requireSdk(): void
  {
    static $loaded = false;
    if ($loaded) {
      return;
    }

    require_once ABSPATH . 'wp-content/plugins/lbwp/resources/libraries/payrexx/autoload.php';
    $loaded = true;
  }

  /**
   * Reads the Payrexx subscription gateway's stored WooCommerce settings.
   * @return array<string,mixed> the gateway settings
   */
  public static function getSettings(): array
  {
    $settings = get_option('woocommerce_' . PayrexxSubscriptionGateway::GATEWAY_ID . '_settings');
    return is_array($settings) ? $settings : [];
  }

  /**
   * Returns the configured Look&Feel profile id, if any.
   * @return string|null the Look&Feel profile id, or null if not configured
   */
  public static function getLookAndFeelProfile(): ?string
  {
    $value = self::getSettings()['look_and_feel_profile'] ?? '';
    return $value === '' ? null : (string) $value;
  }

  /**
   * Returns the configured currency for the shop (WooCommerce's active currency).
   * @return string the ISO currency code
   */
  public static function getCurrency(): string
  {
    return get_woocommerce_currency();
  }
}
