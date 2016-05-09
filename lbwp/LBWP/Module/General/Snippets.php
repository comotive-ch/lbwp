<?php

namespace LBWP\Module\General;

use LBWP\Util\WordPress;
use LBWP\Util\File;

/**
 * This module provides a snippet posttype and the possibility to easly
 * copy those snippets to your content or adding a shortcode with a reference
 * @author Michael Sebel <michael@comotive.ch>
 */
class Snippets extends \LBWP\Module\Base
{
  /**
   * @var string the post type
   */
  const TYPE_SNIPPET = 'lbwp-snippet';
  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_action('init', array($this, 'addSnippetType'));
    add_action('media_buttons', array($this, 'addSnippetButton'), 30);
  }

  /**
   * Adding the post type
   */
  public function addSnippetType()
  {
    // Register a non queryable post type with icon
    WordPress::registerType(self::TYPE_SNIPPET, 'Snippet', 'Snippets', array(
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'menu_icon' => 'dashicons-image-rotate-left',
      'menu_position' => 45
    ));
  }

  /**
   * Adds the link to add a snippet
   */
  public function addSnippetButton()
  {
    global $current_screen;

    if (
      ($current_screen->base != 'post' && $current_screen->base != 'widgets') ||
      (wp_count_posts(self::TYPE_SNIPPET)->publish == 0)
    ) {
      return;
    }

    $link = File::getViewsUri() . '/module/snippets/add.php?body_id=media-upload&TB_iframe=1';

    echo '
      <a href="' . $link . '" id="add-snippet" title="Snippet einfügen"
        class="thickbox button dashicons-before dashicon-big dashicon-snippets dashicons-image-rotate-left"></a>
    ';
  }
}