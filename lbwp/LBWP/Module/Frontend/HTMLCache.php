<?php

namespace LBWP\Module\Frontend;

use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;

/**
 * HTML Cache module. Caches contents of a html page with a few exceptions.
 * The HTML Cache saves the content into a very fast cache server.
 * @author Michael Sebel <michael@comotive.ch>
 */
class HTMLCache extends \LBWP\Module\Base
{
  /**
   * @var int time to cache pages that are not single
   */
  protected $cacheTime = 0;
  /**
   * @var int time to cache single pages
   */
  protected $cacheTimeSingle = 0;
  /**
   * Different CacheGroup between desktop/mobile
   * @var string
   */
  protected $currentCacheGroup = '';
  /**
   * @var bool can be set to false to prohibit caching of the current call
   */
  protected static $avoidCache = false;
  /**
   * cache will be set off for follow sites by filename
   * @var array
   */
  protected $noCachePaths = array(
    '/wp-login.php',
    '/favicon.ico',
    '/robots.txt',
    '/wp-content/plugins/lbwp/views/cron/daily.php',
    '/wp-content/plugins/lbwp/views/cron/hourly.php',
    '/wp-content/plugins/lbwp/views/cron/job.php',
  );
  /**
   * @var int const used for ommiting cache for a few seconds
   */
  const FEW_SECONDS = 15;
  /**
   * @var int constant used for ommiting cache for a minute
   */
  const MINUTE = 60;
  /**
   * @var int the minimum time to cache
   */
  const MIN_CACHE_TIME = 300;
  /**
   * @var string cached objects need this size in bytes at least
   */
  const MIN_CACHEABLE_SIZE = 24;

  /**
	 * call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
	}

  /**
   * Register the actions and the actual caching, if possible
   */
  public function initialize()
  {
    if (!is_admin() || isset($_GET['forceHtmlCache'])) {
      // break if current page in noCacheSites
      $currentPaths = parse_url($_SERVER['REQUEST_URI']);
      $currentPaths = $currentPaths['path'];
      if (in_array($currentPaths, $this->noCachePaths)) {
        return; // site is prevent from cache
      }
      $this->setCacheTimes(
        $this->config['HTMLCache:CacheTime'],
        $this->config['HTMLCache:CacheTimeSingle']
      );
      $this->expireTimedCacheAvoids();
      // set correct cache group (desktop always))
      $this->currentCacheGroup = getHtmlCacheGroup();
      // This avoids commenting users to cache an unapproved comment
      add_action('init', array($this, 'avoidCommenterCache'));
      add_action('wp_insert_comment', array($this, 'avoidCacheForAFewSeconds'), 20);
      add_action('wp_insert_comment', array($this, 'invalidateCommentedPage'), 20, 2);
      add_action('wp_before_admin_bar_render',array($this, 'flushCacheFromAdminBar'));
      add_filter('output_buffer',array($this, 'setSiteToCache'), 10000);
      add_filter('the_password_form', array($this, 'avoidCacheFiltered'));

      // manually delete cache for one site
      if ($_GET['htmlCache'] == 'invalidate') {
        // clean invalidation param state from get -> remove
        $siteId = $this->removeGetVar('htmlCache');
        // delete cache
        $this->clearHtmlCache($siteId);
      }
    }
  }

  /**
   * Set the avoid cache session, but only if not admin
   */
  public function avoidCacheNoAdmin()
  {
    if (!is_admin()) {
      $this->avoidCache();
    }
  }

  /**
   * @param mixed $data the data coming in
   * @return mixed the same data
   */
  public function avoidCacheFiltered($data)
  {
    self::$avoidCache = true;
    return $data;
  }

  /**
   * Avoids the cache for the current user for a few seconds
   */
  public function avoidCacheForAFewSeconds()
  {
    $this->avoidCacheTimed(self::FEW_SECONDS);
  }

  /**
   * Avoids the cache for a minute
   */
  public function avoidCacheForAMinute()
  {
    $this->avoidCacheTimed(self::MINUTE);
  }

  /**
   * Let current timed cache avoids expire
   */
  protected function expireTimedCacheAvoids()
  {
    if (isset($_SESSION['avoidCacheTimed'])) {
      if (time() > $_SESSION['avoidCacheTimed']) {
        unset($_SESSION['avoidCacheTimed']);
      }
    }
  }

