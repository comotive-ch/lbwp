<?php

namespace LBWP\Helper\MetaItem;

use LBWP\Util\ArrayManipulation;

/**
 * Helper method to simplify usage of cross references in Metabox Helper
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class CrossReference
{
  /**
   * @var string prefix for the saving key
   */
  const PREFIX = 'cross-reference';

  /**
   * @param string $localType the cross reference requesting type
   * @param string $referencedType the type that needs to be referenced
   * @return string the key to use to save the data
   */
  public static function getKey($localType, $referencedType)
  {
    return self::PREFIX . '_' . $localType . '_' . $referencedType;
  }

  /**
   * @param string $localType the cross reference requesting type
   * @param string $referencedType the type that needs to be referenced
   * @return array dropdown configuration
   */
  public static function createDropdownArguments($localType, $referencedType)
  {
    // Basic configuration to allow sorting and multiple items
    $dropdown = array(
      'sortable' => true,
      'multiple' => true,
      'saveCallback' => array('\LBWP\Helper\MetaItem\CrossReference', 'saveCallback'),
      'items' => array()
    );

    // Add all posts as items from the references type
    $posts = get_posts(array(
      'post_type' => $referencedType,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'post_status' => 'any'
    ));

    foreach ($posts as $item) {
      $dropdown['items'][$item->ID] = $item->post_title;
    }

    return $dropdown;
  }

  /**
   * Save the dropdown values, accepts arrays (if multiple was specified) and stores them in order with add_post_meta $unique=false.
   * @param int $postId the psot to save this info to
   * @param string $field the key to save to
   * @param string $boxId the metabox id
   * @return array|string saved value
   */
  public static function saveCallback($postId, $field, $boxId)
  {
    list($prefix, $localType, $referencedType) = explode('_', $field['key']);
    $referencedItems = ArrayManipulation::forceArray($_POST[$postId . '_' . $field['key']]);
    $currentItems = ArrayManipulation::forceArray(get_post_meta($postId, $field['key'], true));

    // If there are actual items in the array, make sure to convert to int
    if (count($referencedItems) > 0) {
      $referencedItems = array_map('intval', $referencedItems);
    }

    // Go trough all referenced items and decide what to add remotely
    foreach ($referencedItems as $referencedId) {
      $referencedId = intval($referencedId);
      // Is it a new item, if yes, add it remotely (else no change)
      if (!in_array($referencedId, $currentItems)) {
        self::addRemoteItem($referencedId, $postId, $localType, $referencedType);
      }
    }

    // Go trough all current items, and see what needs to be removed remotely
    foreach ($currentItems as $currentId) {
      $currentId = intval($currentId);
      // If in current items, but not in referenced, remove the item (else, no change)
      if (!in_array($currentId, $referencedItems)) {
        self::deleteRemoteItem($currentId, $postId, $localType, $referencedType);
      }
    }

    // Finally, save the new referenced items locally as well
    update_post_meta($postId, self::getKey($localType, $referencedType), $referencedItems);
    return $referencedItems;
  }

  /**
   * @param int $destinationId the post where the meta record list needs to be update (add new)
   * @param int $addedId the id that should be added to the meta array of $destiationId
   * @param string $sourceType source post type (type of added id)
   * @param string $destinationType destination post type (type of updated post)
   */
  public static function addRemoteItem($destinationId, $addedId, $sourceType, $destinationType)
  {
    // Get current meta value from remote
    $key = self::getKey($destinationType, $sourceType);
    $references = ArrayManipulation::forceArray(get_post_meta($destinationId, $key, true));

    // If the item is not yet in the array, remove it and save
    if (!in_array($addedId, $references)) {
      $references[] = intval($addedId);
      update_post_meta($destinationId, $key, $references);
    }
  }

  /**
   * @param int $destinationId the post where the meta record list needs to be update (remove)
   * @param int $removedId the id that should be removed to the meta array of $destiationId
   * @param string $sourceType source post type (type of added id)
   * @param string $destinationType destination post type (type of updated post)
   */
  public static function deleteRemoteItem($destinationId, $removedId, $sourceType, $destinationType)
  {
    // Get current meta value from remote
    $key = self::getKey($destinationType, $sourceType);
    $references = ArrayManipulation::forceArray(get_post_meta($destinationId, $key, true));

    // If the item is in the array, remove and save it
    if (in_array($removedId, $references)) {
      // Do this the ugly way, since by far the fastest
      $newArray = array();
      foreach ($references as $referenceId) {
        if ($referenceId !== $removedId) {
          $newArray[] = intval($referenceId);
        }
      }

      // Save the new array
      update_post_meta($destinationId, $key, $newArray);
    }
  }

  /**
   * Savely get list items with checks, if list is still complete and auto heal if not
   * @param int $listId the list you want
   * @param string $metaKey the meta key given from self::getKey
   * @return array post items that are valid
   */
  public static function getListItems($listId, $metaKey)
  {
    $postIds = ArrayManipulation::forceArray(get_post_meta($listId, $metaKey, true));
    list($prefix, $listType, $itemType) = explode('_', $metaKey);
    $removedItems = array();
    $postList = array();

    // Load all the posts and return
    foreach ($postIds as $postId) {
      $item = get_post($postId);
      if ($item->post_status == 'publish') {
        $postList[] = $item;
      } else if ($item->post_status == 'trash' || $item->post_status == NULL) {
        // Remove that item from the list of no status or trash
        $removedItems[] = $postId;
      }
    }

    // If there are items to be removed, remove them gracefully
    if (count($removedItems)) {
      foreach ($removedItems as $postId) {
        self::deleteRemoteItem($listId, $postId, $itemType, $listType);
      }
    }

    return $postList;
  }
} 