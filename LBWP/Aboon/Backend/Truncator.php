<?php

namespace LBWP\Aboon\Backend;

use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Theme\Base\Component;
use LBWP\Util\WordPress;

/**
 * Provides truncate functions for a woocommerce database
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch
 */
class Truncator extends Component
{
  /**
   * Init API endpoint
   */
  public function init()
  {
    if (isset($_GET['purge_that_booty_yo']) && current_user_can('administrator')) {
      $this->purgeDatabase(intval($_GET['step']));
    }
  }

  /**
   * Runs the various purge steps for woocommerce
   * @param int $step
   */
  protected function purgeDatabase($step)
  {
    // In any case, stop caching completely
    global $wp_object_cache;
    $wp_object_cache->can_write = false;

    switch ($step) {
      case 1:
        $this->truncateTables();
        break;
      case 2:
        $this->truncateOrders();
        break;
      case 3:
        $this->truncateProducts();
        break;
      case 4:
        $this->truncateTerms();
        break;
      case 5:
        $this->truncateUsers();
        break;
      case 6:
        $this->truncateAttachments();
        break;
      case 7:
        $this->truncatePostmeta();
        break;
    }

    // After the action, flush cache completely
    MemcachedAdmin::flushFullCacheHelper();
    exit;
  }

  /**
   * Run simple truncates
   * @return void
   */
  protected function truncateTables()
  {
    $db = WordPress::getDb();

    $tables = array(
      'actionscheduler_actions',
      'actionscheduler_claims',
      'actionscheduler_logs',
      'yoast_indexable',
      'yoast_indexable_hierarchy',
      'yoast_migrations',
      'yoast_primary_term',
      'yoast_seo_links',
      'yoast_seo_meta',
      'lbwp_prod_map',
      'lbwp_word_index',
      'wc_product_meta_lookup',
      'wc_order_tax_lookup',
      'wc_order_stats',
      'wc_order_product_lookup',
      'wc_order_coupon_lookup',
      'wc_customer_lookup',
      'wc_category_lookup',
      'wc_admin_note_actions',
      'wc_admin_notes',
      'woocommerce_order_items',
      'woocommerce_order_itemmeta',
      'woocommerce_sessions'
    );

    foreach ($tables as $table) {
      $db->query('TRUNCATE TABLE ' . $db->prefix . $table);
    }
  }

  /**
   * Removes all orders and their meta
   */
  protected function truncateOrders()
  {
    set_time_limit(3600);
    $db = WordPress::getDb();

    $orderIds = $db->get_col('SELECT ID FROM ' . $db->posts . ' WHERE post_type = "shop_order"');
    foreach ($orderIds as $orderId) {
      wp_delete_post($orderId, true);
    }

    $refundIds = $db->get_col('SELECT ID FROM ' . $db->posts . ' WHERE post_type = "shop_order_refund"');
    foreach ($refundIds as $refundId) {
      wp_delete_post($refundId, true);
    }
  }

  /**
   * Removes all products and their meta
   */
  protected function truncateProducts()
  {
    set_time_limit(36000);
    $db = WordPress::getDb();

    $orderIds = $db->get_col('SELECT ID FROM ' . $db->posts . ' WHERE post_type = "product"');
    foreach ($orderIds as $orderId) {
      wp_delete_post($orderId, true);
    }
  }

  /**
   * Removes all users except our admin user
   */
  protected function truncateTerms()
  {
    set_time_limit(7200);
    $db = WordPress::getDb();

    $termIds = $db->get_col('SELECT term_id FROM ' . $db->term_taxonomy . ' WHERE taxonomy IN("product_cat", "product_prop")');

    // At this point, relationsships were already deleted, so just delete terms and term_tax tables
    foreach ($termIds as $termId) {
      $db->query('DELETE FROM ' . $db->term_taxonomy . ' WHERE term_id = ' . $termId);
      $db->query('DELETE FROM ' . $db->terms . ' WHERE term_id = ' . $termId);
    }
  }

  /**
   * Removes all product cat and prop terms and connections
   */
  protected function truncateUsers()
  {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    set_time_limit(3600);
    $db = WordPress::getDb();

    $userIds = $db->get_col('SELECT ID FROM ' . $db->users . ' WHERE user_login != "comotive"');
    foreach ($userIds as $userId) {
      wp_delete_user($userId);
    }
  }

  /**
   * Removes all attachment datasets (but not original image)
   */
  protected function truncateAttachments()
  {
    set_time_limit(7200);
    $db = WordPress::getDb();

    $attachments = $db->get_results('SELECT ID,post_name FROM ' . $db->posts . ' WHERE post_type = "attachment" AND (LENGTH(post_name)=5 OR LENGTH(post_name)=6 OR LENGTH(post_name)=7)');
    foreach ($attachments as $attachment) {
      $artnr = intval(str_replace('-', '', $attachment->post_name));
      if ($artnr >= 10000) {
        $db->query('DELETE FROM ' . $db->posts . ' WHERE ID = ' . $attachment->ID);
        $db->query('DELETE FROM ' . $db->postmeta . ' WHERE post_id = ' . $attachment->ID);
      }
    }
  }

  /**
   * Remove post meta that doesn't have meta anymore
   */
  protected function truncatePostmeta()
  {

  }
}