  /**
   * @param int $time number of seconds to avoid the cache
   */
  protected function avoidCacheTimed($time)
  {
    $_SESSION['avoidCacheTimed'] = time() + $time;
  }

  /**
   * Avoid that a known commenter can put things into the cache
   */
  public function avoidCommenterCache()
  {
    if (isset($_COOKIE['comment_author_' . COOKIEHASH])) {
      // And don't cache this request (so it's cached again after the next)
      $this->avoidCache();
    }
  }

  /**
   * Invalidate the cache of the commented page, if comments are immediately public
   * @param int $id the comment id
   * @param \stdClass $comment the comment object
   */
  public function invalidateCommentedPage($id, $comment)
  {
    MemcachedAdmin::flushByKeyword(MemcachedAdmin::HTML_CACHE_PREFIX);
  }

  /**
   * Safely removes a cookie
   * @param string $cookie the name of the cookie to remove
   */
  protected function unsetCookie($cookie)
  {
    if (isset($_COOKIE[$cookie])) {
      unset($_COOKIE[$cookie]);
      setcookie($cookie, '', time() - 60, '/');
    }
  }

  /**
   * @param int $pages pages cache time
   * @param int $single single cache time
   */
  public function setCacheTimes($pages, $single)
  {
    $pages = intval($pages);
    $single = intval($single);

    if ($pages <= self::MIN_CACHE_TIME) {
      $pages = self::MIN_CACHE_TIME;
    }

    if ($single <= self::MIN_CACHE_TIME) {
      $single = $pages;
    }

    $this->cacheTime = $pages;
    $this->cacheTimeSingle = $single;
  }

  /**
   * Löscht beim speichern einer Seite, diese aus dem Cache
   */
  public static function cleanPostHtmlCache($postId)
  {
    $htmlCache = LbwpCore::getModule('HTMLCache');
    if (!($htmlCache instanceof HTMLCache)) {
      $htmlCache = new HTMLCache();
    }

    // Get the correct URL. cut domain start by .xxx/
    $siteId = $htmlCache->getUriPath(get_permalink($postId));
    $htmlCache->clearHtmlCache($siteId);
  }

  /**
   * Delete a cached site (desktop)
   * @param string $siteId
   */
  public function clearHtmlCache($siteId)
  {
    self::invalidatePage($siteId);
  }

  /**
   * Invalidate the current page
   */
  public static function invalidateCurrentPage()
  {
    $siteId = md5($_SERVER['REQUEST_URI']);
    self::invalidatePage($siteId);
  }

  /**
   * @param string $uri the URI to invalidate
   */
  public static function invalidatePage($uri)
  {
    $siteId = md5($uri);
    wp_cache_delete($siteId, FRONT_CACHE_GROUP);
    wp_cache_delete($siteId, FRONT_CACHE_GROUP_HTTPS);
  }

  /**
   * Delete multiple cached sites (desktop)
   * @param array $siteIds
   */
  public static function invalidatePageArray(array $siteIds)
  {
    foreach ($siteIds as $uri) {
      $siteId = md5($uri);
      wp_cache_delete($siteId, FRONT_CACHE_GROUP);
      wp_cache_delete($siteId, FRONT_CACHE_GROUP_HTTPS);
    }
  }

  /**
   * Delete multiple cached sites (desktop)
   * @param array $siteIds
   */
  public function clearHtmlCacheArray(array $siteIds)
  {
    self::invalidatePageArray($siteIds);
  }

  /**
   * Can remove one Variable from GET. Write direct in $_SERVER['REQUEST_URI']
   * @param string $var variable to remove
   * @return string querystring without removed variable
   */
  public function removeGetVar($var)
  {
    // remove variable
    $newGetStr = '';
    foreach ($_GET as $key => $value) {
      if ($key !== $var) {
        $newGetStr .= $key . '=' . $value . '&';
      }
    }
    // cut last &
    $newGetStr = substr($newGetStr, 0, -1);

    $arrRequestUri = explode('?', $this->getCacheSiteId());
    if (!$newGetStr) {
      return $arrRequestUri[0];
    } else {
      return $arrRequestUri[0] . '?' . $newGetStr;
    }
  }

