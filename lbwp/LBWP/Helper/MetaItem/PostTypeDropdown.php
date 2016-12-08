<?php

namespace LBWP\Helper\MetaItem;

use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Util\Date;

/**
 * Helper method to simplify ppst type dropdowns
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class PostTypeDropdown
{
  /**
   * @param array $types string or array of types
   * @param array $args the basic arguments
   * @return array the extended arguments
   */
  public static function createDropdownArguments($types, $args)
  {
    // Make sure to have a fillable array but don't override
    if (!is_array($args['items'])) {
      $args['items'] = array();
    }
    if (!isset($args['containerClasses'])) {
      $args['containerClasses'] = 'chosen-dropdown-item post-type-dropdown';
    }

    if (is_string($types)) {
      $types = array($types);
    }

    // Set the basic query
    $query = array(
      'post_type' => $types,
      'post_status' => 'all',
      'posts_per_page' => -1,
      'orderby' => 'title',
	    'order' => 'ASC'
    );

    if (isset($args['parent']) && intval($args['parent']) > 0) {
      $query['post_parent'] = $args['parent'];
    }

    // Set a specific or all languages, if needed
    if (isset($args['language'])) {
      $query['lang'] = $args['language'];
    }

    // Get all items of a posttype and order alphabetically
    $postItems = get_posts($query);

    // Make a mapping of the actual post type names, if more than one
    $postTypeMap = array();
    if (count($types) > 1) {
      foreach ($types as $type) {
        $typeObject = get_post_type_object($type);
        $postTypeMap[$type] = $typeObject->labels->singular_name;
      }
    } else {
      $typeObject = get_post_type_object($types[0]);
    }

    // Set a callback for html generation (default none, simple title for each element)
    $callback = array('\LBWP\Helper\MetaItem\PostTypeDropdown', 'defaultItemHtml');
    if (isset($args['itemHtmlCallback']) && is_callable($args['itemHtmlCallback'])) {
      $callback = $args['itemHtmlCallback'];
    }

    // Generate the item arguments for choosen
    foreach ($postItems as $postItem) {
      $args['items'][$postItem->ID] = array(
        'title' => self::getPostElementName($postItem, $postTypeMap),
        'data' => array(
          'url' => admin_url('post.php?post=' . $postItem->ID . '&action=edit&ui=show-as-modal'),
          'html' => esc_attr(call_user_func($callback, $postItem, $postTypeMap)),
          'is-modal' => 1
        )
      );
    }

    // Allow new item link and UI if only one post type
    if (count($types) == 1) {
      $args['newItem'] = array(
        'title' => $typeObject->labels->add_new_item,
        'containerClass' => 'new-post-type-element',
        'ajaxAction' => 'newPostTypeItem',
        'postType' => $typeObject->name
      );
    }

    return $args;
  }

  /**
   * The basic new post type item call
   */
  public static function addNewPostTypeItem()
  {
    // Create a new empty post with the title
    $assignedPostId = intval($_POST['postId']);
    $postType = Strings::forceSlugString($_POST['postType']);
    $newPostId = intval(wp_insert_post(array(
      'post_title' => $_POST['title'],
      'post_type' => $postType,
      'post_status' => 'draft',
      'post_parent' => ($postType == 'onepager-item') ? $assignedPostId : 0,
    )));

    // Add the new ID to the option, in case the user doesn't save afterwards
    $assignedPosts = get_post_meta($assignedPostId, $_POST['optionKey']);
    if (is_array($assignedPosts)) {
      $assignedPosts[] = $newPostId;
      ChosenDropdown::saveToMeta($assignedPostId, $_POST['optionKey'], $assignedPosts);
    }

    // Create the option HTML to be added to chosen
    $optionHtml = '
      <option value="' . $newPostId . '" selected="selected"
        data-url="' . admin_url('post.php?post=' . $newPostId . '&action=edit&ui=show-as-modal') . '"
        data-is-modal="1">' . $_POST['title'] . ' (Entwurf)
      </option>
    ';

    // Let developers add meta data to the element
    do_action('mbh_addNewPostTypeItem', $newPostId, $postType);

    // Report back the new ID and the to be added option
    WordPress::sendJsonResponse(array(
      'newPostId' => $newPostId,
      'newOptionHtml' => $optionHtml
    ));
  }

  /**
   * @param \WP_Post $item the post item
   * @param array $typeMap a post type mapping
   * @return string html code to represent the item
   */
  public static function defaultItemHtml($item, $typeMap)
  {
    $image = '';
    if (has_post_thumbnail($item->ID)) {
      $image = '<img src="' . WordPress::getImageUrl(get_post_thumbnail_id($item->ID), 'thumbnail') . '">';
    }

    return '
      <div class="mbh-chosen-inline-element">
        ' . $image . '
        <h2>' . self::getPostElementName($item, $typeMap) . '</h2>
        <p class="mbh-post-info">Autor: ' . get_the_author_meta('display_name', $item->post_author) . '</p>
        <p class="mbh-post-info">Letzte Änderung: ' . Date::convertDate(Date::SQL_DATETIME, Date::EU_DATE, $item->post_modified) . '</p>
      </div>
    ';
  }

  /**
   * @param $post
   * @param array $map
   * @param array $stati
   * @return string
   */
  public static function getPostElementName($post, $map = array(), $stati = array('draft' => 'Entwurf', 'pending' => 'Review', 'future' => 'Geplant'))
  {
    $itemInfos = array();
    $itemTitle = $post->post_title;
    if (isset($map[$post->post_type])) {
      $itemInfos[] = $map[$post->post_type];
    }

    // If it has a specified mentionable post status
    foreach ($stati as $postStatus => $statusName) {
      if ($postStatus == $post->post_status) {
        $itemInfos[] = $statusName;
      }
    }

    // Let developers add their own shit
    $itemInfos = apply_filters('mbh_filter_item_infos_' . $post->post_type, $itemInfos, $post);

    if (count($itemInfos) > 0) {
      $itemTitle .= ' (' . implode(', ', $itemInfos) . ')';
    }

    return $itemTitle;
  }
} 