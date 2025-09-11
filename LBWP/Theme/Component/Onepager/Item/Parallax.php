<?php

namespace LBWP\Theme\Component\Onepager\Item;

use LBWP\Theme\Component\Onepager\Core;

/**
 * Basic parallax image class
 * @package LBWP\Theme\Component\Onepager\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Parallax extends Base
{
  /**
   * Upon adding metaboxes the the item
   */
  public function onMetaboxAdd()
  {
    // Allow using the editor and the featured image
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
    $url = get_the_post_thumbnail_url($this->post->ID, 'original');
    $thumbId = intval(get_post_thumbnail_id($this->post->ID));
    $caption = '';
    // Set a caption if given
    if ($thumbId > 0) {
      $description = get_post($thumbId)->post_excerpt;
      if (strlen($description) > 0) {
        $caption = '<figcaption>' . $description . '</figcaption>';
      }
    }

    // Append editor content
    return '<div class="parallax-image" style="background-image:url(' . $url . ');">' . $caption . '</div>';
  }
}