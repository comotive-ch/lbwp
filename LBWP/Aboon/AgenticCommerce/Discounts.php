<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Aboon\AgenticCommerce\Enum\Message;
use WC_Order;
use WP_Error;

/**
 * Applies and removes coupon codes on a draft order and maps WooCommerce errors to neutral
 * message codes.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Discounts
{
  /**
   * Synchronises the order's coupons with the requested list and reports rejections.
   * @param WC_Order $order the draft order (billing email should be set first)
   * @param array $codes requested coupon codes
   * @return array rejected entries [['code' => …, 'message' => neutral message], …]
   */
  public static function apply(WC_Order $order, array $codes): array
  {
    $codes = array_values(array_unique(array_filter(array_map(fn($c) => wc_format_coupon_code(sanitize_text_field((string) $c)), $codes), 'strlen')));
    foreach ($order->get_coupon_codes() as $existing) {
      if (!in_array($existing, $codes, true)) {
        $order->remove_coupon($existing);
      }
    }
    $rejected = [];
    foreach ($codes as $index => $code) {
      if (in_array($code, $order->get_coupon_codes(), true)) {
        continue;
      }
      $result = $order->apply_coupon($code);
      if ($result instanceof WP_Error) {
        $rejected[] = ['code' => $code, 'message' => self::mapError($result, $index)];
      }
    }
    return $rejected;
  }

  /**
   * Maps a coupon WP_Error to a neutral message.
   * @param WP_Error $error the error
   * @param int $index index of the coupon in the request
   * @return array neutral message
   */
  protected static function mapError(WP_Error $error, int $index): array
  {
    $text = wp_specialchars_decode((string) $error->get_error_message(), ENT_QUOTES);
    $haystack = strtolower($error->get_error_code() . ' ' . $text);
    $code = Message::COUPON_INVALID;
    if (str_contains($haystack, 'expired') || str_contains($haystack, 'abgelaufen')) {
      $code = Message::COUPON_EXPIRED;
    } elseif (str_contains($haystack, 'minimum') || str_contains($haystack, 'mindest')) {
      $code = Message::MINIMUM_NOT_MET;
    }
    return Message::make(Message::LEVEL_ERROR, $code, $text !== '' ? $text : __('Gutscheincode ungültig.', 'lbwp'), '$.coupons[' . $index . ']', false);
  }
}
