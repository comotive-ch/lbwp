<?php

namespace LBWP\Module\General;

use LBWP\Core;
use LBWP\Helper\Cronjob;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Module\General\Multilang\OptionBridge;
use LBWP\Module\General\Cms\PageSpeed;
use LBWP\Core as LbwpCore;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This module holds serveral backend/frontend CMS features
 * @author Michael Sebel <michael@comotive.ch>
 */
class CmsFeatures extends \LBWP\Module\Base
{
  /**
   * @var string the last from email from global phpmailer filter
   */
  protected static $lastFromEmail = '';
  /**
   * @var array overrides for the yoast wpseo_titles option
   */
  protected $yoastTitlesOverrides = array(
    'hideeditbox-lbwp-form' => true,
    'hideeditbox-lbwp-table' => true,
    'hideeditbox-lbwp-list' => true,
    'hideeditbox-lbwp-listitem' => true,
    'hideeditbox-lbwp-snippet' => true,
    'hideeditbox-lbwp-user-group' => true,
    'hideeditbox-onepager-item' => true, // yes, lbwp missing
    'hideeditbox-lbwp-mailing-list' => true
  );
  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters and removes some.
   */
  public function initialize()
  {
    if (is_admin()) {
      // Backend features
      add_action('wp_dashboard_setup', array($this, 'addWidgets'), 20);
      add_filter('upload_mimes', array($this, 'filterUploadableFileTypes'));
      add_filter('lbwp_settings_various_maintenancemode', array($this, 'flushFrontendCache'), 1);
      add_filter('wp_link_query_args', array($this, 'removeUnlinkablePosttypes'));
      add_filter('wp_link_query', array($this, 'filterAssetLinks'));
      add_action('admin_menu', array($this, 'removeAdminMetaboxes'));
      add_action('transition_post_status', array($this, 'addGuaranteedPublication'), 200, 3);
      add_action('media_view_settings', array($this, 'overrideGallerySettings'));
      // Sub module singletons (calling getInstance runs them at the specified filter)
      add_action('admin_menu', array('LBWP\Module\General\Cms\SystemLog', 'getInstance'));
      add_filter('admin_body_class', array($this, 'addAdminBodyClasses'));
      add_filter('mce_external_plugins', array($this, 'loadEditorPlugins'));
      add_filter('option_wpseo_titles', array($this, 'mergeYoastTitleDefaults'));
      // Allow yoast seo title overrides
      $this->yoastTitlesOverrides = apply_filters('lbwp_yoast_seo_title_override', $this->yoastTitlesOverrides);
    } else {
      // Frontend features
      $url = File::getResourceUri() . '';
      wp_enqueue_style('lbwp-frontend', $url . '/css/lbwp-frontend.css', array(), LbwpCore::REVISION);
      // Add global rss filter to add media images
      add_action('rss2_item', array($this, 'addRssMediaItems'));
      add_action('rss2_ns', array($this, 'addRssNamespace'));
      add_filter('the_excerpt_rss', array($this, 'fixFeedExcerpt'));
      add_action('wp', array($this, 'runAfterQueryHooks'));
      // Additional robots content, if given
      if (strlen($this->config['Various:RobotsTxt']) > 0) {
        add_filter('robots_txt', array($this, 'addAdditionalRobotsContent'), 50);
      }
      // Redirect attachment detail to its parent post if given
      if ($this->config['Various:RedirectAttachmentDetail'] == 1) {
        add_filter('wp', array($this, 'redirectAttachmentToParent'));
      }
      // Frontend comment notifications are extended with our own
      if (strlen($this->config['Various:AdditionalCommentNotifications']) > 0) {
        add_filter('comment_notification_recipients', array($this, 'addCommentNotificationRecipients'));
      }
      // Create a page speed instance with default settings
      PageSpeed::getInstance();
      // Print acme challenge for ssl domain validation, if given
      if (is_array(get_option('letsEncryptAcmeChallenge')) && stristr($_SERVER['REQUEST_URI'], '/acme-challenge/') !== false) {
        $this->printAcmeChallenge();
      }
    }

    // Features that are run only in logged in password protection mode of posts
    if (isset($_COOKIE['wp-postpass_' . COOKIEHASH])) {
      $this->validatePasswordProtectionCookie();
      add_filter('the_content', array($this, 'addPasswordProtectionLogoutLink'));
    }

    // Multilang features
    if (Multilang::isActive()) {
      // Add translation files of lbwp textdomain in frontend / backend
      load_plugin_textdomain('lbwp', false, 'lbwp/resources/languages');
      // Feed fix filters
      add_filter('wp_title_rss', array($this, 'fixMultilangFeedTitle')); //[&#8230;]
      // Option bridge, to have multilang capable options
      OptionBridge::getInstance()->addDefaultOptions();
      // Allow to use the "language" parameter on WP REST API
      add_action('rest_api_init', array($this, 'addPolylangRestApiParams'));
    }

    // General features
    $this->registerLibraries();
    $this->initGlobalFeatures();
  }

