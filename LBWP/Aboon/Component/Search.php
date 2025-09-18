<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\Component\Filter as BaseFilter;
use LBWP\Aboon\Base\Shop;
use LBWP\Helper\WooCommerce\Util;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Component\ACFBase;
use LBWP\Theme\Feature\FocusPoint;
use LBWP\Theme\Feature\Search as SiteSearch;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Core as LbwpCore;

/**
 * Provides search results and auto complete logic
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Search extends ACFBase
{
  /**
   * @var int id of the search result page
   */
  public static $SEARCH_RESULT_ID = 0;
  /**
   * @var int id of the search result page
   */
  public static $SITE_SEARCH_RESULT_ID = 0;
  /**
   * @var int id of the filter only result page
   */
  public static $FILTER_RESULT_ID = 0;
  /**
   * @var int min number of chars for a word be get on the index
   */
  public static $MIN_WORD_CHARS = 4;
  /**
   * @var int the number of autocomp products
   */
  public static $AUTOCOMP_PRODUCT_RESULTS = 4;
  /**
   * @var int if we need more autocomp results, sort them and after that slice, only used if overriden an > 0
   */
  public static $AUTOCOMP_PRODUCT_RESULTS_SLICE = 0;
  /**
   * @var int the number of autocomp products
   */
  public static $AUTOCOMP_SUGGEST_RESULTS = 7;
  /**
   * @var int tells the minimum of products to be found, else secondary search for autocomp is used (needs more perf)
   */
  public static $AUTOCOMP_SEC_SEARCH_THRESHOLD = 0;
  /**
   * @var bool try searching with a like statement instead of match against, only if that has no results, continue with match
   */
  public static $SEARCH_PRIMARY_WITH_LIKE = false;
  /**
   * @var int max number of split up words for a search in inexact search
   */
  public static $MAX_SPLIT_TERMS = 7;
  /**
   * @var bool
   */
  public static $TRACK_USER_SEARCHES = false;
  /**
   * @var string
   */
  public static $TRACK_USER_SEARCHES_OPTION = 'aboon_user_searches_tracker_v1';
  /**
   * @var array
   */
  public static $META_KEYWORDS = array();
  /**
   * NOT USED ANYMORE
   * @var array
   */
  public static $AUTOCOMP_META = array();
  /**
   * @var bool
   */
  public static $AUTOCOMP_CALLBACK = false;
  /**
   * @var bool
   */
  public static $SUMUP_AUTOCOMP_TERMS = true;
  /**
   * @var bool
   */
  public static $APPLY_FILTER_LIMITATION_LISTS = true;
  /**
   * @var bool
   */
  public static $BUILD_SEARCH_WORD_INDEX = true;
  /**
   * @var bool
   */
  public static $BUILD_META_PROP_INDEX = false;
  /**
   * @var bool
   */
  public static $HAS_SITESEARCH = false;
  /**
   * @var bool
   */
  public static $OVERRIDE_WORDPRESS_SEARCH = false;
  /**
   * @var string[]
   */
  public static $CONJUNCTIONS = array('und', 'mit', 'zu', 'bis', 'kann', 'wo', 'wie');
  /**
   * @var string
   */
  public static $TEXT_DOMAIN = 'lbwp';
  /**
   * @var string
   */
  public static $POST_TYPE = 'product';
  /**
   * @var string[]
   */
  public static $TEXT_INDEX_TYPES = array('post', 'page');
  /**
   * @var string[]
   */
  public static $PROD_MAP_TAXONOMIES = array('product_cat', 'product_prop');
  /**
   * Set lower when using AI, as it may take a lot of time
   * @var int
   */
  public static $TEXT_INDEX_MAX_EMPTY_DATASETS = 500;
  /**
   * @var int
   */
  public static $TEXT_INDEX_MAX_RENEWED_DATASETS = 100;
  /**
   * @var bool
   */
  public static $TEXT_INDEX_USE_AI = true;
  /**
   * @var bool
   */
  public static $USE_SEARCH_INDEX = true;
  /**
   * @var bool
   */
  public static $USE_FALLBACK_NO_RESULTS = true;
  /**
   * @var bool can give less results but only accurate ones
   */
  public static $USE_INDEX_BOOLEAN_MODE = false;
  /**
   * @var bool can give way more results but maybe inaccurate ones in boolean mode
   */
  public static $USE_INDEX_BOOLEAN_MODE_WILDCARDS = false;
  /**
   * @var bool
   */
  public static $TEXT_INDEX_FORCE_SWISSGERMAN = true;
  /**
   * @var string
   */
  public static $TEXT_INDEX_TITLE_PREFIX_META = '';
  /**
   * @var string
   */
  const CHATGPT_SECRET = LBWP_AI_SEARCH_TEXT_INDEX_CHATGPT_SECRET;

  /**
   * Registers endpoints and filters
   */
  public function init()
  {
    if (is_string(static::$POST_TYPE)) {
      static::$POST_TYPE = array(static::$POST_TYPE);
    }

    parent::init();
    add_action('rest_api_init', array($this, 'registerApiEndpoint'));
    add_action('cron_daily_1', array($this, 'updateIndexTables'));
    add_action('cron_daily_7', array($this, 'buildTextIndex'));

    $productive = defined('LBWP_ABOON_ERP_PRODUCTIVE') && LBWP_ABOON_ERP_PRODUCTIVE || defined('LOCAL_DEVELOPMENT');
    if (static::$BUILD_SEARCH_WORD_INDEX) {
      add_action('cron_daily_8', array($this, 'buildSearchWordIndex'));
      add_action('cron_job_manual_update_search_index_meta', array($this, 'rebuildSearchWordIndexMeta'));
    }
    if ($productive && static::$BUILD_META_PROP_INDEX) {
      add_action('cron_daily_9', array($this, 'buildMetaPropIndex'));
    }
    if (static::$USE_SEARCH_INDEX && static::$OVERRIDE_WORDPRESS_SEARCH) {
      add_action('pre_get_posts', array($this, 'overrideSearchQuery'));
    }

    // Update indizes on save of post (or product)
    if ($productive) {
      $types = static::$POST_TYPE;
      // Add post and page if not given yet
      if (!in_array('post', $types)) {
        $types[] = 'post';
      }
      if (!in_array('page', $types)) {
        $types[] = 'page';
      }
      foreach ($types as $type) {
        add_action('save_post_' . $type, array($this, 'buildIndizesOnSave'), 10, 2);
      }
    }
  }

  /**
   * @param $query
   * @return void
   */
  public function overrideSearchQuery($query)
  {
    if ($query->is_search() && !is_admin() && $query->is_main_query()) {
      // Get the search term
      $backup = static::$POST_TYPE;
      $isBoolMode = static::$USE_INDEX_BOOLEAN_MODE;
      // Add post and page if not given yet
      if (!in_array('post', static::$POST_TYPE)) {
        static::$POST_TYPE[] = 'post';
      }
      if (!in_array('page', static::$POST_TYPE)) {
        static::$POST_TYPE[] = 'page';
      }

      $term = $query->get('s');
      // Force a more open search, independent of otherwise settings
      static::$USE_INDEX_BOOLEAN_MODE = false;
      // Do our own query for post ids
      $postIds = static::getProductIdsByTerm($term, true);
      $results = count($postIds);
      // When no results, try to correct eventual errors
      if ($results == 0) {
        $searchWordIndex = static::getSearchWordIndex();
        if (count($searchWordIndex) > 0) {
          $alternate = Strings::getMostSimilarString($term, $searchWordIndex, true);
          $similiarity = 0;
          similar_text($term, $alternate, $similiarity);

          if ($alternate != $term && $similiarity >= 75) {
            $postIds = static::getProductIdsByTerm($alternate, true);
            $results = count($postIds);
            $term = $alternate;
          }
        }
      }

      // Ensure WordPress only returns these posts
      if ($results > 0) {
        // Order posts by id, descending so the yare most likely sorted by date
        sort($postIds, SORT_NUMERIC);
        $postIds = array_reverse($postIds);

        $query->set('s', '');
        $query->set('s_fallback', $term);
        $query->set('found_posts_primary', $results);
        $query->set('post__in', $postIds);
        $query->set('orderby', 'post__in');
      }

      static::$POST_TYPE = $backup;
      static::$USE_INDEX_BOOLEAN_MODE = $isBoolMode;
    }
  }

  /**
   * @param $postId
   * @param $post
   * @return void
   */
  public function buildIndizesOnSave($postId, $post)
  {
    // Skip if not already published
    if ($post->post_status != 'publish') {
      return;
    }

    $db = WordPress::getDb();

    // Get tids of the product already in index
    $tids = array_map('intval', $db->get_col('
      SELECT tid FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE pid = ' . $postId
    ));
    // Get term current relationships from db
    $current = array_map('intval', $db->get_col('
      SELECT term_id FROM ' . $db->term_relationships . '
      INNER JOIN ' . $db->term_taxonomy . ' 
      ON ' . $db->term_taxonomy . '.term_taxonomy_id = ' . $db->term_relationships . '.term_taxonomy_id
      WHERE taxonomy IN("' . implode('","', static::$PROD_MAP_TAXONOMIES) . '") AND object_id = ' . $postId . '
    '));
    // Only add new ones, deleting will be done at daily cron
    $new = array_diff($current, $tids);
    // Insert this into the prod map
    foreach ($new as $tid) {
      $db->insert($db->prefix . 'lbwp_prod_map', array(
        'pid' => $postId,
        'tid' => $tid
      ));
    }

    // Add prefix data to title if needed
    if (strlen(static::$TEXT_INDEX_TITLE_PREFIX_META) > 0) {
      $prefix = get_post_meta($post->ID, static::$TEXT_INDEX_TITLE_PREFIX_META, true);
      if (strlen($prefix) > 0) {
        $post->post_title = trim($prefix . ' ' . $post->post_title);
      }
    }

    // Very first simple variant only updating the title/excerpt/content if given
    if (static::$USE_SEARCH_INDEX && in_array($post->post_type, static::$TEXT_INDEX_TYPES)) {
      $index = array(
        'post_type' => $post->post_type,
        'title' => html_entity_decode(substr($post->post_title, 0, 500)),
        'excerpt' => html_entity_decode(trim(substr($post->post_excerpt, 0, 1000))),
        'content' => html_entity_decode(trim($post->post_content))
      );

      // Make sure none of the fields has html in it
      foreach ($index as $key => $value) {
        $index[$key] = strip_tags($value);
        // Also remove all line breaks
        $index[$key] = str_replace(array("\r", "\n"), ' ', $index[$key]);
        // Maybe force swissgerman characters
        if (static::$TEXT_INDEX_FORCE_SWISSGERMAN) {
          $index[$key] = str_replace('ß', 'ss', $index[$key]);
        }
      }

      $exists = $db->get_var('SELECT COUNT(id) FROM ' . $db->prefix . 'lbwp_text_index WHERE id = ' . $postId) == 1;
      // Save to database
      if ($exists) {
        $db->update(
          $db->prefix . 'lbwp_text_index',
          $index,
          array('id' => $post->ID)
        );
      } else {
        $index['id'] = $post->ID;
        $db->insert(
          $db->prefix . 'lbwp_text_index',
          $index
        );
      }

    }
  }

  /**
   * @return void
   */
  public function assets()
  {
    parent::assets();
    $url = File::getResourceUri();
    wp_enqueue_script('aboon-search', $url . '/js/aboon/search.js', array('jquery'), LbwpCore::REVISION);
  }

  /**
   * Register the aboon REST to get all the sales
   */
  public function registerApiEndpoint()
  {
    register_rest_route('custom/search', 'autocomplete', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getAutocompletion')
    ));
    register_rest_route('custom/search', 'categories', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getCategories')
    ));
    register_rest_route('custom/search', 'website', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getWebsiteResults')
    ));
    register_rest_route('custom/search', 'tracktermclick', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'trackSearchTermClick')
    ));
  }

  /**
   * Uses site search API to determine how many results there MAY be (0, 0-10 or more than 10)
   */
  public function getWebsiteResults()
  {
    // Faciliate a request that the sitesearch needs
    $_POST['search'] = $_GET['term'];
    $results = SiteSearch::getApiSearchResults();
    $html = $results['resultCount'];
    if ($results['resultCount'] < $results['nativeCount']) {
      $html .= '+';
    }

    return array(
      'result' => $html,
      'display' => $results['resultCount'] > 0
    );
  }

  /**
   * Build a meta field _prop-index with all property names of a product
   */
  public function buildMetaPropIndex()
  {
    $db = WordPress::getDb();
    $index = array();

    // Query for all products with their respective tids
    $products = $db->get_col('
      SELECT DISTINCT pid FROM ' . $db->prefix . 'lbwp_prod_map
    ');

    foreach ($products as $productId) {
      $terms = $db->get_col('
        SELECT LCASE(' . $db->terms . '.name) FROM ' . $db->terms . '
        INNER JOIN ' . $db->prefix . 'lbwp_prod_map ON term_id = tid
        WHERE pid = ' . $productId . '
      ');
      // Add the terms to the index if not already in index
      $index[$productId] = '';
      foreach ($terms as $term) {
        if (stristr($term, '.') !== false) {
          $term = Strings::removeUntil($term, '.');
        }
        if (stristr($index[$productId], $term) === false) {
          $index[$productId] .= $term . ' ';
        }
      }
    }

    // Trim off loose ends/spaces
    $index = array_map('trim', $index);

    // Get the current index and replace only what changed or isn't there already
    $current = array();
    $raw = $db->get_results('
      SELECT post_id, meta_value FROM ' . $db->postmeta . '
      WHRE meta_key = "_prop-index"
    ');
    foreach ($raw as $row) {
      $current[$row->post_id] = $row->meta_value;
    }

    foreach ($index as $id => $meta) {
      if (!isset($current[$id]) || $current[$id] != $index[$id]) {
        update_post_meta($id, '_prop-index', $index[$id]);
      }
    }
  }

  /**
   * Warning, only for products at the moment
   * @return void
   */
  public function rebuildSearchWordIndexMeta()
  {
    if (!current_user_can('administrator')) {
      return;
    }

    // Get all IDs to be updated
    $db = WordPress::getDb();
    $postIds = $db->get_col('SELECT id FROM '. $db->prefix . 'lbwp_text_index WHERE post_type = "post"');
    $fullMapBogus = array();
    // Just update the meta of all posts
    foreach ($postIds as $postId) {
      $post = array(
        'ID' => $postId,
        'post_type' => 'post',
        'meta' => WordPress::getAccessiblePostMeta($postId)
      );
      $meta = $this->getIndexableMetaContent($post, array(), $fullMapBogus);
      // Save to database
      $db->update(
        $db->prefix . 'lbwp_text_index',
        array('meta' => $meta),
        array('id' => $postId)
      );
    }
  }

  /**
   * (Re)build the search word index
   */
  public function buildSearchWordIndex()
  {
    // Make sure to create or update our index table
    $db = WordPress::getDb();
    $table = $db->prefix . 'lbwp_word_index';
    $rebuildIndex = array();
    $searchWordIndex = self::getSearchWordIndex();

    // Build indexable words from our text index
    $this->getSearchWordsContent($rebuildIndex);

    // Everything new is put into the index (no updating or deleting yet)
    foreach ($rebuildIndex as $word => $occurences) {
      if (!in_array($word, $searchWordIndex)) {
        $db->insert($table, array(
          'word' => $word,
          'occurences' => $occurences,
          'results' => $occurences // Assumption, must not be correct and will be corrected by actual searches
        ));
      }
    }

    // Remove from index what has more than one space in it (too long, can't use that) and no results
    $db->query('
      DELETE FROM ' . $table . '
      WHERE (LENGTH(word) - LENGTH(REPLACE(word, " ", ""))) >= 2
      OR results = 0;
    ');

    // Flush search words cache and reload
    self::getSearchWordIndex(true);
  }

  /**
   * @return void
   */
  public function buildTextIndex()
  {
    set_time_limit(600);
    ini_set('memory_limit', '1024M');

    // Add product to index if not given and woocommerce is active
    if (!in_array('product', static::$TEXT_INDEX_TYPES) && Util::isWoocommerceActive()) {
      static::$TEXT_INDEX_TYPES[] = 'product';
    }

    $db = WordPress::getDb();
    // Remove items from index that are not in the posts table anymore
    $db->query('
      DELETE FROM ' . $db->prefix . 'lbwp_text_index
      WHERE id NOT IN(
        SELECT ID FROM ' . $db->posts . ' WHERE post_status = "publish" AND post_type IN ("' . implode('","', static::$TEXT_INDEX_TYPES) . '")
      )
    ');

    // If number of indexable posts and index table entries doesn't match, add empty entries
    $indexedPostCount = intval($db->get_var('SELECT COUNT(id) FROM ' . $db->prefix . 'lbwp_text_index WHERE title <> ""'));
    $indexablePostCount = intval($db->get_var('
      SELECT COUNT(id) FROM ' . $db->posts . '
      WHERE post_status = "publish"
      AND post_type IN ("' . implode('","', static::$TEXT_INDEX_TYPES) . '")
    '));

    // Get all posts that are indexable and not yet indexed
    if ($indexedPostCount < $indexablePostCount) {
      $postIds = $db->get_col('
        SELECT ID FROM ' . $db->posts . '
        WHERE post_status = "publish"
        AND post_type IN ("' . implode('","', static::$TEXT_INDEX_TYPES) . '")
        AND ID NOT IN(SELECT id FROM ' . $db->prefix . 'lbwp_text_index)
      ');
      // Create all empty datasets
      foreach ($postIds as $postId) {
        $db->insert($db->prefix . 'lbwp_text_index', array('id' => $postId));
      }
    }

    // Get changed posts from the last 24 hours
    $lastUpdate = Date::getTime(Date::SQL_DATETIME, current_time('timestamp') - DAY_IN_SECONDS);
    $updateablePosts = $db->get_results('
      SELECT ID, post_title, post_content, post_excerpt, post_type FROM ' . $db->posts . '
      WHERE post_status = "publish"
      AND post_type IN ("' . implode('","', static::$TEXT_INDEX_TYPES) . '")
      AND post_modified > "' . $lastUpdate . '"
      LIMIT 0,' . static::$TEXT_INDEX_MAX_RENEWED_DATASETS . '
    ', ARRAY_A);

    // If available add records that have an empty title in the index
    $hasEmptyDatasets = $db->get_var('SELECT COUNT(id) FROM ' . $db->prefix . 'lbwp_text_index WHERE title = "" OR alt_id = ""') > 0;
    if ($hasEmptyDatasets) {
      $updateablePosts = array_merge($updateablePosts, $db->get_results('
        SELECT ID, post_title, post_content, post_excerpt, post_type FROM ' . $db->posts . '
        WHERE post_status = "publish"
        AND post_type IN ("' . implode('","', static::$TEXT_INDEX_TYPES) . '")
        AND ID IN(SELECT id FROM ' . $db->prefix . 'lbwp_text_index WHERE title = "" OR alt_id = "")
        LIMIT 0,' . static::$TEXT_INDEX_MAX_EMPTY_DATASETS . '
      ', ARRAY_A));
    }

    // Get the ids of the posts we're updating
    $postIds = array();
    foreach ($updateablePosts as $post) {
      $postIds[] = $post['ID'];
    }

    // Get meta for all found posts and store in assoc array
    $meta = array();
    $raw = $db->get_results('
      SELECT post_id, meta_key, meta_value FROM ' . $db->postmeta . '
      WHERE post_id IN(' . implode(',', $postIds) . ')
    ');
    foreach ($raw as $row) {
      if (!isset($meta[$row->post_id])) {
        $meta[$row->post_id] = array();
      }
      $meta[$row->post_id][$row->meta_key] = $row->meta_value;
    }

    // Override content field in all posts, if lbwp_index_search_content is given
    foreach ($updateablePosts as $key => $post) {
      $updateablePosts[$key]['meta'] = $meta[$post['ID']];
      if (isset($meta[$post['ID']]['lbwp_index_search_content'])) {
        $updateablePosts[$key]['post_content'] = $meta[$post['ID']]['lbwp_index_search_content'];
        unset($updateablePosts[$key]['meta']['lbwp_index_search_content']);
      }
      // Make sure to not have html in content or excerpt
      $updateablePosts[$key]['post_content'] = strip_tags($post['post_content']);
      $updateablePosts[$key]['post_excerpt'] = strip_tags($post['post_excerpt']);
    }

    // Set taxonomies used for index
    $taxonomies = array();
    if (in_array('product', static::$TEXT_INDEX_TYPES)) {
      $taxonomies[] = 'product_cat';
      if (taxonomy_exists('product_prop')) {
        $taxonomies[] = 'product_prop';
      }
    }
    if (in_array('post', static::$TEXT_INDEX_TYPES)) {
      $taxonomies[] = 'category';
      if (taxonomy_exists('post_tag')) {
        $taxonomies[] = 'post_tag';
      }
    }

    // Let developers add their own taxonomies that should be indexed as well
    $taxonomies = apply_filters('lbwp_search_text_index_taxonomies', $taxonomies);

    // Get property map, eventually used for meta index
    if (in_array('product', static::$TEXT_INDEX_TYPES) && in_array('product_prop', $taxonomies)) {
      $fullMap = Filter::getPropertyTreeFull();
    }

    // Eventually AI libraries are used
    require_once ABSPATH . 'wp-content/plugins/lbwp/resources/libraries/openai-php/vendor/autoload.php';

    // Build index entries for each post
    foreach ($updateablePosts as $post) {
      // Add prefix data to title if needed
      if (strlen(static::$TEXT_INDEX_TITLE_PREFIX_META) > 0) {
        $prefix = $post['meta'][static::$TEXT_INDEX_TITLE_PREFIX_META] ?? '';
        if (strlen($prefix) > 0) {
          $post['post_title'] = trim($prefix . ' ' . $post['post_title']);
        }
      }

      $generatedMeta = $this->getIndexableMetaContent($post, $taxonomies, $fullMap);
      $ai = $this->getIndexableAiContent($post, $generatedMeta);
      $index = array(
        'id' => $post['ID'],
        'alt_id' => $post['post_type'] == 'product' && strlen($post['meta']['_sku'] > 0) ? $post['meta']['_sku'] : $post['ID'],
        'post_type' => $post['post_type'],
        'title' => html_entity_decode(substr($post['post_title'], 0, 500)),
        'synonyms' => html_entity_decode(trim(substr($ai['synonyms'], 0,500))),
        'plurals' => html_entity_decode(trim(substr($ai['plurals'], 0,500))),
        'meta' => html_entity_decode(trim(substr($generatedMeta . $ai['meta'], 0, 1000))),
        'excerpt' => html_entity_decode(trim(substr($post['post_excerpt'], 0, 1000))),
        'excerpt_ai' => html_entity_decode(trim(substr($ai['excerpt_ai'], 0, 1000))),
        'content' => html_entity_decode(trim($post['post_content']))
      );

      // Make sure none of the fields has html in it
      foreach ($index as $key => $value) {
        $index[$key] = strip_tags($value);
        // Also remove all line breaks
        $index[$key] = str_replace(array("\r", "\n"), ' ', $index[$key]);
        // Maybe force swissgerman characters
        if (static::$TEXT_INDEX_FORCE_SWISSGERMAN) {
          $index[$key] = str_replace('ß', 'ss', $index[$key]);
        }
      }

      // Save to database
      $db->update(
        $db->prefix . 'lbwp_text_index',
        $index,
        array('id' => $post['ID'])
      );
    }
  }

  /**
   * @param array $post
   * @return array
   */
  public function getIndexableAiContent($post, $meta)
  {
    $ai = array(
      'synonyms' => '', // max 500 chars
      'plurals' => '', // max 500 chars
      'meta' => '', // max 1000, but acutally ask for 300, as most of it is generated locally
      'excerpt_ai' => '' // max 1000 chars
    );

    // Decide if we use the full AI function, minimal or nothing
    $contentLength = strlen($post['post_content']);
    $metaOnlyLength = strlen($meta);
    $fullContentLength = strlen($post['post_title'] . $post['post_excerpt']) + $contentLength + $metaOnlyLength;

    // Skip, if not enough content or AI disabled
    if (static::$TEXT_INDEX_USE_AI) {
      if ($contentLength < 100 && ($fullContentLength > 300 || $metaOnlyLength > 120)) {
        $ai = $this->getIndexableAiContentReduced($ai, $post, $meta);
      } else if ($fullContentLength > 1500 && $fullContentLength < 8000) {
        $ai = $this->getIndexableAiContentFull($ai, $post);
      }
      // Make sure, if the get array fields, to reduce them to a string
      foreach ($ai as $key => $value) {
        if (is_array($value)) {
          $ai[$key] = implode(' ', $value);
        }
      }
    }

    return $ai;
  }

  /**
   * @param $ai
   * @param $post
   * @param $meta
   * @return array
   */
  protected function getIndexableAiContentReduced($ai, $post, $meta)
  {
    $client = \OpenAI::client(self::CHATGPT_SECRET);

    $prompt = 'Erzeuge Synonyme und Wortalternativen zu Produktdaten im format {"synonyms": "string", "plurals" : "string", "excerpt" : "string"}. Sprache: Deutsch.' .
      'synonyms: Synonyme von Wörtern in Produktdaten und Titel, bis 500 zeichen.' . PHP_EOL .
      'plurals: Plurale von Namenswörtern und Synonymen in Titel/Produktdaten, bis 300 zeichen, nur einzelne Stichwörter.' . PHP_EOL .
      'excerpt: Zusammenfassung des Produkts, als Freitext bis 300 zeichen.' . PHP_EOL .
      'Titel: ' . $post['post_title'] . PHP_EOL .
      'Produktdaten: ' . $meta;

    try {
      $response = $client->chat()->create([
        'model' => 'gpt-5-mini',
        'messages' => [
          ['role' => 'user', 'content' => $prompt],
        ],
      ]);

      $data = json_decode($response->choices[0]->message->content, true);
      if (isset($data['synonyms'])) {
        $ai['synonyms'] = $data['synonyms'];
      }
      if (isset($data['plurals'])) {
        $ai['plurals'] = $data['plurals'];
      }
      if (isset($data['excerpt']) && strlen($data['excerpt']) > 0) {
        $ai['excerpt_ai'] = $data['excerpt'];
      }
    } catch (\Exception $e) {
      SystemLog::add('Search', 'error', 'search index gpt error: ' . $e->getMessage());
    }

    return $ai;
  }

  /**
   * @param $ai
   * @param $post
   * @return array
   */
  protected function getIndexableAiContentFull($ai, $post)
  {
    $client = \OpenAI::client(self::CHATGPT_SECRET);

    $prompt = 'Ich habe Titel, Text und (optional) Zusammenfassung eines Inhalts unserer Website.' .
      'Analysiere und erzeuge JSON mit folgenden Keys: ' . PHP_EOL .
      '- synonyms: Passende Synonyme zu Schlüsselwörtern des Inhalts, die nicht im Inhalt vorkommen, bis 500 zeichen.' . PHP_EOL .
      '- plurals:  Passende Plurale von Schlüsselwörtern, Wortalternativen , bis 500 zeichen.' . PHP_EOL .
      '- meta: Weitere wichtige Metainfos zum Inhalt die für eine Suche relevant sein können, bis 300 zeichen, nur einzelne Stichwörter.' . PHP_EOL .
      '- excerpt: eine Zusammenfassung des Inhalts, bis 500 zeichen.' . PHP_EOL .
      '- Antwort nur in validem JSON nach dieser Vorgabe: {"synonyms": "string", "plurals" : "string", "meta" : "string" "excerpt" : "string"}' . PHP_EOL .
      PHP_EOL .
      'Titel: ' . $post['post_title'] . PHP_EOL .
      'Zusammenfassung: ' . $post['post_excerpt'] . PHP_EOL .
      'Inhalt: ' . mb_substr($post['post_content'], 0, 5000);

    try {
      $response = $client->chat()->create([
        'model' => 'gpt-5-mini',
        'messages' => [
          ['role' => 'user', 'content' => $prompt],
        ],
      ]);

      $content = $response->choices[0]->message->content;
      if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $matches)) {
        $json = $matches[1];
      } else {
        $json = $content;
      }

      $data = json_decode($json, true);
      if (isset($data['synonyms'])) {
        $ai['synonyms'] = $data['synonyms'];
      }
      if (isset($data['meta'])) {
        $ai['meta'] = $data['meta'];
      }
      if (isset($data['plurals'])) {
        $ai['plurals'] = $data['plurals'];
      }
      if (isset($data['excerpt']) && strlen($data['excerpt']) > 0) {
        $ai['excerpt_ai'] = $data['excerpt'];
      }
    } catch (\Exception $e) {
      SystemLog::add('Search', 'error', 'search index gpt error: ' . $e->getMessage());
    }

    return $ai;
  }

  /**
   * @param array $post
   * @return string
   */
  protected function getIndexableMetaContent($post, $taxonomies, &$fullMap)
  {
    $meta = '';

    // Get all associated terms of the post for all taxonomies
    $terms = wp_get_post_terms($post['ID'], $taxonomies);
    if (count($terms) > 0) {
      $meta = 'Kategorie: ';
      foreach ($terms as $term) {
        $meta .= Strings::removeUntilIf($term->name, '.') . ', ';
      }
    }

    // Add selected meta info to the product
    foreach (static::$META_KEYWORDS as $keyId) {
      if (isset($post['meta'][$keyId]) && !empty($post['meta'][$keyId])) {
        $meta .= strip_tags($post['meta'][$keyId]) . ', ';
      }
    }

    // If product, get a meaningful list of properties
    if ($post['post_type'] == 'product' && in_array('product_prop', $taxonomies)) {
      $meta .= 'Produkteigenschaften: ';
      $specifications = array();
      $props = wp_get_post_terms($post['ID'], array('product_prop'));
      foreach ($props as $term) {
        if ($term->parent > 0) {
          $specifications[$term->term_id] = array(
            'key' => false,
            'value' => Strings::removeUntilIf($term->name, '.'),
            'parent' => $term->parent
          );
          // Remove some floating point values that we don't need to show
          $specifications[$term->term_id]['value'] = str_replace(array('.00','.0'),'', $specifications[$term->term_id]['value']);
        }
      }

      // Map the names of the properties
      foreach ($specifications as $spec) {
        foreach ($fullMap as $property) {
          if ($property['id'] == $spec['parent'] && !$property['config']['hidedetail']) {
            $meta .= Strings::removeUntilIf($property['name'], '.') . '=' . $spec['value'] . ', ';
          }
        }
      }
    }

    return $meta;
  }

  /**
   * Tracks actual searches of users
   */
  public static function trackSearchTerm($term, $results = 0, $corrected = false)
  {
    $db = WordPress::getDb();
    // Skip if the word is too short or too long
    if (strlen($term) <= 3 || strlen($term) > 40) {
      return;
    }

    // Track into our option, not matter if successful
    if (!$corrected && static::$TRACK_USER_SEARCHES) {
      $searches = ArrayManipulation::forceArray(get_option(static::$TRACK_USER_SEARCHES_OPTION));
      $searches[$term] = $results;
      update_option(static::$TRACK_USER_SEARCHES_OPTION, $searches, false);
    }

    $searchWordIndex = self::getSearchWordIndex();
    if ($results > 0 && !$corrected && !is_numeric($term)) {
      if (!in_array($term, $searchWordIndex)) {
        $db->insert($db->prefix . 'lbwp_word_index', array(
          'word' => $term,
          'occurences' => 1,
          'views' => 1,
          'clicks' => 1,
          'results' => $results
        ));
        $searchWordIndex[$db->insert_id] = $term;
        wp_cache_set('searchWordIndex', $searchWordIndex, 'Search', 86400);
      } else {
        // Update number of clicks and occurences by one, also update results
        $wid = array_search($term, $searchWordIndex);
        if ($wid > 0) {
          self::countIndexWordClick($wid);
          self::countIndexWordOccurence($wid);
          self::setResultCount($wid, $results);
        }
      }
    }
  }

  /**
   * Track a click on a search term suggestions
   */
  public function trackSearchTermClick()
  {
    if (!static::$BUILD_SEARCH_WORD_INDEX) {
      return;
    }
    $word = addslashes($_POST['term']);
    $searchWordIndex = self::getSearchWordIndex();
    $wid = array_search($word, $searchWordIndex);
    if ($wid > 0) {
      self::countIndexWordClick($wid);
    }
  }

  /**
   * @param array $index the index to be filled with words
   */
  protected function getSearchWordsContent(&$index)
  {
    // Rawly read the database for certain product post meta fields
    $db = WordPress::getDb();
    $table = $db->prefix . 'lbwp_text_index';
    $raw = $db->get_col('SELECT CONCAT(title, " ", synonyms, " ", meta, " ", excerpt, " ", excerpt_ai, " ", content, " ") AS words FROM ' . $table);
    // Build actual useable words from raw and add to index whilst counting
    foreach ($raw as $string) {
      $words = $this->buildIndexableWords($string);
      foreach ($words as $word) {
        $index[$word]++;
      }
    }
  }

  /**
   * At the moment only used once to create an initial index
   * @param $string
   * @return false|string[]
   */
  protected function buildIndexableWords($string)
  {
    // Parse out URLs from the string
    $string = preg_replace('/(https?:\/\/[^\s]+)/', ' ', $string);
    // Replace every character that is not a-zA-Z with a space
    $string = preg_replace('/[^a-zA-ZäöüÄÖÜßéàèâ]/', ' ', $string);
    // Build words, but lose the short ones and number only, also just use words starting wir captial letter
    $words = explode(' ', $string);
    foreach ($words as $key => $word) {
      if (strlen($word) < static::$MIN_WORD_CHARS || !ctype_upper($word[0]) || is_numeric($word)) {
        unset($words[$key]);
      }
    }

    return $words;
  }

  /**
   * Make sure the search word index table is maintained
   */
  public function updateIndexTables()
  {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table = $wpdb->prefix . 'lbwp_word_index';
    $sql = "CREATE TABLE $table (
      wid int(11) UNSIGNED AUTO_INCREMENT NOT NULL,
      word VARCHAR(32) NOT NULL,
      occurences int(11) UNSIGNED NOT NULL,
      views int(11) UNSIGNED NOT NULL,
      clicks  int(11) UNSIGNED NOT NULL,
      results  int(11) UNSIGNED NOT NULL,
      PRIMARY KEY  (wid),
      KEY occurences (occurences),
      KEY results (results),
      KEY clicks (clicks),
      KEY results (results)
    ) $charset;";
    dbDelta($sql);

    $table_name = $wpdb->prefix . 'lbwp_text_index';
    $sql = "CREATE TABLE $table_name (
      id BIGINT(20) UNSIGNED NOT NULL,
      alt_id VARCHAR(200) NOT NULL,
      post_type VARCHAR(20) NOT NULL,
      title VARCHAR(500) NOT NULL,
      synonyms VARCHAR(500) NOT NULL,
      plurals VARCHAR(500) NOT NULL,
      meta VARCHAR(1000) NOT NULL,
      excerpt VARCHAR(1000) NOT NULL,
      excerpt_ai VARCHAR(1000) NOT NULL,
      content TEXT NOT NULL,
      PRIMARY KEY  (id),      
      KEY alt_id_idx (alt_id),
      KEY post_type_idx (post_type),
      KEY title (title),
      FULLTEXT KEY fulltext_primary_v2 (title, excerpt, content, meta),
      FULLTEXT KEY fulltext_secondary_v2 (synonyms, plurals, excerpt_ai)
    ) $charset;
    ";
    dbDelta($sql);

    // TODO Remove code once run, these were previous indexes
    $wpdb->query('ALTER TABLE ' . $table_name . ' DROP INDEX `fulltext_primary`');
    $wpdb->query('ALTER TABLE ' . $table_name . ' DROP INDEX `fulltext_secondary`');
  }

  /**
   * @param bool $force force reloading of cached list
   */
  public static function getSearchWordIndex($force = false)
  {
    $index = wp_cache_get('searchWordIndex', 'Search');
    if (is_array($index) && count($index) && !$force) {
      return $index;
    }

    // Rebuild the index from db and override in cache
    $index = array();
    $db = WordPress::getDb();
    $results = $db->get_results('SELECT wid, word FROM ' . $db->prefix . 'lbwp_word_index ORDER BY results DESC');
    foreach ($results as $result) {
      $index[$result->wid] = $result->word;
    }

    wp_cache_set('searchWordIndex', $index, 'Search', 86400);
    return $index;
  }

  /**
   * @param string äterm
   * @return string validates a given term for search
   */
  public static function validateTerm($term)
  {
    return strip_tags(trim(urldecode($term)));
  }

  /**
   * @param string $term the search term
   * @param bool $exact NOT USED ANYMORE with INDEX
   * @param Filter $filter eventually given
   * @return int[] a list of possible products to be listed
   */
  public static function getProductIdsByTerm($term, $exact, $filter = false, $limit = 0)
  {
    if (static::$USE_SEARCH_INDEX) {
      return static::getProductIdsByTerm_index($term, $filter, $limit);
    }

    // Fallback to old search, as we're testing the new one with specified customers
    return static::getProductIdsByTerm_legacy($term, $exact, $filter, $limit);
  }

  /**
   * @param string $term the search term
   * @param Filter $filter eventually given
   * @return int[] a list of possible products to be listed
   */
  public static function getProductIdsByTerm_index($term, $filter = false, $limit = 0)
  {
    $postsNotInSql = '';
    if (static::$APPLY_FILTER_LIMITATION_LISTS) {
      $blacklist = $filter instanceof \LBWP\Aboon\Base\Filter ? $filter::getProductIdBlacklist() : Filter::getProductIdBlacklist();
      $blacklist = array_merge($blacklist, self::getSearchHiddenProducts());
      if (count($blacklist) > 0) {
        $postsNotInSql = ' AND id NOT IN(' . implode(',', $blacklist) . ')';
      }
    }

    $db = WordPress::getDb();
    // Check if the term exactly matched the alt_id (which is the sku in case of products
    $sql = '
      SELECT id FROM ' . $db->prefix . 'lbwp_text_index
      WHERE alt_id = {term} AND post_type IN("' . implode('","', static::$POST_TYPE) . '"' . $postsNotInSql . ') ' . $postsNotInSql
    ;
    $productIds = WordPress::getDb()->get_col(Strings::prepareSql($sql, array('term' => $term)));
    if (count($productIds) > 0 || apply_filters('aboon_search_term_before_index_search', false, $term)) {
      self::applyWhitelistRetractions($productIds, $filter);
      return array_map('intval', $productIds);
    }

    // Always use boolean mode, if more than three words (as it's to many results)
    $raw = array();
    $term = apply_filters('aboon_seach_term_before_index_search', $term);
    $terms = explode(' ', $term);
    $forceBooleanMode = count($terms) > 3;
    $preventBooleanMode = strlen($term) <= 3;

    // Try with exact like search if feature active
    if (static::$SEARCH_PRIMARY_WITH_LIKE) {
      $term = str_replace(array('+','*'), '', $term);
      $sql = '
        SELECT id, ( 
            (title LIKE "{raw:term}%")*4 + 
            (content LIKE "%{raw:term}%")*2 + 
            (excerpt LIKE "%{raw:term}%")*2 + 
            (meta LIKE "%{raw:term}%")*1 + 
            (plurals LIKE "%{raw:term}%")*1 + 
            (title LIKE "%{raw:term}%")*3
          ) AS relevance
        FROM ' . $db->prefix . 'lbwp_text_index
        WHERE (title LIKE "{raw:term}%" 
           OR title LIKE "%{raw:term}%" 
           OR meta LIKE "%{raw:term}%" 
           OR content LIKE "%{raw:term}%" 
           OR plurals LIKE "%{raw:term}%" 
           OR excerpt LIKE "%{raw:term}%")
           ' . $postsNotInSql . '
        ORDER BY relevance DESC
      ';
      $raw = $db->get_results(Strings::prepareSql($sql, array(
        'term' => $term
      )));
    }

    if (count($raw) == 0) {
      $mode = 'NATURAL LANGUAGE';
      if (!$preventBooleanMode && ($forceBooleanMode || static::$USE_INDEX_BOOLEAN_MODE)) {
        $mode = 'BOOLEAN';
        // Convert for bool mode to "+term1 +term2" etc
        $term = '';
        foreach ($terms as $word) {
          // Our ft_min_word_len is 4, shorter words would prevent search results
          if (strlen($word) >= 4) {
            if (static::$USE_INDEX_BOOLEAN_MODE_WILDCARDS && !str_ends_with($word, '*')) {
              $word .= '*';
            }
            $term .= '+' . $word . ' ';
          }
        }
        $term = trim($term);
      }

      $sql = '
      SELECT DISTINCT id,
      (
        (MATCH(title, content, excerpt, meta) AGAINST({term} IN ' . $mode . ' MODE) * 3) +
        (MATCH(synonyms, plurals, excerpt_ai) AGAINST({term} IN ' . $mode . ' MODE) * 2)
      ) AS relevance
      FROM ' . $db->prefix . 'lbwp_text_index
      WHERE (
        MATCH(title, content, excerpt, meta) AGAINST({term} IN ' . $mode . ' MODE) OR
        MATCH(synonyms, plurals, excerpt_ai) AGAINST({term} IN ' . $mode . ' MODE)
      )
      AND (post_type IN("' . implode('","', static::$POST_TYPE) . '") ' . $postsNotInSql . ')
      ORDER BY relevance DESC
    ';

      // Add limit if needed
      if ($limit > 0) {
        $sql .= ' LIMIT 0,' . $limit;
      }
      $raw = $db->get_results(Strings::prepareSql($sql, array(
        'term' => $term
      )));
    }

    // Fallback to normal like search if no results and feature is active
    if (static::$USE_FALLBACK_NO_RESULTS && count($raw) == 0) {
      $term = str_replace(array('+','*'), '', $term);
      $sql = '
        SELECT id, ( 
            (title LIKE "%{raw:term}%")*3 + 
            (synonyms LIKE "%{raw:term}%")*2 + 
            (plurals LIKE "%{raw:term}%")*1
          ) AS relevance
        FROM ' . $db->prefix . 'lbwp_text_index
        WHERE title LIKE "%{raw:term}%" 
           OR synonyms LIKE "%{raw:term}%" 
           OR plurals LIKE "%{raw:term}%" 
        ORDER BY relevance DESC
      ';
      $raw = $db->get_results(Strings::prepareSql($sql, array(
        'term' => $term
      )));
    }

    // For compat with custom sorts still build groups 1,2,3
    $grouped = array(1 => array(), 2 => array(), 3 => array());
    foreach ($raw as $item) {
      if (!isset($item->relevance) || $item->relevance <= 2) {
        $grouped[3][] = $item->id;
      } else if ($item->relevance >= 6) {
        $grouped[1][] = $item->id;
      } else if ($item->relevance >= 3) {
        $grouped[2][] = $item->id;
      }
    }

    // Allow developers to interact with the results at this point
    $grouped = apply_filters('aboon_search_autocomplete_product_ids', $grouped);
    // Make a simple ungrouped array again
    $productIds = array();
    foreach ($grouped as  $group) {
      $productIds = array_merge($productIds, $group);
    }

    // Apply retractions from search with the blacklist if limitations are made
    self::applyWhitelistRetractions($productIds, $filter);

    return array_map('intval', $productIds);
  }

  /**
   * @param $productIds
   * @param $filter
   * @return void
   */
  protected static function applyWhitelistRetractions(&$productIds, $filter)
  {
    if (static::$APPLY_FILTER_LIMITATION_LISTS) {
      if ($filter !== false && method_exists($filter, 'getProductIdWhitelist')) {
        $whitelist = $filter->getProductIdWhitelist();
      } else {
        $whitelist = Filter::getProductIdWhitelist();
      }

      if (count($whitelist) > 0) {
        foreach ($productIds as $key => $id) {
          if (!in_array($id, $whitelist)) {
            unset($productIds[$key]);
          }
        }
      }
    }
  }

  /**
   * @param string $term the search term
   * @param bool $exact exact search or not
   * @param Filter $filter eventually given
   * @return int[] a list of possible products to be listed
   */
  public static function getProductIdsByTerm_legacy($term, $exact, $filter = false, $limit = 0)
  {
    if ($exact) {
      // Add the exact term and one where spaces are wildcards
      $terms[] = $term;
      $terms[] = str_replace(' ', '%', $term);
    } else {
      // Search for all words, but also for the full word
      $terms = explode(' ', $term);
      // Remove conjunctions as it may pose to many results
      foreach ($terms as $key => $value) {
        if (in_array($value, static::$CONJUNCTIONS) || strlen($value) <= 2) {
          unset($terms[$key]);
        }
      }
      // Add the full term as well. but cut if too long (hence won't find anything anyway)
      array_unshift($terms, substr($term, 0, 30));
      // Remove duplicates
      $terms = array_unique($terms);
      // At most, search for X terms to not kill the DB
      if (count($terms) > static::$MAX_SPLIT_TERMS) {
        // Order by length, so we cut the shortest words
        usort($terms, function($a, $b) {
          return strlen($b) - strlen($a);
        });
        $terms = array_slice($terms, 0, static::$MAX_SPLIT_TERMS);
      }
    }

    $prepare = array();
    $titleWhere = array();
    $contentWhere = array();
    $metaWhere = array();
    foreach ($terms as $key => $term) {
      $prepare['term_' . $key] = '%' . $term . '%';
      $titleWhere[] = 'post_title LIKE {term_' . $key . '}';
      $contentWhere[] = 'post_content LIKE {term_' . $key . '}';
      foreach (static::$AUTOCOMP_META as $id) {
        $metaWhere[] = '(meta_key LIKE "' . $id . '" AND meta_value LIKE {term_' . $key . '})';
      }
    }

    $postsNotInSql = '';
    if (static::$APPLY_FILTER_LIMITATION_LISTS) {
      $blacklist = array_merge(Filter::getProductIdBlacklist(), self::getSearchHiddenProducts());
      if (count($blacklist) > 0) {
        $postsNotInSql = ' AND ID NOT IN(' . implode(',', $blacklist) . ')';
      }
    }

    $db = WordPress::getDb();
    $sqlTitle = '
      SELECT ID, 1 as Prio FROM ' . $db->posts . '
      WHERE (' . implode(' OR ', $titleWhere) . ') 
      AND post_type IN("' . implode('","', static::$POST_TYPE) . '") AND post_status = "publish" ' . $postsNotInSql . '
    ';
    $sqlContent = '
      SELECT ID, 2 as Prio FROM ' . $db->posts . '
      WHERE (' . implode(' OR ', $contentWhere) . ') 
      AND post_type IN("' . implode('","', static::$POST_TYPE) . '") AND post_status = "publish" ' . $postsNotInSql . '
    ';

    $expensiveSearchSql = '';
    if (count($metaWhere) > 0) {
      $postsNotInSql = str_replace('ID NOT IN', 'post_id NOT IN', $postsNotInSql);
      // Limit this to 4 with is mostly okay for autocomplete
      $expensiveSearchSql = '
        SELECT post_id AS ID, 3 AS Prio FROM ' . $db->postmeta . '
        WHERE ' . implode(' OR ', $metaWhere) . ' ' . $postsNotInSql . '
        LIMIT 0,' . static::$AUTOCOMP_SEC_SEARCH_THRESHOLD . '
      ';
    }

    if ($limit > 0) {
      $sqlTitle .= ' LIMIT 0,' . $limit;
    }

    $results = 0;
    $grouped = array();
    $raw = $db->get_results(Strings::prepareSql($sqlTitle, $prepare));
    foreach ($raw as $item) {
      $grouped[$item->Prio][] = $item->ID;
      $results++;
    }
    // If we don't have enough add second prio
    if ($results < static::$AUTOCOMP_PRODUCT_RESULTS) {
      $raw = $db->get_results(Strings::prepareSql($sqlContent, $prepare));
      foreach ($raw as $item) {
        $grouped[$item->Prio][] = $item->ID;
        $results++;
      }
    }
    if ($results < static::$AUTOCOMP_SEC_SEARCH_THRESHOLD) {
      $raw = $db->get_results(Strings::prepareSql($expensiveSearchSql, $prepare));
      foreach ($raw as $item) {
        $grouped[$item->Prio][] = $item->ID;
      }
    }

    // Allow developers to interact with the results at this point
    $grouped = apply_filters('aboon_search_autocomplete_product_ids', $grouped);

    // Make a simple ungrouped array from that, build it unique
    $productIds = array();
    foreach ($grouped as $ids) {
      foreach ($ids as $id) {
        if (!in_array($id, $productIds)) {
          $productIds[] = intval($id);
        }
      }
    }

    // Apply retractions from search with the blacklist if limitations are made
    if (static::$APPLY_FILTER_LIMITATION_LISTS) {
      if ($filter !== false && method_exists($filter, 'getProductIdWhitelist')) {
        $whitelist = $filter->getProductIdWhitelist();
      } else {
        $whitelist = Filter::getProductIdWhitelist();
      }

      if (count($whitelist) > 0) {
        foreach ($productIds as $key => $id) {
          if (!in_array($id, $whitelist)) {
            unset($productIds[$key]);
          }
        }
      }
    }

    return $productIds;
  }

  /**
   * @return array
   */
  public static function getSearchHiddenProducts()
  {
    $db = WordPress::getDb();
    return array_map('intval', $db->get_col('
      SELECT DISTINCT post_id FROM ' . $db->postmeta . '
      WHERE meta_key = "product-visibility" and meta_value LIKE "%hide-search%"
    '));
  }

  /**
   * @param int $wid tracks view of a index word
   */
  public static function countIndexWordView($wid)
  {
    $db = WordPress::getDb();
    $db->query('UPDATE ' . $db->prefix . 'lbwp_word_index SET views = (views+1) WHERE wid = ' . intval($wid));
  }

  /**
   * @param int $wid tracks clicks of a index word
   */
  public static function countIndexWordClick($wid)
  {
    $db = WordPress::getDb();
    $db->query('UPDATE ' . $db->prefix . 'lbwp_word_index SET clicks = (clicks+1) WHERE wid = ' . intval($wid));
  }

  /**
   * @param int $wid tracks occurence of a index word
   */
  public static function countIndexWordOccurence($wid)
  {
    $db = WordPress::getDb();
    $db->query('UPDATE ' . $db->prefix . 'lbwp_word_index SET occurences = (occurences+1) WHERE wid = ' . intval($wid));
  }

  /**
   * @param $wid
   * @param $results
   * @return void
   */
  public static function setResultCount($wid, $results)
  {
    $db = WordPress::getDb();
    $db->query('UPDATE ' . $db->prefix . 'lbwp_word_index SET results = ' . intval($results) . ' WHERE wid = ' . intval($wid));
  }

  /**
   * @return array category endpoint data
   */
  public function getCategories()
  {
    Shop::setApiUserContext();
    $html = '';
    $raw = array();
    $results = $this->getCategoryAutocompletions($_GET['term'], $raw);
    foreach ($results as $link) {
      $html .= '<li class="sidescroll-item sidescroll-item__text">' . $link . '</li>';
    }

    return array(
      'html' => $html,
      'results' => count($results)
    );
  }

  /**
   * @param array $tree a category tree
   * @param array $whitelist list of whitelisted products
   */
  public static function reduceCategoryTree(&$tree, $whitelist)
  {
    // Get tids from product list to know which we can omit
    $tids = self::getTidsFromProductIdList($whitelist);

    // Walk the tree and remove everything that isn't in tids
    foreach ($tree as $firstKey => $first) {
      if (!in_array($first['id'], $tids)) {
        unset($tree[$firstKey]);
        continue;
      }
      foreach ($first['sub'] as $secKey => $second) {
        if (!in_array($second['id'], $tids)) {
          unset($tree[$firstKey]['sub'][$secKey]);
        }
      }
    }
  }

  /**
   * @param $pids
   * @return array
   */
  protected static function getTidsFromProductIdList($pids)
  {
    $db = WordPress::getDb();
    return $db->get_col('
      SELECT DISTINCT tid FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE pid IN(' . implode(',', $pids) . ')
    ');
  }

  /**
   * @return false|string|\WP_Error
   */
  public static function getFilterUrl()
  {
    return get_permalink(static::$FILTER_RESULT_ID);
  }

  /**
   * @param string $searchTerm
   * @return array a list of links for autocompletion
   */
  protected function getCategoryAutocompletions($searchTerm, &$raw)
  {
    // Search the category tree, set keys so that secondary is priorized higher
    $autocompletions = array();
    $filter = $this->getTheme()->searchUniqueComponent('Filter', true);
    if ($filter !== false && method_exists($filter, 'getCategoryTree')) {
      $whitelist = $filter->getProductIdWhitelist();
      $tree = $filter->getCategoryTree();
    } else {
      $whitelist = Filter::getProductIdWhitelist();
      $tree = Filter::getCategoryTree();
    }

    if (count($whitelist) > 0) {
      self::reduceCategoryTree($tree, $whitelist);
    }
    $url = static::getFilterUrl();
    foreach ($tree as $mainId => $tree) {
      // Second hierarchy
      foreach ($tree['sub'] as $secId => $secTree) {
        // See if the hierarchiv itself matches
        if (stristr($secTree['name'], $searchTerm) !== false) {
          $autocompletions[1000 + $secId] = '<a href="' . $url . '#m:' . $mainId . ';s:' . $secId . '">' . $tree['name'] . ' &rsaquo; ' . $secTree['name'] . '</a>';
          $raw[] = array('url' => $url . '#m:' . $mainId . ';s:' . $secId, 'term' => $tree['name'] . ' &rsaquo; ' . $secTree['name']);
        }
        // Also look if there are tertiaries matching
        foreach ($secTree['sub'] as $terId => $terTree) {
          if (stristr($terTree['name'], $searchTerm) !== false) {
            $autocompletions[100000 + $terId] = '<a href="' . $url . '#m:' . $mainId . ';s:' . $secId . ';t:' . $terId . '">' . $secTree['name'] . ' &rsaquo; ' . $terTree['name'] . '</a>';
            $raw[] = array('url' => $url . '#m:' . $mainId . ';s:' . $secId . ';t:' . $terId, 'term' => $secTree['name'] . ' &rsaquo; ' . $terTree['name']);
          }
        }
      }
    }

    // Also look for exact matches of properties
    foreach (Filter::getPropertyTree() as $property) {
      if ($property['config']['type'] == 'text' && $property['config']['visible']) {
        foreach ($property['props'] as $id => $item) {
          if (stristr($item, $searchTerm) !== false) {
            $autocompletions[200000 + $id] = '<a href="' . $url . '#p:' . $id . '">' . $property['name'] . ' &rsaquo; ' . $item . '</a>';
            $raw[] = array('url' => $url . '#p:' . $id, 'term' => $property['name'] . ' &rsaquo; ' . $item);
          }
        }
      }
    }

    return $autocompletions;
  }

  /**
   * @return string
   */
  public static function getSearchUrl()
  {
    return get_permalink(static::$SEARCH_RESULT_ID);
  }

  /**
   * @return string
   */
  public static function getSiteSearchUrl()
  {
    return get_permalink(static::$SITE_SEARCH_RESULT_ID);
  }

  /**
   * @param $name
   * @return mixed|null
   */
  protected function icon($name)
  {
    $icon = '';
    switch ($name) {
      case 'search-autocomplete-close': $icon = '<i class="fal fa-times"></i>'; break;
    }

    return apply_filters('aboon_general_icon_filter', $icon, $name);
  }

  /**
   * @return string
   */
  public function getAutocompletion()
  {
    Shop::setApiUserContext();
    $results = 0;
    $visualTerm = self::validateTerm($_GET['term']);
    $searchTerm = strtolower($visualTerm);
    $correctedTerm = '';
    /** @var \LBWP\Aboon\Base\Filter $filter */
    $filter = $this->getTheme()->searchUniqueComponent('Filter', true);

    $searchWordIndex = array_map('strtolower', self::getSearchWordIndex());
    // Special character handling
    if (static::$TEXT_INDEX_FORCE_SWISSGERMAN) {
      $searchTerm = str_replace('ß', 'ss', $searchTerm);
    }
    $url = static::getSearchUrl();
    $html = '<div class="suggestions__close-wrapper"><button class="suggestions__closer">' . $this->icon('search-autocomplete-close') . '</button></div>';

    // If given, use the prop tree to add more autocompletion words to the index
    if ($filter !== false && method_exists($filter, 'getPropertyTreeFull')) {
      $props = $filter->getPropertyTreeFull();
      foreach ($props as $prop) {
        if ($prop['config']['visible']) {
          foreach ($prop['props'] as $item) {
            if (!is_numeric($item)) {
              $searchWordIndex[] = strtolower($item);
            }
          }
        }
      }
    }

    // Make sure to reduce index to be unique
    $searchWordIndex = array_unique($searchWordIndex);

    $autocompletions = array();
    if (static::$BUILD_SEARCH_WORD_INDEX) {
      // First, try getting autocompletes starting with the same characters
      foreach ($searchWordIndex as $id => $term) {
        if (str_starts_with($term, $searchTerm)) {
          $autocompletions[$id] = $term;
          //Search::countIndexWordView($id); skip as not needed and performance hungry
        }
      }
      // Now if not enough elements, try again with contains search
      if (count($autocompletions) == 0) {
        foreach ($searchWordIndex as $id => $term) {
          if (stristr($term, $searchTerm) !== false) {
            $autocompletions[$id] = $term;
            //Search::countIndexWordView($id); skip as not needed and performance hungry
          }
        }
      }
    }

    // Get autocomplete suggestions (maybe corrects the terms on 85% certainty)
    if (!is_numeric($searchTerm) && count($autocompletions) == 0) {
      $correctedTerm = $searchTerm;
      $autocompletions = $this->getAutocompleteSuggestions($correctedTerm, $searchWordIndex);
      // If no results, try again with removing spaces from the term (as it may be a compound word)
      if (count($autocompletions) == 0) {
        $backupTerm = $searchTerm;
        $searchTerm = str_replace(' ', '', $searchTerm);
        $autocompletions = $this->getAutocompleteSuggestions($searchTerm, $searchWordIndex);
        // Restore backup if nothing found
        if (count($autocompletions) == 0) {
          $searchTerm = $backupTerm;
        }
      }
    }

    // Add autocomplete words
    if (count($autocompletions) > 0) {
      ++$results;
      $autocompletions = array_slice($autocompletions, 0, static::$AUTOCOMP_SUGGEST_RESULTS);
      $html .= '<div class="search-form__suggestion search-form__terms">';
      $html .= '<div class="search-form__title">Suchvorschläge</div><ul data-wg-notranslate>';
      foreach ($autocompletions as $term) {
        $termHtml = Strings::wrap($term, $searchTerm, '<strong>', '</strong>');
        $termHtml = str_replace($searchTerm, $visualTerm, $termHtml);
        $html .= '<li><a href="' . $url . '#f:' . $term . '">' . $termHtml . '</a></li>';
        $raw[] = array('url' => $url . '#f:' . $term, 'term' => $term);
      }
      $html .= ' </ul></div>';
    }

    // Search the category tree, set keys so that secondary is priorized higher
    $autocompletions = $this->getCategoryAutocompletions($searchTerm, $raw);

    // If something has been found
    if (count($autocompletions) > 0) {
      ++$results;
      ksort($autocompletions);
      $autocompletions = array_slice($autocompletions, 0, 5);
      $html .= '<div class="search-form__suggestion search-form__categories">';
      $html .= '<div class="search-form__title">' . __('Kategorien', static::$TEXT_DOMAIN) . '</div><ul>';
      foreach ($autocompletions as $link) {
        $html .= '<li>' . $link . '</li>';
      }
      $html .= '</div></ul>';
    }

    // Return early as we just search for autocompletes and not for actual results
    if (isset($_GET['content']) && $_GET['content'] == 0) {
      return array(
        'visual' => $visualTerm,
        'term' => $searchTerm,
        'corrected' => $correctedTerm,
        'raw' => $raw,
        'results' => $results,
        'result' => $html
      );
    }

    // Simple product suggestions by actual search on term, try the corrected one if the given in one doesn't find anything
    $productIds = self::getProductIdsByTerm($searchTerm, true, $filter, static::$AUTOCOMP_PRODUCT_RESULTS);
    if (count($productIds) == 0 && strlen($correctedTerm) > 0 && $searchTerm != $correctedTerm) {
      $productIds = self::getProductIdsByTerm($correctedTerm, true, $filter, static::$AUTOCOMP_PRODUCT_RESULTS);
    }

    if (count($productIds) > 0) {
      ++$results;
      $productIds = apply_filters('lbwp_search_autocomp_product_ids_before', $productIds);
      // Only take needed results from that
      $completions = array_slice($productIds, 0, static::$AUTOCOMP_PRODUCT_RESULTS);
      // Slice if needed
      if (static::$AUTOCOMP_PRODUCT_RESULTS_SLICE > 0) {
        $completions = array_slice($completions, 0, static::$AUTOCOMP_PRODUCT_RESULTS_SLICE);
      }
      $html .= '<div class="search-form__suggestion search-form__products">';
      $html .= '<div class="search-form__title">' . __('Vorschläge', static::$TEXT_DOMAIN) . '</div><ul class="product-suggestions" data-wg-notranslate>';
      foreach ($completions as $id) {
        if (static::$AUTOCOMP_CALLBACK === false) {
          $product = wc_get_product($id);

          if($product === null || $product === false){
            continue;
          }

          $url = get_permalink($id);
          $html .= '
            <li class="product-suggestion">
              <div class="row">
                <div class="col-8 col-md-9 col-lg-9 product-suggestion__content">
                  <div class="product-price product-price__wrapper">
                    <span class="product-price__current">' . $product->get_price_html() . '</span>
                  </div>
                  <p><a href="' . $url . '">' . $this->getProductTitle($product). '</a></p>
                </div>
                <div class="col-4 col-md-3 col-lg-3 product-suggestion__image">
                  <a href="' . $url . '">' . FocusPoint::getFeaturedImage($id) . '</a>
                </div>
              </div>
            </li>
          ';
        } else {
          $html .= call_user_func(static::$AUTOCOMP_CALLBACK, $id);
        }
      }
      $html .= '</ul></div>';
    }

    return array(
      'visual' => $visualTerm,
      'term' => $searchTerm,
      'corrected' => $correctedTerm,
      'raw' => $raw,
      'results' => $results,
      'result' => $html
    );
  }

  /**
   * @param $searchTerm
   * @param $searchWordIndex
   * @return array
   */
  public function getAutocompleteSuggestions(&$searchTerm, &$searchWordIndex)
  {
    // Get all autocompletions that match
    $autocompletions = array();

    // Order the autocompletions by similarity to the term
    if (count($autocompletions) > 0) {
      $autocompletions = Strings::orderStringsBySimilarity($searchTerm, $autocompletions);
      // Maybe cut to the max
      $autocompletions = array_slice($autocompletions, 0, static::$AUTOCOMP_SUGGEST_RESULTS);
    }

    // If we found not autocomps, try again with fuzzy search
    if (count($autocompletions) == 0 || $this->maybeStillTypo($searchTerm, $autocompletions)) {
      // At this point remove all non single words from the index before doing the fuzzy search
      $searchWordIndex = array_filter($searchWordIndex, function($term) {
        return stristr($term, ' ') === false;
      });
      // Sort index by number of characters in each word
      usort($searchWordIndex, function($a, $b) {
        return strlen($a) - strlen($b);
      });
      $autocompletions = array_merge(Strings::fuzzySearch($searchTerm, $searchWordIndex, true, 1, 4, 2), $autocompletions);
      // From found completions, use the nearest one as search term instead
      if (count($autocompletions) > 0) {
        $searchTerm = Strings::getMostSimilarString($searchTerm, $autocompletions);
        // Maybe cut to the max again
        $autocompletions = array_slice($autocompletions, 0, static::$AUTOCOMP_SUGGEST_RESULTS);
      }
    }
    // Sum up same words to their lowercase version
    if (static::$SUMUP_AUTOCOMP_TERMS) {
      $autocompletions = array_map('strtolower', $autocompletions);
      $autocompletions = array_unique($autocompletions);
    }

    return $autocompletions;
  }

  /**
   * Now even if we have results, we try to see if it could still be a typo
   * @param $term
   * @param $results
   * @return void
   */
  protected function maybeStillTypo($term, $results)
  {
    // See if the found results have a large distance, then maybe the term is a type or has nothing to do with what was intended
    $totalDistance = 0;
    foreach ($results as $result) {
      $totalDistance += levenshtein($term, $result);
    }

    // If total distance mean is above 4, it might be a type
    return $totalDistance / count($results) > 4;
  }

  /**
   * @param \WC_Product $product
   * @return string the title of the product
   */
  protected function getProductTitle($product)
  {
    return $product->get_title();
  }

  /**
   * Register blocks with ACF
   */
  public function blocks()
  {
    $this->registerBlock(array(
      'name' => 'aboon-search',
      'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="192" height="192" fill="#000000" viewBox="0 0 256 256"><rect width="256" height="256" fill="none"></rect><circle cx="116" cy="116" r="84" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></circle><line x1="175.4" y1="175.4" x2="224" y2="224" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" stroke-width="16"></line></svg>',
      'title' => __('Suchergebnisse', 'banholzer'),
      'preview' => false,
      'description' => __('Block für Suchergebnisse', 'banholzer'),
      'render_callback' => array($this, 'getSearchHtml'),
      'post_types' => array('post', 'page'),
      'category' => 'theme',
    ));
  }

  /**
   * Provides the search result tabs and category results (upper part of search)
   */
  public function getSearchHtml($block)
  {
    // Full search filter or web results?
    $query = htmlentities(strip_tags(addslashes($_GET['q'])));
    $context = $block['data']['context'];
    $tabs = '';

    // Build category results
    $categories = '
      <section class="sidescroll sidescroll__wrapper categories__results-wrapper">
        <div class="container">
          <div class="row">
            <div class="filter__header"> <!-- col-12 col-md-10 offset-md-1 col-lg-8 offset-lg-2 -->
              <h3>' . __('Kategorien', static::$TEXT_DOMAIN) . '</h3>
              <div class="categories__results found-categories" data-template="' . __('{x} Kategorien', static::$TEXT_DOMAIN) . '"></div>
            </div>
          </div>
        </div>
        <div class="search-categories sidescroll-list sidescroll-list__wrapper">
          <ul class="sidescroll-list__inner hide-scrollbars"></ul>
        </div>
      </section>
    ';

    // Build tabs to switch to site search eventually
    if (static::$HAS_SITESEARCH) {
      if ($context == 'gss') {
        $tabs = '
          <li class="search-tabs__entry">
            <a href="' . static::getSearchUrl() . '#f:' . $query . '">' . __('Filter', static::$TEXT_DOMAIN) . '</a>
          </li>
          <li class="search-tabs__entry search-tabs__entry--active"> 
            <a href="javascript:void(0)">' . __('Website', static::$TEXT_DOMAIN) . '</a>
          </li>
        ';
        // Not category results in sitesearch mode
        $categories = '';
      } else {
        $tabs = '
          <li class="search-tabs__entry search-tabs__entry--active"> 
            <a href="javascript:void(0)">' . __('Filter', static::$TEXT_DOMAIN) . '</a>
          </li>
          <li class="search-tabs__entry"> 
            <a href="' . static::getSiteSearchUrl() . '" class="run-website-search">' . __('Website', static::$TEXT_DOMAIN) . '</a>
          </li>
        ';
      }

      // Wrap the tabs output
      $tabs = '
        <div class="row"> 
          <div class="search-tabs"> <!-- col-12 col-md-10 offset-md-1 col-lg-8 offset-lg-2  -->
            <ul>' . $tabs . '</ul>
          </div>
        </div>
      ';
    }



    $template = __('Für', static::$TEXT_DOMAIN) . ' «<strong>{term}</strong>»';
    $html = '
      <section class="wp-block-wrapper wp-block-search-results container"> 
        <div class="row"> 
          <div class="search-term-title"> <!-- col-12 col-md-10 offset-md-1 col-lg-8 offset-lg-2 -->
            <p class="searched-term" data-template="' . htmlentities($template) . '"></p>
          </div>        
        </div>
      ' . $tabs . '
      </section>
      ' . $categories . '
    ';

    echo $html;
  }

  /**
   * No fixed field definitions yet
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_60b7983bef95a',
      'title' => 'Block: Suche',
      'fields' => array(
        array(
          'key' => 'field_60b79846f8928',
          'label' => 'Kontext',
          'name' => 'context',
          'type' => 'select',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'all' => 'Alle Suchergebnisse',
            'gss' => 'Nur Website Suchergebnisse',
          ),
          'default_value' => false,
          'allow_null' => 0,
          'multiple' => 0,
          'ui' => 0,
          'return_format' => 'value',
          'ajax' => 0,
          'placeholder' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'block',
            'operator' => '==',
            'value' => 'acf/aboon-search',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }
}