<?php

namespace LBWP\Module\Backend;

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
  protected $duplicateableTypes = array('post', 'page', 'lbwp-form', 'lbwp-event', 'lbwp-table', 'crm-custom-field');
  /**
   * @var array global meta data duplication blacklist
   */
  protected $globalMetaBlacklist = array(
    '_edit_lock',
    '_edit_last',
    'snap_isAutoPosted',
    'subscribeInfo'
  );

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
    if (!in_array($post->post_type, $this->duplicateableTypes) || !current_user_can('edit_post')) {
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
    $post = get_post($postId, ARRAY_A);

    // Remove the id to create a new post
    unset($post['ID']);
    $post['post_status'] = 'draft';
    $post['post_title'] = 'Kopie von ' . $post['post_title'];
    $post['post_date'] = current_time('mysql');
    $post['post_date_gmt'] = get_gmt_from_date($post['post_date']);

    // Set a surely unique post name, which is changed to a normal one after insert
    $postNameOriginal = $post['post_name'];
    $post['post_name'] = $post['post_name'].'-'.md5(time());

    $meta = get_post_custom($postId);
    $fmeta = array();
    $blacklist = apply_filters('duplicate_post_meta_key_blacklist', $this->globalMetaBlacklist);
    foreach($meta as $key => $value) {
      if (!in_array($key, $blacklist)) {
        $fmeta[$key] = count($value) == 1 ? $value[0] : $value;
      }
    }

    // Duplicate primitively or with logic
    if (apply_filters('duplicate_post_' . $post['post_type'] . '_has_callback', false)) {
      $newPostId = apply_filters('duplicate_post_' . $post['post_type'], $post, $fmeta);
    } else {
      $newPostId = wp_insert_post($post);
    }

    // Now generate a new slug and update the record
    $postName = wp_unique_post_slug(
      $postNameOriginal,
      $newPostId,
      'publish',
      $post['post_type'],
      $post['post_parent']
    );

    // Update the record
    $this->wpdb->update(
      $this->wpdb->posts,
      array('post_name' => $postName),
      array('ID' => $newPostId)
    );

    // Copy the taxonomies
    $taxonomies = get_object_taxonomies($post['post_type']);

    // Make sure to not copy post translations from multilang plugin
    if (($key = array_search('post_translations', $taxonomies)) !== false) {
      unset($taxonomies[$key]);
    }

    foreach ($taxonomies as $tax) {
      $terms = wp_get_object_terms($postId, $tax);
      $term = array();

      foreach ($terms as $t) {
        $term[] = $t->slug;
      }

      wp_set_object_terms($newPostId, $term, $tax);
    }

    // Save the post meta data
    foreach ($fmeta as $k => $v) {
      if (is_array($v)) {
        foreach ($v as $iv) {
          add_post_meta($newPostId, $k, $iv);
        }
      } else {
        update_post_meta($newPostId, $k, $v);
      }
    }

    // Clean the cache for this post (Since we changed the db directly)
    clean_post_cache($newPostId);

    // Let developers and other modules do their stuff
    do_action('after_post_duplicate', $postId, $newPostId, $post['post_type']);
    do_action('after_duplicate_' . $post['post_type'], $postId, $newPostId);

    // Send back to the original page
    wp_redirect(admin_url('post.php?action=edit&post=' . $newPostId));
    exit;
  }
}