  /**
   * Hooks and features that are run on "wp", so after the query is done
   */
  public function runAfterQueryHooks()
  {
    if ($this->config['NotFoundSettings:UsePermanentRedirect'] == 1 && is_404()) {
      header('Location:' . get_bloginfo('url'), null, 301);
      exit;
    }
  }

  /**
   * @param string $excerpt the excerpt
   * @return string the slighly changed excerpt
   */
  public function fixFeedExcerpt($excerpt)
  {
    if (Strings::endsWith($excerpt, ' [&#8230;]')) {
      return str_replace(' [&#8230;]', '...', $excerpt);
    }

    return $excerpt;
  }

  /**
   * Adds user defined content for the robots.txt file
   * @param string $txt the previous content
   * @return string the new content
   */
  public function addAdditionalRobotsContent($txt)
  {
    $txt .= PHP_EOL . $this->config['Various:RobotsTxt'] . PHP_EOL;

    return $txt;
  }

  /**
   * @param string $title original feed title
   * @return string the fixed title
   */
  public function fixMultilangFeedTitle($title)
  {
    return str_replace(' &#187; Languages', '', $title);
  }

  /**
   * Checks for global features (Switched on) and launches them
   */
  protected function initGlobalFeatures()
  {
    if ($this->config['Various:MaintenanceMode'] == 1) {
      MaintenanceMode::init();
    }

    // Globally used widgets
    add_action('widgets_init', array($this, 'registerGlobalWidgets'));
    add_filter('wp_mail_from', array($this, 'replaceEmailFrom'), 50);
    add_action('phpmailer_init', array($this, 'addReplyToEmail'), 50);
    //add_action('shutdown', array($this, 'trackUncachedResponseTime'));
    add_filter('antispam_bee_patterns', array($this, 'addCustomSpamPatterns'));
    add_action('cron_job_test_cron', array($this, 'testAndLogCron'));
  }

  /**
   * Mostly all outgoing mail needs to be sent from our domain. A later
   * filter will add the "last email from" as reply to address, if none is given
   */
  public function replaceEmailFrom($email)
  {
    self::$lastFromEmail = $email;
    // Honestly, if starting with wordpress@, it's bullshit. Then use admin_email
    if (Strings::startsWith($email, 'wordpress@')) {
      self::$lastFromEmail = get_option('admin_email');
    }

    return CUSTOM_EMAIL_SENDER;
  }

  /**
   * @param array $recipients the comment notification recipients
   * @return array maybe additional recipients
   */
  public function addCommentNotificationRecipients($recipients)
  {
    $emails = array_map('trim', explode(',', $this->config['Various:AdditionalCommentNotifications']));
    // Only add emails that are not already in the list
    foreach ($emails as $email) {
      if (!in_array($email, $recipients)) {
        $recipients[] = $email;
      }
    }

    return $recipients;
  }

  /**
   * Redirects and attachment to its parent, if given
   */
  public function redirectAttachmentToParent()
  {
    if (is_singular('attachment')) {
      $post = WordPress::getPost();
      if ($post->post_parent > 0) {
        header('Location: ' . get_permalink($post->post_parent), null, 301);
        exit;
      }
    }
  }

