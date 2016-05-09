<?php

namespace LBWP\Theme\Feature\ArticleSeries;

/**
 * Simple taxonomy based article series functions
 * @package LBWP\Theme\Feature\ArticleSeries
 * @author Michael Sebel <michael@comotive.ch>
 */
class Simple
{
  /**
   * @var Simple the instance
   */
  protected static $instance = NULL;

  /**
   * Prevent instantiation of class
   */
  protected function  __construct() {  }

  /**
   * Initializes the at the init action
   */
  public static function init()
  {
    add_action('init', array(
      '\LBWP\Theme\Feature\ArticleSeries\Simple',
      'createInstance'
    ));
  }

  /**
   * Actually creates an instance, instantly
   */
  public function createInstance()
  {
    if (self::$instance == NULL) {
      self::$instance = new Simple();
      self::$instance->registerTaxonomy();
    }
  }

  /**
   * Registers the article series taxonomy
   */
  protected function registerTaxonomy()
  {
    register_taxonomy('articleseries', array('post'), array(
      'hierarchical' => true,
      'labels' => array(
        'name' => 'Artikelserien',
        'singular_name' => 'Artikelserie',
        'search_items' => 'Artikelserien suchen',
        'all_items' => 'Alle Artikelserien',
        'parent_item' => 'Elternserie',
        'parent_item_colon' => 'Elternserie: ',
        'edit_item' => 'Artikelserie bearbeiten',
        'update_item' => 'Artikelserie bearbeiten',
        'add_new_item' => 'Artikelserie hinzufügen',
        'new_item_name' => 'Neue Artikelserie',
        'menu_name' => 'Artikelserien',
      ),
      'show_ui' => true,
      'query_var' => true,
      'rewrite' => array('slug' => 'artikelserie'),
    ));
  }

  /**
   * Returns true if the submitted post is member of a series with more than
   * @param int $postId the post id to check
   * @return bool true/false if is series member or not
   */
  public static function isMember($postId = 0)
  {
    $postId = self::getPostId($postId);
    $terms = wp_get_object_terms($postId, 'articleseries');

    if (count($terms) == 0) {
      return false;
    }

    $slug = $terms[0]->slug;
    $posts = get_posts(array(
      'articleseries' => $slug,
      'numberposts' => 2,
      'exclude' => $postId
    ));

    return (count($posts) > 0);
  }

  /**
   * Returns an array with maximal the submitted number of posts of the series of articles in
   * which the submitted articles is part of. The current article is excluded of that list.
   * @param int $number number of posts to show
   * @param int $postId the post id to get the series of
   * @return array list of posts from the same series
   */
  public static function getSeries($number = 3, $postId = 0)
  {
    $postId = self::getPostId($postId);

    if (self::isMember($postId)) {
      $terms = wp_get_object_terms($postId, 'articleseries');
      $slug = $terms[0]->slug;

      return get_posts(array(
        'articleseries' => $slug,
        'numberposts' => $number,
        'exclude' => $postId
      ));
    }

    return array();
  }

  /**
   * Returns the title of the series of articles of which the current
   * @param $postId
   * @return string
   */
  public static function getTitle($postId = 0)
  {
    $postId = self::getPostId($postId);

    if (self::isMember($postId)) {
      $terms = wp_get_object_terms($postId, 'articleseries');
      return $terms[0]->name;
    }

    return '';
  }

  /**
   * @param int $postId post id, overridden by current if not given
   * @return int for sure, a post id.
   */
  public static function getPostId($postId)
  {
    if ($postId == 0) {
      global $post;
      $postId = $post->ID;
    }

    return $postId;
  }
}