<?php

namespace LBWP\Theme\Component\Onepager\Item;

use LBWP\Theme\Component\Onepager\Core;

/**
 * Basic content class for one pager items
 * @package LBWP\Theme\Component\Onepager\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class SimpleContent extends Base
{
  /**
   * Upon adding metaboxes the the item
   */
  public function onMetaboxAdd()
  {
    // No metaboxes, but allow using the editor
    add_post_type_support(Core::TYPE_SLUG, 'editor');
    add_post_type_support(Core::TYPE_SLUG, 'thumbnail');
    // Still call parent to have a message, that there are no additional settings
    parent::onMetaboxAdd();
  }

  /**
   * the function that is executed to generate the items output
   * @param \WP_Post the post object of the item
   * @return string html code
   */
  public function getHtml()
  {
    return apply_filters('the_content', $this->post->post_content);
  }
}