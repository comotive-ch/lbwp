<?php

namespace LBWP\Aboon\AgenticCommerce;

/**
 * Minor unit conversion and currency helpers. Both agentic protocols transport amounts as
 * integers in the smallest currency unit, independent of the shop's display decimals.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Money
{
  /**
   * @var array ISO 4217 currencies without a minor unit
   */
  public const array ZERO_DECIMAL = [
    'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
  ];
  /**
   * @var array ISO 4217 currencies with three minor unit digits
   */
  public const array THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

  /**
   * Converts a shop amount (major units) to protocol minor units.
   * @param float $amount amount in major units, e.g. 19.90
   * @param string $currency ISO 4217 code, empty for the shop currency
   * @return int amount in minor units, e.g. 1990
   */
  public static function toMinor(float $amount, string $currency = ''): int
  {
    return (int) round($amount * (10 ** self::exponent($currency)));
  }

  /**
   * Converts protocol minor units back into a shop amount.
   * @param int $amount amount in minor units
   * @param string $currency ISO 4217 code, empty for the shop currency
   * @return float amount in major units
   */
  public static function fromMinor(int $amount, string $currency = ''): float
  {
    return $amount / (10 ** self::exponent($currency));
  }

  /**
   * Returns the number of minor unit digits of a currency.
   * @param string $currency ISO 4217 code, empty for the shop currency
   * @return int 0, 2 or 3
   */
  public static function exponent(string $currency = ''): int
  {
    $currency = strtoupper($currency === '' ? self::currency() : $currency);
    if (in_array($currency, self::ZERO_DECIMAL, true)) {
      return 0;
    }
    if (in_array($currency, self::THREE_DECIMAL, true)) {
      return 3;
    }
    return 2;
  }

  /**
   * Returns the shop currency in upper case.
   * @return string ISO 4217 code
   */
  public static function currency(): string
  {
    return strtoupper((string) get_woocommerce_currency());
  }

  /**
   * Checks whether a requested currency matches the shop currency (case insensitive).
   * @param string $currency requested ISO 4217 code
   * @return bool true when supported
   */
  public static function isSupported(string $currency): bool
  {
    return strtoupper($currency) === self::currency();
  }
}
