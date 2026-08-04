<?php

namespace LBWP\Aboon\Subscription\Cron;

use LBWP\Aboon\Subscription\Admin\AdminScreen;
use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\Helper;
use LBWP\Aboon\Subscription\PostType;
use LBWP\Module\General\Cms\SystemLog;

/**
 * Daily safety-net that re-syncs every still-active subscription's status from Payrexx, in case
 * a webhook delivery was missed. Uses the framework's existing "cron_daily" tick (fired by
 * LBWP\Module\General\CronHandler) rather than a custom scheduling mechanism.
 * @package LBWP\Aboon\Subscription\Cron
 * @author Michael Sebel <michael@comotive.ch>
 */
class SyncCron
{
  /**
   * @var int maximum number of subscriptions synced per run, to keep a single run fast
   */
  protected const int BATCH_SIZE = 200;

  /**
   * Hooks the daily sync onto the platform's existing daily cron tick.
   * @return void
   */
  public function register(): void
  {
    add_action('cron_daily', [$this, 'run']);
  }

  /**
   * Re-fetches every still-active subscription from Payrexx and updates its mapped status.
   * @return void
   */
  public function run(): void
  {
    $subscriptionPostIds = get_posts([
      'post_type' => PostType::SLUG,
      'post_status' => 'publish',
      'posts_per_page' => self::BATCH_SIZE,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => 'subscription_status', 'value' => Helper::ACTIVE_STATUSES, 'compare' => 'IN'],
      ],
    ]);

    if (count($subscriptionPostIds) >= self::BATCH_SIZE) {
      SystemLog::add('AboonSubscription', 'warning', 'Daily subscription sync hit the batch size limit, some subscriptions may not have been synced today.');
    }

    $api = new SubscriptionApi();
    foreach ($subscriptionPostIds as $subscriptionPostId) {
      $this->syncOne($subscriptionPostId, $api);
    }
  }

  /**
   * Re-syncs a single subscription's status from Payrexx.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @param SubscriptionApi $api the Payrexx subscription API wrapper
   * @return void
   */
  protected function syncOne(int $subscriptionPostId, SubscriptionApi $api): void
  {
    $payrexxSubscriptionId = (string) get_field('payrexx_subscription_id', $subscriptionPostId);
    if ($payrexxSubscriptionId === '') {
      return;
    }

    $subscription = $api->getOne($payrexxSubscriptionId);
    if ($subscription === null) {
      return;
    }

    $internalStatus = Helper::mapPayrexxStatus($subscription->getStatus());
    update_field('subscription_status', $internalStatus, $subscriptionPostId);
    update_field('next_pay_date', $subscription->getNextPayDate(), $subscriptionPostId);

    if ($internalStatus === Helper::STATUS_FAILED_FINAL) {
      AdminScreen::moveToDraftAndNotifyAdmin($subscriptionPostId);
    }
  }
}
