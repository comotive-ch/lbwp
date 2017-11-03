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
    // Allow using the editor and the featured image
    add_post_type_support(Core::TYPE_SLUG, 'editor');
    add_post_type_support(Core::TYPE_SLUG, 'thumbnail');

    // Add an option to hide the post title
    $this->helper->addCheckbox('hide-post-title', self::MAIN_BOX_ID, 'Titel-Anzeige', array(
      'description' => 'Den Haupt-Titel von diesem Inhaltselement ausblenden.'
    ));

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
    $html = '';

    // Show the post title if the option allows to
    $showTitle = (get_post_meta($this->post->ID, 'hide-post-title', true) == 'on') ? false : true;
    if ($showTitle) {
      $html = '
        <header>
          <h2>' . $this->post->post_title . '</h2>
        </header>
      ';
    }

    // Append editor content
    $html .= '
      <div class="lbwp-editor-content">'
        . apply_filters('the_content', $this->post->post_content) .
      '</div>';

    return $html;
  }
}