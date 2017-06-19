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

      // Add basic author info, if needed
      if (isset($config['author_info']) && $config['author_info']) {
        RestApi::addAuthorInfoField();
      }

      // Add all data for a featured image
      if (isset($config['include_featured_image'])) {
        RestApi::addFeaturedImage($config['include_featured_image']);
      }

      // Add an unrendered excerpt
      if (isset($config['excerpt_unrendered'])) {
        RestApi::addUnrenderedExcerpt($config['excerpt_unrendered']);
      }

      // Handle all sorts of specific http headers
      RestApi::handleHttpHeaders($config);

      // Execute callable, if available
      if (is_callable($callback)) {
        call_user_func($callback, $config);
      }
    });
  }

  /**
   * @param array $config the config
   */
  protected function handleHttpHeaders($config)
  {
    // Add specific or wildcard cors header
    if (isset($config['cors_header'])) {
      if (is_array($config['cors_header'])) {
        header('Access-Control-Allow-Origin: ' . implode(', ', $config['cors_header']));
      } else {
        header('Access-Control-Allow-Origin: *');
      }
    }
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
   * Adds the comment count to each post list element
   */
  protected static function addAuthorInfoField()
  {
    register_rest_field('post', 'author_info', array(
      'get_callback' => function($post) {
        $userId = intval($post['author']);
        return array(
          'last_name' => get_the_author_meta('last_name', $userId),
          'first_name' => get_the_author_meta('first_name', $userId),
          'display_name' => get_the_author_meta('display_name', $userId),
          'archive_url' => get_author_posts_url($userId)
        );
      }
    ));
  }

  /**
   * Adds various types of unrendered excerpts without html
   */
  protected static function addUnrenderedExcerpt($type)
  {
    register_rest_field('post', 'excerpt', array(
      'get_callback' => function($post) use ($type) {
        $unrendered = '';
        $postObject = get_post($post['id']);
        // By type, decide what to actually render
        switch ($type) {
          case 'field':
          default:
            $unrendered = strip_tags($postObject->post_excerpt);
            break;
        }

        return array_merge($post['excerpt'], array(
          'unrendered' => $unrendered
        ));
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