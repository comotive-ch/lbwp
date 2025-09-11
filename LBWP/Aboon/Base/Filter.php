<?php

namespace LBWP\Aboon\Base;

use LBWP\Aboon\Component\PackagingUnit;
use LBWP\Aboon\Component\Preorder;
use LBWP\Aboon\Component\Search;
use LBWP\Aboon\Component\SimpleVariations;
use LBWP\Aboon\Component\Watchlist;
use LBWP\Core;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Theme\Component\ACFBase;
use LBWP\Theme\Feature\FocusPoint;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provide the vast filter logic
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Filter extends ACFBase
{
  /**
   * @var int page id of the main filter application
   */
  public static $FILTER_PAGE_ID = 0;
  /**
   * @var int page id of the main filter application
   */
  public static $SEARCH_PAGE_ID = 0;
  /**
   * @var int maximum amount of initial filters shown
   */
  public static $FILTERS_INITIAL = 8;
  /**
   * @var int fallback main id, to calculate categories even in search #f context
   */
  public static $FIXED_FALLBACK_MAIN_ID = 0;
  /**
   * @var int where actual IDS start, below this value are virtual props
   */
  public static $VIRTUAL_PROPS = 0;
  /**
   * @var bool automatically filters after clicking filter checkboxes
   */
  public static $AUTO_UPDATE_ON_CLICK = false;
  /**
   * @var int where actual IDS start, below this value are virtual props
   */
  public static $SEARCH_THRESHOLD_INEXACT = 50;
  /**
   * @var string used for css and code prefixes
   */
  public static $THEME_PREFIX = '__need_override';
  /**
   * @var string the theme text domain
   */
  public static $TEXT_DOMAIN = '__need_override';
  /**
   * @var string the name of the block
   */
  public static $BLOCK_NAME = 'Produktefilter';
  /**
   * @var string[] the post types to use the filter block
   */
  public static $BLOCK_POST_TYPES = array('post', 'page');
  /**
   * @var string the queried post type we're working with
   */
  public static $POST_TYPE = 'product';
  /**
   * @var string[] list of taxonomies to be included in product map generation for the filter
   */
  public static $PROD_MAP_TAXONOMIES = array('product_prop','product_cat');
  /**
   * @var string default sorting
   */
  public static $DEFAULT_SORT = 'sells';
  /**
   * @var string the default sort field
   */
  public static $DEFAULT_SORT_DB_FIELD = 'post_date';
  /**
   * @var bool sort categories a-z
   */
  public static $SORT_CATEGORIES = true;
  /**
   * @var bool set to use hierarchy display in categories and properties
   */
  public static $USE_HIERARCHIES = false;
  /**
   * @var bool sort inner properties a-z (or leave sql orderby order if false
   */
  public static $SORT_PROPERTIES = true;
  /**
   * @var bool sort selections on top of each filter after reload
   */
  public static $SELECTIONS_ON_TOP = false;
  /**
   * @var bool reduce filters to select based on resultset
   */
  public static $AUTO_REDUCE_FILTERS = true;
  /**
   * @var bool use focus point for filter images
   */
  public static $USE_FOCUSPOINT = false;
  /**
   * @var bool sort by slug with natcasessort
   */
  public static $SORT_PROPERTIES_NATCASESLUG = false;
  /**
   * @var bool shows auto h1 in filters for m or s
   */
  public static $SHOW_AUTO_H1 = false;
  /**
   * @var bool to make some hacks for polylang use cases
   */
  public static $IS_POLYLANG = false;
  /**
   * @var array map of term_taxonomy_id of languages
   */
  public static $POLYLANG_TTID_MAP = array();
  /**
   * @var string
   */
  public static $PROPERTY_ORDERBY = 'none';
  /**
   * @var string
   */
  public static $PROPERTY_ORDER = 'DESC';
  /**
   * @var bool|callable a custom function to call on creating single html output
   */
  public static $CUSTOM_SINGLE_HTML_FUNCTION = false;
  /**
   * @var string the icon for non puchasable items
   */
  public static $PRODUCT_NON_PURCHASABLE_ICON = 'icon-chevron-right';
  /**
   * Default, redirect if one result, might night make sense for content results
   * @var bool if a single result should redirect to the product page
   */
  public static $FILTER_SINGLE_RESULT_REDIRECT = true;
  /**
   * @var array breadcrumb settings
   */
  public static $BREADCRUMBS = array(
    'active' => false,
    'home' => true, // show home and use home_page_id, int = use specific id
    'home_name' => 'Home',
    'use_full_url' => false,
    'single' => true, // if active, show on single page too
    'single_use_back' => false,
    'single_use_url' => false,
    'delimiter' => '>'
  );
  /**
   * @var string base of product category page
   */
  const CATEGORY_BASE = '/produkt-kategorie';
  /**
   * The zero one are just here for documentation, they are merged as-is
   * @var int[] Size factors to convert into smallest unit equivalent
   */
  protected static $sizeFactors = array(
    //'g' => 0,
    'kg' => 1000,
    //'mm' => 0,
    'cm' => 10,
    'm' => 100,
    //'ml' => 0,
    'cl' => 10,
    'dl' => 100,
    'l' => 1000
  );
  /**
   * @var string[] keys to merge same attributes into one
   */
  protected static $sizeMergeables = array(
    'Gewicht in g' => 'Gewicht',
    'Gewicht in kg' => 'Gewicht',
    'Durchmesser in mm' => 'Durchmesser',
    'Durchmesser in cm' => 'Durchmesser',
    'Länge in mm' => 'Länge',
    'Länge in cm' => 'Länge',
    'Länge in m' => 'Länge',
    'Höhe in mm' => 'Höhe',
    'Höhe in cm' => 'Höhe',
    'Höhe in m' => 'Höhe', // not existing yet
    'Breite in mm' => 'Breite',
    'Breite in cm' => 'Breite',
    'Breite in m' => 'Breite', // not existing yet
    'Füllmenge in ml' => 'Füllmenge',
    'Füllmenge in cl' => 'Füllmenge',
    'Füllmenge in dl' => 'Füllmenge',
    'Füllmenge in l' => 'Füllmenge',
  );

	/**
	 * Convert unit string to data-attribute
	 */
	protected static $unitAttribute = array(
		'Gewicht' => 'weight',
		'Durchmesser' => 'width',
		'Länge' => 'width',
		'Höhe' => 'width',
		'Breite' => 'width',
		'Füllmenge' => 'filling',
	);
  /**
   * At the moment a fixed list to only match certain properties for testing the feature
   * @var string[] If a search term directly matches a property item, it will be used as a filter
   */
  protected static $searchTraversableProperties = array(
    'Marke',
    //'Material', has yet the problem, that for example "glas" is a material but also a title component
    'Farbe',
    'Farbgruppe',
    'Inhaltsarten',
    'Entwicklungsfaktoren',
    'Serie'
  );
  /**
   * At the moment a fixed list to only match certain properties for testing the feature
   * @var string[] If a search term directly matches a property item, it will be used as a filter
   */
  protected static $searchFuzzyTraversableProperties = array(
    'Farbe',
    'Farbgruppe'
  );

  /**
   * We use the virtual id as sort number as well
   * @var string[]
   */
  protected static $mergeFields = array(
    'Durchmesser' => 35,
    'Länge' => 32,
    'Breite' => 33,
    'Höhe' => 34,
    'Gewicht' => 36,
    'Füllmenge' => 37
  );
  /**
   * @var array merge parent ids
   */
  protected static $mergeParents = array();
  protected static $mergeFactor = array();
  protected static $prodIds = array();
  /**
   * @var array filled on setup
   */
  protected static $text = array();

  protected $isShop = false;

  /**
   * Registers endpoints and filters
   */
  public function init()
  {
    if (is_string(static::$POST_TYPE)) {
      static::$POST_TYPE = array(static::$POST_TYPE);
    }

    parent::init();

    static::$text = array(
      'categories-name' => __('Kategorien', static::$TEXT_DOMAIN),
      'update-filter-button' => __('Anwenden', static::$TEXT_DOMAIN),
      'show-more-filters' => __('Weitere Filter anzeigen', static::$TEXT_DOMAIN),
      'show-less-filters' => __('Filter reduzieren', static::$TEXT_DOMAIN),
      'filter-name' => __('Filter', static::$TEXT_DOMAIN),
      'filter-search' => __('%s suchen', static::$TEXT_DOMAIN),
      'filter-reset' => __('Filter zurücksetzen', static::$TEXT_DOMAIN),
      'sort-label-desktop' => __('Produkte sortieren', static::$TEXT_DOMAIN),
      'sort-label-mobile' => __('Sortieren', static::$TEXT_DOMAIN),
      'sort-default' => __('Beliebteste', static::$TEXT_DOMAIN),
      'sort-stock' => __('Verfügbarkeit', static::$TEXT_DOMAIN),
      'sort-newest' => __('Neuste', static::$TEXT_DOMAIN),
      'sort-price-desc' => __('Teuerste', static::$TEXT_DOMAIN),
      'sort-price-asc' => __('Günstigste', static::$TEXT_DOMAIN),
      'x-active' => __('{x} Aktiv', static::$TEXT_DOMAIN),
      'add-to-cart' => __('In den Warenkorb', static::$TEXT_DOMAIN),
      'remove-all-filters' => __('Alle Filter entfernen', static::$TEXT_DOMAIN),
      'result-show-single' => __('1 Produkt anzeigen', static::$TEXT_DOMAIN),
      'result-show-multi' => __('{x} Produkte anzeigen', static::$TEXT_DOMAIN),
      'filter-result-count' => __('{x} von {y} Produkten', static::$TEXT_DOMAIN),
      'no-results-text' => __('Es wurden keine Produkte mit deiner Filterauswahl gefunden. Bitte passe deine Auswahl an.', static::$TEXT_DOMAIN)
    );

    // Check if woocommerce is active
    $this->isShop = Core::hasWooCommerce();

    // Get notices for add-to-cart ajax
    add_action('wc_ajax_get_notices', array($this, 'getWcNotices'));
    add_action('rest_api_init', array($this, 'registerApiEndpoint'));
    add_action('save_post_product', array($this, 'onSaveProduct'));
    add_action('transition_post_status', array($this, 'onTrashPost'), 10, 3);
    // Make sure to rebuild helper tables and caches
    if (LBWP_ABOON_ERP_PRODUCTIVE) {
      add_action('cron_daily_4', array($this, 'buildProductMap'));
      add_action('cron_daily_6', array($this, 'fixMissingProductMapData'));
      add_action('cron_daily_6', array($this, 'updateCaches'));
      add_action('cron_daily_12', array($this, 'updateCaches'));
      add_action('cron_daily_14', array($this, 'buildProductMap'));
      // Also run build product map, after importing via csv files
      add_action('aboon_after_ftpsv_manual_import', array($this, 'buildProductMap'));
    }

    if (static::$BREADCRUMBS['single']) {
      remove_all_actions('woocommerce_before_main_content');
      add_action('woocommerce_before_main_content', array($this, 'getSingleBreadCrumbHtml'));
    }

    // Filter into product listings from woocomm to adapt white and blacklist
    add_action('wp', function() {
      if (is_singular('product')) {
        add_filter('woocommerce_product_is_visible', array($this, 'applyLimitationToProductId'), 10, 2);
      }
    });

  }

  /**
   * Make sure that on trashing, entries in filter get removed immediately
   * @param $newStatus
   * @param $oldStatus
   * @param $post
   * @return void
   */
  public function onTrashPost($newStatus, $oldStatus, $post)
  {
    if ($newStatus === 'trash' && in_array($post->post_type, static::$POST_TYPE)) {
      // Remove all result caches the might have contained the post
      MemcachedAdmin::flushByKeyword('filter_request_');
    }
  }

  /**
   * Update various caches in background as they take a little long
   */
  public function updateCaches()
  {
    static::getPropertyTree(true);
    static::getPropertyTreeFull(true);
    static::getCategoryTree(true);
    static::getCategoryProductMap(true);
    static::getPromoPriceMap(true);
  }

  /**
   * @return void rbuilds the promo price map
   */
  public function onSaveProduct()
  {
    static::getPromoPriceMap(true);
  }

  /**
   * Register the aboon REST to get all the sales
   */
  public function registerApiEndpoint()
  {
    register_rest_route('custom/products', 'get', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getProductsHtml')
    ));
    register_rest_route('custom/products', 'variation', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getVariationData')
    ));
    register_rest_route('custom/products', 'notices', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'getLatestNotices')
    ));
    register_rest_route('custom/products', 'filter', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getFilterQuery')
    ));
  }

  /**
   * @return void
   */
  public function getWcNotices(){
    // Important get notices before printing them
    $notices = wc_get_notices();
    wp_send_json(array(
      'html' => wc_print_notices(true),
      'data' => $notices
    ));
    wc_clear_notices();
  }

  /**
   * @return array
   */
  public function getLatestNotices()
  {
    Shop::setApiUserContext();
    wc_load_cart(); // loads notice functions
    $notice = wc_get_notices();
    return array(
      'html' => wc_print_notices(true),
      'data' => $notice
    );
  }

  /**
   * Get data for a variation (minimum for filter)
   */
  public function getVariationData()
  {
    Shop::setApiUserContext();
    // Check if its even a shop, variations only work with woocommerce
    if (!$this->isShop) {
      return array();
    }

    $productId = intval($_GET['id']);
    $product = wc_get_product($productId);
    // if no instance of wc product, return empty
    if (!$product instanceof \WC_Product) {
      return array();
    }

		$inputArgs = PackagingUnit::getInputArgs($product);
		// Please don't insert linebreaks
		$qtyInput = '<input type="number" class="hide ' . implode(' ' , $inputArgs['classes']) . '" step="' . $inputArgs['step'] . '" min="' . $inputArgs['min_value'] . '" max="" name="quantity" value="' . $inputArgs['min_value'] . '" title="Menge" placeholder="" inputmode="numeric">';

    return apply_filters('aboon_filter_ajax_variation_data', array(
      'title' => static::filterProductTitle($product->get_title(), $productId),
      'subtitle' => static::getProductSubtitle($productId),
      'price' => $product->get_price_html(),
      'sku' => $product->get_sku(),
      'stock' => intval($product->get_stock_quantity()),
      'stockStatus' => $product->get_stock_status(),
      'image' => get_the_post_thumbnail($productId),
      'url' => get_permalink($productId),
			'puInput' => $qtyInput
    ), $product);
  }

  /**
   * @return array a list of products queried by selected categories
   */
  public function getFilterQuery()
  {
    Shop::setApiUserContext();
    $hasMoreFilters = false;
    $isSearch = false;
    $title = '';
    $cacheKey = '';
    $showAll = $_GET['showall'] == 1;
    $language = $_GET['lang'];
    $mainId = intval($_GET['m']);
    $secondaryId = intval($_GET['s']);
    $tertiaryIds = array_map('intval', isset($_GET['t']) ? explode(',', $_GET['t']) : array());
    $propIds = array_map('intval', isset($_GET['p']) ? explode(',', $_GET['p']) : array());
    $whitelist = static::getProductIdWhitelist();
    // Cacheable are all non-whitelist requests with m or m+s but not t, f or p parameters, also only for defautl sorting
    $isCacheable = $mainId > 0 && count($tertiaryIds) == 0 && count($propIds) == 0 && !$showAll && count($whitelist) == 0 && !isset($_GET['q']);
    // Try getting from cache if cacheable request
    if ($isCacheable) {
      $cacheKey = apply_filters('lbwp_filter_cache_key', 'filter_request_' . $mainId . '_' . $secondaryId . '_' . $_GET['sort'] . $language);
      $response = wp_cache_get($cacheKey, 'Filter');
      if (is_array($response) && isset($response['results'])) {
        $response['cached'] = true;
        return $response;
      }
    }

    // Use search or main/secondary category to get full possible set of results
    if (isset($_GET['f']) && strlen($_GET['f']) > 0) {
      $isSearch = true;
      $search = strip_tags($this->getSearchTerm());
      $words = explode(' ', $search);
      // If multiple words, try translating them into properties eventually
      if (count($words) > 1) {
        // Add the full search in front of words array, so that we eventually have an exact match
        $words = array_merge(array($search), $words);
        $matchedProperties = array();
        foreach ($words as $index => $word) {
          $propIds = $this->getPropIdsBySearchWord($word);
          if (count($propIds) > 0) {
            $matchedProperties = array_merge($matchedProperties, $propIds);
            $search = trim(str_replace($word, '', $search));
            // If first index (the full search), we can cancel the loop
            if ($index == 0) {
              break;
            }
          }
        }
        if (count($matchedProperties) > 0) {
          // Build a redirect url with changed search and direct property selection
          return array(
            'success' => true,
            'redirect' => get_permalink(static::$SEARCH_PAGE_ID) . '#f:' . $search . ';p:' . implode(',', $matchedProperties)
          );
        }
      }
      // Try an exact search first and try inexact if
      $productIds = $this->getSearchTermResults($search, true, false);
      // When nothing found, maybe try again if there is a correction to the search term with high certainty
      if (count($productIds) == 0 && !is_numeric($search)) {
        $index = Search::getSearchWordIndex();
        $alternate = Strings::getMostSimilarString($search, $index, true);
        if ($alternate != $search) {
          $productIds = $this->getSearchTermResults($alternate, true, true);
          $search = $alternate;
        }
      }

      // When polylang, we need to reduce with only post of our language
      if (static::$IS_POLYLANG) {
        $productIds = array_intersect($productIds, $this->getAllIdsOfType());
      }
      // Special case to show full list of products for whitelist shops
      if ($_GET['f'] == 'kundensortiment') {
        $title = 'Kundensortiment';
        $productIds = $this->prepareCustomerAssortment($whitelist);
        $isSearch = false;
      }
    } else if (isset($_GET['d'])) {
      list($from,$to) = explode('/', $_GET['d']);
      $productIds = $this->getProductyIdsByDateQuery($from, $to);
    } else if ($secondaryId > 0) {
      $productIds = $this->getProductIdsByProps(array($secondaryId));
    } else if ($mainId > 0) {
      // Special case if main==1, then get everything as this in an internal ID
      if ($mainId == 1) {
        $productIds = $this->getAllIdsOfType();
      } else {
        $productIds = $this->getProductIdsByProps(array($mainId));
      }
    } else if (count($propIds) > 0) {
      // This only happens for links to series, where initially one p is shown
      // TODO this is shaky, it should take all primary propIds, but not the further filters
      $productIds = $this->getProductIdsByProps($propIds, false);
    } else {
      // Nothing is selected anymore, take all products as base
      $productIds = array_values(Shop::getSkuMap());
    }
    // Maybe reduce the very first candidate resultset
    $productIds = apply_filters('lbwp_filter_product_ids_before', $productIds);

    // Generate a fallback title, for single tertiary/prop selections
    if (count($tertiaryIds) == 1) {
      $title = Strings::removeUntil(get_term_by('id', $tertiaryIds[0], 'product_cat')->name, '.');
    } else if (count($propIds) == 1) {
      $title = Strings::removeUntil(get_term_by('id', $propIds[0], 'product_prop')->name, '.');
    }

    // Reduce to whitelist if given
    if (count($whitelist) > 0) {
      $productIds = array_filter($productIds, function($id) use ($whitelist) {
        return in_array($id, $whitelist);
      });
    }

    // Now we have the full possible resultset, calculate the available properties
    $availablePropIds = $this->getPropertiesForResultset($productIds);
    $total = count($productIds);
    // Reduce with tertiary Ids if given (basically secondary AND any of tertiary)
    if (count($tertiaryIds) > 0) {
      $productIds = $this->reduceProductListByTermIds($productIds, $tertiaryIds);
    }
    // Reduce with properties if given (always an OR within group on the resultset)
    if (count($propIds) > 0) {
      $map = $this->calculatePropertyMap($propIds);
      foreach ($map as $tids) {
        $productIds = $this->reduceProductListByTermIds($productIds, $tids);
      }
    }

    // Use blacklist to remove all blacklisted products from resultset
    $blacklist = static::getProductIdBlacklist();
    foreach ($productIds as $key => $productId) {
      if (in_array($productId, $blacklist)) {
        unset($productIds[$key]);
        $total--;
      }
    }

    // Now we have the fully reduced set, calculate what is still selectable
    $results = count($productIds);
    if (static::$AUTO_REDUCE_FILTERS) {
      $selectablePropIds = $this->getPropertiesForResultset($productIds);
    } else {
      $selectablePropIds = $this->getAvailablePropIds();
    }
    // Always add pp properties if given as they are always selectable
    if (isset($_GET['pp'])) {
      foreach (array_map('intval', explode(',', $_GET['pp'])) as $propId) {
        $selectablePropIds[$propId]++;
      }
    }
    $whitelistedMainIds = array();
    if (static::$USE_HIERARCHIES) {
      $tree = static::getPropertyTree();
      foreach ($tree as $branch) {
        foreach ($branch['subs'] as $id => $subs) {
          $whiteListedMainIds[$id] = array_keys($subs);
        }
      }
    }

    // Everything matching the results is not selectable (as it would change the filter)
    if (static::$AUTO_REDUCE_FILTERS) {
      foreach ($selectablePropIds as $id => $matches) {
        if ($results == $matches && !in_array($id, $propIds)) {
          // When using hierarchies, main items that have subs selected are whitelisted from removal
          if (static::$USE_HIERARCHIES && isset($whiteListedMainIds[$id])) {
            if (ArrayManipulation::anyValueMatch($propIds, $whiteListedMainIds[$id])) {
              continue;
            }
          }

          unset($selectablePropIds[$id]);
        }
      }
    }

    // Eventually sort the productIds if needed
    if (isset($_GET['sort'])) {
      if (!$isSearch || ($isSearch && $_GET['sort'] != static::$DEFAULT_SORT)) {
        $productIds = $this->sortResultSet($productIds, $_GET['sort']);
      }
    }

    // And now group them to make them easier accessible in sizes
    $productIds = $this->groupProductIds($productIds, $whitelist, $blacklist);

    // If exactly one result and it was a term search, provide url to redirect
    $redirect = '';
    if (count($productIds) === 1 && static::$FILTER_SINGLE_RESULT_REDIRECT && isset($_GET['f']) && strlen($_GET['f']) > 0) {
      // Basically get the link
      $redirect = get_permalink(intval($productIds[0]));
      // And input the language tag if weglot and not default lang
      if (Multilang::isWeGlot() && strlen($language) == 2 && $language != 'de') {
        $homeUrl = get_bloginfo('url');
        $redirect = str_replace($homeUrl, $homeUrl . '/' . $language, $redirect);
      }
    }

    // Handle fixes main ID fallback if given
    if ($mainId == 0 && static::$FIXED_FALLBACK_MAIN_ID > 0) {
      $mainId = static::$FIXED_FALLBACK_MAIN_ID;
    }

    $response = array(
      'success' => $total > 0,
      'ids' => $productIds,
      'total' => $total,
      'html' => $this->getFullFilterHtml($mainId, $secondaryId, array_keys($availablePropIds), $tertiaryIds, $propIds, $selectablePropIds, $showAll, $hasMoreFilters),
      'tertiary' => $tertiaryIds,
      'selected' => array_merge($tertiaryIds, $propIds),
      'selectable' => array_keys($selectablePropIds),
      'morefilters' => $hasMoreFilters,
      'breadcrumbs' => $this->getBreadcrumbHtml($mainId, $secondaryId, $propIds),
      'redirect' => $redirect,
      'titleFallback' => $title,
      'cached' => false,
      'cachekey' => $cacheKey,
      //'memory' => memory_get_usage(true) / 1024 / 1024,
      'results' => $results
    );

    // Let developers add additional custom data to the filter
    $response = apply_filters('lbwp_filter_query_response', $response);

    // Maybe cache for two hours
    if ($isCacheable) {
      wp_cache_set($cacheKey, $response, 'Filter', 7200);
    }

    return $response;
  }

  /**
   * @param $from
   * @param $to
   * @return array|void
   */
  protected function getProductyIdsByDateQuery($from, $to)
  {
    if (!Strings::checkDate($from, Date::SQL_FORMAT_DATE) || !Strings::checkDate($to, Date::SQL_FORMAT_DATE)) {
      return array();
    }

    $db = WordPress::getDb();
    return array_map('intval', $db->get_col('
      SELECT ID FROM ' . $db->posts . ' WHERE post_type = "product"
      AND post_date_gmt BETWEEN "' . $from . '" AND "' . $to . '"
    '));
  }

  /**
   * @param $word
   * @return array
   */
  protected function getPropIdsBySearchWord($word)
  {
    $propIds = array();
    $word = strtolower($word);
    $tree = static::getPropertyTree();
    foreach ($tree as $branch) {
      if (in_array($branch['name'], static::$searchTraversableProperties)) {
        foreach ($branch['props'] as $id => $property) {
          if (mb_strtolower($property) == $word) {
            $propIds[] = $id;
          }
        }
      }
    }

    if (count($propIds) == 0) {
      $similarThreshold = strlen($word) > 3 ? 80 : 60;
      foreach ($tree as $branch) {
        if (in_array($branch['name'], static::$searchFuzzyTraversableProperties)) {
          foreach ($branch['props'] as $id => $property) {
            similar_text(mb_strtolower($property), $word, $percent);
            if ($percent >= $similarThreshold) {
              $propIds[] = $id;
            }
          }
        }
      }
    }

    return array_unique($propIds);
  }

  /**
   * @param $assortment
   * @return array
   */
  protected function prepareCustomerAssortment($assortment)
  {
    return $assortment;
  }

  /**
   * @return array all ids of the specified post type
   */
  protected function getAllIdsOfType()
  {
    $db = WordPress::getDb();
    // Do a normal or a polylang language query
    if (static::$IS_POLYLANG && isset($_GET['lang'])) {
      $ttId = intval(static::$POLYLANG_TTID_MAP[$_GET['lang']]);
      return array_map('intval', $db->get_col('
        SELECT ID FROM ' . $db->posts . ' INNER JOIN
        ' . $db->term_relationships . ' ON ' . $db->term_relationships . '.object_id = ' . $db->posts . '.ID
        WHERE post_type IN("' . implode('","', static::$POST_TYPE) . '") AND post_status = "publish"
        AND ' . $db->term_relationships . '.term_taxonomy_id = ' . $ttId . '
        ORDER BY ' . static::$DEFAULT_SORT_DB_FIELD . ' ASC
      '));
    } else {
      return array_map('intval', $db->get_col('
        SELECT ID FROM ' . $db->posts . '
        WHERE post_type IN("' . implode('","', static::$POST_TYPE) . '") AND post_status = "publish"
        ORDER BY ' . static::$DEFAULT_SORT_DB_FIELD . ' ASC
      '));
    }
  }

  /**
   * @return string html for single breadcrumb
   */
  public function getSingleBreadCrumbHtml()
  {
    $product = WordPress::getPost();
    $breadcrumbs = array();
    // Add home if given
    if (static::$BREADCRUMBS['home'] !== false) {
      $pageId = 0;
      if (static::$BREADCRUMBS['home'] === true) {
        $pageId = get_option('page_on_front');
      } else if (intval(static::$BREADCRUMBS['home']) > 0) {
        $pageId = intval(static::$BREADCRUMBS['home']);
      }

      // Add the home crumb
      $breadcrumbs[] = '<a href="' . get_permalink($pageId) . '">' . static::$BREADCRUMBS['home_name'] . '</a>';
    }

    // Get back link eventually
    if (static::$BREADCRUMBS['single_use_back'] !== false && is_singular('product') && strlen($_SERVER['HTTP_REFERER']) > 0) {
      echo '<span class="breadcrumb-back-config" data-template="Zurück zum Filter" data-delimiter="|"></span>';
    }

    // Get all terms of that object
    $categories = $this->getPropertiesForProduct($product->ID);
    $tree = static::getCategoryTree();
    $mainId = $secondaryId = 0;

    foreach ($tree as $main) {
      if (in_array($main['id'], $categories)) {
        $mainId = $main['id'];
        foreach ($main['sub'] as $secondary) {
          if (in_array($secondary['id'], $categories)) {
            $secondaryId = $secondary['id'];
            break;
          }
        }
        break;
      }
    }

    // Override with info from url if configured
    if (static::$BREADCRUMBS['single_use_url']) {
      $parts = array_filter(explode('/', $_SERVER['REQUEST_URI']));
      // Remove last and first
      array_pop($parts);
      array_shift($parts);
      $candidates = array();
      // Try the first item which can be a main category
      if (isset($parts[0])) {
        foreach ($tree as $main) {
          if ($main['slug'] == $parts[0]) {
            $mainId = $main['id'];
            // Reset as we MUST find one to be overridden es well
            $secondaryId = 0;
            $candidates = $main['sub'];
            breaK;
          }
        }
      }

      // Try secondary if we have candidates and a part
      if (isset($parts[1]) && count($candidates) > 0) {
        foreach ($candidates as $secondary) {
          if (Strings::endsWith($secondary['slug'], $parts[1])) {
            $secondaryId = $secondary['id'];
            breaK;
          }
        }
      }
    }

    if ($mainId > 0) {
      $term = $this->getCategoryById($mainId);
      $breadcrumbs[] = '<a href="' . get_term_link($mainId) . '#m:' . $mainId. '">' . $term['name'] . '</a>';
    }
    if ($secondaryId > 0) {
      $term = $this->getCategoryById($secondaryId);
      $breadcrumbs[] = '<a href="' . get_term_link($secondaryId) . '#m:' . $mainId. ';s:' . $secondaryId . '">' . $term['name'] . '</a>';
    }

    echo $this->getBreacrumbListHtml($breadcrumbs);
  }

  /**
   * @param $mainId
   * @param $secondaryId
   * @param $propIds
   * @return string
   */
  protected function getBreadcrumbHtml($mainId, $secondaryId, $propIds)
  {

    if (!static::$BREADCRUMBS['active']) {
      return '';
    }
    if ($mainId == 0 && $secondaryId == 0 && count($propIds) == 0) {
      return '';
    }

    $breadcrumbs = array();
    // Add home if given
    if (static::$BREADCRUMBS['home'] !== false) {
      $pageId = 0;
      if (static::$BREADCRUMBS['home'] === true) {
        $pageId = get_option('page_on_front');
      } else if (intval(static::$BREADCRUMBS['home']) > 0) {
        $pageId = intval(static::$BREADCRUMBS['home']);
      }

      // Add the home crumb
      $breadcrumbs[] = '<a href="' . get_permalink($pageId) . '">' . static::$BREADCRUMBS['home_name'] . '</a>';
    }

    // Let devs filter breadcrumbs right here to add after home
    $breadcrumbs = apply_filters('lbwp_filter_breadcrumbs_after_home', $breadcrumbs, $mainId, $secondaryId, $propIds);

    // When main or secondary is set, display those
    if ($mainId > 0 || $secondaryId > 0) {
      if ($mainId > 0) {
        $url = '';
        if (static::$BREADCRUMBS['use_full_url']) {
          $url = get_term_link($mainId, 'product_cat');
        }
        $term = $this->getCategoryById($mainId);
        if ($term !== false)
          $breadcrumbs[] = '<a href="' .  $url . '#m:' . $mainId. '">' . $term['name'] . '</a>';
      }
      if ($secondaryId > 0) {
        $url = '';
        if (static::$BREADCRUMBS['use_full_url']) {
          $url = get_term_link($secondaryId, 'product_cat');
        }
        $term = $this->getCategoryById($secondaryId);
        if ($term !== false)
          $breadcrumbs[] = '<a href="' . $url . '#m:' . $mainId. ';s:' . $secondaryId . '">' . $term['name'] . '</a>';
      }
    } else if (count($propIds) == 1) {
      // When exactly one prop is set, display that
      $name = $this->getPropertyById($propIds[0]);
      $breadcrumbs[] = '<a href="#p:' . $propIds[0]. '">' . $name . '</a>';
    }

    return $this->getBreacrumbListHtml($breadcrumbs);
  }

  /**
   * @param array $breadcrumbs
   * @return string
   */
  public function getBreacrumbListHtml($breadcrumbs)
  {
    // Create html with delimiters
    $key = count($breadcrumbs) - 1;
    $html = '<ul class="filter__breacrumb">';
    foreach ($breadcrumbs as $id => $crumb) {
      $last = $id != $key;
      $html .= '<li class="item' . ((!$last) ? ' last' : '') . '">' . $crumb . '</li>';
      if ($last) {
        $html .= '<li class="delimiter">' . static::$BREADCRUMBS['delimiter'] . '</li>';
      }
    }
    $html.= '</ul>';

    return $html;
  }

  /**
   * @param $id
   * @return mixed|void
   */
  protected function getCategoryById($id)
  {
    $tree = static::getCategoryTree();
    foreach ($tree as $main) {
      if ($main['id'] == $id) {
        return $main;
      }
      foreach ($main['sub'] as $secondary) {
        if ($secondary['id'] == $id) {
          return $secondary;
        }
        foreach ($secondary['sub'] as $tertiary) {
          if ($tertiary['id'] == $id) {
            return $secondary;
          }
        }
      }
    }

    return false;
  }

  /**
   * @param $id
   * @return mixed|void
   */
  protected function getPropertyById($id, &$propName = NULL)
  {
    $tree = static::getPropertyTree();
    foreach ($tree as $prop) {
      foreach ($prop['props'] as $propId => $name) {
        if ($propId == $id) {
          $propName = $prop['name'];
          return $name;
        }
      }
    }

    return false;
  }

  /**
   * @param string $search the search term
   */
  protected function getTermIdsBySearchTerm($search)
  {
    $termIds = array();
    $search = htmlentities($search);
    foreach (static::getCategoryTree() as $mainId => $tree) {
      if (stristr($tree['name'], $search) !== false) {
        $termIds[] = $mainId;
      }
      // Only look in the second/third hierarchy
      foreach ($tree['sub'] as $secId => $secTree) {
        // See if the hierarchiv itself matches
        if (stristr($secTree['name'], $search) !== false) {
          $termIds[] = $secId;
        }
        foreach ($secTree['sub'] as $terId => $terTree) {
          if (stristr($terTree['name'], $search) !== false) {
            $termIds[] = $terId;
          }
        }
      }
    }

    // Also look for exact matches of properties
    foreach (Filter::getPropertyTree() as $property) {
      if ($property['config']['type'] == 'text' && $property['config']['visible']) {
        foreach ($property['props'] as $id => $item) {
          if (stristr($item, $search) !== false) {
            $termIds[] = $id;
          }
        }
      }
    }

    return $termIds;
  }

  /**
   * @param array $productIds
   * @return array
   */
  protected function groupProductIds($productIds, $whitelist, $blacklist)
  {
    $hasWhitelist = count($whitelist) > 0;
    $groups = array();
    $skuMap = Shop::getSkuMap();
    // Get article relations directly from db
    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT post_id, meta_value FROM ' . $db->postmeta . '
      WHERE meta_key LIKE "attribute-relation-skus"
      AND LENGTH(meta_value) > 10
      AND post_id IN(' . implode(',', $productIds) . ')
    ');
    $relations = array();
    foreach ($raw as $row) {
      $relations[$row->post_id] = array();
      foreach (unserialize($row->meta_value) as $sku) {
        // When on blacklist, don't add
        if (in_array($skuMap[$sku], $blacklist))
          continue;
        // When positive whitelist and not on whitelist
        if ($hasWhitelist && !in_array($skuMap[$sku], $whitelist))
          continue;
        // When passed, add to relations
        $relations[$row->post_id][] = $skuMap[$sku];
      }
    }

    $shadow = $productIds;

    foreach ($productIds as $id => $productId) {
      if ($shadow[$id] === false) continue;
      $element = $productId;
      if (isset($relations[$productId])) {
        foreach ($relations[$productId] as $relationId) {
          $element .= ',' . $relationId;
          $search = array_search($relationId, $productIds);
          if (isset($productIds[$search])) {
            $shadow[$search] = false;
          }
        }
      }
      $groups[] = $element;
    }

    return $groups;
  }

  /**
   * @param $productIds
   * @param $sort
   * @return array|mixed
   */
  protected function sortResultSet($productIds, $sort)
  {
    $sort = Strings::forceSlugString($sort);

    switch ($sort) {
      case 'sells':
      case 'sort-display':
        $type = 'DESC';
        $field = '_sells';
        if ($sort != 'sells') {
          $field = $sort;
          $type = 'ASC';
        }
        // Do a query on the base price (not customer price, too expensive)
        $db = WordPress::getDb();
        // meta_value*1 allows mysql to sort correctly as float cast
        $sorted = $db->get_col('
          SELECT post_id FROM ' . $db->postmeta . ' WHERE post_id IN (' . implode(',', $productIds) . ')
          AND meta_key = "' . $field . '" ORDER BY meta_value*1 ' . $type . '
        ');
        // Add new products that aren't sorted yet again to the array
        if (count($sorted) < count($productIds)) {
          foreach ($productIds as $id) {
            if (!in_array($id, $sorted)) {
              $sorted[] = $id;
            }
          }
        }
        return $sorted;

      case 'stock':
        // Do a query on the base price (not customer price, too expensive)
        $db = WordPress::getDb();
        // meta_value*1 allows mysql to sort correctly as float cast
        $sorted = $db->get_col('
          SELECT post_id FROM ' . $db->postmeta . ' WHERE post_id IN (' . implode(',', $productIds) . ')
          AND meta_key = "_stock" ORDER BY meta_value*1 DESC
        ');
        // Add new products that aren't sorted yet again to the array
        if (count($sorted) < count($productIds)) {
          foreach ($productIds as $id) {
            if (!in_array($id, $sorted)) {
              $sorted[] = $id;
            }
          }
        }
        return $sorted;

      case 'newest':
        $db = WordPress::getDb();
        return $db->get_col('
          SELECT ID FROM ' . $db->posts . '
          WHERE ID IN (' . implode(',', $productIds) . ')
          ORDER BY post_date DESC
        ');
        break;

      case 'price-asc':
      case 'price-desc':
        list($type, $order) = explode('-', $sort);
        // Do a query on the base price (not customer price, too expensive)
        $db = WordPress::getDb();
        // meta_value*1 allows mysql to sort correctly as float cast
        return $db->get_col('
          SELECT post_id FROM ' . $db->postmeta . ' WHERE post_id IN (' . implode(',', $productIds) . ')
          AND meta_key = "_regular_price" ORDER BY meta_value*1 ' . strtoupper($order) . '
        ');
        break;
    }

    return $productIds;
  }

  /**
   * @param $pids
   * @param $tids
   * @return array
   */
  protected function reduceProductListByTermIds($pids, $tids)
  {
    $db = WordPress::getDb();
    return array_map('intval', $db->get_col('
      SELECT DISTINCT pid FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE tid IN(' . implode(',', $tids) . ')
      AND pid IN(' . implode(',', $pids) . ')
    '));
  }

  /**
   * @param array $propIds list of properties
   */
  protected function calculatePropertyMap($propIds)
  {
    $map = array();
    $tree = static::getPropertyTree();

    foreach ($tree as $key => $property) {
      $candidates = array_keys($property['props']);
      if (isset($property['subs'])) {
        foreach ($candidates as $candidateId) {
          if (isset($property['subs'][$candidateId])) {
            $candidates = array_merge($candidates, array_keys($property['subs'][$candidateId]));
          }
        }
      }
      foreach ($propIds as $id => $propId) {
        if (in_array($propId, $candidates)) {
          $map[$key][] = $propId;
          unset($propIds[$id]);
        }
      }
      // If propIds empty, break as we're done early
      if (count($propIds) == 0) {
        break;
      }
    }

    return $map;
  }

  /**
   * @param $pids
   * @return array key value of tid > count
   */
  protected function getPropertiesForResultset($pids)
  {
    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT tid, COUNT(tid) AS matches FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE pid IN(' . implode(',', $pids) . ') GROUP BY tid
    ');

    $result = array();
    foreach ($raw as $row) {
      $result[$row->tid] = $row->matches;
    }

    return array_map('intval', $result);
  }

  /**
   * @param $pids
   * @return array key value of tid > count
   */
  protected function getAvailablePropIds()
  {
    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT DISTINCT tid FROM ' . $db->prefix . 'lbwp_prod_map
    ');

    $result = array();
    foreach ($raw as $row) {
      $result[$row->tid] = 1;
    }

    return array_map('intval', $result);
  }

  /**
   * @param $pid
   * @return array of matching tids
   */
  protected function getPropertiesForProduct($pid)
  {
    $db = WordPress::getDb();
    return array_map('intval', $db->get_col('
      SELECT tid FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE pid = ' . $pid . '
    '));
  }

  /**
   * @param $tid
   * @return array
   */
  public static function getProductIdsByTid($tid)
  {
    $db = WordPress::getDb();
    return array_map('intval', $db->get_col('
      SELECT pid FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE tid = ' . $tid . '
    '));
  }

  /**
   * @param array $tids an array of term ids
   */
  public function getProductIdsByProps($tids, $and = true)
  {
    $db = WordPress::getDb();
    $results = $db->get_results('
      SELECT pid, tid FROM ' . $db->prefix . 'lbwp_prod_map
      WHERE tid IN(' . implode(',', $tids) . ')
    ');

    // Simply return full list, when only one tid is selected
    if (count($tids) === 1) {
      $productIds = array();
      foreach ($results as $result) $productIds[] = $result->pid;
      return array_map('intval', $productIds);
    }

    // If multiple, products with any or all tids are returned
    if ($and) {
      $temp = $productIds = array();
      $needed = count($tids);
      foreach ($results as $result) {
        $temp[$result->pid]++;
      }
      foreach ($temp as $pid => $matches) {
        if ($matches === $needed) {
          $productIds[] = $pid;
        }
      }
    } else {
      $productIds = array();
      foreach ($results as $result) $productIds[] = $result->pid;
      $productIds = array_unique($productIds);
    }

    return array_map('intval', $productIds);
  }

  /**
   * @param array $tree
   * @return array $tertiaries
   */
  public static function getTertiariesFromTree($tree)
  {
    $tertiaries = array();
    foreach ($tree as $branch) {
      foreach ($branch['sub'] as $subbranch) {
        $tertiaries = array_merge($tertiaries, $subbranch['sub']);
      }
    }

    return $tertiaries;
  }

  /**
   * @param int $mainId
   * @param int $secondaryId
   * @param int[] $properties
   * @param int[] $tertiaryIds
   * @param int[] $propIds
   * @param int[] $selectablePropIds
   * @param bool $showAll
   * @param bool $hasMoreFilters
   * @return string html for the filtering
   */
  public function getFullFilterHtml($mainId, $secondaryId, $properties, $tertiaryIds, $propIds, $selectablePropIds, $showAll, &$hasMoreFilters)
  {
    $countFilters = 0;
    $desktop = $mobile = array();

    // First, build the category selection from secondary hierarchy
    if ($mainId > 0) {
      $countFilters++;
      $tree = static::getCategoryTree()[$mainId];
      $nudge = $inner = $counter = array();
      // Need to calculate doubles when we show all categories of a main
      if ($secondaryId == 0) {
        foreach ($tree['sub'] as $category) {
          foreach ($category['sub'] as $filter) {
            $counter[$filter['name']]++;
          }
        }
      }

      // Generate the tree
      foreach ($tree['sub'] as $tempMainId => $category) {
        if ($tempMainId == $secondaryId || $secondaryId == 0) {
          if (count($category['sub']) > 0) {
            // Still display the container category in hierarchy mode
            if (static::$USE_HIERARCHIES) {
              $inner[$category['name'] . $category['id']] = '
                <div class="filter-item has-subcategories">
                  <label for="has-subs-' . $category['id'] . '">
                    <input type="checkbox" name="hs[]" data-type="t" id="has-subs-' . $category['id'] . '" value="' . $category['id'] . '" />
                    <div class="filter-checkbox">' . $category['name'] . '</div>
                  </label>
                </div>         
              ';
              if (in_array($category['id'], $tertiaryIds)) {
                $nudge[$category['id']] = $category['name'];
              }
            }

            foreach ($category['sub'] as $id => $filter) {
              $classes = 'filter-item';
              $displayName = $filter['name'] . (($counter[$filter['name']] > 1) ? ' (' . $category['name'] . ')' : '');
              if (static::$USE_HIERARCHIES && $counter[$filter['name']] > 0) {
                $classes .= ' subcategory-item';
                $displayName = $filter['name'];
              }
              $inner[$filter['name'] . $id] = '
                <div class="' . $classes . '" data-sub-of="' . $category['id'] . '">
                  <label for="term-' . $id . '" tabindex="0">
                    <input type="checkbox" name="t[]" id="term-' . $id . '" value="' . $id . '" />
                    <div class="filter-checkbox">' . $displayName . '</div>
                  </label>
                </div>         
              ';
              // Add the nudge if selected category
              if (in_array($id, $tertiaryIds)) {
                $nudge[$id] = $filter['name'];
              }
            }
          } else {
            if ($secondaryId == 0) {
              $inner[$category['name'] . $category['id']] = '
                <div class="filter-item">
                  <label for="term-' . $category['id'] . '" tabindex="0">
                    <input type="checkbox" name="t[]" id="term-' . $category['id'] . '" value="' . $category['id'] . '" />
                    <div class="filter-checkbox">
                      ' . $category['name'] . '
                    </div>
                  </label>
                </div>         
              ';
              // Add the nudge if selected category
              if (in_array($category['id'], $tertiaryIds)) {
                $nudge[$category['id']] = $category['name'];
              }
            }
          }
        }
      }

      if (count($inner) > 0) {

        // Sort by key (hence, by name)
        if (static::$SORT_CATEGORIES) {
          ksort($inner);
        }
        // Mobile entrypoint for the secondary category if given
        $mobile[] = '
          <li class="entrypoint-list__entry entrypoint-list__category" style="display:none">
            <a class="list-entry__inner mobile-filter" data-filter-id="' . $category['id'] . '">
              <span class="list-entry__title">' . static::$text['categories-name'] . '</span>
              ' . $this->getNudgeHtml($nudge, 'mobile') . '
              <div class="list-entry__arrow">' . static::icon('icon-chevron-right', '', false) . '</div>
            </a>
          </li>
        ';

        $desktop[] = '      
          <div class="single-filter single-filter__wrapper single-filter__cat single-filter__selection" data-filter-id="' . $category['id'] . '"> 
            <div class="single-filter__inner">
              <div class="single-filter__header" tabindex="0">
                <div class="single-filter__headerbar">
                  <div class="filter-icon filter-icon__back">' . static::icon('icon-chevron-left', '', false) . '</div>
                  <span class="filter-title">' . static::$text['categories-name'] . '</span>
                  ' . $this->getNudgeHtml($nudge, 'desktop') . '
                  <div class="filter-icon filter-icon__close">' . static::icon('icon-close', '', false) . '</div>
                </div>
                ' . static::icon('icon-caret-down', 'filter-caret', false) . '
              </div>
              <div class="single-filter__content"> 
                <div class="filter-content__search"> 
                  <div class="search-input">
                    <input type="text" placeholder="' . sprintf(static::$text['filter-search'], static::$text['categories-name']) . '" >                    
                  </div>
                </div>
                <div class="filter-content__list"> 
                  ' . implode('', $inner) . '
                </div>   
                <div class="filter-content__footer"> 
                  <button class="btn btn-primary update-filter" tabindex="0">' . static::$text['update-filter-button'] . '</button>
                </div>             
              </div>
            </div>          
          </div>        
        ';
      }
    }

    // Also build html for all properties that are selectable
    $tree = static::getPropertyTree();
    // Sort selected properties to the beginning
    if (count($propIds) > 0 && static::$AUTO_REDUCE_FILTERS) {
      $sort = 10000;
      foreach ($tree as $id => $prop) {
        $tree[$id]['sort'] = ++$sort;
        if (ArrayManipulation::anyValueMatch($propIds, array_keys($prop['props']))) {
          $tree[$id]['sort'] -= 10000;
        }
      }
      // Sort by the new field
      ArrayManipulation::sortByNumericFieldPreserveKeys($tree, 'sort');
    }

    foreach ($tree as $id => $prop) {
      if (!$prop['config']['visible']) {
        continue;
      }

      // Below X are legitimate virtual properties
      if (in_array($id, $properties) || $id < static::$VIRTUAL_PROPS) {
        // We can only check this if we exceed the maximum
        if (!$showAll && ($countFilters - 1) == static::$FILTERS_INITIAL) {
          $hasMoreFilters = true;
          break;
        }
        $innerCount = 0;
        $inner = '';
        $nudge = array();
        foreach ($prop['props'] as $innerId => $name) {
          if (!isset($selectablePropIds[$innerId])) {
            continue;
          }

          // Display prop with subs if given
          if (static::$USE_HIERARCHIES && count($prop['subs']) > 0 && isset($prop['subs'][$innerId])) {
            $inner .= '
              <div class="filter-item has-subcategories">
                <label for="has-subs-' . $innerId . '">               
                  <input type="checkbox" name="hs[]" id="has-subs-' . $innerId . '" value="' . $innerId . '" data-type="p" data-filter-value="' . trim($name) . '"/>
                  <div class="filter-checkbox">
                    ' . $name . '
                  </div>
                </label>
              </div>
            ';
            ++$innerCount;
            // Add the nudge if selected category
            if (in_array($innerId, $propIds)) {
              $nudge[$innerId] = $name;
            }
            // And also add the sub items
            foreach ($prop['subs'][$innerId] as $subId => $sub) {
              if (!isset($selectablePropIds[$subId])) {
                continue;
              }

              $inner .= '
                <div class="filter-item subcategory-item" data-sub-of="' . $innerId . '">
                  <label for="term-' . $subId . '" tabindex="0">               
                    <input type="checkbox" name="p[]" id="term-' . $subId . '" value="' . $subId . '" data-filter-value="' . trim($sub) . '"/>
                    <div class="filter-checkbox">
                      ' . $sub . '
                    </div>
                  </label>
                </div>
              ';
              ++$innerCount;
              // Add the nudge if selected category
              if (in_array($subId, $propIds)) {
                $nudge[$subId] = $sub;
              }
            }
          } else {
            // Just a normal single way prop
            $inner .= '
              <div class="filter-item">
                <label for="term-' . $innerId . '" tabindex="0">               
                  <input type="checkbox" name="p[]" id="term-' . $innerId . '" value="' . $innerId . '" data-filter-value="' . trim($name) . '"/>
                  <div class="filter-checkbox">
                    ' . $name . '
                  </div>
                </label>
              </div>
            ';
            ++$innerCount;
            // Add the nudge if selected category
            if (in_array($innerId, $propIds)) {
              $nudge[$innerId] = $name;
            }
          }
        }

        // Skip if there is nothing to select
        if ($innerCount == 0 || ($prop['config']['type'] == 'number' && $innerCount <= 1)) {
          continue;
        }

        // Only now count the filter, as it will be shown
        $countFilters++;
        $mobile[] = '
          <li class="entrypoint-list__entry" style="display:none">
            <a class="list-entry__inner mobile-filter" data-filter-id="' . $prop['id'] . '">
              <span class="list-entry__title">' . $prop['name'] . '</span>
              ' . $this->getNudgeHtml($nudge, 'mobile') . '
              <div class="list-entry__arrow">' . static::icon('icon-chevron-right', '', false) . '</div>
            </a>
          </li>
        ';

        $desktop[] = '
          <div 
						class="single-filter single-filter__wrapper single-filter__prop single-filter__selection" 
						style="display:none" 
						data-filter-id="' . $prop['id'] . '" 
						data-type="' . $prop['config']['type'] . '" 
						' . (self::$unitAttribute[$prop['name']] !== null ? ' data-unit="' . self::$unitAttribute[$prop['name']] . '"' : '') . '
					> 
            <div class="single-filter__inner">
              <div class="single-filter__header" tabindex="0"> 
                <div class="single-filter__headerbar">
                  <div class="filter-icon filter-icon__back">' . static::icon('icon-chevron-left', '', false) . '</div>
                  <span class="filter-title">' . $prop['name'] . '</span>
                  ' . $this->getNudgeHtml($nudge, 'desktop') . '
                  <div class="filter-icon filter-icon__close">' . static::icon('icon-close', '', false) . '</div>
                </div>
                ' . static::icon('icon-caret-down', 'filter-caret', false) . '
              </div>
              <div class="single-filter__content"> 
                <div class="filter-content__search"> 
                  <div class="search-input">
                    <input type="text" placeholder="' . sprintf(static::$text['filter-search'], $prop['name']) . '">                   
                  </div>
                </div>
                <div class="filter-content__list"' . ((isset($prop['config']['translate']) && !$prop['config']['translate']) ? ' data-wg-notranslate' : '') . '> 
                  ' . $inner . '
                </div>       
                <div class="filter-content__footer"> 
                  <button class="btn btn-primary update-filter" tabindex="0">' . static::$text['update-filter-button'] . '</button>
                </div>       
              </div>
            </div>          
          </div>
        ';
      }
    }

    // If we don't show all it seems we dont have more filters, remove one, if we actually exeeeded the max
    if (!$showAll && !$hasMoreFilters && $countFilters > static::$FILTERS_INITIAL) {
      array_pop($desktop);
      $hasMoreFilters = true;
    }

    // Add more filters link  or less filters link eventually
    if (!$showAll) {
      // If there is more, add a link to show more to both arrays
      if ($hasMoreFilters) {
        // Remove last entry from desktop (mobile not necessary), as design only works that way
        array_pop($desktop);
        // Add entry to both arrays
        $desktop[] = '<div class="filter-expand filter-expand--desktop"><a href="javascript:void(0)" class="show-all-filters">' . static::$text['show-more-filters'] . '</a></div>';
        $mobile[] = '<li class="filter-expand filter-expand--mobile"><a href="javascript:void(0)" class="show-all-filters">' . static::$text['show-more-filters'] . '</a></li>';
      }
    } else {
      // We show all, add less filters link to both arrays
      $desktop[] = '<div class="filter-expand filter-expand--desktop"><a href="javascript:void(0)" class="show-less-filters">' . static::$text['show-less-filters'] . '</a></div>';
      $mobile[] = '<li class="filter-expand filter-expand--mobile"><a href="javascript:void(0)" class="show-less-filters">' . static::$text['show-less-filters'] . '</a></li>';
    }

    // Build html from $desktop variables
    $html = '';
    if (static::$SHOW_AUTO_H1) {
      $title = '';
      if ($secondaryId > 0) {
        $title = $this->getCategoryById($secondaryId)['name'];
      } else if ($mainId > 0) {
        $title = $this->getCategoryById($mainId)['name'];
      }
      if (strlen($title) > 0) {
        $html .= '<div class="container single-filter__title"><h1 class="color-primary">' . $title . '</h1></div>';
      }
    }

    // Add Desktop html after title
    $html .= implode(PHP_EOL, $desktop);
    // Wrap and add mobile entrypoints after desktop filters
    $html .= '
      <div class="filter-entrypoint single-filter single-filter__wrapper"> 
        <div class="single-filter__inner">
          <div class="single-filter__header" tabindex="0"> 
            <div class="single-filter__headerbar">
              <div class="filter-icon filter-icon__back">' . static::icon('icon-chevron-right', '', false) . '</div>
              <span class="filter-title">' . static::$text['filter-name'] . '</span>
              <div class="filter-icon filter-icon__close">' . static::icon('icon-times', '', false) . '</div>
            </div>
            ' . static::icon('icon-caret-down', 'filter-caret', false) . '
          </div>
          <div class="single-filter__content"> 
            <div class="filter-summary"> 
              <strong class="filter-summary__number filter-summary__number--active" data-template="' . static::$text['x-active'] . '"></strong>
              <a class="filter-summary__reset" style="display:none">' . static::$text['remove-all-filters'] . '</a>
            </div>
            <div class="filter-content__list">                 
              <ul class="entrypoint-list">' . implode(PHP_EOL, $mobile) . '</ul>                  
            </div>       
            <div class="filter-content__footer"> 
              <button class="btn btn-primary show-results" data-template="' . static::$text['result-show-multi'] . '" data-template-single="' . static::$text['result-show-single'] . '"></button>
            </div>       
          </div>
        </div>          
      </div>
    ';

    return $html;
  }

  /**
   * @param array $nudge
   * @param string $context either mobile or desktop
   * @return string
   */
  public function getNudgeHtml($nudge, $context)
  {
    if (count($nudge) == 0) {
      return '';
    }

    // Get first name of nudge info and count what is left
    $name = array_shift($nudge);
    $count = count($nudge);

    $html = '<div class="filter-nudge is-' . $context . '"><div class="filter-nudge__inner"><span>' . $name . '</span>';
    if ($count > 0) $html .= ' <strong class="nudge-counter">+' . $count . '</strong>';

    $html .= '</div><div class="reset-according-filters">' . static::icon('icon-close', '', false) . '</div>';
    $html .= '</div>';

    return $html;
  }

  /**
   * @return array a list of products queried by selected categories
   */
  public function getProductsHtml()
  {
    Shop::setApiUserContext();
    $prodIds = array();
    foreach ($_GET['products'] as $candidate) {
      $products = explode(',', $candidate);
      $mainId = array_shift($products);
      $prodIds[$mainId] = array();
      if (count($products) > 0) {
        $prodIds[$mainId] = $products;
      }
    }

    do_action('lbwp_aboon_filter_before_generate_product_html', array_keys($prodIds));
    // Retrieve html for a set of products
    $html = '';
    self::$prodIds = array_values(Shop::getSkuMap());
    if (is_callable(static::$CUSTOM_SINGLE_HTML_FUNCTION)) {
      foreach ($prodIds as $productId => $relations) {
        $html .= call_user_func(static::$CUSTOM_SINGLE_HTML_FUNCTION, $productId);
      }
    } else {
      // Use the default method
      foreach ($prodIds as $productId => $relations) {
        $product = wc_get_product($productId);
        $html .= self::getSingleProductHtml($product, $relations);
      }
    }

    return array(
      'success' => strlen($html) > 0,
      'html' => $html,
      'button' => ''
    );
  }

  /**
   * Register blocks with ACF
   */
  public function blocks()
  {
    $this->registerBlock(array(
      'name' => static::$THEME_PREFIX . '-filter',
      'icon' => 'store',
      'title' => __(static::$BLOCK_NAME, static::$TEXT_DOMAIN),
      'preview' => false,
      'description' => __('Zeigt Produkte an', static::$TEXT_DOMAIN),
      'render_callback' => array($this, 'getFilterBaseHtml'),
      'post_types' => static::$BLOCK_POST_TYPES,
      'category' => 'theme',
    ));
  }

  /**
   * @param array $block
   */
  public function getFilterBaseHtml($block)
  {
    $html = $nav = '';
    // Enqueue scripts and other dependencies
    if (!is_admin() && !acf_is_block_editor()) {
      wp_enqueue_script('jquery-mobile-events');
      wp_enqueue_script('wc-cart-fragments');
    }

    $tree = static::getCategoryTree();
    // Remove what we don't need in categoryTree JS object
    foreach ($tree as $mainId => $mainCategory) {
      foreach ($mainCategory['sub'] as $secondaryId => $secondaryCategory) {
        unset($tree[$mainId]['sub'][$secondaryId]['image']);
        unset($tree[$mainId]['sub'][$secondaryId]['slug']);
        foreach ($secondaryCategory['sub'] as $tertiaryId => $tertiaryCategory) {
          unset($tree[$mainId]['sub'][$secondaryId]['sub'][$tertiaryId]['image']);
          unset($tree[$mainId]['sub'][$secondaryId]['sub'][$tertiaryId]['slug']);
        }
      }
    }

    // Also add configs for the block to operate correctly
    $html .= '
      <script type="text/javascript">
        filterBaseSettings = ' . json_encode(array(
        'preloadHash' =>  $block['data']['preload-hash'],
        'language' => (Multilang::isWeGlot()) ? Multilang::getWeGlotLanguage() : Multilang::getCurrentLang(),
        'sortDefault' => static::$DEFAULT_SORT,
        'selectionsOnTop' => static::$SELECTIONS_ON_TOP,
        'autoUpdate' => static::$AUTO_UPDATE_ON_CLICK,
        'autoReduceFilters' => static::$AUTO_REDUCE_FILTERS,
        'useFocuspoint' => static::$USE_FOCUSPOINT,
        'isSingular' => is_singular() && !in_array(WordPress::getPostId(), array(static::$FILTER_PAGE_ID, static::$SEARCH_PAGE_ID)),
        'categoryTree' => $tree
      )) . '
      </script>
    ';

    // Wrap this in main template
    $html .= '
      <section class="wp-block-wrapper wp-block-product-filter">
        ' . apply_filters('lbwp_filter_block_before_filters', '') . '
        <section class="lbwp-wc__filter-breadcrumbs row"></section>
        <section class="lbwp-wc__product-filter row"></section>
        <section class="filter__wrapper">
          <div class="row"> 
            <div class="col-12 col-md-10 col-lg-8 offset-md-1 offset-lg-2 filter__header"></div>           
          </div> 
          <div class="row"> 
            <div class="col-12 open-filter open-filter__wrapper"> 
              <button class="filter-button filter-button__open">' . static::icon('icon-filter', '', false) . ' Filter</button>
              <button class="filter-button filter-button__reset">' . static::icon('icon-filter-reset', '', false) . '</button>
            </div>
          </div>       
        </section>
        ' . apply_filters('lbwp_filter_block_after_filters', '') . '
        <section class="product-sort product-sort__wrapper row">           
          <div class="filter__results" data-template="' . static::$text['filter-result-count'] . '"></div>                  
          ' . $this->getSortingDropdownHtml() . '
          <div class="filter__reset">
            <button class="filter-button filter-button__reset">' . static::icon('icon-filter-reset', '', false) . ' ' . static::$text['remove-all-filters'] . '</button>
          </div>
        </section>
        <section class="lbwp-wc__product-listing row"></section>
				<section class="lbwp-wc__no-results row">
					<p>' . static::$text['no-results-text'] . '</p>
				</section>
        ' . $this->getLoadingSkeleton() . '
      </section>
    ';

    // Print invisible weglot translateable spans of text
    foreach (static::$text as $key => $text) {
      echo '<span id="langtext-' . $key . '" data-template="' . esc_attr($text) . '"></span>' . PHP_EOL;
    }

    echo $html;
  }

  /**
   * @return string
   */
  protected function getSortingDropdownHtml()
  {
    return '
      <div class="shop-dropdown sort-dropdown shop-dropdown__right"> 
        <div class="shop-dropdown__inner">
          <div class="shop-dropdown__header"> 
            ' . static::icon('icon-sort', '', false) . ' 
            <span class="sort-label sort-label--desktop">' . static::$text['sort-label-desktop'] . '</span>
            <span class="sort-label sort-label--mobile">' . static::$text['sort-label-mobile'] . '</span>
          </div>
          <ul class="shop-dropdown__content">
             <li class="shop-dropdown__entry shop-dropdown__entry--current" data-order="' . static::$DEFAULT_SORT . '">                       
               ' . static::$text['sort-default'] . '                      
             </li>
             <li class="shop-dropdown__entry" data-order="stock">                       
               ' . static::$text['sort-stock'] . '                     
             </li>
             <li class="shop-dropdown__entry" data-order="newest">                       
               ' . static::$text['sort-newest'] . '                  
             </li>
             <li class="shop-dropdown__entry" data-order="price-desc">                       
               ' . static::$text['sort-price-desc'] . '                        
             </li>
             <li class="shop-dropdown__entry" data-order="price-asc">                      
               ' . static::$text['sort-price-asc'] . '                      
             </li>
          </ul>
        </div>  
      </div> 
    ';
  }

  /**
   * @param \WC_Product $product
   */
  public static function getSingleProductHtml($product, $relations = array())
  {
    // Leave early, if it's not WC_Product instance
    if (!($product instanceof \WC_Product)) {
      return '';
    }

    $promos = static::getPromoPriceMap();
    $classes = array('lbwp-wc-product');
		$classes[] = $product->is_in_stock() ? 'lbwp-product-instock' : 'lbwp-product-outofstock';
    $title = static::filterProductTitle($product->get_title(), $product->get_id());
    $id = $product->get_id();
    $context = apply_filters('lbwp_filter_single_html_context', 'filter');
    $url = apply_filters('lbwp_filter_get_product_permalink', get_permalink($product->get_id()), $product);
    // initialize a few not always shown things blank
    $subprice = $subtitle = $topline = $label = '';
    $addUrl = $url . '?add-to-cart=' . $product->get_id();
    if ($context == 'filter') {
      $addUrl .= '&skip-notices=1';
    }

		$inputArgs = PackagingUnit::getInputArgs($product);
		$qtyInput = '
		<input 
			type="number" 
			class="hide ' . implode(' ' , $inputArgs['classes']) . '" 
			step="' . $inputArgs['step'] . '" 
			min="' . $inputArgs['min_value'] . '" 
			max="" 
			name="quantity" 
			value="' . $inputArgs['min_value'] . '" 
			title="Menge" 
			placeholder="" 
			inputmode="numeric"
		>';

		$addToCart = '
			<div class="product-cart__wrapper hide">
				<a href="' . $addUrl . '" data-product_id="' . $id . '" rel="nofollow" class="btn btn--primary btn-outline-primary btn-add-to-cart">
					' . static::icon('icon-cart', '', false) . '
				</a>
			</div>
		';

    $price = $product->get_price_html();
    // Handle variations, show one price if it doesn't differ or show the lowest
    if ($product->get_type() == 'variable') {
      /** @var \WC_Product_Variable $product */
      $variants = $product->get_variation_prices(false);
      $prices = array_map('floatval', array_unique($variants['price']));
      // Only if there are different prices, get the lowest for display
      if (count($prices) > 1) {
        $price = sprintf(__('Ab %s', 'standard-03'), wc_price(min($prices)));
      }
    }

    $percentBubble = '';
    if (isset($promos[$id])) {
      $priceTemplate = '<bdi>%s&nbsp;<span class="woocommerce-Price-currencySymbol">' . get_woocommerce_currency() . '</span></bdi>';
      $price = '
        <strong class="product-price__amount--current sale" data-wg-notranslate>' . sprintf($priceTemplate, $promos[$id]['promo']) . '</strong>
        <span class="product-price__amount--previous">statt <span data-wg-notranslate>' . sprintf($priceTemplate, $promos[$id]['normal']) . '</span></span>
      ';
      $percentBubble = '<div class="product-image__promo-percent" data-wg-notranslate><span>' . $promos[$id]['percent'] . '%</span></div>';
    }

    $preorderBubble = '';
    if(Preorder::isAvailable($id)){
      $preorderBubble = '<div class="lbwp-wc-product__image--preorder" data-wg-notranslate><span>' . __('Vorbestellung', 'standard-03') . '</span></div>';
    }

    // Wrap the label if it was set
    if (strlen($label) > 0) {
      $label = '<span class="product__label label-action">' . $label . '</span>';
    }

    // Set the subtitle
    $titleClass = ' show-title-3';
    $subtitle = static::getProductSubtitle($id);
    if (strlen($subtitle) > 0) {
      $subtitle = '<p data-wg-notranslate>' . $subtitle . '</p>';
      $titleClass = '';
    }

    $dropdown = '';
    if (count($relations) > 0) {
      $values = '';
      $classes[] = 'has-relation-dropdown';
      $names = array($id => get_post_meta($id, 'attribute-relation-text', true));
      if (strlen($names[$id]) == 0) {
        $names[$id] = static::getProductSubtitle($id);
      }
      foreach ($relations as $relId) {
        if (!in_array($relId, self::$prodIds)) {
          continue;
        }
        $names[$relId] = get_post_meta($relId, 'attribute-relation-text', true);
        if (strlen($names[$relId]) == 0) {
          $names[$relId] = static::getProductSubtitle($relId);
        }
      }
      natcasesort($names);
      foreach ($names as $relId => $name) {
        $class = 'shop-dropdown__entry--selectable';
        if ($relId == $id) {
          $class = 'shop-dropdown__entry--current';
        }
        $values .= '<li class="shop-dropdown__entry ' . $class . '" data-rel-id="' . $relId . '">' . $name . '</li>';
      }
      $dropdown = '
        <div class="shop-dropdown product-dropdown" data-wg-notranslate> 
          <div class="shop-dropdown__inner">
            <div class="shop-dropdown__header"> 
              ' . $names[$id] . '
            </div>
            <ul class="shop-dropdown__content">
               ' . $values . '
            </ul>
          </div>
        </div> 
      ';
    } else if (!empty(get_field('has-variants', $id))) {
      $dropdown = SimpleVariations::getOneVariantDropdown(wc_get_product($id), true);

      if (strlen($dropdown) > 0) {
        $dropdown = '
          <div class="shop-dropdown product-dropdown"> 
            <div class="shop-dropdown__inner">' . $dropdown . '</div>
          </div>
        ';
      }else{
				$qtyInput = '';
				$addToCart = '
					<div class="product-cart__wrapper hide">
						<a href="' . $url . '" class="btn btn--primary btn--outline btn-outline-primary product-detail-link">
							' . static::icon('icon-cart', '', false) . '
						</a>
					</div>
				';
			}
    }

		// Dont show ajax button if product is out of stock and backorders aren't allowed or if it's not purchasable
    $hideButton = (!$product->is_in_stock() && !$product->backorders_allowed()) || !$product->is_purchasable();
		if (apply_filters('aboon_filter_hide_purchase_button', $hideButton, $product)){
			$qtyInput = '';
			$addToCart =
				(!$product->is_in_stock() ? '<span class="availability-text">' . $product->get_availability()['availability'] . '</span>' : '') . '
				<div class="product-cart__wrapper hide">
					<a href="' . $url . '" class="btn btn--primary btn--outline btn-outline-primary product-detail-link">
						' . static::icon(static::$PRODUCT_NON_PURCHASABLE_ICON, '', false) . '
					</a>
				</div>
			';
		}

		$htmlFooter = $dropdown . $qtyInput . $addToCart;

		$watchlistHtml = Watchlist::isActive() && apply_filters('aboon_filter_show_default_watchlist_button', true) ?
			'<div class="lbwp-wc-product__watchlist">
				' . Watchlist::watchlistButtonHtml('', $id) . '
			</div>' : 
			'';
    return '
      <div class="' . implode(' ', $classes) . '">
        <div class="lbwp-wc-product__inner">
          <div class="lbwp-wc-product__image">
            ' . apply_filters('aboon_filter_percent_bubble_html', $percentBubble, $id) . '
            ' . apply_filters('aboon_filter_preorder_bubble_html', $preorderBubble, $id) . '
            <figure>
              <a href="' . $url . '">' . get_the_post_thumbnail($id) . '</a>
            </figure>
          </div>
				  ' . $watchlistHtml . '
          <div class="lbwp-wc-product__info-header">
            <div class="product-price product-price__wrapper">
              <span class="product-price__current">
                ' . apply_filters('aboon_filter_current_price_html', $price, $id) . '
              </span>
              <span class="product-price__normal">
                ' . $subprice  . '
              </span>
            </div>
            ' . static::getAvailabilityHtml($id) . '
          </div>

          <div class="lbwp-wc-product__content">        
           
            <header class="product-header" data-wg-notranslate>' . $label . '</header>

            <div class="product-description' . $titleClass . '">
              <h3 data-wg-notranslate><a href="' . $url . '">' . $title . '</a></h3>
              ' . $subtitle . '
              <p class="lbwp-wc-sku" data-sku="' . $product->get_sku() . '">' . sprintf(__('Artikel-Nr.<span data-wg-notranslate>: %s</span>', 'lbwp'), $product->get_sku()) . '</p>
            </div>

            <footer class="product-footer">
							' . $htmlFooter . '
						</footer>
          </div>
        </div>
      </div>
    ';
  }

  /**
   * @return string the loading skeleton html
   */
  protected function getLoadingSkeleton()
  {
    return '
      <section class="lbwp-wc__loading-skeleton">
        <div class="row"> 
          <div class="lbwp-wc__loading-product">
            <div class="skeleton-image"></div> 
            <div class="skeleton-title"></div> 
            <div class="skeleton-footer">
              <div class="skeleton-dropdown"></div> 
              <div class="skeleton-button"></div>               
              <div class="skeleton-button"></div>               
            </div> 
          </div>
          <div class="lbwp-wc__loading-product">
            <div class="skeleton-image"></div> 
            <div class="skeleton-title"></div> 
            <div class="skeleton-footer">
              <div class="skeleton-dropdown"></div> 
              <div class="skeleton-button"></div>               
              <div class="skeleton-button"></div>               
            </div> 
          </div>
          <div class="lbwp-wc__loading-product">
            <div class="skeleton-image"></div> 
            <div class="skeleton-title"></div> 
            <div class="skeleton-footer">
              <div class="skeleton-dropdown"></div> 
              <div class="skeleton-button"></div>               
              <div class="skeleton-button"></div>               
            </div> 
          </div>
        </div>
      </section>
    ';
  }

  /**
   * @return string the url of the filter
   */
  public static function getUrl()
  {
    return get_permalink(static::$FILTER_PAGE_ID);
  }

  /**
   * @return string the category base url
   */
  public static function getCategoryBase()
  {
    return self::CATEGORY_BASE;
  }

  /**
   * @return string product_cat query var if given
   */
  public static function getCurrentMainCategorySlug()
  {
    $query = WordPress::getQuery();
    return $query->get('product_cat');
  }

  /**
   * @return string product_cat query var if given
   */
  public static function getCurrentMainPropertySlug()
  {
    $query = WordPress::getQuery();
    return $query->get('product_prop');
  }

  /**
   * @param \WP_Term $term
   * @param array $tree tree
   * @param string $classes
   * @return string
   */
  public static function getMegaSubMenuFor($term, $tree, $classes, $title = '')
  {
    $cards = '';
    $base = get_option('woocommerce_permalinks')['category_base'];

    foreach ($tree as $mainId => $main) {
      foreach ($main['sub'] as $secId => $second) {
        if ($secId == $term->term_id) {
          $sub = $second['sub'];
          break 2;
        }
      }
    }

    // Build cards from sub categories with an image
    foreach ($sub as $category) {
      $url = '/' . $base . '/' . $term->slug . '/' . $category['slug'] . '/#m:'.$mainId.';s:'.$secId.';t:' . $category['id'];
      if (strlen($category['image']) > 0) {
        $cards .= '
          <div class="mega-menu-card">
            <a href="' . $url . '" data-subid="' . $category['id'] . '">
              <div class="mega-menu-card__image">
                <figure>' . $category['image'] . '</figure>
              </div>
              <div class="mega-menu-card__title">' . $category['name'] . '</div>
            </a>
          </div>
        ';
      }
    }

    if (strlen($cards) == 0) {
      return '';
    }

    return '
      <div class="shop-megamenu mega-menu__wrapper ' . $classes . '" data-id="' . $term->term_id . '">
        <div class="mega-menu__inner shop-overlay__inner container">
          <div class="shop-overlay__content">
            <div class="shop-overlay__header">
              ' . $title . '
              ' . wpautop($term->description) . '
              <button type="button">' . static::icon('icon-close', '', false) . '</button>
            </div>
            <div class="mega-menu__content">
              <div class="mega-menu__entries mega-menu__entries--card">
                ' . $cards . '
              </div>
            </div>
          </div>
        </div>
      </div>
    ';
  }

  /**
   * @param \WP_Term $term
   * @param array $sub subcategories
   * @param string $classes
   * @return string
   */
  public static function getMegaMenuFor($term, $sub, $classes, $title = '')
  {
    $cards = $links = '';
    $base = get_option('woocommerce_permalinks')['category_base'];

    // Build cards from sub categories with an image
    foreach ($sub as $category) {
      $url = '/' . $base . '/' . $term->slug . '/' . $category['slug'] . '/';
      if (strlen($category['image']) > 0) {
        $cards .= '
          <div class="mega-menu-card">
            <a href="' . $url . '" data-subid="' . $category['id'] . '">
              <div class="mega-menu-card__image">
                <figure>' . $category['image'] . '</figure>
              </div>
              <div class="mega-menu-card__title">' . $category['name'] . '</div>
            </a>
          </div>
        ';
      } else {
        $links .= '
          <li class="mega-menu-textlink">
            <a href="' . $url . '" data-subid="' . $category['id'] . '">' . $category['name'] . '</a>
          </li>
        ';
      }
    }

		$getLinks = get_field('texts-urls', $term);
		$getLinks = $getLinks === null ? array() : $getLinks;
		$linksHtml = '';

		foreach($getLinks as $link){
			$linksHtml .= '
				<li><a href="' . $link['url'] . '">' . $link['text'] . '</a></li>
			';
		}

    $cards = apply_filters('aboon_megamenu_cards_html', $cards, $term);


    return '
      <div class="shop-megamenu mega-menu__wrapper ' . $classes . '" data-id="' . $term->term_id . '">
        <div class="mega-menu__inner shop-overlay__inner container">
          <div class="shop-overlay__content">
            <div class="shop-overlay__header">
              ' . $title . '
              ' . wpautop($term->description) . '
              <button type="button">' . static::icon('icon-close', '', false) . '</button>
            </div>
            <div class="mega-menu__content">
              <div class="mega-menu__entries mega-menu__entries--card">
                ' . $cards . '
              </div>
              <div class="mega-menu__entries mega-menu__entries--text">
                ' .
                (!empty($links) ? '
                  <div class="simple-listing-nav mega-menu-textlinks__categories">
                    <strong>Weitere Kategorien</strong>
                    <nav>
                      <ul>
                        ' . $links . '                    
                      </ul>
                    </nav>
                  </div>' : '') .

								(!empty($getLinks) ? '
									<div class="simple-listing-nav mega-menu-textlinks__additional">
										<strong>Weitere Inhalte</strong>
										<nav>
											<ul>
												' . $linksHtml . '
											</ul>
										</nav>
									</div>' : '') 
								. '
                
              </div>
            </div>
          </div>
        </div>
      </div>
    ';
  }

  /**
   * @param array $terms
   */
  public static function getPropertySortOrder($terms)
  {
    $termIds = array();
    foreach ($terms as $term) {
      if (is_array($term)) {
        $termIds[] = $term['id'];
      } else {
        $termIds[] = $term->term_id;
      }
    }

    // Get order meta
    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT term_id, meta_value FROM ' . $db->termmeta . '
      WHERE meta_key = "order"
      AND term_id IN(' . implode(',', $termIds) . ')
    ');

    $map = array();
    foreach ($raw as $item) {
      $map[$item->term_id] = intval($item->meta_value);
    }

    return $map;
  }

  /**
   * @param bool $forceRebuild
   * @return array products with promotion prices
   */
  public static function getPromoPriceMap($forceRebuild = false)
  {
    $map = wp_cache_get('promoPriceMap_v2', 'Filter');
    if (is_array($map) && !$forceRebuild) {
      return $map;
    }

    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT ' . $db->postmeta . '.post_id, ' . $db->postmeta . '.meta_value AS promo_price, p2.meta_value AS normal_price
      FROM ' . $db->postmeta . ' INNER JOIN ' . $db->postmeta  . ' p2 ON (' . $db->postmeta  . '.post_id = p2.post_id AND p2.meta_key = "_regular_price")
      WHERE ' . $db->postmeta . '.meta_key = "_sale_price" AND LENGTH(' . $db->postmeta . '.meta_value) > 0
    ');

    // Take products that are not published into account
    $unpublished = static::getProductIdBlacklist();

    $map = array();
    foreach ($raw as $row) {
      if ($row->normal_price == '' || $row->promo_price == '' || in_array($row->post_id, $unpublished)) {
        continue;
      }
      $row->normal_price = floatval($row->normal_price);
      $row->promo_price = floatval($row->promo_price);
      $map[intval($row->post_id)] = array(
        'normal' => number_format($row->normal_price, 2, '.', ''),
        'promo' => number_format($row->promo_price, 2, '.', ''),
        'percent' => round(($row->normal_price - $row->promo_price) / $row->normal_price * 100, 0)
      );
    }

    wp_cache_set('promoPriceMap_v2', $map, 'Filter', 86400);
    return $map;
  }

  /**
   * Gets a cached meaningful complete list of the category tree for products
   */
  public static function getCategoryTree($forceRebuild = false)
  {
    $tree = wp_cache_get('categoryTree', 'Filter');
    if (is_array($tree) && count($tree) > 0 && !$forceRebuild) {
      return $tree;
    }

    // If not yet given, calculate it
    $raw = get_terms(array(
      'taxonomy' => 'product_cat',
      'hide_empty' => true
    ));

    // Build first hierarchy
    foreach ($raw as $key => $category) {
      if ($category->parent == 0) {
        $tree[$category->term_id] = array(
          'id' => $category->term_id,
          'slug' => $category->slug,
          'name' => $category->name,
          'sub' => array()
        );
        unset($raw[$key]);
      }
    }

    // Build secondary hierarchy
    foreach ($tree as $id => $entry) {
      foreach ($raw as $key => $category) {
        if ($entry['id'] == $category->parent) {
          // Generate image if given
          $image = '';
          $thumbnailId = intval(get_term_meta($category->term_id, 'thumbnail_id', true));
          if ($thumbnailId > 0) {
            $image = FocusPoint::getImage($thumbnailId, 'medium');
          }
          $tree[$id]['sub'][$category->term_id] = array(
            'id' => $category->term_id,
            'slug' => $category->slug,
            'name' => stristr($category->name, '.') !== false ? Strings::removeUntil($category->name, '.') : $category->name,
            'image' => $image,
            'sub' => array()
          );
          unset($raw[$key]);
        }
      }
    }

    // Build tertiary hierarchy
    foreach ($tree as $id => $entry) {
      foreach ($entry['sub'] as $subid => $subentry) {
        foreach ($raw as $key => $category) {
          if ($subentry['id'] == $category->parent) {
            $image = '';
            $thumbnailId = intval(get_term_meta($category->term_id, 'thumbnail_id', true));
            if ($thumbnailId > 0) {
              $image = FocusPoint::getImage($thumbnailId, 'medium');
            }
            $tree[$id]['sub'][$subid]['sub'][$category->term_id] = array(
              'id' => $category->term_id,
              'slug' => $category->slug,
              'name' => stristr($category->name, '.') !== false ? Strings::removeUntil($category->name, '.') : $category->name,
              'image' => $image,
            );
            unset($raw[$key]);
          }
        }
      }
    }

    wp_cache_set('categoryTree', $tree, 'Filter', 86400);
    return $tree;
  }



  /**
   * @param false $forceRebuild
   * @return array|false
   */
  public static function getPropertyTreeFull($forceRebuild = false)
  {
    // Get the latest tree from cache
    $tree = wp_cache_get('propertyTreeFull', 'Filter');
    if (is_array($tree) && count($tree) > 0 && !$forceRebuild) {
      return $tree;
    }

    wp_cache_start_transaction();
    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT t.term_id, t.name, tt.taxonomy, tt.parent, tt.description
			 FROM ' . $db->terms . ' AS t INNER JOIN ' . $db->term_taxonomy . ' AS tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = "product_prop"'
    );
    // Load tree from DB if not in cache yet
    $tree = array();
    // First build the property base terms
    foreach ($raw as $term) {
      if ($term->parent == 0) {
        // Override the config eventually
        $settings = ArrayManipulation::forceArray(
          get_term_meta($term->term_id, 'settings', true)
        );
        $tree[$term->term_id] = array(
          'id' => $term->term_id,
          'name' => $term->name,
          'props' => array(),
          'config' => array(
            'visible' => !in_array('invisible', $settings),
            'translate' => !in_array('no-translate', $settings),
            'hidedetail' => in_array('invisible-detail', $settings)
          ),
        );
      }
    }

    // Order those by their... order :-)
    $map = self::getPropertySortOrder($tree);
    uasort($tree, function($a, $b) use ($map) {
      if ($map[$a['id']] > $map[$b['id']]) {
        return 1;
      } else if ($map[$a['id']] < $map[$b['id']]) {
        return -1;
      }
      return 0;
    });

    // Now build the sub terms, actual property names
    foreach ($raw as $term) {
      if ($term->parent > 0) {
        // Add the term to the terms of that branch
        if (isset($tree[$term->parent])) {
          $tree[$term->parent]['props'][$term->term_id] = Strings::removeUntil($term->name, '.');
        }
      }
    }

    wp_cache_set('propertyTreeFull', $tree, 'Filter', 86400);
    wp_cache_commit_transaction();

    return $tree;
  }

  /**
   * @param false $forceRebuild
   * @return array|false
   */
  public static function getPropertyTree($forceRebuild = false)
  {
    // Get the latest tree from cache
    $tree = wp_cache_get('propertyTree', 'Filter');
    if (is_array($tree) && count($tree) > 0 && !$forceRebuild) {
      return $tree;
    }

    // Load tree from DB if not in cache yet
    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT t.term_id, t.name, tt.taxonomy, tt.parent, tt.description
			 FROM ' . $db->terms . ' AS t INNER JOIN ' . $db->term_taxonomy . ' AS tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = "product_prop"'
    );

    // Order $raw elements slug with natcasessort
    if (static::$SORT_PROPERTIES_NATCASESLUG) {
      usort($raw, function($a, $b) {
        return strnatcasecmp($a->slug, $b->slug);
      });
    }

    $tree = array();
    // First build the property base terms
    foreach ($raw as $term) {
      if ($term->parent == 0) {
        // Skip if in sizeMergeables and remember the parent for later mapping
        if (isset(self::$sizeMergeables[$term->name])) {
          self::$mergeParents[$term->term_id] = self::$mergeFields[self::$sizeMergeables[$term->name]];
          // Calculate the merge factor from the name
          $size = explode(' ', $term->name)[2];
          if ($size !== null && isset(self::$sizeFactors[$size])) {
            self::$mergeFactor[$term->term_id] = self::$sizeFactors[$size];
          }
          continue;
        }
        $config = array(
          'visible' => true,
          'hidedetail' => false,
          'translate' => true,
          'color' => false,
          'type' => 'text'
        );
        // Override the config eventually
        $settings = get_term_meta($term->term_id, 'settings', true);
        if (is_array($settings)) {
          $config['visible'] = !in_array('invisible', $settings);
          $config['translate'] = !in_array('no-translate', $settings);
          $config['hidedetail'] = in_array('invisible-detail', $settings);
        }
        if (!$config['visible']) {
          continue;
        }
        $type = get_term_meta($term->term_id, 'type', true);
        if (strlen($type) > 0) {
          $config['type'] = $type;
        }
        $tree[$term->term_id] = array(
          'id' => $term->term_id,
          'name' => $term->name,
          'config' => $config,
          'props' => array()
        );
      }
    }

    // Get the sort order of our native terms
    $map = self::getPropertySortOrder($tree);

    // Add virtual term merges from config, also add in their sort to the map
    foreach (self::$mergeFields as $name => $id) {
      // First, create the property
      $tree[$id] = array(
        'id' => $id,
        'name' => $name,
        'props' => array(),
        'config' => array(
          'visible' => true,
          'hidedetail' => true,
          'translate' => false,
          'type' => 'number'
        )
      );
      // ID is the sort number
      $map[$id] = $id;
    }

    // Actually sort the first hierarchy of the tree
    uasort($tree, function($a, $b) use ($map) {
      if ($map[$a['id']] > $map[$b['id']]) {
        return 1;
      } else if ($map[$a['id']] < $map[$b['id']]) {
        return -1;
      }
      return 0;
    });

    // Now build the sub terms, actual property names
    foreach ($raw as $term) {
      if ($term->parent > 0) {
        // Add the term to the terms of that branch
        if (isset($tree[$term->parent])) {
          $tree[$term->parent]['props'][$term->term_id] = Strings::removeUntil($term->name, '.');
        } else if (isset(self::$mergeParents[$term->parent])) {
          $value = Strings::removeUntil($term->name, '.');
          if (self::$mergeFactor[$term->parent]) {
            $value = (float) $value * self::$mergeFactor[$term->parent];
          }
          $id = self::$mergeParents[$term->parent];
          $tree[$id]['props'][$term->term_id] = (float) $value;
        }
      }
    }

    // Order all props arrays alphanumeric
    if (static::$SORT_PROPERTIES) {
      foreach ($tree as $parentId => $parent) {
        if ($tree[$parentId]['config']['type'] == 'number') {
          asort($tree[$parentId]['props'], SORT_NUMERIC);
        } else {
          asort($tree[$parentId]['props'], SORT_STRING);
        }
      }
    }

    wp_cache_set('propertyTree', $tree, 'Filter', 86400);
    return $tree;
  }

  /**
   * @param $forceRebuild
   * @return array|false
   */
  public static function getCategoryProductMap($forceRebuild = false)
  {
    // Get the latest tree from cache
    $map = wp_cache_get('categoryProductMap', 'Filter');
    if (is_array($map) && count($map) > 0 && !$forceRebuild) {
      return $map;
    }

    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT object_id, term_id FROM ' . $db->term_relationships . '
      INNER JOIN ' . $db->term_taxonomy . ' 
      ON ' . $db->term_taxonomy . '.term_taxonomy_id = ' . $db->term_relationships . '.term_taxonomy_id
      WHERE taxonomy = "product_cat" AND parent > 0
    ');

    // Rework this into products by category for easy assignment
    $reworked = array();
    foreach ($raw as $row) {
      $reworked[$row->term_id][] = intval($row->object_id);
    }

    // Get the category tree as base for this
    $categoryTree = static::getCategoryTree();
    foreach ($categoryTree as $mainCategory) {
      foreach ($mainCategory['sub'] as $sid => $secondaryCategory) {
        $map[$sid] = array(
          'sub' => array(),
          'products' => $reworked[$sid]
        );
        // Now assign sub as well
        foreach ($secondaryCategory['sub'] as $tid => $tertiaryCategory) {
          $map[$sid]['sub'][$tid] = $reworked[$tid];
        }
      }
    }

    wp_cache_set('categoryProductMap', $map, 'Filter', 86400);
    return $map;
  }

  /**
   * Removes entries from the product map table when pid or tid are not existing anymore
   */
  public function fixMissingProductMapData()
  {
    $db = WordPress::getDb();
    // Delete all map records where the according product doesn't exist anymore
    $db->query('
      DELETE ' . $db->prefix . 'lbwp_prod_map FROM ' . $db->prefix . 'lbwp_prod_map
      LEFT JOIN ' . $db->posts . ' ON ' . $db->posts . '.ID = ' . $db->prefix . 'lbwp_prod_map.pid
      WHERE ID IS NULL
    ');
    // Delete all map records where the according term doesn't exist anymore
    $db->query('
      DELETE ' . $db->prefix . 'lbwp_prod_map FROM ' . $db->prefix . 'lbwp_prod_map
      LEFT JOIN ' . $db->terms . ' ON ' . $db->terms . '.term_id = ' . $db->prefix . 'lbwp_prod_map.tid
      WHERE term_id IS NULL
    ');
  }

  /**
   * This rebuilds the prod_map connecting product ids with property ids
   * It build and sustains a whole table for fast access in the filter
   */
  public function buildProductMap()
  {
    // Do two querys to get data basics as fast as possible
    $source = array();
    $target = array();
    $db = WordPress::getDb();

    // Make sure to create or update our temp table
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'lbwp_prod_map';

    $sql = "CREATE TABLE $table (
      pid bigint(11) UNSIGNED NOT NULL,
      tid bigint(11) UNSIGNED NOT NULL,
      PRIMARY KEY  (pid,tid),
      KEY pid (pid),
      KEY tid (tid)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    // Get all live product ids
    $productIds = $db->get_col('
      SELECT ID FROM ' . $db->posts . '
      WHERE post_type IN("' . implode('","', static::$POST_TYPE) . '") AND post_status = "publish"
    ');

    foreach ($productIds as $id) {
      $map[$id] = '';
    }

    $sdb = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    for ($i = 1; $i < 25; $i++) {
      $limit = $i * 50000;
      $offset = $limit - 50000;
      $result = mysqli_query($sdb, '
        SELECT object_id, term_id FROM ' . $db->term_relationships . '
        INNER JOIN ' . $db->term_taxonomy . ' 
        ON ' . $db->term_taxonomy . '.term_taxonomy_id = ' . $db->term_relationships . '.term_taxonomy_id
        WHERE taxonomy IN("' . implode('","', static::$PROD_MAP_TAXONOMIES) . '") LIMIT ' . $offset . ',' . $limit . ';
      ', MYSQLI_USE_RESULT);
      $results = false;
      while ($row = mysqli_fetch_assoc($result)) {
        extract($row);
        $source[$object_id . '-' . $term_id] = true;
        $results = true;
      }
      $result->free_result();
      if (!$results) {
        break;
      }
    }
    for ($i = 1; $i < 25; $i++) {
      $limit = $i * 50000;
      $offset = $limit - 50000;
      $result = mysqli_query($sdb, '
        SELECT pid,tid FROM ' . $table . ' LIMIT ' . $offset . ',' . $limit . ';
      ', MYSQLI_USE_RESULT);
      $results = false;
      while ($row = mysqli_fetch_assoc($result)) {
        extract($row);
        $target[$pid . '-' . $tid] = true;
        $results = true;
      }
      $result->free_result();
      if (!$results) {
        break;
      }
    }
    $sdb->close();

    // Get keys that are yet to be added
    $addable = array_diff_key($source, $target);
    // If more than 10000 we are in bulk mode, just add and don't do delete checks
    if (count($addable) > 10000) {
      // Make sure to not overload by only doing a 10k slice each time
      $addable = array_slice($addable, 0, 10000);
      foreach ($addable as $data => $bool) {
        list($pid, $tid) = explode('-', $data);
        $db->query('INSERT INTO ' . $table . ' (pid,tid) VALUES ('.$pid.','.$tid.')');
      }
    } else {
      // First add what needs to be added to the db
      foreach ($addable as $data => $bool) {
        list($pid, $tid) = explode('-', $data);
        $db->query('INSERT INTO ' . $table . ' (pid,tid) VALUES ('.$pid.','.$tid.')');
      }
      // No bulk mode, also calculate what we need to delete from $target that is not in $source
      $removeable = array_diff_key($target, $source);
      foreach ($removeable as $data => $bool) {
        list($pid, $tid) = explode('-', $data);
        $db->query('DELETE FROM ' . $table . ' WHERE pid = '.$pid.' AND tid = '.$tid);
      }
    }
  }

  /**
   * Eventually reduces the given set to whitelist, if one is given
   * @param array $productIds
   * @return array eventually reduced list
   */
  public static function reduceToWhiteList($productIds)
  {
    $whitelist = static::getProductIdWhitelist();
    if (count($whitelist) > 0) {
      foreach ($productIds as $key => $id) {
        if (!in_array($id, $whitelist)) {
          unset($productIds[$key]);
        }
      }
    }

    return $productIds;
  }

  /**
   * Eventually reduces the given set with the blacklist, if one is given
   * @param array $productIds
   * @return array eventually reduced list
   */
  public static function reduceToBlackList($productIds)
  {
    $blacklist = static::getProductIdBlackList();
    if (count($blacklist) > 0) {
      foreach ($productIds as $key => $id) {
        if (in_array($id, $blacklist)) {
          unset($productIds[$key]);
        }
      }
    }

    return $productIds;
  }

  /**
   * @param int $productId
   * @return bool visible or not
   */
  public function applyLimitationToProductId($visible, $productId)
  {
    // Check if product on whitelist, if there is a whitelist
    $whitelist = static::getProductIdWhitelist();
    if (count($whitelist) > 0 && !in_array($productId, $whitelist)) {
      return false;
    }

    $blacklist = static::getProductIdBlacklist();
    if (count($blacklist) > 0 && in_array($productId, $blacklist)) {
      return false;
    }

    return $visible;
  }

  /**
   * @param $ids
   * @return void
   */
  public static function setProdIds($ids)
  {
    self::$prodIds = $ids;
  }

  /**
   * Register fieldsets with ACF
   */
  public function fields()
  {
    $this->blockSettingsFields();
  }

  /**
   * Theme settings
   */
  public function blockSettingsFields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_62530ea62950f',
      'title' => 'Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_60540ffeb2ce4',
          'label' => 'Vordefinierter Filter (Hash Syntax)',
          'name' => 'preload-hash',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'step' => '',
          'prepend' => '',
          'append' => '',
        )
      ),
      'location' => array(
        array(
          array(
            'param' => 'block',
            'operator' => '==',
            'value' => 'acf/' . static::$THEME_PREFIX . '-filter',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }

  /**
   * @param $name
   * @param string $classes
   * @param bool $echo
   * @return mixed
   */
  abstract protected static function icon($name, $classes = '', $echo = true);

  /**
   * @param string $title
   * @param int $productId
   * @return string
   */
  abstract protected static function filterProductTitle($title, $productId);

  /**
   * @param int $productId
   * @return string
   */
  abstract protected static function getProductSubtitle($productId);

  /**
   * @param $productId
   * @return string
   */
  abstract protected static function getAvailabilityHtml($productId);

  /**
   * @return mixed
   */
  abstract public static function getProductIdWhitelist();

  /**
   * @return mixed
   */
  abstract public static function getProductIdBlacklist();

  /**
   * @return mixed
   */
  abstract public function getSearchTerm();

  /**
   * @param $term
   * @return mixed
   */
  abstract protected function getSearchTermResults($term, $exact = true, $correction = false);
}