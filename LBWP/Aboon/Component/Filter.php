<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\Base\Filter as Base;
use LBWP\Core;
use LBWP\Util\File;
use LBWP\Util\WordPress;

/**
 * Provide the vast filter logic
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Filter extends Base
{
  /**
   * @var int page id of the main filter application
   */
  public static $FILTER_PAGE_ID = 0;
  /**
   * @var string used for css and code prefixes
   */
  public static $THEME_PREFIX = 'aboon';
  /**
   * @var string the theme text domain
   */
  public static $TEXT_DOMAIN = 'aboon';
  /**
   * @var int
   */
  public static $VIRTUAL_PROPS = 3;

  public function assets()
  {
    parent::assets();

    $base = File::getResourceUri();
    wp_enqueue_script('aboon-filter', $base . '/js/aboon/filter.js', array('jquery'), Core::REVISION, true);

    // Make sure to hide products in autocomplete and in filter search context
    if (isset($_GET['f']) && strlen($_GET['f'] > 0)) {
      add_filter('lbwp_filter_product_ids_before', array($this, 'reduceWithProductBehaviourSettings'));
    }
    add_filter('lbwp_search_autocomp_product_ids_before', array($this, 'reduceWithProductBehaviourSettings'));
  }

  /**
   * @param $productIds
   * @return array
   */
  public function reduceWithProductBehaviourSettings($productIds)
  {
    $hiddenIds = wp_cache_get('product-visibility-hide-search-list', 'Filter');
    if (!is_array($hiddenIds)) {
      $db = WordPress::getDb();
      $hiddenIds = array_map('intval', $db->get_col('
        SELECT post_id FROM ' . $db->postmeta . ' WHERE meta_key = "product-visibility" AND meta_value LIKE "%hide-product%"
      '));
      wp_cache_set('product-visibility-hide-search-list', $hiddenIds, 'Filter', 3600);
    }

    // Remove $hiddenIds from $productIds
    $productIds = array_diff($productIds, $hiddenIds);
    return $productIds;
  }

  /**
   * @param string $name
   * @param string $classes
   * @param bool $echo
   */
  protected static function icon($name, $classes = '', $echo = true)
  {
    // Fallback for icon migration, if we miss any
    $icon = '<i class="' . $name . ' ' . $classes . '"></i>';
    // use fixed fa6 icons, as in s03 this function is overridden and this one is most likely never used
    switch ($name) {
      case 'icon-cart': $icon = '<i class="fa-light fa-cart-plus ' . $classes . '"></i>'; break;
      case 'icon-close': // compat
      case 'icon-times': $icon = '<i class="fa-light fa-times ' . $classes . '"></i>'; break;
      case 'icon-sort': $icon = '<i class="fa-light fa-arrow-down-wide-short icon-sort ' . $classes . '"></i>'; break;
      case 'icon-caret-down': $icon = '<i class="fa-light fa-caret-down ' . $classes . '"></i>'; break;
      case 'icon-chevron-left': $icon = '<i class="fa-light fa-chevron-left"></i>'; break;
      case 'icon-chevron-right': $icon = '<i class="fa-light fa-chevron-right"></i>'; break;
      case 'icon-filter': $icon = '<i class="fa-light fa-filter-list icon-filter"></i>'; break;
      case 'icon-filter-reset': $icon = '<i class="fa-light fa-rotate-left"></i>'; break;
    }
    if ($echo) {
      echo $icon;
    } else {
      return $icon;
    }
  }

  /**
   * @param string $title
   * @param int $productId
   * @return string
   */
  protected static function filterProductTitle($title, $productId)
  {
    return $title;
  }

  /**
   * @param int $productId
   * @return string
   */
  protected static function getProductSubtitle($productId)
  {
    return '';
  }

  protected static function getAvailabilityHtml($productId)
  {
    return '';
  }

  /**
   * @return array|mixed
   */
  public static function getProductIdWhitelist()
  {
    return array();
  }

  /**
   * Set everything to the blacklist that is not published
   * @return array|mixed
   */
  public static function getProductIdBlacklist()
  {
    $blacklist = wp_cache_get('product_blacklist', 'Filter');
    if ($blacklist === false) {
      // Get all products that are not published
      $db = WordPress::getDb();
      $blacklist = array_map('intval', $db->get_col('
        SELECT ID FROM ' . $db->posts . ' WHERE post_type = "product" and post_status != "publish"
      '));
      wp_cache_set('product_blacklist', $blacklist, 'Filter', 7200);
    }

    return $blacklist;
  }

  /**
   * @return string
   */
  public function getSearchTerm()
  {
    return '';
  }

  /**
   * @param string $term
   * @return int[]
   */
  protected function getSearchTermResults($term, $exact = true, $correction = false)
  {
    return array();
  }
}