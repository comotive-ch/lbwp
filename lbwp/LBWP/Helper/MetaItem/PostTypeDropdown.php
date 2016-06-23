<?php

namespace LBWP\Helper\MetaItem;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

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

    if (is_string($types)) {
      $types = array($types);
    }

    // Get all items of a posttype and order alphabetically
    $postItems = get_posts(array(
      'post_type' => $types,
      'post_status' => 'all',
      'posts_per_page' => -1,
      'orderby' => 'title',
	    'order' => 'ASC',
    ));

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

    foreach ($postItems as $postItem) {
      $args['items'][$postItem->ID] = array(
        'title' => self::getPostElementName($postItem, $postTypeMap),
        'data' => array(
          'url' => admin_url('post.php?post=' . $postItem->ID . '&action=edit&ui=show-as-modal'),
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
    $newPostId = intval(wp_insert_post(array(
      'post_title' => $_POST['title'],
      'post_type' => Strings::forceSlugString($_POST['postType']),
      'post_status' => 'draft'
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

    // Report back the new ID and the to be added option
    WordPress::sendJsonResponse(array(
      'newPostId' => $newPostId,
      'newOptionHtml' => $optionHtml
    ));
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

    if (count($itemInfos) > 0) {
      $itemTitle .= ' (' . implode(', ', $itemInfos) . ')';
    }

    return $itemTitle;
  }
} 