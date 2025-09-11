<?php

namespace LBWP\Helper;

use LBWP\Core;

/**
 * LBWP install and uninstall helper
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class Installer
{
  /**
   * The install function, when the plugin is enabled
   */
  public static function install()
  {
    // Set bigger image size defaults
    self::setImageSizeDefaults();
    self::updateLbwpTables();
  }

  /**
   * Sets bigger image sizes, if they're still the basic ones
   */
  protected static function setImageSizeDefaults()
  {
    $config = array(
      'thumbnail_size_w' => '150;300',
      'thumbnail_size_h' => '150;300',
      'medium_size_w' => '300;600',
      'medium_size_h' => '300;600',
      'large_size_w' => '1024;1280',
      'large_size_h' => '1024;1280'
    );

    // Set new defaults, if needed
    foreach ($config as $option => $data) {
      list($before, $after) = explode(';', $data);
      if (get_option($option) == $before) {
        update_option($option, $after);
      }
    }
  }

  /**
   * Installs or upgrades all lbwp core tables
   */
  public static function updateLbwpTables()
  {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'lbwp_data';

    $sql = "CREATE TABLE $table (
      pid bigint(11) UNSIGNED NOT NULL AUTO_INCREMENT,
      row_key varchar(100) DEFAULT '' NOT NULL,
      row_id varchar(100) DEFAULT '' NOT NULL,
      row_created datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      row_modified datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      user_id bigint(11) UNSIGNED DEFAULT 0 NOT NULL,
      row_data mediumtext NOT NULL,
      PRIMARY KEY  (pid),
      KEY  (row_key),
      KEY  (row_id)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  /**
   * The uninstall function, if the plugin is disabled
   */
  public static function uninstall()
  {
    // Nothing do to yet (since LBWP is NEVER uninstalled :-))
  }

  /**
   * Code copied at woocommerce/includes/class-wc-install.php:193
   */
  public static function resetWooCommerceCrons()
  {
    global $woocommerce;
    if ($woocommerce instanceof \WooCommerce) {
      // Cron jobs
      wp_clear_scheduled_hook( 'woocommerce_scheduled_sales' );
      wp_clear_scheduled_hook( 'woocommerce_cancel_unpaid_orders' );
      wp_clear_scheduled_hook( 'woocommerce_cleanup_sessions' );
      wp_clear_scheduled_hook( 'woocommerce_language_pack_updater_check' );

      $ve = get_option( 'gmt_offset' ) > 0 ? '+' : '-';

      wp_schedule_event( strtotime( '00:00 tomorrow ' . $ve . get_option( 'gmt_offset' ) . ' HOURS' ), 'daily', 'woocommerce_scheduled_sales' );

      $held_duration = get_option( 'woocommerce_hold_stock_minutes', null );

      if ( is_null( $held_duration ) ) {
        $held_duration = '60';
      }

      if ( $held_duration != '' ) {
        wp_schedule_single_event( time() + ( absint( $held_duration ) * 60 ), 'woocommerce_cancel_unpaid_orders' );
      }

      wp_schedule_event( time(), 'twicedaily', 'woocommerce_cleanup_sessions' );
      wp_schedule_single_event( time(), 'woocommerce_language_pack_updater_check' );
    }
  }
}