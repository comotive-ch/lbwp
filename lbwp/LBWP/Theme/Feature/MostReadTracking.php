<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\Date;
use LBWP\Util\String;
use LBWP\Util\WordPress;

/**
 * This theme support will track the hits on every post (all post types)
 * in post_meta data "most_read_tracker_hits" and will reset them every consecutive
 * configured period (like every x-Days) if needed. It doesn't yet provide any
 * standardized widgets, since we made this feature for a customer with very
 * specific requests and therefore his widget's can't be used in standard.
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 */
class MostReadTracking
{
  /**
   * @var MostReadTracking the sticky post config object
   */
  protected static $instance = NULL;
  /**
   * @var \wpdb wordpress db object
   */
  protected $wpdb = NULL;

  /**
   * Can only be instantiated by calling init method
   */
  protected function __construct()
  {
    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /**
   * Initialise while overriding settings defaults
   */
  public static function init()
  {
    self::$instance = new MostReadTracking();
    self::$instance->load();
  }

  /**
   * Register the post type and shortcode for displaying
   */
  protected function load()
  {
    // Only track if the user is not logged in (so we don't log admins etc.)
    if (!is_user_logged_in()) {
      // Check if we can use the direct method, if cache is not activated
      add_action('wp_footer', array($this, 'addTrackingJs'));
      add_action('wp_ajax_nopriv_most_read_track_hit', array($this, 'trackHitAjax'));
    }

    // Reset settings and cron
    add_action('admin_init', array($this, 'registerOptions'));
    add_action('cron_daily', array($this, 'resetCounters'));
  }

  /**
   * Answers the ajax request given by the frontend to track a post. This will
   * be used if the fullsite cache is active
   */
  public function trackHitAjax()
  {
    $postId = intval($_POST['postId']);
    $this->trackHit($postId);
    WordPress::sendJsonResponse(array('track' => true));
  }

  /**
   * @param int $postId the post on which we track a hit
   */
  protected function trackHit($postId)
  {
    if ($postId > 0 && stristr(strtolower($_SERVER['HTTP_USER_AGENT']), 'bot') === false) {
      $count = intval(get_post_meta($postId, 'most_read_tracker_hits', true));
      // increment and save
      update_post_meta($postId, 'most_read_tracker_hits', ++$count);
    }
  }

  /**
   * Adds tracking javascript, if the current page is a single site
   * that can be tracked (js is directly printed in footer)
   */
  public function addTrackingJs()
  {
    global $post;
    if (is_single() && intval($post->ID) > 0) {
      echo '
        <script type="text/javascript">
          jQuery(function() {
            var data = {
              action : "most_read_track_hit",
              postId : '.$post->ID.'
            };
            jQuery.post("/wp-admin/admin-ajax.php", data);
          })
        </script>
      ';
    }
  }

  /**
   * Registers the settings to configure the amount of days until reset
   */
  public function registerOptions()
  {
    add_settings_field(
      'most_read_reset_days',
      'Meistgelesen-Statistik',
      array($this, 'displayOptionResetDays'),
      'reading'
    );
    register_setting('reading','most_read_reset_days');
    register_setting('reading','most_read_switch_from_random');
  }

  /**
   * Displays the reset days setting
   */
  public function displayOptionResetDays()
  {
    $field = 'most_read_reset_days';
    $value = get_option($field);
    echo '<p>
      Alle <input name="'.$field.'" id="'.$field.'" type="number" min="5" value="'.$value.'" class="small-text">
      Tage zurücksetzen.
    </p>';

    $field = 'most_read_switch_from_random';
    $value = get_option($field);
    echo '<p>
      Nach <input name="'.$field.'" id="'.$field.'" type="number" min="200" value="'.$value.'" class="small-text">
      Hits von "Zufall" auf "Meistgelesen" wechseln.
    </p>';
  }

  /**
   * This is called daily in the cron and will reset all counters, if the
   * configured time limit is reached (and of course only if configured)
   */
  public function resetCounters()
  {
    // Get the configuration in days and the last reset date
    $days = intval(get_option('most_read_reset_days'));
    $lastReset = get_option('most_read_last_reset');
    // If there is no last reset, set it to the past, so it will excute immediately
    if ($lastReset == false) {
      $lastReset = Date::getTime(Date::SQL_DATETIME, 1);
    }

    // Get a timestamp from the last reset date and compare if reset needs to be done
    $lastResetTs = Date::getStamp(Date::SQL_DATETIME, $lastReset);
    $nextResetTs = $lastResetTs + ($days * 86400);
    if ($days > 0 && time() > $nextResetTs) {
      // Get all meta fields directly from db (get_posts would be too slow in time)
      $sql = 'SELECT post_id FROM {sql:postMeta} WHERE meta_key = {metaKey}';
      $metas = $this->wpdb->get_results(String::prepareSql($sql, array(
        'postMeta' => $this->wpdb->postmeta,
        'metaKey' => 'most_read_tracker_hits'
      )));

      // Loop through, resetting the option, which is slower, but also cleans the cache
      foreach ($metas as $meta) {
        update_post_meta($meta->post_id, 'most_read_tracker_hits', 0);
      }

      // Set last reset time to only execute it after the next period of time
      update_option('most_read_last_reset', Date::getTime(Date::SQL_DATETIME));
    }
  }

  /**
   * Get tracker hit total, to switch from "random" to actual most read
   */
  public static function getTrackerHitTotal()
  {
    $total = wp_cache_get('tracker_hit_total', 'MostReadTracking');
    if ($total === false) {
      $db = WordPress::getDb();
      $sql = 'SELECT SUM(meta_value) FROM {sql:metaTable} WHERE meta_key = "most_read_tracker_hits"';
      $total = $db->get_var(String::prepareSql($sql, array(
        'metaTable' => $db->postmeta
      )));
      wp_cache_set('tracker_hit_total', $total, 'MostReadTracking', 3600);
    }

    return $total;
  }

  /**
   * Get a number of most read posts
   * @param $number
   * @param string $type
   * @param bool $fillRandom
   * @return array
   */
  public static function getPosts($number, $type = 'post', $fillRandom = false)
  {
    $args = self::getQueryParams();
    $args['post_type'] = $type;
    $args['posts_per_page'] = $number;

    $posts = get_posts($args);

    // Fill with randoms if needed
    if ($fillRandom && $number < count($posts)) {
      // not enough posts? get random posts
      $postIds = array();
      foreach ($posts as $mostReadPost) {
        $postIds[] = $mostReadPost->ID;
      }
      $randomPosts = get_posts(array(
        'post_type' => array('post'),
        'posts_per_page' => $number - count($posts),
        'orderby' => 'rand',
        'post__not_in' => $postIds
      ));
      $posts = array_merge($posts, $randomPosts);
    }

    return $posts;
  }

  /**
   * Basic query parameters to track most read posts
   * @return array configuration for meta_key, orderby and order
   */
  public static function getQueryParams()
  {
    // If we exceed the hit total, switch to most read, else random
    if (self::getTrackerHitTotal() > intval(get_option('most_read_switch_from_random'))) {
      // Most read order
      return array(
        'meta_key' => 'most_read_tracker_hits',
        'orderby' => 'meta_value_num',
        'order' => 'DESC'
      );
    } else {
      // Random order
      return array(
        'orderby' => 'rand'
      );
    }
  }
}