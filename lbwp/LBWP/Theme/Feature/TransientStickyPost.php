<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\Date;
use LBWP\Util\String;

/**
 * Provides settings to set sticky posts and remove the stickyness on a specific date
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class TransientStickyPost
{
  /**
   * Default Settings for the pagenavi
   * @var array
   */
  protected $settings = array(
    'postTypes' => array('post')
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
    self::$instance = new TransientStickyPost($settings);
    self::$instance->load();
  }

  /**
   * Loads/runs the needed actions and functions
   */
  protected function load()
  {
    add_action('admin_init', array($this, 'addMetabox'));
    add_action('save_post', array($this, 'savePost'));
    add_action('cron_daily', array($this, 'checkFlags'));
  }

  /**
   * Adding the metabox to the system
   */
  public function addMetabox()
  {
    foreach ($this->settings['postTypes'] as $postType) {
      add_meta_box(
        'transient_stickyflag',
        __('Beitrag oben halten', 'lbwp'),
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
    $html = '';
    // Add the checkbox sticky posts are not saved at post_meta, but in options -.-
    $stickyPosts = get_option('sticky_posts');
    if (!is_array($stickyPosts)) {
      $stickyPosts = array();
    }
    $checked = '';
    if (in_array($post->ID, $stickyPosts)) {
      $checked = ' checked="checked"';
    }

    $html .= '
      <label for="transient_sticky">
        <input type="checkbox" name="transient_sticky" id="transient_sticky" value="1"' . $checked . ' />
        <span>' . __('Diesen Beitrag oben halten', 'lbwp') . '</span>
      </label>
    ';

    // Date field, only shows the date, if it is selected
    $date = get_post_meta($post->ID, 'transient_sticky_until', true);
    if ($date == false) {
      $date =  __('Nie', 'lbwp');
    }
    $html .= '
      <p>
        ' . __('Automatisch Zurücksetzen:', 'lbwp') . '
        <input type="text" value="' . $date . '" name="transient_sticky_until" id="transient_sticky_until" style="width:100px;" />
      </p>
    ';

    // Make the date field jQuery-ready
    $html .= '
      <script type="text/javascript">
        jQuery(function($) {
          $("#transient_sticky_until").datepicker(' . Date::getDatePickerJson() . ');
        });
      </script>
    ';

    echo $html;
  }

  /**
   * Saves the custom field
   * @param int $postId the saved post id
   */
  public function savePost($postId)
  {
    // Check for autosave and don't do anything
    if (
      defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
      (isset($_REQUEST['action']) && $_REQUEST['action'] == 'inline-save') ||
      isset($_REQUEST['bulk_edit'])
    ) {
      return;
    }

    // Don't add autosave/revision or attachments
    $type = get_post_type($postId);
    if ($type == 'revision' || $type == 'attachment') {
      return;
    }

    if ($_POST['transient_sticky'] == 1) {
      // The flag is active, save stickyness
      $this->setStickyPost($postId);
      // Also save the transient date, if set by the user
      if (String::checkDate($_POST['transient_sticky_until'], Date::EU_FORMAT_DATE)) {
        update_post_meta($postId, 'transient_sticky_until', $_POST['transient_sticky_until']);
      }
      // For wordpress not to remove the sticky flag, we need to add these fields
      $_POST['hidden_post_sticky'] = 'sticky';
      $_POST['sticky'] = 'sticky';
    } else {
      // Be sure to remove the stickyness
      $this->removeStickyPost($postId);
      // Also, remove the post meta that contains the transient
      delete_post_meta($postId, 'transient_sticky_until');
      // For wordpress not add it again, remove some post fields
      unset($_POST['hidden_post_sticky']);
      unset($_POST['sticky']);
    }
  }

  /**
   * Called in daily cron. Checks all posts with a transient stickyflag and
   * removes the sticky flag, if the transient is older than todays date
   */
  public function checkFlags()
  {
    // Only check, if there are actually sticky posts
    $stickyPosts = get_option('sticky_posts');
    if (is_array($stickyPosts) && count($stickyPosts) > 0) {
      // Get all post meta records with a transient
      $sql = 'SELECT post_id, meta_value FROM {sql:postMeta} WHERE meta_key = {metaKey}';
      $metaRecords = $this->wpdb->get_results(String::prepareSql($sql,array(
        'postMeta' => $this->wpdb->postmeta,
        'metaKey' => 'transient_sticky_until'
      )));
      $nowTs = current_time('timestamp');
      foreach ($metaRecords as $record) {
        // make a timestamp from the date (plus 86400 seconds to cover the day)
        $postTs = Date::getStamp(Date::EU_DATE, $record->meta_value) + 86400;
        // make a date timestamp from "today"
        if ($postTs < $nowTs) {
          // Remove the posts from sticky posts array and remove the transient
          $this->removeStickyPost($record->post_id);
          delete_post_meta($record->post_id, 'transient_sticky_until');
        }
      }
    }
  }

  /**
   * Add a post to sticky post options, if it's not already in there
   * @param int $postId the post to check and add
   */
  protected function setStickyPost($postId)
  {
    $stickyPosts = get_option('sticky_posts');
    if (!in_array($postId, $stickyPosts)) {
      $stickyPosts[] = $postId;
      update_option('sticky_posts', $stickyPosts);
    }
  }

  /**
   * Removes a sticky post from the options, if it's in there
   * @param int $postId the post id to check and remove
   */
  protected function removeStickyPost($postId)
  {
    $stickyPosts = get_option('sticky_posts');
    if (in_array($postId, $stickyPosts)) {
      $newStickyPosts = array();
      foreach ($stickyPosts as $stickyId) {
        if ($stickyId != $postId) {
          $newStickyPosts[] = $stickyId;
        }
      }
      // Save the new array not containing the post id
      update_option('sticky_posts', $newStickyPosts);
    }
  }
}