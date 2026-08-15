<?php

namespace LBWP\Aboon\Subscription\Admin;

use LBWP\Aboon\Subscription\Email\AdminFailureNotice;
use LBWP\Aboon\Subscription\Helper;
use LBWP\Aboon\Subscription\PostType;
use LBWP\Aboon\Subscription\SubscriptionHelper;
use LBWP\Util\File;

/**
 * Renders the subscription detail meta box on the lbwp-subscription post edit screen: user data
 * read live from the linked order, the payment/charge log, and admin action buttons.
 * @package LBWP\Aboon\Subscription\Admin
 * @author Michael Sebel <michael@comotive.ch>
 */
class AdminScreen
{
  /**
   * Registers the meta box and the "create without payment" admin page.
   * @return void
   */
  public function register(): void
  {
    add_action('add_meta_boxes', [$this, 'addMetaBox']);
    add_action('admin_menu', [$this, 'addCreatePage']);
    add_filter('manage_' . PostType::SLUG . '_posts_columns', [$this, 'addListColumns']);
    add_action('manage_' . PostType::SLUG . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
  }

  /**
   * Adds the "Gültig bis" and "Rechnungsadresse" columns to the subscription list table.
   * @param array $columns the existing list table columns
   * @return array the extended columns
   */
  public function addListColumns(array $columns): array
  {
    $columns['valid_until'] = __('Gültig bis', 'lbwp');
    $columns['billing_address'] = __('Rechnungsadresse', 'lbwp');
    return $columns;
  }

  /**
   * Renders the content of a custom subscription list table column.
   * @param string $column the column key being rendered
   * @param int $postId the subscription post id
   * @return void
   */
  public function renderListColumn(string $column, int $postId): void
  {
    if ($column === 'valid_until') {
      $until = (int) get_field('valid_until', $postId);
      echo esc_html($until > 0 ? date_i18n('d.m.Y', $until) : '–');
      return;
    }

    if ($column === 'billing_address') {
      $order = SubscriptionHelper::getLinkedOrder($postId);
      echo $order !== null ? wp_kses_post($order->get_formatted_billing_address() ?: '–') : '–';
    }
  }

  /**
   * Adds the "Abo ohne Zahlung erstellen" submenu page under the WooCommerce menu.
   * @return void
   */
  public function addCreatePage(): void
  {
    add_submenu_page(
      'woocommerce',
      __('Abo ohne Zahlung erstellen', 'lbwp'),
      __('Abo ohne Zahlung erstellen', 'lbwp'),
      'manage_woocommerce',
      'lbwp-subscription-create',
      [$this, 'renderCreatePage']
    );
  }

  /**
   * Renders the "create subscription without initial payment" form.
   * @return void
   */
  public function renderCreatePage(): void
  {
    require File::getViewsPath() . '/module/subscriptions/admin-create-without-payment.php';
  }

  /**
   * Adds the detail meta box to the subscription edit screen.
   * @return void
   */
  public function addMetaBox(): void
  {
    add_meta_box(
      'lbwp-subscription-detail',
      __('Abonnement-Details', 'lbwp'),
      [$this, 'render'],
      PostType::SLUG,
      'normal',
      'high'
    );
  }

  /**
   * Renders the meta box content.
   * @param \WP_Post $post the subscription post
   * @return void
   */
  public function render(\WP_Post $post): void
  {
    $order = SubscriptionHelper::getLinkedOrder($post->ID);
    $chargeLog = SubscriptionHelper::getChargeLog($post->ID);
    $status = SubscriptionHelper::getStatus($post->ID);

    require File::getViewsPath() . '/module/subscriptions/admin-subscription-detail.php';
  }

  /**
   * Moves a subscription's WordPress post status to draft (per convention, only a definitive
   * payment failure does this) and notifies the site admin by email.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  public static function moveToDraftAndNotifyAdmin(int $subscriptionPostId): void
  {
    if (get_post_status($subscriptionPostId) === 'draft') {
      return;
    }

    update_field('subscription_status', Helper::STATUS_FAILED_FINAL, $subscriptionPostId);
    wp_update_post([
      'ID' => $subscriptionPostId,
      'post_status' => 'draft',
    ]);

    AdminFailureNotice::send($subscriptionPostId);
  }
}
