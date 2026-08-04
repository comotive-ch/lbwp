<?php
/**
 * My Account "Abonnemente" list view.
 * @var array<int,int> $subscriptions lbwp-subscription post ids
 */

use LBWP\Aboon\Subscription\Account\AccountEndpoint;
use LBWP\Aboon\Subscription\Helper;
use LBWP\Aboon\Subscription\SubscriptionHelper;

if (!defined('ABSPATH')) {
  exit;
}
?>
<?php if (empty($subscriptions)) : ?>
  <p><?php esc_html_e('Du hast noch keine Abonnemente.', 'lbwp'); ?></p>
<?php else : ?>
  <table class="woocommerce-orders-table shop_table shop_table_responsive">
    <thead>
      <tr>
        <th><?php esc_html_e('Abonnement', 'lbwp'); ?></th>
        <th><?php esc_html_e('Turnus', 'lbwp'); ?></th>
        <th><?php esc_html_e('Status', 'lbwp'); ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($subscriptions as $subscriptionPostId) : ?>
        <tr>
          <td><?php echo esc_html(get_the_title($subscriptionPostId)); ?></td>
          <td><?php echo esc_html(Helper::recurrenceLabel((string) get_field('recurrence_snapshot', $subscriptionPostId))); ?></td>
          <td><?php echo esc_html((string) SubscriptionHelper::getStatus($subscriptionPostId)); ?></td>
          <td>
            <a class="woocommerce-button button" href="<?php echo esc_url(wc_get_account_endpoint_url(AccountEndpoint::ENDPOINT . '/' . $subscriptionPostId)); ?>">
              <?php esc_html_e('Details', 'lbwp'); ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
