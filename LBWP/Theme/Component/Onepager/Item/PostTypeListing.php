<?php

namespace LBWP\Theme\Component\Onepager\Item;

use LBWP\Helper\Metabox;
use LBWP\Theme\Component\Onepager\Core;
use LBWP\Util\Templating;

/**
 * Base class for post type listing one pager elements
 * @package LBWP\Theme\Component\Onepager\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class PostTypeListing extends Base
{
  /**
   * @var string the container html
   */
  protected $container = '<ul class="{containerClasses}">{content}</ul>';
  /**
   * @var string the container class
   */
  protected $containerClasses = array('post-type-list');
  /**
   * @var array the query that is executed to get listed items
   */
  protected $postQuery = array();
  /**
   * @var callable the item callback to create each elements html
   */
  protected $itemCallback = NULL;

  /**
   * the function that is executed to generate the items output
   * @param \WP_Post the post object of the item
   * @return string html code
   */
  public function getHtml()
  {
    // Set the callback to be used
    $this->itemCallback = array($this, 'getElementHtml');

    $html = '';
    $posts = get_posts($this->postQuery);
    // Go trough all posts and create html for them
    foreach ($posts as $key => $element) {
      $html .= call_user_func($this->itemCallback, $element, $key);
    }

    // Put it into the container and return
    $container = str_replace('{containerClasses}', implode(' ', $this->containerClasses), $this->container);
    $container = $this->filterContainerHtml($container);
    return Templating::getContainer($container, $html, '{content}');
  }

  /**
   * Can be overridden, if stuff needs to be done on the container
   * @param string $html the container html
   * @return string changes html, if needed
   */
  protected function filterContainerHtml($html)
  {
    $title = apply_filters('the_title', $this->post->post_title);
    return str_replace('{title}', $title, $html);
  }

  /**
   * @param \WP_Post $post a post object
   * @return string element html
   */
  abstract protected function getElementHtml($post, $key);
}