<?php

namespace LBWP\Module\Backend;

use LBWP\Core as LbwpCore;
use LBWP\Helper\MasterApi;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\Multilang;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This module provides management of cache keys / information. It is
 * only loaded while the user is logged in and in admin mode.
 * @author Michael Sebel <michael@comotive.ch>
 */
class MemcachedAdmin extends \LBWP\Module\Base
{
  /**
   * @var string the flush endpoint for each node
   */
  const FLUSH_ENDPOINT = '/wp-content/plugins/lbwp/views/api/flush.php';
  /**
   * @var string the html cache prefix
   */
  // OLD MEMCACHED const HTML_CACHE_PREFIX = '_htmlCache';
  const HTML_CACHE_PREFIX = 'htmlCache';
  /**
   * @var bool flushed the html cache? true if already flushed in this request
   */
  protected $flushedHtmlCache = false;
  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Initializes filters
   */
  public function initialize()
  {
    if (defined('LBWP_DISABLE_MEMCACHED') && LBWP_DISABLE_MEMCACHED) {
      return false;
    }

    add_action('admin_menu', array($this, 'registerMenu'));

    // If HTMLCache is activated clean cache on saving things
    if ($this->features['FrontendModules']['HTMLCache'] == 1) {
      $this->registerCacheInvalidations();
    }
  }

  /**
   * @return void
   */
  public function registerCacheInvalidations()
  {
    add_action('transition_post_status', array($this, 'onPostTransitionFlush'), 200, 2);
    add_action('post_updated', array($this, 'onPostSavedFlush'), 200, 2);
    add_action('deleted_post', array($this, 'onChangeImmediateFlush'), 200);
    add_action('wp_update_nav_menu', array($this, 'onChangeImmediateFlush'), 200);
    add_action('widget_update_callback', array($this, 'onChangeSidebarFlush'), 200, 1);
    add_action('edited_term', 'flush_rewrite_rules', 200);
    add_action('edited_term', array($this, 'onChangeImmediateFlush'), 200);
    add_action('delete_term', array($this, 'onChangeImmediateFlush'), 200);
    add_action('transition_comment_status', array($this, 'onCommentStatusChangeFlush'), 200, 3);
    add_action('wp_insert_comment', array($this, 'onNewApprovedCommentFlush'), 200, 2);
    add_action('edit_comment', array($this, 'onEditApprovedCommentFlush'), 200, 1);
    add_action('cron_job_flush_html_cache', array($this, 'checkPublishablePosts'), 180);
    add_action('cron_job_flush_html_cache', array($this, 'onChangeImmediateFlush'), 200);
    add_action('customize_save_after', array($this, 'onChangeImmediateFlush'), 200);
    add_action('profile_update', array($this, 'onChangeImmediateFlush'), 200);
    add_action('acf/options_page/save', array($this, 'onChangeImmediateFlush'), 200);
  }

  /**
   * If a comment is released or unreleased, flush the cache of the corresponding page
   * @param string $newStatus new comment status
   * @param string $oldStatus old comment status
   * @param \stdClass $comment the comment being changed
   */
  public function onCommentStatusChangeFlush($newStatus, $oldStatus, $comment)
  {
    // Only if comment goes live or gets taken offline
    if ($newStatus == 'approved' || $newStatus == 'unapproved' || $newStatus == 'trash') {
      $this->flushFrontendCache(false);
    }
  }

  /**
   * @param int $id flush cache if comment is edited
   */
  public function onEditApprovedCommentFlush($id)
  {
    $comment = get_comment($id);
    $this->onNewApprovedCommentFlush($id, $comment);
  }

  /**
   * @param int $id the new comments id
   * @param \WP_Comment $comment the comment object
   */
  public function onNewApprovedCommentFlush($id, $comment)
  {
    if ($id > 0 && $comment->comment_approved == 1) {
      $this->flushFrontendCache(false);
    }
  }

  /**
   * Flush frontend cache on widget change and return the widget
   * @param array $instance widget config
   * @return array unchanged widget config
   */
  public function onChangeSidebarFlush($instance)
  {
    $this->flushFrontendCache(false);
    return $instance;
  }

