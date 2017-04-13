<?php

namespace LBWP\Helper;
use LBWP\Util\WordPress;

/**
 * Helper to extend comment use cases for the REST API
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class RestApi
{
  /**
   * Possible configurations as of yet
   * array(
   *   'comment_count' => true,
   *   'include_featured_image' => 'size' // large, medium etc. must be given
   * )
   * @param array $config the configuration / endpoints that need to be extended
   * @param callable $callback an additional callback to be called within rest_api_init
   */
  public static function configure($config, $callback = NULL)
  {
    add_action('rest_api_init', function() use ($config, $callback) {
      // Add reasonable amount of comment count infos
      if (isset($config['comment_count']) && $config['comment_count']) {
        RestApi::addCommentCountField();
      }

      // Add all data for a featured image
      if (isset($config['include_featured_image'])) {
        RestApi::addFeaturedImage($config['include_featured_image']);
      }

      // Execute callable, if available
      if (is_callable($callback)) {
        call_user_func($callback, $config);
      }
    });
  }

  /**
   * Adds the comment count to each post list element
   */
  protected static function addCommentCountField()
  {
    register_rest_field('post', 'comment_count', array(
      'get_callback' => function($post) {
        return wp_count_comments($post['id']);
      }
    ));
  }

  /**
   * Adds the image info of the featured image
   * @param string $size the image size
   */
  protected static function addFeaturedImage($size)
  {
    register_rest_field('post', 'featured_image', array(
      'get_callback' => function($post) use ($size) {
        $imageId = get_post_thumbnail_id();
        if ($imageId > 0) {
          return array(
            'imageId' => $imageId,
            'htmlTag' => get_the_post_thumbnail($post['id'], $size),
            'imageUrl' => WordPress::getImageUrl($imageId, $size)
          );
        }

        return false;
      }
    ));
  }
} 