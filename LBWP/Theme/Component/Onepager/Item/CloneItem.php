<?php

namespace LBWP\Theme\Component\Onepager\Item;

use LBWP\Theme\Component\Onepager\Core;
use LBWP\Theme\Component\Onepager\Item\Base as BaseItem;

/**
 * Class to clone content of a specific item
 * @package LBWP\Theme\Component\Onepager\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class CloneItem extends Base
{
  /**
   * Let the user select the clone (which is a little primitive now
   */
  public function onMetaboxAdd()
  {
    parent::onMetaboxAdd();
    // Let the user select from all the items that are currently given
    $this->helper->addPostTypeDropdown('clone-id', self::MAIN_BOX_ID, 'Quelle auswählen', Core::TYPE_SLUG, array(
      'itemOverallCallback' => array($this, 'extendItemListData')
    ));
    // Remove the basic fields, as they will be filled with data from the clone
    $this->helper->removeField('core-classes', self::MAIN_BOX_ID);
  }

  /**
   * @param array $items
   * @param array $posts
   * @return mixed
   */
  public function extendItemListData($items, $posts)
  {
    foreach ($items as $postId => $item) {
      $post = $this->getPostById($postId, $posts);
      if ($post instanceof \WP_Post) {
        $parentTitle = get_post($post->post_parent)->post_title;
        $items[$postId]['title'] = $parentTitle . ' - ' . $items[$postId]['title'];
      }
    }

    // Now sort by title again, as they changed
    uasort($items, function($a, $b) {
      return strcmp($a['title'], $b['title']);
    });

    return $items;
  }

  /**
   * @param $postId
   * @param $posts
   * @return bool
   */
  protected function getPostById($postId, $posts)
  {
    foreach ($posts as $post) {
      if ($post->ID == $postId) {
        return $post;
      }
    }

    return false;
  }

  /**
   * @return string
   * @throws \Exception
   */
  public function getHtml()
  {
    // Get the original item
    $cloneId = intval(get_post_meta($this->post->ID, 'clone-id', true));
    /** @var Core $onepager */
    /** @var BaseItem $item */
    $onepager = $this->getHandler()->getTheme()->searchUniqueComponent('OnepagerComponent');
    $item = array_shift($onepager->getItemsByIdList(array($cloneId)));
    $post = $item->getPost();
    // Override the post status and maybe return no content if not published
    $this->post->post_status = $post->post_status;
    if ($this->post->post_status != 'publish' && !is_user_logged_in()) {
      return '';
    }
    // Set core classes and eventual slider id into the item
    $this->additionalClasses = array_merge($this->additionalClasses, get_post_meta($post->ID, 'core-classes'));
    // Set the item type class from the source item
    $this->additionalClasses[] = $item->getKey();

    // Now just return the html of the source item
    return $item->getHtml();
  }
}