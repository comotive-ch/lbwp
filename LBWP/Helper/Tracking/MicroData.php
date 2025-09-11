<?php

namespace LBWP\Helper\Tracking;

use LBWP\Core;
use LBWP\Helper\Converter;
use LBWP\Util\Date;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Helper to print google defined JSON-LD microdata to the page
 * @package LBWP\Helper\Tracking
 * @author Michael Sebel <michael@comotive.ch>
 */
class MicroData
{
  /**
   * @param \WP_Post $post the current post
   */
  public static function printArticleData($post)
  {
    // Create basic data array
    $data = array(
      '@context' => 'http://schema.org',
      '@type' => 'BlogPosting',
      'mainEntityOfPage' => array('@type' => 'WebPage'),
      'headline' => Strings::chopString($post->post_title, 107, true, '...'),
      'datePublished' => date('c', Date::getStamp(Date::SQL_DATETIME, $post->post_date_gmt)),
      'dateModified' => date('c', Date::getStamp(Date::SQL_DATETIME, $post->post_modified_gmt)),
      'author' => array(
        '@type' => 'Person',
        'name' => get_user_by('id', $post->post_author)->display_name
      ),
      'publisher' => array(
        '@type' => 'Organization',
        'name' => get_bloginfo('name')
      ),
      'description' => WordPress::getConfigurableExcerpt($post->ID, 300, '')
    );

    // Add image object, if given
    self::addImageObject($post, $data);
    self::addLogoUrl($data, 'publisher');

    // Filter, then print data
    $data = apply_filters('lbwp_helper_microdata_article', $data, $post);
    self::printJsonLdOject($data);
  }

  /**
   * @param $data
   */
  public static function addLogoUrl(&$data, $field)
  {
    // Add logo if given as a favicon or better, an actual logo
    $config = Core::getInstance()->getConfig();
    if (strlen($config['EmailTemplates:LogoImageUrl']) > 0) {
      $data[$field]['logo'] = Converter::forceNonWebpImageUrl($config['EmailTemplates:LogoImageUrl']);
    } else if (strlen($config['HeaderFooterFilter:FaviconPngUrl']) > 0) {
      $data[$field]['logo'] = Converter::forceNonWebpImageUrl($config['HeaderFooterFilter:FaviconPngUrl']);
    }
  }

  /**
   * @param \WP_Post $post the current post
   */
  public static function printPageData($post)
  {
    // Create basic data array
    $data = array(
      '@context' => 'http://schema.org',
      '@type' => 'WebPage',
      'mainEntityOfPage' => array('@type' => 'WebPage'),
      'name' => $post->post_title,
      'publisher' => array(
        '@type' => 'Organization',
        'name' => get_bloginfo('name')
      ),
      'datePublished' => date('c', Date::getStamp(Date::SQL_DATETIME, $post->post_date_gmt)),
      'dateModified' => date('c', Date::getStamp(Date::SQL_DATETIME, $post->post_modified_gmt))
    );

    // Add image object, if given
    self::addImageObject($post, $data);
    self::addLogoUrl($data, 'publisher');

    // Filter, then print data
    $data = apply_filters('lbwp_helper_microdata_page', $data, $post);
    self::printJsonLdOject($data);
  }

  /**
   * @param \WP_Post $post the post object
   * @param array &$data the data object
   */
  public static function addImageObject($post, &$data)
  {
    if (has_post_thumbnail($post->ID)) {
      $imageId = get_post_thumbnail_id($post->ID);
      $image = wp_get_attachment_image_src($imageId, 'large');
      // Add the image object
      $data['image'] = array(
        '@type' => 'ImageObject',
        'url' => $image[0],
        'height' => $image[2],
        'width' => $image[1]
      );
    }
  }

  /**
   * @param \WP_Post $event the lbwp event post
   */
  public static function printEventData($event)
  {
    // Create basic data array
    $data = array(
      '@context' => 'http://schema.org',
      '@type' => 'Event',
      'mainEntityOfPage' => array('@type' => 'WebPage'),
      'name' => $event->post_title,
      'publisher' => array(
        '@type' => 'Organization',
        'name' => get_bloginfo('name')
      ),
      'location' => array(
        '@type' => 'Place',
        'name' => get_post_meta($event->ID, 'event-location', true),
        'address' => get_post_meta($event->ID, 'event-location', true)
      )
    );

    $startTs = intval(get_post_meta($event->ID, 'event-start', true));
    if ($startTs > 0) {
      $data['startDate'] = date('c', $startTs);
    }

    // Add image object, if given
    self::addImageObject($event, $data);
    self::addLogoUrl($data, 'publisher');

    // Filter, then print data
    $data = apply_filters('lbwp_helper_microdata_event', $data, $event);
    self::printJsonLdOject($data);
  }

  /**
   * @param array $data the micro data object
   */
  public static function printJsonLdOject($data)
  {
    echo '<script type="application/ld+json">';
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT);
    echo '</script>';
  }
} 