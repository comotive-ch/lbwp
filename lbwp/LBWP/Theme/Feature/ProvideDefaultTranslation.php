<?php

namespace LBWP\Theme\Feature;

/**
 * Provide default title/content
 * @package LBWP\Theme\Feature
 */
class ProvideDefaultTranslation
{
  /**
   * @param array $config initialize the configuration
   */
  public static function init($config = array('title', 'content'))
  {
    if (in_array('title', $config)) {
      add_filter('default_title', array('\LBWP\Theme\Feature\ProvideDefaultTranslation', 'setDefaultTitle'));
    }

    if (in_array('content', $config)) {
      add_filter('default_content', array('\LBWP\Theme\Feature\ProvideDefaultTranslation', 'setDefaultContent'));
    }

    if (in_array('excerpt', $config)) {
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