  /**
   * @param string $classes
   * @return string
   */
  public function addAdminBodyClasses($classes)
  {
    if ($_GET['ui'] == 'show-as-modal') {
      $classes .= 'modal-backend';
      // Allow to open it in modal once again after saving
      $_SESSION['open-modal-' . $_GET['post']] = true;
    }

    if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['post']) && !isset($_GET['ui'])) {
      if (isset($_SESSION['open-modal-' . $_GET['post']]) && isset($_GET['message'])) {
        $classes .= 'modal-backend';
        // Allow to open it in modal once again after saving
        $_SESSION['open-modal-' . $_GET['post']] = true;
      } else {
        unset($_SESSION['open-modal-' . $_GET['post']]);
      }
    }

    return $classes;
  }

  /**
   * @param \PHPMailer $phpMailer the mailer object
   */
  public function addReplyToEmail(&$phpMailer)
  {
    // If it has no reply to yet, add the last found from email from above function
    if (count($phpMailer->getReplyToAddresses()) == 0) {
      $phpMailer->addReplyTo(self::$lastFromEmail);
    }
  }

  /**
   * Registering global libraries that can be used by themes
   */
  protected function registerLibraries()
  {
    $url = File::getResourceUri() . '';
    wp_register_script('jquery-mobile-events', $url . '/js/jquery-mobile-events.min.js', array('jquery'), LbwpCore::REVISION, true);
    wp_register_script('jquery-multisort', $url . '/js/jquery.multisort.js', array('jquery'), LbwpCore::REVISION, true);
    wp_register_script('jquery-cookie', $url . '/js/jquery.cookie.js', array('jquery'), LbwpCore::REVISION, true);
    wp_register_script('lbwp-gallery-inline-fix', $url . '/js/lbwp-gallery-inline-fix.js', array('jquery'), LbwpCore::REVISION, true);
    wp_register_script('lbwp-gallery-inline-fix-v2', $url . '/js/lbwp-gallery-inline-fix-v2.js', array('jquery'), LbwpCore::REVISION, true);
    wp_register_style('jquery-ui-theme-lbwp', $url . '/css/jquery.ui.theme.min.css', array(), LbwpCore::REVISION);
    wp_register_script('chosen-js', $url . '/js/chosen/chosen.jquery.min.js', array('jquery'), LbwpCore::REVISION);
    wp_register_script('chosen-sortable-js', $url . '/js/chosen/chosen.sortable.jquery.js', array('chosen-js'), LbwpCore::REVISION);
    wp_register_style('chosen-css', $url . '/js/chosen/chosen.min.css', array(), LbwpCore::REVISION);
  }

  /**
   * @param mixed $value the settings value
   * @return mixed the unchanged value
   */
  public function flushFrontendCache($value)
  {
    $module = LbwpCore::getModule('MemcachedAdmin');
    if ($module instanceof MemcachedAdmin) {
      $module->flushFrontendCache(true);
    }

    // Return value to be saved
    return $value;
  }

  /**
   * @param array $results link search results
   * @return array cleaned up array from images and with better info
   */
  public function filterAssetLinks($results)
  {
    // Save results in $files, other handling for polylang
    $query = $_POST['search'];
    // Initialize util arrays
    $additionalResults = array();
    $removedTypes = array('image/jpeg', 'image/png', 'image/gif');
    $results = ArrayManipulation::forceArray($results);

    // If there is polylang there are no file results possible, search directly
    if (strlen($query) > 0) {
      // Do a direct database query, since media are not translated and not found
      $db = WordPress::getDb();
      $sql = '
        SELECT ID,post_mime_type,post_type,guid FROM {sql:postTable}
        WHERE (post_title LIKE {escape:queryText} OR guid LIKE {escape:queryText})
        AND post_type = "attachment" ORDER BY post_date DESC
      ';

      // To the same query as if to load the post data used below
      $additionalResults = $db->get_results(Strings::prepareSql($sql, array(
        'postTable' => $db->posts,
        'queryText' => '%' . str_replace('*', '%', $query) . '%'
      )));
    }

    // Filter existing results from images
    foreach ($results as $key => $result) {
      $item = get_post($result['ID']);
      if ($item->post_type == 'attachment') {
        if (in_array($item->post_mime_type, $removedTypes)) {
          // Remove from results if image
          unset($results[$key]);
        } else {
          // Add better info, if asset
          $results[$key]['permalink'] = $item->guid;
          $results[$key]['title'] = File::getFileOnly($item->guid);
          $results[$key]['info'] = substr(strtoupper(File::getExtension($item->guid)), 1);
        }
      }
    }

    // Filter results of images and only add "real" assets
    foreach ($additionalResults as $item) {
      if (!in_array($item->post_mime_type, $removedTypes)) {
        $results[] = array(
          'permalink' => $item->guid,
          'title' => File::getFileOnly($item->guid),
          'info' => substr(strtoupper(File::getExtension($item->guid)), 1)
        );
      }
    }

    // Return false if no files, for the editor to not go crazy
    if (!is_array($results) || count($results) == 0) {
      return false;
    } else {
      return $results;
    }
  }

  /**
   * Print challenge data for ssl domain validation
   */
  protected function printAcmeChallenge()
  {
    // Test for a specified path for the host
    foreach (get_option('letsEncryptAcmeChallenge') as $challenge) {
      if ($_SERVER['REQUEST_URI'] == $challenge['path']) {
        echo $challenge['content'];
        header('HTTP/1.1 200 OK');
        header('Content-Type:text/plain');
        exit;
      }
    }
  }

  /**
   * @param array $query query arguments
   * @return array cleaned arguments
   */
  public function removeUnlinkablePosttypes($query)
  {
    $postTypes = array();
    $forbiddenTypes = array('lbwp-form', 'lbwp-snippet', 'lbwp-list', 'lbwp-listitem', 'lbwp-user-group','onepager-item');
    // Rebuild array (fastest way to do this, actually)
    foreach ($query['post_type'] as $type) {
      if (!in_array($type, $forbiddenTypes)) {
        $postTypes[] = $type;
      }
    }

    $query['post_type'] = $postTypes;
    return $query;
  }

  /**
   * Removes unused or unsenseful metaboxes
   */
  public function removeAdminMetaboxes()
  {
    remove_meta_box('commentsdiv', 'post', 'normal'); // Comments Metabox
    remove_meta_box('postcustom', 'post', 'normal'); // Custom Fields Metabox
    remove_meta_box('trackbacksdiv', 'post', 'normal'); // Trackback Metabox
    remove_meta_box('postcustom', 'post', 'normal');
    remove_meta_box('postcustom', 'page', 'normal');
  }

  /**
   * Blindly verride the gallery settings, if given
   * -> Sets "link to" dropdown to media item, instead of attachment link
   */
  public function overrideGallerySettings($settings)
  {
    $settings['galleryDefaults']['link'] = 'file';
    return $settings;
  }

  /**
   * Register widgets that are globally useable by customers
   */
  public function registerGlobalWidgets()
  {
    register_widget('\LBWP\Theme\Widget\ClonedContent');
  }

  /**
   * Adds some lbwp widgets
   */
  public function addWidgets()
  {
    // Disable the widgets, if needed
    if (defined('LBWP_DISABLE_DASHBOARD_WIDGETS')) {
      return;
    }

    // LBWP news widget
    wp_add_dashboard_widget(
      'lbwp-news',
      'LBWP News',
      array(
        '\LBWP\Module\General\Cms\AdminDashboard',
        'getNewsFeed'
      )
    );

    // Usage statistics dashboard
    wp_add_dashboard_widget(
      'lbwp-usage',
      'Infrastruktur-Nutzung',
      array(
        '\LBWP\Module\General\Cms\AdminDashboard',
        'getUsageStatistics'
      )
    );
  }

  /**
   * Adds or removes support for certain file types
   * @param array $types wordpress types array
   * @return array same array, possibly expaneded
   */
  public function filterUploadableFileTypes($types)
  {
    if (!isset($types['vcf'])) {
      $types['vcf'] = 'text/vcard';
    }

    return $types;
  }

  /**
   * Tests the cron and logs the data given
   */
  public function testAndLogCron()
  {
    SystemLog::add('testCron', 'debug', 'received external cron call', $_GET);
  }

  /**
   * @param string $new new status (only do something if future)
   * @param string $old old status (ignored)
   * @param \WP_Post $post the post object beeing transitioned
   */
  public function addGuaranteedPublication($new, $old, $post)
  {
    // If the article is in future and is allowed
    if ($new == 'future') {
      // Get the publication timestamp
      $timestamp = strtotime($post->post_date);
      // Add to jobs slightly later than the actual publication
      Cronjob::register(array(
        ($timestamp + 90) => 'flush_html_cache'
      ));
    }
  }

  /**
   * Allows administrator to add users even if a same email for a user already exists
   */
  public static function allowIndistinctAuthorEmail()
  {
    add_action(
      'user_profile_update_errors',
      array('\LBWP\Module\General\CmsFeatures', 'removeEmailExistsError')
    );
  }

  /**
   * @param \WP_Error $errors the error array on saving users
   */
  public static function removeEmailExistsError($errors)
  {
    $codes = $errors->get_error_codes();
    if (in_array('email_exists', $codes) && count($codes) == 1) {
      $errors->remove('email_exists');
      // Set a constant, that it also skips the check in insert_user
      define('WP_IMPORTING', true);
    }
  }

  /**
   * @param array $plugins
   * @return array
   */
  public function loadEditorPlugins($plugins)
  {
    $plugins['wplinkpre45'] = File::getResourceUri() . '/js/tinymce/wplinkpre45.js';
    return $plugins;
  }

  /**
   * Called filter at every rss item, providing the additional feed thumbnails tags
   */
  public function addRssMediaItems()
  {
    global $post;

    // Get url of the attachment item
    $imageSize = apply_filters('feed_rss_media_type', 'thumbnail');
    $id = get_post_thumbnail_id($post->ID);
    list($url) = wp_get_attachment_image_src($id, $imageSize, false);

    if ($url != '') {
      $attachment = get_post($id);
      $metaData = get_post_meta($id, '_wp_attachment_metadata', true);

      if (isset($metaData['sizes'][$imageSize]) && !isset($metaData['sizes'][$imageSize]['filesize'])) {
        if (stristr($url, Core::getCdnName()) !== false) {
          $fileName = str_replace(Core::getCdnProtocol() . '://' . Core::getCdnName() . '/', '', $url);
        } else {
          $fileName = $url;
        }

        // Get s3 item size
        $metaData['sizes'][$imageSize]['filesize'] = Core::getModule('S3Upload')->getFileSize($fileName);

        if ($metaData['sizes'][$imageSize]['filesize'] > 0) {
          update_post_meta($id, '_wp_attachment_metadata', $metaData);
        }
      }

      echo '<enclosure url="' . $url . '" type="' . $attachment->post_mime_type . '" length="' . $metaData['filesize'] . '" />' . "\n";
      echo '<media:content url="' . $url . '" type="' . $attachment->post_mime_type . '" expression="sample" />' . "\n";
    }
  }

  /**
   * @param array $patterns the standard patterns
   * @return array improved patterns
   */
  public function addCustomSpamPatterns($patterns)
  {
    // Body text with two or less characters
    $patterns[] = array('body' => '^(?=.{0,2}$).*');
    // Spammy email addresses (gmail would be here too, but this is not useful for an european blog)
    $patterns[] = array('email' => '@mail\.ru|@yandex\.$');
    // Every comment with .ru/.bid top level domain
    $patterns[] = array('email' => '(^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.(ru|bid)+$)');
    // Spam text in email, host and body
    $patterns[] = array('email' => 'viagra|cialis|casino');
    $patterns[] = array('host' => 'viagra|cialis|casino');
    $patterns[] = array('body' => 'target[t]?ed (visitors|traffic)|viagra|cialis');
    // 3 or more links in body
    $patterns[] = array('body' => '(.*(http|https|ftp|ftps)\:\/\/){3,}');
    // Non latin characters (like Cyricllic, Japanese, etc.) in body
    $patterns[] = array('body' => '\p{Arabic}|\p{Armenian}|\p{Bengali}|\p{Bopomofo}|\p{Braille}|\p{Buhid}|\p{Canadian_Aboriginal}|\p{Cherokee}|\p{Cyrillic}|\p{Devanagari}|\p{Ethiopic}|\p{Georgian}|\p{Greek}|\p{Gujarati}|\p{Gurmukhi}|\p{Han}|\p{Hangul}|\p{Hanunoo}|\p{Hebrew}|\p{Hiragana}|\p{Inherited}|\p{Kannada}|\p{Katakana}|\p{Khmer}|\p{Lao}|\p{Limbu}|\p{Malayalam}|\p{Mongolian}|\p{Myanmar}|\p{Ogham}|\p{Oriya}|\p{Runic}|\p{Sinhala}|\p{Syriac}|\p{Tagalog}|\p{Tagbanwa}|\p{Tamil}|\p{Telugu}|\p{Thaana}|\p{Thai}|\p{Tibetan}|\p{Yi}');

    return $patterns;
  }

  /**
   * Validates the password protection cookie or unsets it if needed
   */
  protected function validatePasswordProtectionCookie()
  {
    if (isset($_GET['wp-pwpt']) && $_GET['wp-pwpt'] == 'logout') {
      setcookie('wp-postpass_' . COOKIEHASH, NULL, time() - 86400, '/');
      header('Location: ' . Strings::getUrlWithoutParameters());
      exit;
    }
  }

  /**
   * @param string $html the post content
   * @return string same content with logout message and link on top of it
   */
  public function addPasswordProtectionLogoutLink($html)
  {
    global $post;
    $hint = '';

    // Only show this hint on a password protected page
    if (strlen($post->post_password) > 0 && !post_password_required()) {
      $hint = '
        <p class="lbwp-password-protected-hint">
          ' . sprintf(__('Sie sind für einen geschützen Bereich angemeldet. <a href="%s">Abmelden</a>.', 'lbwp'), $this->getPasswordProtectionLogoutLink($post)) . '
        </p>
      ';
    }

    return $hint . $html;
  }

  /**
   * @param \WP_Post $post the post object
   * @return string get password protection logout link
   */
  public function getPasswordProtectionLogoutLink($post)
  {
    $link = get_permalink($post);
    if (stristr($link, '?') === false) {
      $link .= '?wp-pwpt=logout';
    } else {
      $link .= '&wp-pwpt=logout';
    }

    return $link;
  }

  /**
   * Add support for polylang to work with REST API
   */
  public function addPolylangRestApiParams()
  {
    global $polylang;

    $default = pll_default_language();
    $langs = pll_languages_list();

    $paramLang = $_GET['lang'];
    if (!in_array($paramLang, $langs)) {
      $paramLang = $default;
    }

    $polylang->curlang = $polylang->model->get_language($paramLang);
    $GLOBALS['text_direction'] = $polylang->curlang->is_rtl ? 'rtl' : 'ltr';
  }

  /**
   * Hide yoast seo crap on our internal types
   * @param array $option
   * @return mixed
   */
  public function mergeYoastTitleDefaults($option)
  {
    return array_merge($option, $this->yoastTitlesOverrides);
  }

  /**
   * Called at the rss_ns filter to print an additional media-rss namespace
   */
  public function addRssNamespace()
  {
    echo 'xmlns:media="http://search.yahoo.com/mrss/" ';
  }

  /**
   * Tracks uncached response times
   */
  public function trackUncachedResponseTime()
  {
    global $lbwpTime;
    //\StatsD::gauge('lbwp.gauges.requests.uncached', (microtime(true) - $lbwpTime) * 1000);
  }
}
