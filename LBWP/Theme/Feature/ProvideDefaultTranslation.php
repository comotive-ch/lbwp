<?php

namespace LBWP\Theme\Feature;

/**
 * Provide default title/content
 * @package LBWP\Theme\Feature
 */
class ProvideDefaultTranslation
{
  protected static $config = array();
  protected static $registered = false;

  /**
   * @param array $config initialize the configuration
   */
  public static function init($config = array('title', 'content'))
  {
    self::$config = $config;
    if (!self::$registered) {
      add_action('init', array('\LBWP\Theme\Feature\ProvideDefaultTranslation', 'registerFilters'));
      self::$registered = true;
    }
  }

  /**
   * @return void
   */
  public static function registerFilters()
  {
    if (in_array('title', self::$config)) {
      add_filter('default_title', array('\LBWP\Theme\Feature\ProvideDefaultTranslation', 'setDefaultTitle'));
    }

    if (in_array('content', self::$config)) {
      add_filter('default_content', array('\LBWP\Theme\Feature\ProvideDefaultTranslation', 'setDefaultContent'));
    }

    if (in_array('excerpt', self::$config)) {
      add_filter('default_excerpt', array('\LBWP\Theme\Feature\ProvideDefaultTranslation', 'setDefaultExcerpt'));
    }
  }

  /**
   * @param string $title the current default (mostly empty)
   * @return string the overridden default from original post
   */
  public static function setDefaultTitle($title)
  {
    if (isset($_GET['from_post']) && intval($_GET['from_post']) > 0) {
      $translatedPost = get_post($_GET['from_post']);
      if ($translatedPost instanceof \WP_Post) {
        return $translatedPost->post_title;
      }
    }

    return $title;
  }

  /**
   * @param string $content the current default (mostly empty)
   * @return string the overridden default from original post
   */
  public static function setDefaultContent($content)
  {
    if (isset($_GET['from_post']) && intval($_GET['from_post']) > 0) {
      $translatedPost = get_post($_GET['from_post']);
      if ($translatedPost instanceof \WP_Post) {
        return $translatedPost->post_content;
      }
    }

    return $content;
  }

  /**
   * @param string $excerpt the current default (mostly empty)
   * @return string the overridden default from original post
   */
  public static function setDefaultExcerpt($excerpt)
  {
    if (isset($_GET['from_post']) && intval($_GET['from_post']) > 0) {
      $translatedPost = get_post($_GET['from_post']);
      if ($translatedPost instanceof \WP_Post) {
        return $translatedPost->post_excerpt;
      }
    }

    return $excerpt;
  }
} 