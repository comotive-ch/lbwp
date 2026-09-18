<?php

namespace LBWP\Aboon\AgenticCommerce\Admin;

use LBWP\Aboon\AgenticCommerce\Session;
use LBWP\Helper\WooCommerce\Util;
use WC_Order;

/**
 * Shows protocol, platform, session and transaction of agent orders in the order list and as a
 * meta box on the order screen (HPOS and legacy screens).
 * @package LBWP\Aboon\AgenticCommerce\Admin
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class OrderMeta
{
  public const string COLUMN = 'agentic';

  /**
   * Registers list column and meta box hooks.
   * @return void
   */
  public function register(): void
  {
    add_action('add_meta_boxes', [$this, 'addMetaBox']);
    add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addColumn']);
    add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderColumn'], 10, 2);
    add_filter('manage_edit-shop_order_columns', [$this, 'addColumn']);
    add_action('manage_shop_order_posts_custom_column', [$this, 'renderColumn'], 10, 2);
  }

  /**
   * Adds the meta box on the order edit screen.
   * @return void
   */
  public function addMetaBox(): void
  {
    $screen = Util::isHposActive() ? wc_get_page_screen_id('shop-order') : 'shop_order';
    add_meta_box('agentic-commerce-order', 'Agentic Commerce', [$this, 'renderMetaBox'], $screen, 'side', 'default');
  }

  /**
   * Renders the meta box content.
   * @param mixed $object WP_Post or WC_Order
   * @return void
   */
  public function renderMetaBox(mixed $object): void
  {
    $order = $object instanceof WC_Order ? $object : wc_get_order(is_object($object) && isset($object->ID) ? (int) $object->ID : 0);
    if (!$order instanceof WC_Order || $order->get_meta(Session::META_PROTOCOL) === '') {
      echo '<p>' . esc_html__('Keine Agenten-Bestellung.', 'lbwp') . '</p>';
      return;
    }
    $rows = [
      'Protokoll' => strtoupper((string) $order->get_meta(Session::META_PROTOCOL)),
      'Plattform' => (string) $order->get_meta(Session::META_PLATFORM),
      'Session' => (string) $order->get_meta(Session::META_SESSION_ID),
      'Transaktion' => (string) $order->get_meta(Session::META_PAYMENT_TXN),
    ];
    echo '<table class="widefat striped" style="border:0">';
    foreach ($rows as $label => $value) {
      echo '<tr><th style="padding:4px 6px">' . esc_html($label) . '</th><td style="padding:4px 6px;word-break:break-all">' . esc_html($value !== '' ? $value : '–') . '</td></tr>';
    }
    echo '</table>';
  }

  /**
   * Adds the list column after the order status column.
   * @param array $columns existing columns
   * @return array columns
   */
  public function addColumn(array $columns): array
  {
    $result = [];
    foreach ($columns as $key => $label) {
      $result[$key] = $label;
      if ($key === 'order_status') {
        $result[self::COLUMN] = 'KI';
      }
    }
    if (!isset($result[self::COLUMN])) {
      $result[self::COLUMN] = 'KI';
    }
    return $result;
  }

  /**
   * Renders the list column cell.
   * @param string $column column key
   * @param mixed $orderOrId WC_Order (HPOS) or post id (legacy)
   * @return void
   */
  public function renderColumn(string $column, mixed $orderOrId): void
  {
    if ($column !== self::COLUMN) {
      return;
    }
    $order = $orderOrId instanceof WC_Order ? $orderOrId : wc_get_order((int) $orderOrId);
    if (!$order instanceof WC_Order) {
      return;
    }
    $protocol = (string) $order->get_meta(Session::META_PROTOCOL);
    if ($protocol === '') {
      echo '–';
      return;
    }
    echo '<span class="order-status status-processing" title="' . esc_attr((string) $order->get_meta(Session::META_PLATFORM)) . '">' . esc_html(strtoupper($protocol)) . '</span>';
  }
}
