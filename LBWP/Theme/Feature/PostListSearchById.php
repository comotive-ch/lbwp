<?php

namespace LBWP\Theme\Feature;

/**
 * Allow to search backend post list by ID
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class PostListSearchById
{
  /**
   * @var Breadcrumb the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct()
  {
    if (is_admin()) {
      add_action('parse_request', array($this, 'searchById'));
    }
  }

  /**
   * Loads the feature
   */
  public static function init()
  {
    self::$instance = new PostListSearchById();
  }

  /**
   * Parse the query for an integer that will search by id
   * @param \WP_Query $wp the query
   */
  public function searchById($wp)
  {
    global $pagenow;

    // Do some checks to skip eventually
    if ('edit.php' != $pagenow || !isset($wp->query_vars['s'])) {
      return;
    }

    // Only continue, if valid integer
    if (intval($wp->query_vars['s']) <= 0 || !is_numeric($wp->query_vars['s'])) {
      return;
    }

    // If we reach here, all criteria is fulfilled, unset search and select by ID instead
    $wp->query_vars['p'] = intval($wp->query_vars['s']);
    unset($wp->query_vars['s'], $_REQUEST['s']);
  }
} 