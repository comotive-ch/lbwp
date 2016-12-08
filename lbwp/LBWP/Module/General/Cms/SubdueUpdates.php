<?php

namespace LBWP\Module\General\Cms;

/**
 * This is originally a plugin, testing integration
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class SubdueUpdates
{
  protected $__pluginsFiles;
  protected $__themeFiles;
  /**
   * @var SubdueUpdates
   */
  protected static $instance = NULL;

  /**
   * Load the filters
   */
  public static function init()
  {
    self::$instance = new SubdueUpdates();
  }

  /**
   * Run all the filters and checks
   */
  protected function __construct()
  {
    $this->__pluginsFiles = array();
    $this->__themeFiles = array();

    add_action('admin_init', array($this, 'removeHooks'));

    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

    if (count(get_plugins()) > 0) foreach (get_plugins() as $file => $pl) $this->__pluginsFiles[$file] = $pl['Version'];
    if (count(wp_get_themes()) > 0) foreach (wp_get_themes() as $theme) $this->__themeFiles[$theme->get_stylesheet()] = $theme->get('Version');

    add_filter('pre_transient_update_themes', array($this, 'last_checked_themes'));
    add_filter('pre_site_transient_update_themes', array($this, 'last_checked_themes'));
    add_action('pre_transient_update_plugins', array($this, 'last_checked_plugins'));
    add_filter('pre_site_transient_update_plugins', array($this, 'last_checked_plugins'));
    add_filter('pre_transient_update_core', array($this, 'last_checked_core'));
    add_filter('pre_site_transient_update_core', array($this, 'last_checked_core'));
    remove_action('admin_bar_menu', 'wp_admin_bar_updates_menu', 50);

    // Disable All Automatic Updates
    add_filter('auto_update_translation', '__return_false');
    add_filter('automatic_updater_disabled', '__return_true');
    add_filter('allow_minor_auto_core_updates', '__return_false');
    add_filter('allow_major_auto_core_updates', '__return_false');
    add_filter('allow_dev_auto_core_updates', '__return_false');
    add_filter('auto_update_core', '__return_false');
    add_filter('wp_auto_update_core', '__return_false');
    add_filter('auto_core_update_send_email', '__return_false');
    add_filter('send_core_update_notification_email', '__return_false');
    add_filter('auto_update_plugin', '__return_false');
    add_filter('auto_update_theme', '__return_false');
    add_filter('automatic_updates_send_debug_email', '__return_false');
    add_filter('automatic_updates_is_vcs_checkout', '__return_true');
    add_filter('automatic_updates_send_debug_email ', '__return_false', 1);
    add_filter('pre_http_request', array($this, 'block_request'), 10, 3);

    // Disable scheduled updates of themes and plugins
    $timestamp = wp_next_scheduled('wp_update_plugins');
    wp_unschedule_event($timestamp, 'wp_update_plugins', array());
    $timestamp = wp_next_scheduled('wp_update_themes');
    wp_unschedule_event($timestamp, 'wp_update_themes', array());
  }


  /**
   * Remove all hooks to completely disable updates
   */
  function removeHooks()
  {
    remove_action('admin_notices', 'update_nag', 3);
    remove_action('admin_notices', 'maintenance_nag');
    remove_action('load-themes.php', 'wp_update_themes');
    remove_action('load-update.php', 'wp_update_themes');
    remove_action('load-update-core.php', 'wp_update_themes');
    remove_action('load-update-core.php', 'wp_update_plugins');
    remove_action('load-plugins.php', 'wp_update_plugins');
    remove_action('load-update.php', 'wp_update_plugins');
    remove_action('admin_init', '_maybe_update_plugins');
    remove_action('admin_init', '_maybe_update_themes');
    remove_action('admin_init', '_maybe_update_core');
    remove_action('admin_init', 'wp_maybe_auto_update');
    remove_action('admin_init', 'wp_auto_update_core');
    remove_action('wp_version_check', 'wp_version_check');
    remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');
    remove_action('wp_update_themes', 'wp_update_themes');
    remove_action('wp_update_plugins', 'wp_update_plugins');

    // Also, add some actions
    add_filter('pre_option_update_core', '__return_null');
    add_action('init', function() {
      remove_action('init', 'wp_version_check');
    }, 2);

    // And make sure to remove scheduled hooks
    wp_clear_scheduled_hook('wp_update_themes');
    wp_clear_scheduled_hook('wp_update_plugins');
    wp_clear_scheduled_hook('wp_version_check');
    wp_clear_scheduled_hook('wp_maybe_auto_update');
  }


  /**
   * Check the outgoing request
   */
  public function block_request($pre, $args, $url)
  {
    /* Empty url */
    if (empty($url)) {
      return $pre;
    }

    /* Invalid host */
    if (!$host = parse_url($url, PHP_URL_HOST)) {
      return $pre;
    }

    $url_data = parse_url($url);

    /* block request */
    if (false !== stripos($host, 'api.wordpress.org') && (false !== stripos($url_data['path'], 'update-check') || false !== stripos($url_data['path'], 'browse-happy'))) {
      return true;
    }

    return $pre;
  }


  /**
   * Override core version check info
   */
  public function last_checked_core()
  {
    global $wp_version;

    return (object)array(
      'last_checked' => time(),
      'updates' => array(),
      'version_checked' => $wp_version
    );
  }

  /**
   * Override themes version check info
   */
  public function last_checked_themes()
  {
    global $wp_version;

    return (object)array(
      'last_checked' => time(),
      'updates' => array(),
      'version_checked' => $wp_version,
      'checked' => $this->__themeFiles
    );
  }

  /**
   * Override plugins version check info
   */
  public function last_checked_plugins()
  {
    global $wp_version;

    return (object)array(
      'last_checked' => time(),
      'updates' => array(),
      'version_checked' => $wp_version,
      'checked' => $this->__pluginsFiles
    );
  }
}