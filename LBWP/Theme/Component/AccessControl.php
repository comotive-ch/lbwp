<?php

namespace LBWP\Theme\Component;

use LBWP\Helper\Metabox;
use LBWP\Theme\Base\Component as BaseComponent;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Core as LbwpCore;

/**
 * Base class for user based frontend access control on types and menu items
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class AccessControl extends BaseComponent
{
  /**
   * The name of the type for user groups
   */
  const GROUP_TYPE = 'lbwp-user-group';
  /**
   * @var array tells on which post types access control is made
   */
  protected $controlledTypes = array('post', 'page');
  /**
   * @var bool use auto hiding css for menus
   */
  protected $useAutoHidingCss = true;
  /**
   * @var int the currently logged in user id
   */
  protected $userId = 0;
  /**
   * @var array cached user restriction arrays
   */
  protected $cachedUserRestrictions = array();

  /**
   * Register all needed types and filters to control access
   */
  public function init()
  {
    $this->userId = intval(get_current_user_id());
    $this->addGroupType();
    // Add metaboxes for the type to assign the users
    add_action('admin_init', array($this, 'addGroupMetaboxes'));
    add_action('admin_init', array($this, 'addAccessControlMetaboxes'));
    add_action('save_post', array($this, 'flushAccessCache'));

    // Check access to the content
    add_action('wp', array($this, 'runSingleAccessController'));
    add_action('pre_get_posts', array($this, 'runArchiveAccessController'));
    add_filter('nav_menu_css_class', array($this, 'runMenuAccessController'), 10, 2);

    // If configured auto hide (Without developer css) restricted menus
    if ($this->useAutoHidingCss) {
      add_action('wp_head', array($this, 'printMenuHideCss'));
    }
  }

  /**
   * Adds the type for user groups access management
   */
  public function addGroupType()
  {
    WordPress::registerType(self::GROUP_TYPE, 'Benutzergruppe', 'Benutzergruppen', array(
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'hierarchical' => false,
      'has_archive' => false,
      'rewrite' => false,
      'show_in_admin_bar' => false,
      'show_in_menu' => 'users.php',
      'supports' => array('title')
    ), '');
  }

  /**
   * Metabox settings for the user groups
   */
  public function addGroupMetaboxes()
  {
    $helper = Metabox::get(self::GROUP_TYPE);
    $helper->addMetabox('group-settings', 'Einstellungen');
    $helper->addUserDropdown('users', 'group-settings', 'Benutzer hinzufügen', array(
      'multiple' => true
    ));
    $helper->addTextarea('group-notes', 'group-settings', 'Interne Notiz', 80);
  }

  /**
   * The access setting metabox for the configured types
   */
  public function addAccessControlMetaboxes()
  {
    foreach ($this->controlledTypes as $type) {
      $helper = Metabox::get($type);
      $helper->addMetabox('access-control', 'Zugriffssteuerung', 'side');
      $helper->addCheckbox('restrict-access', 'access-control', '', array(
        'description' => 'Zugriff auf Gruppen einschränken'
      ));
      $helper->addPostTypeDropdown('access-groups', 'access-control', 'Berechtigte Gruppen', self::GROUP_TYPE, array(
        'multiple' => true,
        'hide_new_item_box' => true,
        'itemHtmlCallback' => array($this, 'getGroupItemHtml')
      ));
    }
  }

  /**
   * Checks on singular objects if there is restricted access and if yes, if the user has access
   */
  public function runSingleAccessController()
  {
    if (is_singular()) {
      $post = WordPress::getPost();
      if (get_post_meta($post->ID, 'restrict-access', true) == 'on') {
        // Check if the user is logged in, only then we can control access
        if (is_user_logged_in()) {
          $accessMap = $this->getAccessMap();
          if (!isset($accessMap[$this->userId]) || !in_array($post->ID, $accessMap[$this->userId])) {
            $this->onAccessRestriction();
          }
        } else {
          // Not logged in, can't access
          $this->onAccessRestriction();
        }
      }
    }
  }

  /**
   * Removes posts from archives, if the user is not allowed to see them
   * @param \WP_Query $query the wordpress query
   */
  public function runArchiveAccessController($query)
  {
    if (!is_admin() && $query->is_main_query() && ($query->is_archive() || $query->is_home())) {
      // Get a map of posts that are restricted and their accessible users
      $restrictedPosts = $this->getRestrictedPosts($this->userId);
      $postsNotIn = ArrayManipulation::forceArray($query->get('post__not_in'));
      // Set posts not in to not show restricted posts to the user
      $postsNotIn = array_merge($postsNotIn, $restrictedPosts);
      $query->set('post__not_in', $postsNotIn);
    }
  }

  /**
   * @param $classes
   * @param $item
   * @return array
   */
  public function runMenuAccessController($classes, $item)
  {
    $restrictedPosts = $this->getRestrictedPosts($this->userId);
    // If the object id of the menu item is redirected, hide the menu
    if (in_array($item->object_id, $restrictedPosts)) {
      $classes[] = 'lbwp-restrict-menu-access';
    }

    return $classes;
  }

  /**
   * @param int $userId the checked user id
   * @return array list of post ids where the user has no access
   */
  protected function getRestrictedPosts($userId)
  {
    // If already set, return the posts array
    if (isset($this->cachedUserRestrictions[$userId])) {
      return $this->cachedUserRestrictions[$userId];
    }

    $map = $this->getPostMap();
    $restrictedPosts = array();
    // Loop trough the post map, add all posts, where the user has no access
    foreach ($map as $postId => $users) {
      if (!in_array($userId, $users)) {
        $restrictedPosts[] = $postId;
      }
    }

    $this->cachedUserRestrictions[$userId] = $restrictedPosts;
    return $restrictedPosts;
  }

  /**
   * @return array user (key) to post (values) array
   */
  protected function getAccessMap()
  {
    $accessMap = wp_cache_get('accessMap', 'lbwpAccessControl');
    if (is_array($accessMap)) {
      return $accessMap;
    }

    // If not cached, generate the access map from all restrictions
    $accessMap = array();
    $db = WordPress::getDb();

    // Get all groups we have
    $groups = get_posts(array(
      'post_type' => self::GROUP_TYPE,
      'posts_per_page' => -1
    ));

    // Add als posts the group as access to
    foreach ($groups as $group) {
      // Get all access for that group
      $sql = 'SELECT post_id FROM {sql:postMeta} WHERE meta_key = "access-groups" AND meta_value = {groupId}';
      $postAccess = $db->get_col(Strings::prepareSql($sql, array(
        'postMeta' => $db->postmeta,
        'groupId' => $group->ID
      )));

      // Also, get the users of this group which will get access to those posts
      $users = get_post_meta($group->ID, 'users');
      foreach ($users as $userId) {
        if (!is_array($accessMap[$userId])) {
          $accessMap[$userId] = $postAccess;
        } else {
          $accessMap[$userId] = array_merge($accessMap[$userId], $postAccess);
        }
      }
    }

    // Save to cache and return
    wp_cache_set('accessMap', $accessMap, 'lbwpAccessControl', 86400);

    return $accessMap;
  }

  /**
   * @return array of posts that are restricted and the users that have access to it
   */
  public function getPostMap()
  {
    $postMap = wp_cache_get('postMap', 'lbwpAccessControl');
    if (is_array($postMap)) {
      return $postMap;
    }

    // If not cached, generate the post map
    $accessMap = $this->getAccessMap();
    $postMap = array();
    $db = WordPress::getDb();

    // Get all posts that are restricted
    $sql = 'SELECT post_id FROM {sql:postMeta} WHERE meta_key = "restrict-access" and meta_value = "on"';
    $restrictedPosts = $db->get_col(Strings::prepareSql($sql, array(
      'postMeta' => $db->postmeta
    )));

    // Create and array of users that have access to the post ids
    foreach ($restrictedPosts as $postId) {
      $postMap[$postId] = array();
      foreach ($accessMap as $userId => $posts) {
        if (in_array($postId, $posts)) {
          $postMap[$postId][] = $userId;
        }
      }
    }

    // Save to cache and return
    wp_cache_set('postMap', $postMap, 'lbwpAccessControl', 86400);
    return $postMap;
  }

  /**
   * Flush all needed caches on post or menu saving
   */
  public function flushAccessCache()
  {
    wp_cache_delete('accessMap', 'lbwpAccessControl');
    wp_cache_delete('postMap', 'lbwpAccessControl');
  }

  /**
   * Simplest way of access restricton, go to home page
   */
  protected function onAccessRestriction()
  {
    header('Location: ' . get_bloginfo('url'), null, 307);
    exit;
  }

  /**
   * @param $item
   * @return string
   */
  public function getGroupItemHtml($item)
  {
    return '
      <div class="mbh-chosen-inline-element">
        <h2>' . $item->post_title . '</a></h2>
      </div>
    ';
  }

  /**
   * Print simple css to hide restricted menus
   */
  public function printMenuHideCss()
  {
    echo '
      <style type="text/css">
        body .lbwp-restrict-menu-access { display:none !important; }
      </style>
    ';
  }
} 