<?php

namespace LBWP\Module\Backend;

use LBWP\Helper\PostCopy;
use WP_Post;

/**
 * Adds the link to duplicate posts
 * @package LBWP\Module\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class PostDuplicate extends \LBWP\Module\Base
{
  /**
   * @var array Array with the duplicateable post types
   */
  protected $duplicateableTypes = array('post', 'page', 'lbwp-form', 'lbwp-event', 'lbwp-table', 'crm-custom-field', 'lbwp-selection-ad', 'lbwp-mailing-list');
  /**
   * @var array Types where the post_date is duplicated as well (and not reset to current date)
   */
  protected $duplicateDateTypes = array('lbwp-selection-ad');

  /**
   * Initialize the module
   */
  public function initialize()
  {
    // Setup links on events listing to duplicate
    add_filter('post_row_actions', array($this, 'addDuplicateLink'), 10, 2);
    add_filter('page_row_actions', array($this, 'addDuplicateLink'), 10, 2);
    add_action('admin_init', array($this, 'checkForDuplicationRequest'));
    add_action('admin_menu', array($this, 'allowUserPostTypes'));
  }

  /**
   * Let themes or plugins add their own post types for duplication support
   */
  public function allowUserPostTypes()
  {
    $this->duplicateableTypes = apply_filters('duplicateable_post_types', $this->duplicateableTypes);
  }

  /**
   * Checks, if the parameter to duplicate is set, and runs the duplication
   */
  public function checkForDuplicationRequest()
  {
    // Duplicate the post
    if (isset($_GET['action']) && $_GET['action'] == 'duplicate') {
      $this->duplicatePost();
    }
  }

  /**
   * Adds a link to duplicate a post type object
   * @param array $actions current links array
   * @param \WP_Post $post the post
   * @return array the altered $actions array
   */
  function addDuplicateLink($actions, $post)
  {
    // Before altering the available actions, ensure we are on a allowed post type page
    if (!in_array($post->post_type, $this->duplicateableTypes) || !current_user_can('edit_posts')) {
      return $actions;
    }

    $nonce = wp_create_nonce('PostDuplicateNonce');
    $actions['duplicate_post'] = '
      <a href="' . admin_url('?post_type=' . $post->post_type . '&action=duplicate&post=' . $post->ID) . '&_nonce=' . $nonce . '">
        Duplizieren
      </a>
    ';

    return $actions;
  }

  /**
   * Duplicates a post
   */
  protected function duplicatePost()
  {
    if (!isset($_GET['_nonce']) || !wp_verify_nonce($_GET['_nonce'], 'PostDuplicateNonce')) {
      return false;
    }

    $postId = intval($_GET['post']);
    $post = get_post($postId);

    // Only users that are allowed to edit posts and read the source post may duplicate it
    if (!$post instanceof WP_Post || !current_user_can('edit_posts') || !current_user_can('read_post', $postId)) {
      return false;
    }

    $overrides = [
      'post_status' => 'draft',
      'post_title' => 'Kopie von ' . $post->post_title
    ];
    // Set current date for most types
    if (!in_array($post->post_type, $this->duplicateDateTypes)) {
      $overrides['post_date'] = current_time('mysql');
      $overrides['post_date_gmt'] = get_gmt_from_date($overrides['post_date']);
    }

    $newPostId = PostCopy::createCopy($postId, $overrides);
    if ($newPostId === 0) {
      return false;
    }

    // Let developers and other modules do their stuff
    do_action('after_post_duplicate', $postId, $newPostId, $post->post_type);
    do_action('after_duplicate_' . $post->post_type, $postId, $newPostId);

    // Send back to the original page
    wp_redirect(admin_url('post.php?action=edit&post=' . $newPostId));
    exit;
  }
}
