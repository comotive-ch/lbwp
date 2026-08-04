<?php
/**
 * Admin meta box view for a single lbwp-subscription post.
 * @var \WP_Post $post
 * @var \WC_Order|null $order
 * @var array<int,array<string,mixed>> $chargeLog
 * @var string|null $status
 */

use LBWP\Aboon\Subscription\Admin\AdminActions;
use LBWP\Aboon\Subscription\Helper;

if (!defined('ABSPATH')) {
  exit;
}
?>
<div class="lbwp-subscription-detail">
  <h3><?php esc_html_e('Status', 'lbwp'); ?></h3>
  <table class="widefat striped">
    <tbody>
      <tr>
        <th><?php esc_html_e('Interner Status', 'lbwp'); ?></th>
        <td><?php echo esc_html((string) $status); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Turnus', 'lbwp'); ?></th>
        <td><?php echo esc_html(Helper::recurrenceLabel((string) get_field('recurrence_snapshot', $post->ID))); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Menge', 'lbwp'); ?></th>
        <td><?php echo esc_html((string) get_field('current_quantity', $post->ID)); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Nächste Zahlung', 'lbwp'); ?></th>
        <td><?php echo esc_html(($next = (int) get_field('next_pay_date', $post->ID)) > 0 ? date_i18n('d.m.Y', $next) : '–'); ?></td>
      </tr>
      <tr>
        <th><?php esc_html_e('Payrexx Subscription ID', 'lbwp'); ?></th>
        <td><?php echo esc_html((string) get_field('payrexx_subscription_id', $post->ID)); ?></td>
      </tr>
    </tbody>
  </table>

  <h3><?php esc_html_e('Kunde & Bestellung', 'lbwp'); ?></h3>
  <?php if ($order === null) : ?>
    <p><?php esc_html_e('Keine verknüpfte Bestellung gefunden.', 'lbwp'); ?></p>
  <?php else : ?>
    <table class="widefat striped">
      <tbody>
        <tr>
          <th><?php esc_html_e('Bestellung', 'lbwp'); ?></th>
          <td><a href="<?php echo esc_url((string) get_edit_post_link($order->get_id(), '')); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a></td>
        </tr>
        <tr>
          <th><?php esc_html_e('Kunde', 'lbwp'); ?></th>
          <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?> (<?php echo esc_html($order->get_billing_email()); ?>)</td>
        </tr>
        <tr>
          <th><?php esc_html_e('Rechnungsadresse', 'lbwp'); ?></th>
          <td><?php echo wp_kses_post($order->get_formatted_billing_address() ?: '–'); ?></td>
        </tr>
        <tr>
          <th><?php esc_html_e('Lieferadresse', 'lbwp'); ?></th>
          <td><?php echo wp_kses_post($order->get_formatted_shipping_address() ?: '–'); ?></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>

  <h3><?php esc_html_e('Zahlungsverlauf', 'lbwp'); ?></h3>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr(AdminActions::ACTION_REFUND); ?>">
    <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $post->ID); ?>">
    <?php wp_nonce_field(AdminActions::ACTION_REFUND . '_' . $post->ID); ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th></th>
          <th><?php esc_html_e('Datum', 'lbwp'); ?></th>
          <th><?php esc_html_e('Typ', 'lbwp'); ?></th>
          <th><?php esc_html_e('Betrag', 'lbwp'); ?></th>
          <th><?php esc_html_e('Status', 'lbwp'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($chargeLog)) : ?>
          <tr><td colspan="5"><?php esc_html_e('Noch keine Zahlungen.', 'lbwp'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($chargeLog as $row) : ?>
          <tr>
            <td><input type="checkbox" name="charge_log_ids[]" value="<?php echo esc_attr((string) $row['id']); ?>"></td>
            <td><?php echo esc_html($row['created_at']); ?></td>
            <td><?php echo esc_html($row['type']); ?></td>
            <td><?php echo esc_html(Helper::formatAmount((int) $row['amount'], $row['currency'])); ?></td>
            <td><?php echo esc_html($row['status']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><button type="submit" class="button"><?php esc_html_e('Ausgewählte Zahlungen zurückerstatten', 'lbwp'); ?></button></p>
  </form>

  <h3><?php esc_html_e('Aktionen', 'lbwp'); ?></h3>
  <p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
      <input type="hidden" name="action" value="<?php echo esc_attr(AdminActions::ACTION_GENERATE_LINK); ?>">
      <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $post->ID); ?>">
      <?php wp_nonce_field(AdminActions::ACTION_GENERATE_LINK . '_' . $post->ID); ?>
      <button type="submit" class="button"><?php esc_html_e('Zahlungsmethode-Link generieren & E-Mail senden', 'lbwp'); ?></button>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js(__('Abonnement wirklich kündigen?', 'lbwp')); ?>');">
      <input type="hidden" name="action" value="<?php echo esc_attr(AdminActions::ACTION_CANCEL); ?>">
      <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $post->ID); ?>">
      <?php wp_nonce_field(AdminActions::ACTION_CANCEL . '_' . $post->ID); ?>
      <button type="submit" class="button button-secondary"><?php esc_html_e('Abonnement kündigen', 'lbwp'); ?></button>
    </form>
  </p>
</div>