  /**
   * @param string $newStatus new status
   * @param string $oldStatus old status
   */
  public function onPostTransitionFlush($newStatus, $oldStatus)
  {
    // Making it easy: always flush if a status changes
    if ($newStatus != $oldStatus) {
      $this->flushFrontendCache(false);
    }
  }

  /**
   * Get all future posts and see if they are indeed from the future and need to be published
   */
  public function checkPublishablePosts()
  {
    global $wpdb;

    // Get future posts that need to be published
		$now = gmdate('Y-m-d H:i:59');
		$posts = $wpdb->get_col('
      SELECT ID FROM ' . $wpdb->posts . '
      WHERE post_status = "future" AND post_date_gmt <= "' . $now . '"
    ');

    SystemLog::add('checkPublishablePosts', 'debug', 'gmdate: ' . $now . ', posts: ' . count($posts), array(
      'now' => $now,
      'count' => count($posts),
      'id' => $posts[0]
    ));

    // Publish all the posts
    foreach ($posts as $postId) {
      wp_publish_post($postId);
    }
  }

  /**
   * @param int $postId id of the saved post
   * @param \WP_Post $savedPost the new saved post
   */
  public function onPostSavedFlush($postId, $savedPost)
  {
    // Set a blacklist for posts that don't force a flush
    $status = $savedPost->post_status;
    $blackListedTypes = array('attachment', 'nav_menu_item', 'revision');

    // Changes in status are handled in transition changes
    // If a post is saved, only flush if public
    if (!in_array($savedPost->post_type, $blackListedTypes) && ($status == 'publish' || $status == 'private')) {
      $this->flushFrontendCache(false);
      // Make sure to always flush current (in case keys are lost)
      HTMLCache::cleanPostHtmlCache($savedPost->ID);
      $this->flushMainPages();
    }

    // Page can be handled seperately (only flush the actual page to be sure)
    if ($savedPost->post_type == 'page') {
      HTMLCache::cleanPostHtmlCache($savedPost->ID);
    }
  }

  /**
   * Immediate flush action callback with no conditions asked
   */
  public function onChangeImmediateFlush()
  {
    $this->flushFrontendCache(false);
    $this->flushMainPages();
  }

  /**
   * Flush the blog main page explicitly
   * Also flush all language main pages, if multilang
   */
  protected function flushMainPages()
  {
    $siteIds = array('/', '/feed/');

    if (Multilang::isActive()) {
      foreach (Multilang::getAllLanguages() as $language) {
        $sideIds[] = '/' . $language . '/';
        $sideIds[] = '/' . $language . '/feed/';
      }
    }

    // Flush them all directly, no matter if existing in cache
    HTMLCache::invalidatePageArray($siteIds);
  }

  /**
   * Registers the user admin page
   */
  public function registerMenu()
  {
    add_submenu_page(
      'tools.php',
      'Cache-Einstellungen',
      'Cache-Einstellungen',
      'administrator',
      'cache-admin',
      array($this, 'adminForm')
    );
  }

  /**
   * Flushes the current page's cache
   * @param string $keyword a keyword to flush for
   * @return string success message on cache deletion
   */
  public function flushCache($keyword = '')
  {
    global $table_prefix;

    $params = array(
      CACHE_FLUSH_KEY => CACHE_FLUSH_SECRET,
      'customer' => CUSTOMER_KEY,
      'prefix' => $table_prefix,
      'search' => $keyword
    );

    // Flush on each node
    $url = get_bloginfo('url') . self::FLUSH_ENDPOINT;
    // Post that thing, but don't wait for a response
    MasterApi::postAsynchronous($url, $params);

    // Let others flush their cache
    do_action('lbwp_flushed_cache', $keyword);

    return '<div class="updated"><p>Der Cache wurde geleert</p></div>';
  }

  /**
   * Has the ability to flush the cache of a foreign instance
   * @param $customerKey
   * @param $tablePrefix
   * @param $hostName
   */
  public static function flushForeignHtmlCache($customerKey, $tablePrefix, $hostName)
  {
    $params = array(
      CACHE_FLUSH_KEY => CACHE_FLUSH_SECRET,
      'customer' => $customerKey,
      'prefix' => $tablePrefix,
      'search' => self::HTML_CACHE_PREFIX
    );

    // Flush on each node
    $url = get_bloginfo('url') . self::FLUSH_ENDPOINT;
    $url = str_replace(LBWP_HOST, $hostName, $url);
    // Post that thing, but don't wait for a response
    MasterApi::postAsynchronous($url, $params);
  }

  /**
   * @param string $keyword the keys to flush by keyword
   * @return bool if anything was flushed
   */
  public static function flushByKeyword($keyword)
  {
    $module = LbwpCore::getModule('MemcachedAdmin');
    if ($module instanceof MemcachedAdmin) {
      $module->flushCache($keyword);
      return true;
    } else {
      // In frontend, there is no instance, create one, to be able to flush
      // There is no need to call initialize, filters are not needed
      $module = new MemcachedAdmin();
      $module->flushCache($keyword);
    }

    return false;
  }

  /**
   * Helper to flush frontend cache with a single, safe, outside call
   */
  public static function flushFrontendCacheHelper()
  {
    self::flushByKeyword(self::HTML_CACHE_PREFIX);
  }

  /**
   * Helper to flush frontend cache with a single, safe, outside call
   */
  public static function flushFullCacheHelper()
  {
    self::flushByKeyword('');
  }

  /**
   * @param bool $force flush, even if already flushed once
   */
  public function flushFrontendCache($force = false)
  {
    if (!$this->flushedHtmlCache || $force) {
      $this->flushCache(self::HTML_CACHE_PREFIX);
      $this->flushedHtmlCache = true;
    }
  }

  /**
   * This function is mainly used at large wocoommerce DB upgrades where the shop
   * has lots of traffic. This sometimes causes a loop of endless actionscheduler actions
   * This function should be able to break this loop without killing the DB
   * @return void
   */
  protected function breakWoocommerceSuicide()
  {
    $db = WordPress::getDb();
    // Do it ten times in a row to make sure all pending actions are gone and woocommerce recognizes it was updated
    for ($i = 0; $i < 10; $i++) {
      $db->query('DELETE FROM ' . $db->prefix . ' . actionscheduler_actions WHERE status LIKE "pending"');
      sleep(1);
      wp_cache_delete('alloptions', 'options');
    }
    // After the loop make sure to remove all options again (not only the "alloptions" one)
    $this->flushCache('options_');
  }

  /**
   * Displays page contents for cache admin information
   */
  public function adminForm()
  {
    $message = '';
    // Do a flush
    if (isset($_POST['doFlushTotal'])) {
      $message = $this->flushCache();
    }
    if (isset($_POST['doFlushHtml'])) {
      $message = $this->flushCache(self::HTML_CACHE_PREFIX);
    }
    if (isset($_POST['doFlushOptions'])) {
      wp_cache_delete('option_alloptions');
      $message = $this->flushCache('options_');
    }
    if (isset($_POST['doBreakWoocommerceSuicideLoop'])) {
      $message = $this->breakWoocommerceSuicide();
    }

    // Get version infos directly from DB to omit cache
    $db = WordPress::getDb();
    $versions = $db->get_results('SELECT option_name, option_value FROM ' . $db->options . ' WHERE option_name LIKE "%version%"');
    $versionHtml = '';
    foreach ($versions as $version) {
      $versionHtml .= '
        <tr>
          <td><strong>' . $version->option_name . '</strong></td>
          <td>' . $version->option_value . '</td>
        </tr>
      ';
    }

    $html = '
			<div class="wrap">
				<div id="icon-tools" class="icon32"><br></div>
				<h2>Cache-Einstellungen</h2>
				' . $message . '
				<p>
				  Falls die Seite nicht korrekt angezeigt wird, können sie den Webseiten Cache, oder den kompletten Cache leeren.<br />
				</p>
				<form action="' . get_admin_url() . 'tools.php?page=cache-admin" method="post">
					<table>
						<tr>
							<td><input type="submit" name="doFlushHtml" value="Webseiten Cache leeren" class="button-primary" /></td>
							<td><input type="submit" name="doFlushTotal" value="Cache komplett leeren" class="button-primary" /></td>
							<td><input type="submit" name="doFlushOptions" value="Options neu laden" class="button-secondary" /></td>
						</tr>
					</table>
				</form>
				<br>
				<h2>Weitere Werkzeuge</h2>
				<form action="' . get_admin_url() . 'tools.php?page=cache-admin" method="post">
					<table>
						<tr>
							<td><input type="submit" name="doBreakWoocommerceSuicideLoop" value="Offene WooCommerce Actions unterbrechen" class="button-secondary" /></td>
						</tr>
						<tr>
							<td><input type="submit" name="showAllKeysStats" value="Key Statistiken anzeigen" class="button-secondary" /></td>
						</tr>
            ' . apply_filters('lbwp_cache_settings_additional_tools_buttons', '') . '
					</table>
				</form>
				<h2>Versionen (DB Query)</h2>
				<table class="wp-list-table widefat fixed striped posts float-left-top-margin">
          <tbody id="the-list">
            ' . $versionHtml . '
          </tbody>
        </table>
		';

    if (isset($_POST['showAllKeysStats'])) {
      $html .= '<p>&nbsp;</p><h2>Cache Keys Statistik</h2>';
      $html .= '<p>Die Statistik zeigt alle Keys, die im Cache gespeichert sind. </p>';
      // Get all keys from redis with our specific prefix
      $html .= '<table class="wp-list-table widefat fixed striped posts float-left-top-margin">';
      global $wp_object_cache;
      global $table_prefix;
      $read = $wp_object_cache->getReadConnection();
      $pattern = CUSTOMER_KEY . ':' . str_replace('_', ':', $table_prefix) . '*';
      $keys = $read->getKeys($pattern);
      $sortedKeys = array();
      $totalSizeByte = 0;
      foreach ($keys as $key) {
        $size = strlen($read->dump($key));
        $sortedKeys[$key] = $size;
        $totalSizeByte += $size;
      }
      arsort($sortedKeys);
      $html .= '<tr><th>Key</th><th>Grösse</th></tr>';
      $counter = 0;
      foreach ($sortedKeys as $key => $size) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($key) . '</td>';
        $html .= '<td>' . number_format($size / 1024, 2) . ' KB</td>';
        $html .= '</tr>';
        // Stop after thousand keys
        if (++$counter >= 1000) {
          $html .= '<tr><td colspan="2">... und ' . (count($keys) - 1000) . ' weitere Keys</td></tr>';
          break;
        }
      }
      $html .= '</table>';
      $html .= '<p>Insgesamt ' . count($keys) . ' Keys mit einer Grösse von ' . number_format($totalSizeByte / 1024 / 1024, 2) . ' MB</p>';
    }

    // If super admin, add some more info
    if (LbwpCore::isSuperlogin()) {
      global $wp_object_cache;
      global $table_prefix;
      $read = $wp_object_cache->getReadConnection();

      $html .= '<pre style="float:left;width:45%;">Write Connection:';
      $html .= Strings::getVarDump($wp_object_cache->getWriteConnection()->info());
      $html .= '</pre>';
      $html .= '<pre style="float:left;width:45%;">Read Connection:';
      $html .= Strings::getVarDump($read->info());
      $html .= '</pre>';

      $pattern = CUSTOMER_KEY . ':' . str_replace('_', ':', $table_prefix) . '*';
      if (isset($_GET['stats'])) {
        $keys = $size = 0;
        foreach ($read->getKeys($pattern) as $key) {
          $keys++;
          $size += strlen($read->dump($key));
        }

        $html .= '
          <p style="float:left;width:45%;">
            Stats:<br>
            Keys total: ' . $keys . '<br>
            Size total: ' . number_format($size/1024/1024, 2) . ' MB
          </p>';
      }
    }

    // Close the div
    $html .= '</div>';
    echo $html;
  }
}