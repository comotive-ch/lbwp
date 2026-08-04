<?php
/**
 * My Account "Abonnemente" detail view.
 * @var int $subscriptionPostId
 * @var \WC_Order $order
 * @var array<int,array<string,mixed>> $chargeLog
 * @var string|null $status
 */

use LBWP\Aboon\Subscription\Account\AccountActions;
use LBWP\Aboon\Subscription\Helper;

if (!defined('ABSPATH')) {
  exit;
}

$productId = 0;
foreach ($order->get_items() as $item) {
  $productId = (int) $item->get_product_id();
  break;
}

$currentQuantity = (int) get_field('current_quantity', $subscriptionPostId);
$canResize = $productId > 0 && (bool) get_field('allow_subscription_resize', $productId);
$canCancel = $productId > 0 && (bool) get_field('allow_subscription_cancel', $productId);
$upgradeTargets = $productId > 0 ? (array) get_field('subscription_upgrade_products', $productId) : [];
$nonceAction = 'lbwp_sub_account_action_' . $subscriptionPostId;
?>
<h3><?php echo esc_html(get_the_title($subscriptionPostId)); ?></h3>

<table class="woocommerce-table shop_table">
  <tbody>
    <tr>
      <th><?php esc_html_e('Status', 'lbwp'); ?></th>
      <td><?php echo esc_html((string) $status); ?></td>
    </tr>
    <tr>
      <th><?php esc_html_e('Turnus', 'lbwp'); ?></th>
      <td><?php echo esc_html(Helper::recurrenceLabel((string) get_field('recurrence_snapshot', $subscriptionPostId))); ?></td>
    </tr>
    <tr>
      <th><?php esc_html_e('Menge', 'lbwp'); ?></th>
      <td><?php echo esc_html((string) $currentQuantity); ?></td>
    </tr>
  </tbody>
</table>

<h4><?php esc_html_e('Zahlungsverlauf', 'lbwp'); ?></h4>
<table class="woocommerce-table shop_table">
  <thead>
    <tr>
      <th><?php esc_html_e('Datum', 'lbwp'); ?></th>
      <th><?php esc_html_e('Typ', 'lbwp'); ?></th>
      <th><?php esc_html_e('Betrag', 'lbwp'); ?></th>
      <th><?php esc_html_e('Status', 'lbwp'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($chargeLog)) : ?>
      <tr><td colspan="4"><?php esc_html_e('Noch keine Zahlungen.', 'lbwp'); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($chargeLog as $row) : ?>
      <tr>
        <td><?php echo esc_html($row['created_at']); ?></td>
        <td><?php echo esc_html($row['type']); ?></td>
        <td><?php echo esc_html(Helper::formatAmount((int) $row['amount'], $row['currency'])); ?></td>
        <td><?php echo esc_html($row['status']); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h4><?php esc_html_e('Aktionen', 'lbwp'); ?></h4>

<form method="post" class="lbwp-sub-account-form">
  <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $subscriptionPostId); ?>">
  <input type="hidden" name="lbwp_sub_action" value="<?php echo esc_attr(AccountActions::ACTION_CHANGE_PAYMENT_METHOD); ?>">
  <?php wp_nonce_field($nonceAction); ?>
  <button type="submit" class="woocommerce-button button"><?php esc_html_e('Zahlungsmethode ändern', 'lbwp'); ?></button>
</form>

<?php if ($canResize) : ?>
  <form method="post" class="lbwp-sub-account-form">
    <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $subscriptionPostId); ?>">
    <input type="hidden" name="lbwp_sub_action" value="<?php echo esc_attr(AccountActions::ACTION_INCREASE_QUANTITY); ?>">
    <?php wp_nonce_field($nonceAction); ?>
    <label>
      <?php esc_html_e('Neue Menge (nur Erhöhung möglich)', 'lbwp'); ?>
      <input type="number" name="new_quantity" min="<?php echo esc_attr((string) ($currentQuantity + 1)); ?>" value="<?php echo esc_attr((string) ($currentQuantity + 1)); ?>">
    </label>
    <button type="submit" class="woocommerce-button button"><?php esc_html_e('Menge erhöhen', 'lbwp'); ?></button>
  </form>
<?php endif; ?>

<?php if (!empty($upgradeTargets)) : ?>
  <form method="post" class="lbwp-sub-account-form">
    <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $subscriptionPostId); ?>">
    <input type="hidden" name="lbwp_sub_action" value="<?php echo esc_attr(AccountActions::ACTION_UPGRADE); ?>">
    <?php wp_nonce_field($nonceAction); ?>
    <label>
      <?php esc_html_e('Upgrade auf', 'lbwp'); ?>
      <select name="target_product_id">
        <?php foreach ($upgradeTargets as $targetProductId) : ?>
          <option value="<?php echo esc_attr((string) $targetProductId); ?>"><?php echo esc_html(get_the_title((int) $targetProductId)); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="woocommerce-button button"><?php esc_html_e('Jetzt upgraden', 'lbwp'); ?></button>
  </form>
<?php endif; ?>

<?php if ($canCancel) : ?>
  <form method="post" class="lbwp-sub-account-form lbwp-sub-cancel-form">
    <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $subscriptionPostId); ?>">
    <input type="hidden" name="lbwp_sub_action" value="<?php echo esc_attr(AccountActions::ACTION_CANCEL); ?>">
    <?php wp_nonce_field($nonceAction); ?>
    <button type="submit" class="woocommerce-button button lbwp-sub-cancel-button"><?php esc_html_e('Abonnement kündigen', 'lbwp'); ?></button>
  </form>
  <script>
    document.querySelectorAll('.lbwp-sub-cancel-form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (!window.confirm('<?php echo esc_js(__('Möchten Sie das Abonnement wirklich kündigen?', 'lbwp')); ?>')) {
          event.preventDefault();
        }
      });
    });
  </script>
<?php endif; ?>
