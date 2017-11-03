<?php

namespace LBWP\Theme\Feature;

use LBWP\Helper\Cronjob;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Util\Date;
use LBWP\Util\Strings;

/**
 * Provides settings to schedule a post object for unpublishing
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class AutoUnpublishPost
{
  /**
   * Default Settings for the pagenavi
   * @var array
   */
  protected $settings = array(
    'postTypes' => array('post', 'page'),
    'unpublishAssociatedMenus' => true,
  );
  /**
   * @var \wpdb the wordpress db object
   */
  protected $wpdb = NULL;
  /**
   * @var TransientStickyPost the sticky post config object
   */
  protected static $instance = NULL;


  /**
   * Can only be instantiated by calling init method
   * @param array|null $settings overriding defaults
   */
  protected function __construct($settings = NULL)
  {
    if (is_array($settings)) {
      $this->settings = array_merge($this->settings, $settings);
    }

    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /**
   * Initialise while overriding settings defaults
   * @param array|null $settings overrides defaults as new default
   */
  public static function init($settings = NULL)
  {
    self::$instance = new AutoUnpublishPost($settings);
    self::$instance->load();
  }

  /**
   * Loads/runs the needed actions and functions
   */
  protected function load()
  {
    add_action('admin_init', array($this, 'addMetabox'));
    add_action('save_post', array($this, 'savePostUnpublishHook'));
    // Look daily, if there are unpublished posts, and add a cron task
    add_action('cron_daily', array($this, 'checkForUnpublishingPosts'));
    // Cron job task to actually check and unbplish posts and their menus
    add_action('cron_job_auto_unpublish_posts', array($this, 'autoUnpublishPosts'));
  }

  /**
   * Adding the metabox to the system
   */
  public function addMetabox()
  {
    foreach ($this->settings['postTypes'] as $postType) {
      add_meta_box(
        'auto_unpublish_post',
        __('Ablaufdatum', 'lbwp'),
        array($this, 'displayMetabox'),
        $postType,
        'side'
      );
    }

    // And add the jquery date picker
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-theme-lbwp');
  }

  /**
   * Show the metabox
   * @param \WP_Post $post the post whose metabox is displayed
   */
  public function displayMetabox($post)
  {
    $html = $date = $time = '';

    // Date field, only shows the date, if it is selected
    $timestamp = intval(get_post_meta($post->ID, 'auto_unpublish_on', true));
    // If there are previous settings, display them
    if ($timestamp > 0) {
      $date = Date::getTime(Date::EU_DATE, $timestamp);
      $time = Date::getTime(Date::EU_CLOCK, $timestamp);
    }
    $html .= '
      <p>' . __('Diesen Beitrag ab folgendem Zeitpunkt nicht mehr öffentlich anzeigen:', 'lbwp') . '</p>
      <p>
        <input type="text" value="' . $date . '" name="auto_unpublish_on_date" placeholder="dd.MM.yyyy" id="auto_unpublish_on_date" style="width:120px;" />
        <input type="text" value="' . $time . '" name="auto_unpublish_on_time" placeholder="hh:mm" style="width:80px;" />
      </p>
    ';

    // Make the date field jQuery-ready
    $html .= '
      <script type="text/javascript">
        jQuery(function($) {
          $("#auto_unpublish_on_date").datepicker(' . Date::getDatePickerJson() . ');
        });
      </script>
    ';

    echo $html;
  }

  /**
   * Saves the custom field and eventually creates a cron task
   * @param int $postId the saved post id
   */
  public function savePostUnpublishHook($postId)
  {
    // Check for autosave and don't do anything
    if (
      defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
      (isset($_REQUEST['action']) && $_REQUEST['action'] == 'inline-save') ||
      isset($_REQUEST['bulk_edit'])
    ) {
      return;
    }

    // Check if we have something to save
    if (!$this->hasValidSaveableDate()) {
      delete_post_meta($postId, 'auto_unpublish_on');
      return;
    }

    // It seems we have valid data, make a timestamp out of date/time
    $threshold = current_time('timestamp') + 86400;
    $timestamp = strtotime(trim($_POST['auto_unpublish_on_date'] . ' ' . $_POST['auto_unpublish_on_time']));
    $previousTimestamp = intval(get_post_meta($postId, 'auto_unpublish_on', true));
    if ($timestamp > 0 && $timestamp != $previousTimestamp) {
      // If that timestamp is within the next 24h, directly create cron task
      if ($timestamp < $threshold) {
        $this->createCronTask($timestamp);
      }
      // Also, make sure to save the meta information
      update_post_meta($postId, 'auto_unpublish_on', $timestamp);
    }
  }

  /**
   * Create a task for automatic unpublishing of posts
   * @param int $timestamp the actual timestamp when to run the cron
   */
  protected function createCronTask($timestamp)
  {
    Cronjob::register(array(
      ($timestamp + 90) => 'auto_unpublish_posts'
    ));
  }

  /**
   * @return bool true, if we have a valid saveable date
   */
  protected function hasValidSaveableDate()
  {
    return strtotime($_POST['auto_unpublish_on_date']) > 0;
  }

  /**
   * At daily cron, see if there are unpublishing posts in the last/next 24 hours
   * and if so, add a cron task when they need to be unpublished
   */
  public function checkForUnpublishingPosts()
  {
    $start = current_time('timestamp') - 86400;
    $end = ($start + (2*86400));
    $sql = 'SELECT meta_value FROM {sql:postMeta} WHERE meta_key = {metaKey} AND meta_value BETWEEN {start} AND {end}';
    $timestamps = $this->wpdb->get_col(Strings::prepareSql($sql,array(
      'postMeta' => $this->wpdb->postmeta,
      'metaKey' => 'auto_unpublish_on',
      'start' => $start,
      'end' => $end
    )));

    foreach ($timestamps as $timestamp) {
      $this->createCronTask($timestamp);
    }
  }

  /**
   * Check for unpublishing posts and actually unpublish them and remove their meta information.
   * This is made as efficient as possible as it can run multiple time troughout the day.
   */
  public function autoUnpublishPosts()
  {
    $sql = 'SELECT post_id FROM {sql:postMeta} WHERE meta_key = {metaKey} AND meta_value <= {now}';
    // Get all posts that must be unpublished by now
    $postIds = $this->wpdb->get_col(Strings::prepareSql($sql,array(
      'postMeta' => $this->wpdb->postmeta,
      'metaKey' => 'auto_unpublish_on',
      'now' => current_time('timestamp')
    )));

    // Go trough all posts and make them a unpublished
    foreach ($postIds as $postId) {
      // Unpublish the actual post, and remove unpublish meta data, this also removes the menu items
      wp_update_post(array(
        'ID' => $postId,
        'post_status' => 'draft'
      ));

      // Unpublish associated menu items as well (dont delete them!)
      if ($this->settings['unpublishAssociatedMenus']) {
        $items = wp_get_associated_nav_menu_items($postId);
        if (is_array($items) && count($items) > 0) {
          foreach ($items as $menuId) {
            wp_update_post(array(
              'ID' => $menuId,
              'post_status' => 'draft'
            ));
          }
        }
      }
    }

    // Flush the frontend cache to make sure the content disappears completely
    MemcachedAdmin::flushFrontendCacheHelper();
  }
}