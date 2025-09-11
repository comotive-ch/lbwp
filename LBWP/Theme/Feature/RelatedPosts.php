<?php

namespace LBWP\Theme\Feature;

/**
 * This serves related posts
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch
 */
class RelatedPosts
{
  /**
   * @var RelatedPosts the instance
   */
  protected static $instance = NULL;
  /**
   * @var bool show the settings?
   */
  protected static $showSettings = true;
  /**
   * @var int fallback if nothing is given and no configuration is set
   */
  const FALLBACK_COUNT = 3;

  /**
   * Register settings for the feature
   */
  protected function  __construct()
  {
    if (is_admin() && self::$showSettings) {
      add_action('admin_init', function() {
        // Section for the settings
        add_settings_section(
          'lbwpRelatedPosts',
          'Verwandte Artikel',
          array($this,'settingsCaption'),
          'reading'
        );

        // Setting to set the number of related posts
        add_settings_field(
          'lbwpRelatedPostsNumber',
          '<label for="lbwpRelatedPostsNumber">Anzahl</label>',
          array($this,'settingsNumber'),
          'reading',
          'lbwpRelatedPosts',
          array()
        );
        register_setting('reading','lbwpRelatedPostsNumber');
      });
    }
  }

  /**
   * Initializes the at the init action
   * @param bool $showSettings show the settings?
   */
  public static function init($showSettings = true)
  {
    self::$showSettings = $showSettings;

    if (!did_action('init')) {
      add_action('init', array('\LBWP\Theme\Feature\RelatedPosts', 'createInstance'));
    } else {
      self::createInstance();
    }
  }

  /**
   * Actually creates an instance, instantly
   */
  public static function createInstance()
  {
    if (self::$instance == NULL) {
      self::$instance = new RelatedPosts();
    }
  }

  /**
   * The settings caption
   */
  public function settingsCaption()
  {
    echo 'Aktivieren Sie die Anzeige von verwandten Artikel im Feed und bestimmen wie viele Artikel maximal angezeigt werden.';
  }

  /**
   * Settings input for number of related posts
   */
  public function settingsNumber()
  {
    echo '<input type="number" name="lbwpRelatedPostsNumber" id="lbwpRelatedPostsNumber" value="' . get_option('lbwpRelatedPostsNumber') . '" class="small-text">';
  }

  /**
   * @param int $postId the post to analyze
   * @return bool true, if there are related posts
   */
  public static function hasPosts($postId = 0)
  {
    if ($postId == 0) {
      global $post;
      $postId = $post->ID;
    }

    return (count(self::getPosts($postId, 1)) > 0);
  }

  /**
   * @param int $postId the post to analyze
   * @param int $maxCount the maximum count to be returned
   * @return \stdClass[] list of posts
   */
  public static function getPosts($postId = 0, $maxCount = 0)
  {
    if ($postId == 0) {
      global $post;
      $postId = $post->ID;
    }

    $postCount = intval(get_option('lbwpRelatedPostsNumber'));
    if ($postCount == 0 && $maxCount > 0) {
      $postCount = $maxCount;
    } else {
      $postCount = self::FALLBACK_COUNT;
    }

    return self::$instance->getPostObjects($postId, $postCount, 'post_tag', $title);
  }

  /**
   * @param int $postId the post to analyze
   * @param int $postCount the maximum count to be returned
   * @param string $taxonomy to be queried
   * @param string $title the title that is set by the random terms name
   * @return \stdClass[] list of posts
   */
  public static function postQuery($postId, $postCount, $taxonomy = 'post_tag', &$title = '')
  {
    return self::$instance->getPostObjects($postId, $postCount, $taxonomy, $title);
  }

  /**
   * @param int $postId the post to analyze
   * @param int $postCount the maximum count to be returned
   * @param string $taxonomy to be queried
   * @param string $title the title that is set by the random terms name
   * @return \stdClass[] list of posts
   */
  protected function getPostObjects($postId, $postCount, $taxonomy, &$title)
  {
    if ($postCount == 0) {
      return array();
    }

    if ($taxonomy == 'category') {
      $terms = wp_get_post_categories($postId, array('fields' => 'all'));
    } else {
      $terms = wp_get_post_tags($postId);
    }

    // Get the related posts, if possible
    if (is_array($terms) && count($terms) > 0) {
      // Select a tag randomly
      $selectableTerms = count($terms);
      $randomIndex = mt_rand(0, $selectableTerms - 1);
      $relatedTerm = $terms[$randomIndex];
      // Set title if not given yet
      if (strlen($title) == 0) {
        $title = get_term_by('id', $relatedTerm->term_id, $taxonomy)->name;
      }

      return get_posts(array(
        'post__not_in' => array($postId),
        'posts_per_page' => $postCount,
        'tax_query' => array(
          array(
            'taxonomy' => $taxonomy,
            'field' => 'slug',
            'terms' => array($relatedTerm->slug)
          )
        )
      ));
    }

    return array();
  }
}
