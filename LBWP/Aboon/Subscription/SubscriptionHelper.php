<?php

namespace LBWP\Aboon\Subscription;

use LBWP\Aboon\Subscription\Api\ChargeLog;

/**
 * Public, developer-facing query API for the subscription feature. Use this class from anywhere
 * else in the codebase to find out whether a user has a subscription and whether it's valid.
 * @package LBWP\Aboon\Subscription
 * @author Michael Sebel <michael@comotive.ch>
 */
class SubscriptionHelper
{
  /**
   * Checks whether a user has any subscription that is currently active (or past due).
   * @param int $userId the WordPress/WooCommerce customer id
   * @param bool $includePastDue whether a "past_due" subscription still counts as active
   * @return bool true if the user has at least one active subscription
   */
  public static function hasActiveSubscription(int $userId, bool $includePastDue = true): bool
  {
    return count(self::getUserSubscriptions($userId, $includePastDue)) > 0;
  }

  /**
   * Checks whether a user has an active subscription for a specific product.
   * @param int $userId the WordPress/WooCommerce customer id
   * @param int $productId the WooCommerce product id
   * @return bool true if such a subscription exists and is active
   */
  public static function hasActiveSubscriptionForProduct(int $userId, int $productId): bool
  {
    return self::getSubscriptionForUserAndProduct($userId, $productId) !== null;
  }

  /**
   * Returns all subscription post ids belonging to a user.
   * @param int $userId the WordPress/WooCommerce customer id
   * @param bool $onlyActive whether to only return active/past_due subscriptions
   * @return array<int,int> the lbwp-subscription post ids
   */
  public static function getUserSubscriptions(int $userId, bool $onlyActive = false): array
  {
    $posts = get_posts([
      'post_type' => PostType::SLUG,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => '_lbwp_sub_customer_id',
          'value' => $userId,
          'compare' => '=',
        ],
      ],
    ]);

    if (!$onlyActive) {
      return $posts;
    }

    return array_values(array_filter($posts, static fn (int $id) => self::isValid($id)));
  }

  /**
   * Finds the subscription a user has for a given product, if any.
   * @param int $userId the WordPress/WooCommerce customer id
   * @param int $productId the WooCommerce product id
   * @return int|null the subscription post id, or null if none found
   */
  public static function getSubscriptionForUserAndProduct(int $userId, int $productId): ?int
  {
    foreach (self::getUserSubscriptions($userId, true) as $subscriptionId) {
      $order = self::getLinkedOrder($subscriptionId);
      if ($order === null) {
        continue;
      }

      foreach ($order->get_items() as $item) {
        if ((int) $item->get_product_id() === $productId) {
          return $subscriptionId;
        }
      }
    }

    return null;
  }

  /**
   * Checks whether a subscription is published and its internal status is active-equivalent.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return bool true if the subscription is valid/usable
   */
  public static function isValid(int $subscriptionPostId): bool
  {
    if (get_post_status($subscriptionPostId) !== 'publish') {
      return false;
    }

    return in_array(self::getStatus($subscriptionPostId), Helper::ACTIVE_STATUSES, true);
  }

  /**
   * Returns the raw internal status of a subscription.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return string|null the status, or null if not found
   */
  public static function getStatus(int $subscriptionPostId): ?string
  {
    $status = get_field('subscription_status', $subscriptionPostId);
    return is_string($status) && $status !== '' ? $status : null;
  }

  /**
   * Returns the WooCommerce order linked to a subscription. User, billing and shipping data is
   * always read from this order, never duplicated onto the subscription post.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return \WC_Order|null the linked order, or null if not found
   */
  public static function getLinkedOrder(int $subscriptionPostId): ?\WC_Order
  {
    $orderId = (int) get_field('linked_order_id', $subscriptionPostId);
    if ($orderId <= 0) {
      return null;
    }

    $order = wc_get_order($orderId);
    return $order instanceof \WC_Order ? $order : null;
  }

  /**
   * Returns the payment/charge log rows for a subscription, newest first.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return array<int,array<string,mixed>> the charge log rows
   */
  public static function getChargeLog(int $subscriptionPostId): array
  {
    return (new ChargeLog())->getForSubscription($subscriptionPostId);
  }

  /**
   * Checks whether a WooCommerce product is configured as a subscription product.
   * @param int $productId the WooCommerce product id
   * @return bool true if the product is a subscription product
   */
  public static function isSubscriptionProduct(int $productId): bool
  {
    return (bool) get_field('is_subscription_product', $productId);
  }
}
