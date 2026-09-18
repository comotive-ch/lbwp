<?php

namespace LBWP\Aboon\AgenticCommerce\Enum;

/**
 * Protocol neutral message levels and codes used inside the shared library. The REST
 * controllers map them to the ACP / UCP wire formats.
 * @package LBWP\Aboon\AgenticCommerce\Enum
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Message
{
  public const string LEVEL_ERROR = 'error';
  public const string LEVEL_WARNING = 'warning';
  public const string LEVEL_INFO = 'info';

  public const string MISSING = 'missing';
  public const string INVALID = 'invalid';
  public const string OUT_OF_STOCK = 'out_of_stock';
  public const string LOW_STOCK = 'low_stock';
  public const string QUANTITY_EXCEEDED = 'quantity_exceeded';
  public const string PAYMENT_DECLINED = 'payment_declined';
  public const string REQUIRES_3DS = 'requires_3ds';
  public const string COUPON_INVALID = 'coupon_invalid';
  public const string COUPON_EXPIRED = 'coupon_expired';
  public const string MINIMUM_NOT_MET = 'minimum_not_met';
  public const string MAXIMUM_EXCEEDED = 'maximum_exceeded';
  public const string REGION_RESTRICTED = 'region_restricted';
  public const string PRICE_CHANGED = 'price_changed';

  /**
   * Builds a neutral message array.
   * @param string $level error|warning|info
   * @param string $code one of the code constants
   * @param string $content human readable text (German)
   * @param string $path JSONPath of the offending field or empty
   * @param bool $blocking whether the session cannot proceed to payment
   * @return array the message
   */
  public static function make(string $level, string $code, string $content, string $path = '', bool $blocking = true): array
  {
    return ['level' => $level, 'code' => $code, 'content' => $content, 'path' => $path, 'blocking' => $blocking && $level === self::LEVEL_ERROR];
  }

  /**
   * Shortcut for a blocking error message.
   * @param string $code code constant
   * @param string $content text
   * @param string $path JSONPath or empty
   * @return array the message
   */
  public static function error(string $code, string $content, string $path = ''): array
  {
    return self::make(self::LEVEL_ERROR, $code, $content, $path, true);
  }

  /**
   * Shortcut for a non-blocking warning.
   * @param string $code code constant
   * @param string $content text
   * @param string $path JSONPath or empty
   * @return array the message
   */
  public static function warning(string $code, string $content, string $path = ''): array
  {
    return self::make(self::LEVEL_WARNING, $code, $content, $path, false);
  }

  /**
   * Checks whether a message list contains a blocking entry.
   * @param array $messages list of messages
   * @return bool true when blocking
   */
  public static function hasBlocking(array $messages): bool
  {
    foreach ($messages as $message) {
      if (!empty($message['blocking'])) {
        return true;
      }
    }
    return false;
  }
}