  /**
   * @param string $output save to cache
   * @return string the maybe slightly changed output
   */
  public function setSiteToCache($output)
  {
    $output = trim($output);

    // is output here -> don't cache an empty site (example because an error)
    if ($this->isCachable()) {
      $expireTime = $this->getCacheTime();
      // make a normal cache value header/content
      $cacheVal = array(
        'header' => array(),
        'host' => $_SERVER['HTTP_HOST'],
        'uri' => $_SERVER['REQUEST_URI'],
        'is404' => is_404(),
        'expires' => $expireTime,
        'content' => $output
      );

      // Add content type headers if set
      $hasLocationHeader = false;
      foreach (headers_list() as $header) {
        if (
          Strings::startsWith(strtolower($header), 'access-control') ||
          Strings::startsWith(strtolower($header), 'content-type') ||
          Strings::startsWith(strtolower($header), 'reverseproxy')
        ) {
          $cacheVal['header'][] = $header;
        }
        if (Strings::startsWith(strtolower($header), 'location')) {
          $hasLocationHeader = true;
          $cacheVal['header'][] = $header;
        }
      }

      // Save cacheVal to cache only if there is content or headers
      if (strlen($output) > self::MIN_CACHEABLE_SIZE || $hasLocationHeader) {
        wp_cache_set(
          md5($this->getCacheSiteId()),
          $cacheVal,
          $this->currentCacheGroup,
          $expireTime
        );
      }
    }

    return $output;
  }

  /**
   * Returns true if a comment cookie is set
   * @return boolean
   */
  protected function haveCommentCookie()
  {
    foreach ($_COOKIE as $key => $value) {
      if (strpos($key, 'comment_author_') === 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * Checks if the current output is cacheable
   * @return bool true if cachable, false if not
   */
  protected function isCachable()
  {
    // If force, let admin sites be cached (counts for wp ajax mostly)
    if (isset($_GET['forceHtmlCache']) && is_admin()) {
      return true;
    }
    // if the avoid cache session is set
    if (self::$avoidCache) {
      return false;
    }
    // if the avoid cache for this call is set
    if (isset($_SESSION['avoidCache']) && !isset($_SESSION['avoidCacheTimed'])) {
      return false;
    }
    // Only non admin page requests are cached
    if (is_admin() || is_user_logged_in()) {
      return false;
    }
    // If the user has an author cookie the site won't be cacheable
    if ($this->haveCommentCookie()) {
      return false;
    }
    // Every post request can't be cached (made for woocommerce)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      return false;
    }
    // Is there a woocommerce cookie and are there items in the cart?
    if (isset($_COOKIE['woocommerce_items_in_cart'])) {
      return false;
    }
    // If the user is logged in for post passing, don't cache his results
    if (isset($_COOKIE['wp-postpass_' . COOKIEHASH])) {
      return false;
    }
    // It's cacheable if we reach this line
    return true;
  }

  /**
   * Omits caching for the current call
   */
  public static function avoidCache()
  {
    self::$avoidCache = true;
  }

  /**
   * Cut the domain from the uri.
   * @param string $locationUri
   * @return string Get the Website Path Uri
   */
  public function getUriPath($locationUri)
  {
    $urlParts = parse_url($locationUri);
    return $urlParts['path'];
  }

  /**
   * Get the timestamp for expires date for the cache key
   * @return int timestamp until cache must be refreshed
   */
  protected function getCacheTime()
  {
    if (is_singular()) {
      return time() + $this->cacheTimeSingle;
    } else {
      return time() + $this->cacheTime;
    }
  }

  /**
   * Generate the ID for the cache object (per site)
   * @return string ID
   */
  public function getCacheSiteId()
  {
    return $_SERVER['REQUEST_URI'];
  }

  /**
   * Flush Cache button in admin bar to flush current page
   */
  public function flushCacheFromAdminBar()
  {
    global $wp_admin_bar;
    // create cache flush link
    $link = $this->getCacheSiteId();
    if (stristr($link,'?') === false) {
      $link .= '?';
    } else {
      $link .= '&';
    }
    // create admin bar element
    $wp_admin_bar->add_node(array(
      'id' => 'flushCacheFromAdminBar',
      'title' => 'Cache leeren',
      'href' => $link.'htmlCache=invalidate'
    ));
  }
}