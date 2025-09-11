<?php

namespace LBWP\Theme\Feature;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use LBWP\Util\WordPress;

/**
 * Tracks and displays the last author of each post type object
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class TrackLastAuthor
{
  /**
   * @var TrackLastAuthor the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'postTypes' => array()
  );

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return TrackLastAuthor the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new TrackLastAuthor($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    // In admin, load the specific scripts and filters
    if (is_admin()) {
      foreach ($this->config['postTypes'] as $type) {
        // Add the save filters for tracking
        add_action('save_post_' . $type, array($this, 'trackLastAuthorMeta'));
        // Add the custom column to display the last author info
        WordPress::addPostTableColumn(array(
          'post_type' => $type,
          'meta_key' => 'editingAuthorInfo',
          'column_key' => $type . '_editing_author_info',
          'single' => true,
          'heading' => 'Letzter Bearbeiter',
          'callback' => function($info, $postId) {
            if (is_array($info) && isset($info['authorName'])) {
              echo '<a href="/wp-admin/user-edit.php?user_id=' . $info['authorId'] . '">' . $info['authorName'] . '</a>';
            } else {
              echo '-';
            }
          }
        ));
      }
    }
  }

  /**
   * Actually tracks the last author data
   * @param $postId
   */
  public function trackLastAuthorMeta($postId)
  {
    $authorId = get_current_user_id();
    $author = get_user_by('id', $authorId);
    update_post_meta($postId, 'editingAuthorInfo', array(
      'authorId' => $authorId,
      'authorName' => $author->display_name
    ));
  }
}



