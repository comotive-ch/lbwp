<?php

namespace LBWP\Aboon\Subscription\Account;

use LBWP\Aboon\Subscription\SubscriptionHelper;
use LBWP\Util\File;

/**
 * Adds the "Abonnemente" My Account page: a list of the customer's own subscriptions, and a
 * detail view (via a trailing path segment, e.g. /mein-konto/abonnemente/123/) per subscription.
 * Mirrors the established pattern used by PurchaseHistory/Watchlist/SimpleAffiliate.
 * @package LBWP\Aboon\Subscription\Account
 * @author Michael Sebel <michael@comotive.ch>
 */
class AccountEndpoint
{
  public const string ENDPOINT = 'abonnemente';

  /**
   * Registers the rewrite endpoint and My Account menu/content hooks.
   * Remember to flush permalinks on activation.
   * @return void
   */
  public function register(): void
  {
    add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    add_action('woocommerce_account_menu_items', [$this, 'addAccountMenu']);
    add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'renderAccountPage'], 99);
  }

  /**
   * Inserts the "Abonnemente" entry into the My Account menu.
   * @param array<string,string> $menuLinks the existing menu items, keyed by endpoint slug
   * @return array<string,string> the updated menu items
   */
  public function addAccountMenu(array $menuLinks): array
  {
    return array_slice($menuLinks, 0, 2, true) +
      [self::ENDPOINT => __('Abonnemente', 'lbwp')] +
      array_slice($menuLinks, 2, null, true);
  }

  /**
   * Renders either the subscription list or, if a subscription id is present in the endpoint's
   * trailing path segment, that subscription's detail view.
   * @return void
   */
  public function renderAccountPage(): void
  {
    $subscriptionPostId = (int) get_query_var(self::ENDPOINT);
    if ($subscriptionPostId > 0) {
      $this->renderDetail($subscriptionPostId);
      return;
    }

    $this->renderList();
  }

  /**
   * Renders the list of the current user's subscriptions.
   * @return void
   */
  protected function renderList(): void
  {
    $subscriptions = SubscriptionHelper::getUserSubscriptions(get_current_user_id());
    require File::getViewsPath() . '/module/subscriptions/account-subscription-list.php';
  }

  /**
   * Renders a single subscription's detail view, after checking it belongs to the current user.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  protected function renderDetail(int $subscriptionPostId): void
  {
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    if ($order === null || (int) $order->get_customer_id() !== get_current_user_id()) {
      echo '<p>' . esc_html__('Abonnement nicht gefunden.', 'lbwp') . '</p>';
      return;
    }

    $chargeLog = SubscriptionHelper::getChargeLog($subscriptionPostId);
    $status = SubscriptionHelper::getStatus($subscriptionPostId);
    require File::getViewsPath() . '/module/subscriptions/account-subscription-detail.php';
  }
}
