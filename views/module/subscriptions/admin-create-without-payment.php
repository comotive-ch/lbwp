<?php
/**
 * Admin page: create a subscription for a customer without an initial payment.
 */

use LBWP\Aboon\Subscription\Admin\AdminActions;

if (!defined('ABSPATH')) {
  exit;
}

$subscriptionProducts = get_posts([
  'post_type' => 'product',
  'posts_per_page' => -1,
  'meta_query' => [
    ['key' => 'is_subscription_product', 'value' => '1', 'compare' => '='],
  ],
]);
?>
<div class="wrap">
  <h1><?php esc_html_e('Abo ohne Zahlung erstellen', 'lbwp'); ?></h1>
  <p><?php esc_html_e('Erstellt eine Bestellung im Status "Auf Zahlung wartend" und ein zugehöriges Abonnement, ohne den Kunden sofort zu belasten. Der Kunde erhält per E-Mail einen Link, um eine Zahlungsmethode zu hinterlegen; die erste Belastung erfolgt sobald er diesen Link abschliesst.', 'lbwp'); ?></p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr(AdminActions::ACTION_CREATE_WITHOUT_PAYMENT); ?>">
    <?php wp_nonce_field(AdminActions::ACTION_CREATE_WITHOUT_PAYMENT); ?>

    <table class="form-table">
      <tr>
        <th><label for="lbwp-sub-user"><?php esc_html_e('Kunde', 'lbwp'); ?></label></th>
        <td>
          <?php
          wp_dropdown_users([
            'name' => 'user_id',
            'id' => 'lbwp-sub-user',
            'show_option_none' => __('Kunde wählen', 'lbwp'),
          ]);
          ?>
        </td>
      </tr>
      <tr>
        <th><label for="lbwp-sub-product"><?php esc_html_e('Abonnement-Produkt', 'lbwp'); ?></label></th>
        <td>
          <select name="product_id" id="lbwp-sub-product" required>
            <option value=""><?php esc_html_e('Produkt wählen', 'lbwp'); ?></option>
            <?php foreach ($subscriptionProducts as $product) : ?>
              <option value="<?php echo esc_attr((string) $product->ID); ?>"><?php echo esc_html($product->post_title); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label for="lbwp-sub-quantity"><?php esc_html_e('Menge', 'lbwp'); ?></label></th>
        <td><input type="number" min="1" step="1" value="1" name="quantity" id="lbwp-sub-quantity"></td>
      </tr>
    </table>

    <?php submit_button(__('Abo erstellen und Link senden', 'lbwp')); ?>
  </form>
</div>
