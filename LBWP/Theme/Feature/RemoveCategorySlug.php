<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\Multilang;

/**
 * Remove /category/ from permalink structure even for multilang installs
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class RemoveCategorySlug
{
  /**
   * @var RemoveCategorySlug single instance of the module
   */
  protected static $instance = NULL;

  /**
   * Helper method to easily initialize the module
   */
  public static function init()
  {
    self::$instance = new RemoveCategorySlug();
  }

  /**
   * Initiate the module and register the needed actions and filters
   */
  public function __construct()
  {
    add_action('created_category', array($this, 'flushRewriteRules'));
    add_action('delete_category', array($this, 'flushRewriteRules'));
    add_action('edited_category', array($this, 'flushRewriteRules'));
    add_action('init', array($this, 'removeCategoryStructure'));

    add_filter('category_rewrite_rules', array($this, 'addNewRewriteRules'));
    add_filter('query_vars', array($this, 'addRedirectQueryVar'));
    add_filter('request', array($this, 'redirectLegacyCategoryRequest'));
  }

  /**
   * Flush rewrite rules
   */
  public function flushRewriteRules()
  {
    /** @var \WP_Rewrite $wp_rewrite */
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
  }

  /**
   * Removes category base
   */
  public function removeCategoryStructure()
  {
    /** @var \WP_Rewrite $wp_rewrite */
    global $wp_rewrite;
    $wp_rewrite->extra_permastructs['category']['struct'] = '%category%';
  }

  /**
   * Adds custom category rewrite rules
   * @param array $rules Category rewrite rules.
   * @return array the new rules
   */
  public function addNewRewriteRules($rules)
  {
    // As a first, reset the current rules
    $rules = array();

    // Get the categories (This retrieves all categories, if polylang)
    $categories = array();
    if (Multilang::isActive()) {
      foreach (Multilang::getAllLanguages() as $slug) {
        $categories = array_merge($categories, get_categories(array('hide_empty' => false, 'lang' => $slug)));
      }
    } else {
      $categories = get_categories(array('hide_empty' => false));
    }

    // Go trough each category adding specific rules
    foreach ($categories as $category) {
      $category_nicename = $category->slug;
      if ($category->parent == $category->cat_ID) {
        $category->parent = 0;
      } elseif ($category->parent != 0) {
        $category_nicename = get_category_parents($category->parent, false, '/', true) . $category_nicename;
      }
      $rules['(' . $category_nicename . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?category_name=$matches[1]&feed=$matches[2]';
      $rules['(' . $category_nicename . ')/page/?([0-9]{1,})/?$'] = 'index.php?category_name=$matches[1]&paged=$matches[2]';
      $rules['(' . $category_nicename . ')/?$'] = 'index.php?category_name=$matches[1]';
    }

    // Redirect support from old (and multilang) category base
    $oldBase = trim(get_option('category_base') ? get_option('category_base') : 'category', '/');
    $rules[$oldBase . '/(.*)$'] = 'index.php?category_redirect=$matches[1]';
    $rules['(.*)/' . $oldBase . '/(.*)$'] = 'index.php?category_redirect=$matches[2]';
    return $rules;
  }

  /**
   * @param $queryVars
   * @return array
   */
  public function addRedirectQueryVar($queryVars)
  {
    $queryVars[] = 'category_redirect';
    return $queryVars;
  }

  /**
   * Handles category redirects.
   * @param array $queryVars Current query vars.
   * @return array $queryVars, or void if category_redirect is present.
   */
  public function redirectLegacyCategoryRequest($queryVars)
  {
    if (isset($queryVars['category_redirect'])) {
      $categoryUrl = get_bloginfo('wpurl');
      if (Multilang::isActive()) {
        $categoryUrl .=  '/' . Multilang::getCurrentLang() . '/';
      }

      $categoryUrl .= $queryVars['category_redirect'] . '/';
      header('Location: ' . $categoryUrl, NULL, 301);
      exit;
    }

    return $queryVars;
  }
}
