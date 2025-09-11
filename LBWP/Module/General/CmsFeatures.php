<?php

namespace LBWP\Module\General;

use LBWP\Core;
use LBWP\Helper\Cronjob;
use LBWP\Helper\Location;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\Backend\MonitorLogins;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\Forms\Component\Posttype;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Module\General\Cms\DeadLinkChecker;
use LBWP\Module\General\Cms\EraseUserData;
use LBWP\Module\General\Cms\PageSpeed;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Module\General\Cms\UsageBasedBilling;
use LBWP\Module\General\Multilang\OptionBridge;
use LBWP\Theme\Feature\MoodOwl;
use LBWP\Theme\Feature\SecureAssets;
use LBWP\Theme\Component\WesignBase;
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
   * @var bool tells of currently creating attachment translations in background
   */
  protected static $creatingAttachmentTranslations = false;
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
   * @var int the basic jpeg quality
   */
  const JPEG_QUALITY = 86;

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
      $this->eventuallyConvertWebpUploads();
      // Backend features
      add_action('admin_footer', array($this, 'allowSpecialFileTypesClientside'));
      add_filter('upload_mimes', array($this, 'filterUploadableFileTypes'));
      add_filter('lbwp_settings_various_maintenancemode', array($this, 'flushFrontendCache'), 1);
      add_filter('wp_link_query_args', array($this, 'removeUnlinkablePosttypes'));
      add_filter('wp_link_query', array($this, 'filterAssetLinks'));
      add_action('admin_menu', array($this, 'removeAdminMetaboxes'));
      add_action('transition_post_status', array($this, 'addGuaranteedPublication'), 200, 3);
      add_action('media_view_settings', array($this, 'overrideGallerySettings'));
      add_action('save_post_shop_subscription', array($this, 'writeSubscriptionSearchIndex'));
      // Sub module singletons (calling getInstance runs them at the specified filter)
      add_action('init', array('LBWP\Module\General\Cms\ReplaceFile', 'getInstance'));
      add_filter('admin_body_class', array($this, 'addAdminBodyClasses'));
      add_action('admin_footer', array($this, 'generalAdminFooter'));
      //add_filter('mce_external_plugins', array($this, 'loadEditorPlugins'));
      add_filter('option_wpseo_titles', array($this, 'mergeYoastTitleDefaults'));
      add_action('enqueue_block_editor_assets', array($this, 'addGlobalGutebergInlineScripts'));
      // Allow yoast seo title overrides
      $this->yoastTitlesOverrides = apply_filters('lbwp_yoast_seo_title_override', $this->yoastTitlesOverrides);
      // Add reusable block to main menu (if any registered)
      add_action('admin_menu', array($this, 'addReusableBlockMenu'));
      $this->addAcfContentToYoast();
      add_action('admin_init', array($this, 'addBackendJsData'));
    } else {
      // Frontend features
      $url = File::getResourceUri() . '';
      wp_enqueue_style('lbwp-frontend', $url . '/css/lbwp-frontend.css', array(), Core::REVISION);
      // Add global rss filter to add media images
      add_action('rss2_item', array($this, 'addRssMediaItems'));
      add_action('rss2_ns', array($this, 'addRssNamespace'));
      add_filter('the_excerpt_rss', array($this, 'fixFeedExcerpt'));
      add_action('wp_head', array($this, 'printGlobalOutputs'));
      add_action('wp', array($this, 'runAfterQueryHooks'));
      add_action('wp', array($this, 'checkBookableWoocommerceProduct'));
      add_action('pre_ping', array($this, 'preventInternalPingback'));
      add_filter('wpseo_metadesc', array($this, 'getFallbackMetaDesc'));
      // This really has to be a spaghetti function to use nested classes
      add_filter('weglot_get_dom_checkers', 'lbwp_addWeglotDomCheckers');
      add_filter('robots_txt', array($this, 'addGeneralRobotsContent'), 40);
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

      // Reusable blocks hack (flush cache on safe)
      if(function_exists('wp_is_json_request') && wp_is_json_request()){
        add_action('post_updated', array($this, 'flushReusableBlocksCache'));
      }
    }

    SystemLog::getInstance();
    // Call dead link checker for customers who activate it
    if (!defined('LBWP_DISABLE_DEADLINK_CHECKER')) {
      DeadLinkChecker::getInstance();
    }

    // Usage based billing if applicable
    UsageBasedBilling::getInstance();
    // Handle user privacy data erasement
    new EraseUserData();
    // Monitor login devices
    new MonitorLogins();

    // Register the image-mood-colors-tool a.k.a. MoodOwl
    if (!defined('LBWP_DISABLE_MOODOWL')) {
      new MoodOwl();
    }

    // Features that are run only in logged in password protection mode of posts
    if (isset($_COOKIE['wp-postpass_' . COOKIEHASH])) {
      $this->validatePasswordProtectionCookie();
      add_filter('the_content', array($this, 'addPasswordProtectionLogoutLink'));
    }


    // Multilang features
    if (Multilang::isActive()) {
      $this->maybeAutoLanguageRedirect('polylang');
      // Feed fix filters
      add_filter('wp_title_rss', array($this, 'fixMultilangFeedTitle')); //[&#8230;]
      add_filter('add_attachment', array($this, 'createAttachmentTranslations'), 1000, 1);
      // Option bridge, to have multilang capable options
      OptionBridge::getInstance()->addDefaultOptions();
    } else if (Multilang::isWeGlot()) {
      add_action('init', function() {
        $this->maybeAutoLanguageRedirect('weglot');
      });
    }

    // Some filters, if ACF is on
    if (WordPress::isPluginActive('advanced-custom-fields-pro/acf.php')) {
      add_filter('acf/settings/capability', array($this, 'restrictAcfCapabilities'));
      add_filter('acf/input/meta_box_priority', array($this, 'overrideMetaboxPriority'), 10, 2);
    }

    // General features
    $this->registerLibraries();
    $this->initGlobalFeatures();
  }

  /**
   * Takes on the $_POST/$_FILES array very early to suggest wordpress the user uploaded an actual webp instead of jpg
   * The jpg ist convertet to webp in this function and lost (at the moment)
   * @return void
   */
  protected function eventuallyConvertWebpUploads()
  {
    $config = Core::getInstance()->getConfig();
    $disabled = isset($config['Various:DisableWebpConversion']) && ($config['Various:DisableWebpConversion'] == 1);

    if (!defined('LBWP_DISABLE_AUTO_WEBP_CONVERSION') && !$disabled && isset($_POST['action']) && $_POST['action'] == 'upload-attachment') {
      // Check $_FILES for jpg/png images and convert them so woredpress thinks a webp was uploaded
      if (isset($_FILES) && is_array($_FILES)) {
        foreach ($_FILES as $key => $file) {
          if (is_array($file) && isset($file['type']) && in_array($file['type'], array('image/jpeg', 'image/png'))) {
            $originalType = $file['type'];
            $file['type'] = 'image/webp';
            // Change its original name to the new extension
            $file['name'] = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file['name']);
            $file['full_path'] = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file['full_path']);
            $_POST['name'] = $file['name'];
            // Convert the actual file
            $sourceName = str_contains($originalType, '/jp') ? $file['tmp_name'] . '.jpg' : $file['tmp_name'] . '.png';
            $convertedName = $file['tmp_name'] . '.webp';
            // Convert to webp
            copy($file['tmp_name'], $sourceName);
            exec('convert ' . $sourceName . ' ' . $convertedName);
            rename($convertedName, $file['tmp_name']);
            $file['size'] = filesize($file['tmp_name']);
            unlink($sourceName);
            $_FILES[$key] = $file;
          }
        }
      }
    }
  }

  /**
   * @param $file
   * @return void
   */
  public function sideloadComplianzCssFiles($file)
  {
    /** @var S3Upload $uploader */
    $uploader = Core::getModule('S3Upload');
    // Use substring of path after finding ASSET_KEY in it
    $remote = substr($file, strpos($file, ASSET_KEY) + strlen(ASSET_KEY));
    $uploader->uploadDiskFileFixedPath($file, $remote, 'text/css', true);
  }

  /**
   * Changes the url of the css file to be loaded from block store
   * @param array $settings
   * @return array
   */
  public function afixComplianzCookieBannerSettings($settings)
  {
    $settings['css_file'] = 'https://assets01.sdd1.ch/assets/lbwp-cdn/' . ASSET_KEY . '/files/complianz/css/banner-{banner_id}-{type}.css?v=' . Core::REVISION;
    return $settings;
  }

  /**
   * @return void
   */
  public function generalAdminFooter()
  {
    if (isset($_GET['role']) && isset($_GET['s']) && strlen($_GET['s']) > 0) {
      echo '<style>.displaying-num { display:none; }';
    }
  }

  /**
   * @return void
   */
  public function allowSpecialFileTypesClientside() {
    echo <<<HTML
      <script>
          (function(){
              if (typeof wp !== 'undefined' && wp.Uploader) {
                  var oldInit = wp.Uploader.prototype.initialize;
                  wp.Uploader.prototype.initialize = function() {
                      if (this.options.filters) {
                          this.options.filters.mime_types.push({
                              title: 'Web Fonts',
                              extensions: 'woff2'
                          });
                      }
                      return oldInit.apply(this, arguments);
                  };
              }
          })();
      </script>
    HTML;
  }

  /**
   * Mainly updates the address index on subscriptions when not done yet
   * This function is only called in non-HPOS mode, and is not needed with HPOS anymore
   * @param $subscriptionId
   * @return void
   */
  public function writeSubscriptionSearchIndex($subscriptionId)
  {
    $subscription = wcs_get_subscription($subscriptionId);
    update_post_meta( $subscriptionId, '_billing_address_index', implode( ' ', $subscription->get_address( 'billing' ) ) );
    update_post_meta( $subscriptionId, '_shipping_address_index', implode( ' ', $subscription->get_address( 'shipping' ) ) );
  }

  /**
   * Does a one time auto redirect based on browser language, should only be called if multilang is available
   * @param string $type one of polylang or weglot
   */
  protected function maybeAutoLanguageRedirect($type)
  {
    // First, make sure to not cache this request at all
    if ($_SERVER['REQUEST_URI'] == '/' && !defined('LBWP_SKIP_AUTO_LANGUAGE_REDIRECT')) {
      HTMLCache::avoidCache();
    }

    // Do it only on absolute / starting point, when no cookie is set and when its not disabled
    if ($_SERVER['REQUEST_URI'] == '/' && !isset($_SESSION['lbwp-auto-lang-redirect']) && !defined('LBWP_SKIP_AUTO_LANGUAGE_REDIRECT')) {
      // Get actual language, just let it go when "de" as this is always the default
      $language = Location::getLangFromBrowser();
      if ($language == 'de') {
        return;
      }

      // Get the redirect to the translates home site
      $redirect = '';
      switch ($type) {
        case 'weglot':
          // For weglot we can just redirect to the language tag
          foreach (weglot_get_destination_languages() as $candidate) {
            if ($candidate['language_to'] == $language && $candidate['public']) {
              $redirect = 'https://' . LBWP_HOST . '/' . $language . '/'; break;
            }
          }
          break;
        case 'polylang':
          // Redirect to another frontpage/blog if valid
          if (in_array($language, Multilang::getAllLanguages())) {
            $redirect = Multilang::getHomeUrl($language);
          }
          break;
      }

      // Add the cookie
      if (Strings::checkUrl($redirect)) {
        $_SESSION['lbwp-auto-lang-redirect'] = true;
        header('Location: ' . $redirect, null, 302);
        exit;
      }
    }
  }

  /**
   * @return void
   */
  public function printGlobalOutputs()
  {
    echo '
      <script type="text/javascript">
        var lbwpGlobal = ' . json_encode(array(
          'language' => (Multilang::isWeGlot()) ? Multilang::getWeGlotLanguage() : Multilang::getCurrentLang(),
          'version' => str_replace('.', '', wp_get_theme()->get('Version')),
        )) . '
      </script>
    ';
  }

  /**
   * @param string $priority
   * @param array $box
   */
  public function overrideMetaboxPriority($priority, $box)
  {
    if (isset($box['priority']) && strlen($box['priority']) > 0) {
      return $box['priority'];
    }
    return $priority;
  }

  /**
   * @param $path
   * @return bool|string
   */
  public function restrictAcfCapabilities($path)
  {
    return (Core::isSuperlogin() || defined('LBWP_ENABLE_ACF_BACKEND')) ? 'administrator' : false;
  }

  /**
   * @param string $desc current meta desc
   * @return string when $desc is empty, post_excerpt (which can also be empty)
   */
  public function getFallbackMetaDesc($desc)
  {
    global $post;
    if (strlen($desc) == 0 && $post instanceof \WP_Post) {
      return $post->post_excerpt;
    }
    return $desc;
  }

  /**
   * Hooks and features that are run on "wp", so after the query is done
   */
  public function runAfterQueryHooks()
  {
    if ($this->config['NotFoundSettings:UsePermanentRedirect'] == 1 && is_404() && !is_home() && !is_front_page()) {
      header('Location: ' . get_bloginfo('url'), null, 301);
      exit;
    }
  }

  /**
   * Do not cache bookings as they use ajax security that expires
   */
  public function checkBookableWoocommerceProduct()
  {
    if (is_singular('product') && Core::hasWooCommerce()) {
      $product = wc_get_product(get_the_ID());
      if ($product->is_type('booking')) {
        HTMLCache::avoidCache();
      }
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
   * Adds general things to robots.txt for ALL websites
   * @param string $txt the previous content
   * @return string the new content
   */
  public function addGeneralRobotsContent($txt)
  {
    $txt .= PHP_EOL . 'Crawl-Delay: 5' . PHP_EOL;

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
      MaintenanceMode::setHeader($this->config['HeaderFooterFilter:HeaderHtml']);
    }

    // Try autologin if given
    if (isset($_GET['lbwp-autologin'])) {
      $this->tryAutoLogin($_GET['lbwp-autologin']);
    }

    // Load text domain
    remove_filter('the_title', 'capital_P_dangit', 11);
    remove_filter('the_content', 'capital_P_dangit', 11);
    remove_filter('comment_text', 'capital_P_dangit', 31);
    // Globally used widgets
    add_action('plugins_loaded', array($this, 'loadTranslationFiles'), 20);
    add_action('cmplz_after_css_generation', array($this, 'sideloadComplianzCssFiles'), 10, 1);
    add_filter('cmplz_cookiebanner_settings_front_end', array($this, 'afixComplianzCookieBannerSettings'));
    add_filter('jpeg_quality', array($this, 'setCompressionRate'), 20, 2);
    add_filter('wp_editor_set_quality', array($this, 'setCompressionRateInEditor'), 20, 2);
    add_filter('antispam_bee_patterns', array($this, 'addCustomSpamPatterns'));
    add_action('widgets_init', array($this, 'registerGlobalWidgets'));
    add_action('cron_job_test_cron', array($this, 'testAndLogCron'));
    add_filter('admin_email_check_interval', '__return_false');
    add_filter('render_block', array($this, 'blockRenderFilters'), 10, 2);
    add_filter('ppp_nonce_life', array($this, 'extendPublicPreviewNonce'));
    add_filter('woocommerce_enable_auto_update_db', '__return_true');
    add_filter('wp_get_nav_menu_items', array($this, 'uncacheMenuItemClasses'));
    add_filter('wpseo_should_index_links', '__return_false', 50);
    add_filter('woo_ce_get_orders_args', array($this, 'preventCachingOnSDExport'));
    add_filter('woo_ce_enable_order_tax_rates', '__return_false');
    add_action('rest_api_init', array($this, 'registerApiEndpoints'));
    add_action('wp_login', array($this, 'logLastLogin'), 10, 2);
    add_action('wp_footer', array($this, 'preventPreviewContentCaching'));
    add_filter('script_loader_tag', array($this, 'filterScripts'), 10, 2);
    // Use our own mail services unless disabled
    if (!defined('LBWP_USE_EXTERNAL_WP_MAIL_SERVICE')) {
      add_filter('wp_mail_from', array($this, 'replaceEmailFrom'), 50);
      add_filter('wp_mail_from_name', array($this, 'replaceEmailFromName'), 50);
      add_action('phpmailer_init', array($this, 'configurePhpMails'), 50);
      // Full featured logging of outgoing mails
      new Cms\MailLogger();
    }
    // Initialize secure assets
    if (CDN_TYPE != CDN_TYPE_NONE) {
      SecureAssets::init();
    }

    // Initialize wesign base component
    if (defined('LBWP_WESIGN_INSTANCE')) {
      new WesignBase();
    }
  }

  /**
   * Somehow WordPress caches the content of the preview/revision to the original post ubject
   * thus the next load in public will bring up the preview/drafted content.
   * We prevent that by cleaning the post cache after loading the object in preview
   * @return void
   */
  public function preventPreviewContentCaching()
  {
    if (isset($_GET['preview_id']) && isset($_GET['preview_nonce']) && current_user_can('edit_posts')) {
      clean_post_cache(intval($_GET['preview_id']));
    }
  }

  /**
   * @param $tag
   * @param $handle
   * @return array|string|string[]
   */
  public function filterScripts($tag, $handle){
    $alternateType = wp_scripts()->get_data($handle, 'type');
    if ($alternateType) {
      return str_replace('text/javascript', $alternateType, $tag);
    }
    return $tag;
  }

  /**
   * @return void
   */
  public function registerApiEndpoints()
  {
    register_rest_route('lbwp/core', 'inbox', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'runIncomingMailTrigger')
    ));
  }

  /**
   * Runs a filter when a mail is incoming to @commotive.ch
   * @return void
   */
  public function runIncomingMailTrigger()
  {
    $filter = Strings::forceSlugString($_REQUEST['filter']);
    SystemLog::mDebug('incoming mail as ' . $filter . ', subject: ' . $_REQUEST['subject']);
    do_action('lbwp_incoming_mail_' . $filter, $_REQUEST);
  }

  /**
   * prevents store exporter from caching the whole DB, thus loading extremely slow
   * @param array $args
   * @return array
   */
  public function preventCachingOnSDExport($args)
  {
    $args['cache_results'] = false;
    $args['update_post_term_cache'] = false;
    $args['update_post_meta_cache'] = false;
    return $args;
  }

  /**
   * WordPress cached the current-menu-item class on every single item object, thus
   * leading to having every menu have the class after some time. We need this removed
   * after getting them from cache
   * @param $items
   * @return $items
   */
  public function uncacheMenuItemClasses($items)
  {
    foreach ($items as &$item) {
      if (isset($item->classes) && is_array($item->classes)) {
        $item->classes = array_filter($item->classes, function($class) {
          return !str_contains($class, 'current');
        });
      }
    }
    unset($item);

    return $items;
  }

  /**
   * Prevent send and log every mail when local development
   * @param bool $send
   * @param array $mail
   * @return bool|null
   */
  public function logLocalWpMails($send, $mail)
  {
    if (SystemLog::logMailLocally($mail['subject'], $mail['email'], $mail['message'])) {
      return false;
    }

    return $send;
  }

  /**
   * @return void load translation files
   */
  public function loadtranslationFiles()
  {
    // Add translation files of lbwp textdomain in frontend / backend
    load_plugin_textdomain('lbwp', false, 'lbwp/resources/languages');
  }

  /**
   * @param $time
   * @return float|int
   */
  public function extendPublicPreviewNonce($time)
  {
    return 7 * DAY_IN_SECONDS;
  }

  /**
   * Adds block and namespace wide render block filters
   * @param $html
   * @param $block
   * @return mixed
   */
  public function blockRenderFilters($html, $block)
  {
    list($namespace, $name) = explode('/', $block['blockName']);
    $html = apply_filters('render_block_' . $namespace . '_' . $name, $html, $block);
    $html = apply_filters('render_block_' . $namespace, $html, $block);
    return $html;
  }

  /**
   * @param $links
   */
  public function preventInternalPingback(&$links)
  {
    $url = get_bloginfo('url');
    foreach ($links as $l => $link) {
      if (0 === strpos($link, $url)) {
        unset($links[$l]);
      }
    }
  }

  /**
   * Creates empty translated connected copies of uploaded new media, if translation is on
   * @param int $attachmentId the newly created attachment
   */
  public function createAttachmentTranslations($attachmentId)
  {
    // Skip to prevent endless loop, since create_media_translation also triggers add_attachment
    if (self::$creatingAttachmentTranslations) {
      return;
    }
    // If its polylangs own action, skip as well to prevent loops
    if (isset($_GET['action']) && $_GET['action'] == 'translate_media' && isset($_GET['from_media'])) {
      return;
    }

    $settings = get_option('polylang');
    if ($settings['media_support'] == 1) {
      global $polylang;
      /** @var \PLL_CRUD_Posts $crud */
      $crud = $polylang->posts;
      $current = Multilang::getPostLang($attachmentId);
      if (strlen($current) == 2) {
        foreach (Multilang::getAllLanguages() as $lang) {
          if ($current !== $lang) {
            // Create translations and prevent endless looping from create_media_translation
            self::$creatingAttachmentTranslations = true;
            $crud->create_media_translation($attachmentId, $lang);
            self::$creatingAttachmentTranslations = false;
          }
        }
      }
    }
  }

  /**
   * @param int $value the initial value
   * @param string $context:
   * @return int the new fixed value
   */
  public function setCompressionRate($quality, $context)
  {
    $customerQuality = intval(Core::getInstance()->getConfig()['Various:ImageCompressionRatio']);
    return $customerQuality > 0 ? $customerQuality : self::JPEG_QUALITY;
  }

  /**
   * @param int $value the initial value
   * @param string $mime the mime type of the image being processed
   * @return int the new fixed value
   */
  public function setCompressionRateInEditor($quality, $mime)
  {
    $customerQuality = intval(Core::getInstance()->getConfig()['Various:ImageCompressionRatio']);
    return $customerQuality > 0 ? $customerQuality : self::JPEG_QUALITY;
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

    return LBWP_CUSTOM_FROM_EMAIL;
  }

  /**
   * @param $name
   * @return string|void
   */
  public function replaceEmailFromName($name)
  {
    return get_bloginfo('name');
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
      $classes .= ' modal-backend';
      // Allow to open it in modal once again after saving
      $_SESSION['open-modal-' . $_GET['post']] = true;
      $_SESSION['parent-id-' . $_GET['post']] = $_GET['parent'];
    }

    if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['post']) && !isset($_GET['ui'])) {
      if (isset($_SESSION['open-modal-' . $_GET['post']]) && isset($_GET['message'])) {
        $classes .= ' modal-backend';
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
  public function configurePhpMails(&$phpMailer)
  {
    // If it has no reply to yet, add the last found from email from above function
    if (count($phpMailer->getReplyToAddresses()) == 0) {
      if (strlen(self::$lastFromEmail) > 0 && self::$lastFromEmail != 'it@comotive.ch') {
        $phpMailer->addReplyTo(self::$lastFromEmail);
      } else {
        $phpMailer->addReplyTo(LBWP_CUSTOM_FROM_EMAIL);
      }
    } else if (defined('LBWP_CUSTOM_FROM_EMAIL')) {
      // Force custom reply to, when custom mail is set
      $phpMailer->clearReplyTos();
      $phpMailer->addReplyTo(LBWP_CUSTOM_FROM_EMAIL);
    }

    // If not external, configure to use our SMTP server
    if (!defined('EXTERNAL_LBWP') && !defined('LOCAL_DEVELOPMENT')) {
      $phpMailer->isSMTP();
      $phpMailer->Host = getSmtpRelayHost();
      $phpMailer->Port = '25';
      $phpMailer->SMTPAuth = false;
      $phpMailer->SMTPAutoTLS = false;
      $phpMailer->SMTPSecure = '';
    }

    // Set X-Mailer to null, so it doesn't add a specific header
    $phpMailer->XMailer = null;
  }

  /**
   * Registering global libraries that can be used by themes
   */
  protected function registerLibraries()
  {
    $url = File::getResourceUri() . '';
    wp_register_script('jquery-tablesorter', $url . '/js/jquery.tablesorter.min.js', array('jquery'), Core::REVISION, true);
    wp_register_script('jquery-mobile-events', $url . '/js/jquery-mobile-events.min.js', array('jquery'), Core::REVISION, true);
    wp_register_script('jquery-multisort', $url . '/js/jquery.multisort.js', array('jquery'), Core::REVISION, true);
    wp_register_script('jquery-cookie', $url . '/js/jquery.cookie.js', array('jquery'), Core::REVISION, true);
    wp_register_script('lbwp-aboon-backend', $url . '/js/lbwp-aboon-backend.js', array('jquery'), Core::REVISION, true);
    wp_register_script('lbwp-gallery-inline-fix', $url . '/js/lbwp-gallery-inline-fix.js', array('jquery'), Core::REVISION, true);
    wp_register_script('lbwp-gallery-inline-fix-v2', $url . '/js/lbwp-gallery-inline-fix-v2.js', array('jquery'), Core::REVISION, true);
    wp_register_style('jquery-ui-theme-lbwp', $url . '/css/jquery.ui.theme.min.css', array(), Core::REVISION);
    wp_register_script('chosen-js', $url . '/js/chosen/chosen.jquery.min.js', array('jquery'), Core::REVISION);
    wp_register_script('chosen-sortable-js', $url . '/js/chosen/chosen.sortable.jquery.js', array('chosen-js'), Core::REVISION);
    wp_register_style('chosen-css', $url . '/js/chosen/chosen.min.css', array(), Core::REVISION);
    wp_register_style('lbwp-aboon-frontend', $url . '/css/lbwp-aboon-frontend.css', array(), Core::REVISION);
    wp_register_script('wptheme-editor-multi-block-styles', $url . '/js/lbwp-multi-block-styles.js', array('wp-blocks'), Core::REVISION, true);
  }

  /**
   * @param mixed $value the settings value
   * @return mixed the unchanged value
   */
  public function flushFrontendCache($value)
  {
    $module = Core::getModule('MemcachedAdmin');
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
   * @param array $query query arguments
   * @return array cleaned arguments
   */
  public function removeUnlinkablePosttypes($query)
  {
    $postTypes = array();
    $forbiddenTypes = array('lbwp-form', 'lbwp-snippet', 'lbwp-list', 'lbwp-listitem', 'lbwp-user-group', 'onepager-item');
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
   * Adds global inline gutenberg scripts
   */
  public function addGlobalGutebergInlineScripts()
  {
    // Disable that gutenberg opens in fullscreen for new users by default
    // DISABLED: We'll see how the reactions are to full screen mode (as it can be disabled)
    //$script = "window.onload = function() { const isFullscreenMode = wp.data.select( 'core/edit-post' ).isFeatureActive( 'fullscreenMode' ); if ( isFullscreenMode ) { wp.data.dispatch( 'core/edit-post' ).toggleFeature( 'fullscreenMode' ); } }";
    //wp_add_inline_script( 'wp-blocks', $script );
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
    if (!isset($types['eps'])) {
      //$types['eps'] = 'application/postscript';
      $types['eps'] = 'image/x-eps';
    }
    if (!isset($types['svg'])) {
      $types['svg'] = 'image/svg+xml';
    }
    if (!isset($types['woff2'])) {
      $types['woff2'] = 'application/x-font-woff2';
    }
    if (!isset($types['otf'])) {
      $types['otf'] = 'application/vnd.ms-opentype';
    }

    // If a csv is uploaded and it has an excel mime, allow it, but change to text/plain so WP won't banter
    if (isset($_FILES['async-upload'])) {
      $allow = 'application/vnd.ms-excel';
      if ($_FILES['async-upload']['type'] == $allow && File::getExtension($_FILES['async-upload']['name']) == '.csv') {
        $_FILES['async-upload']['type'] = 'text/plain';
        $types['csv'] = 'text/plain';
      }
      // Uncomment this code if an XLSM doesn't work. it fakes xlsx headers
      // Basically this switches from xlsm to xslx mime type as finfo gets the xlsx type for some xlsm files
      /*
      $allow = 'application/vnd.ms-excel.sheet.macroEnabled.12';
      if ($_FILES['async-upload']['type'] == $allow && File::getExtension($_FILES['async-upload']['name']) == '.xlsm') {
        $_FILES['async-upload']['type'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $types['xlsm'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
      }
      */
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
        ($timestamp + 5) => 'flush_html_cache',
        ($timestamp + 75) => 'flush_html_cache'
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
   * Conditionally enables the block editor for all but some internal types
   */
  public static function enableBlockEditor($status, $post)
  {
    switch ($post->post_type) {
      case Posttype::FORM_SLUG:
      case 'acf-field-group':
      case 'attachment':
        return false;
    }

    return true;
  }

  /**
   * @return WP_Error|mixed the rest result
   */
  public static function addRestGlobalAuthentication($result)
  {
    if (!empty($result) || Strings::contains($_SERVER['REQUEST_URI'], 'wp-json/lbwp/')) {
      return $result;
    }
    if (!is_user_logged_in()) {
      return new \WP_Error('rest_not_logged_in', 'You are not currently logged in.', array('status' => 401));
    }
    return $result;
  }

  /**
   * @return WP_Error|mixed the rest result
   */
  public static function addRestGlobalAdminAuthentication($result)
  {
    if (!empty($result) || Strings::contains($_SERVER['REQUEST_URI'], 'wp-json/lbwp/')) {
      return $result;
    }
    if (!current_user_can('administrator')) {
      return new \WP_Error('rest_not_logged_in', 'You are not currently logged in as administrator.', array('status' => 401));
    }
    return $result;
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
    $imageSize = apply_filters('feed_rss_media_type', 'medium');
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

      $fileSize = isset($metaData['sizes'][$imageSize]['filesize']) ? $metaData['sizes'][$imageSize]['filesize'] : 0;
      echo '<enclosure url="' . $url . '" type="' . $attachment->post_mime_type . '" length="' . $fileSize . '" />' . "\n";
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
   * Explicitly uses secure=true to only work on https
   * @param string $key a key to autologin that must be stored within a matching transient
   */
  protected function tryAutoLogin($key)
  {
    $userId = intval(get_transient('lbwp-autologin-' . $key));
    // The transient is a valid key and still existing, if it returns a user
    if ($userId > 0) {
      wp_set_auth_cookie($userId, false, defined('WP_FORCE_SSL') && WP_FORCE_SSL);
    }
    // Always redirect to the profile, which might be showing login screen if cookie wasn't set
    header('Location: ' . get_edit_profile_url($userId), null, 307);
    exit;
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
      $text = apply_filters('lbwp_password_protect_hint', __('Sie sind für einen geschützen Bereich angemeldet. <a href="%s">Abmelden</a>', 'lbwp'));
      $hint = '
        <p class="lbwp-password-protected-hint">
          ' . sprintf($text, $this->getPasswordProtectionLogoutLink($post)) . '
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
   * Record the last login date
   * @param $userLogin string the username
   * @param $user object the user object
   * @return void
   */
  public function logLastLogin($userLogin, $user){
    update_user_meta($user->data->ID, 'lbwp_last_login_date', current_time('timestamp'));
  }

  /**
   * Add reusable block menu to the admin menu
   * @return void
   */
  public function addReusableBlockMenu(){
    $ruBlockRegistered = wp_cache_get('lbwp-reusable-block-count');

    if($ruBlockRegistered === false){
      $db = WordPress::getDb();
      $ruBlockRegistered = intval($db->get_var('
        SELECT COUNT(ID) FROM ' . $db->prefix . 'posts WHERE post_type = "wp_block" AND post_status != "trash"
      '));

      wp_cache_set('lbwp-reusable-block-count', $ruBlockRegistered, '', 7200);
    }

    if($ruBlockRegistered > 0){
      add_menu_page(
        __('Vorlagen', 'lbwp'),
        __('Vorlagen', 'lbwp'),
        'manage_options',
        'edit.php?post_type=wp_block',
        false,
        'dashicons-block-default',
        22
      );
    }
  }

  /**
   * Flush cache on save for reusable blocks
   * @param $post
   * @return void
   */
  public function flushReusableBlocksCache($post){
    $post = get_post($post);

    if($post->post_type === 'wp_block'){
      MemcachedAdmin::flushFrontendCacheHelper();
      wp_cache_delete('lbwp-reusable-block-count', '');
    }
  }

  /**
   * Add JS to add acf content to the yoast analysis
   * @return void
   */
  private function addAcfContentToYoast(){
    $base = File::getResourceUri();
    wp_enqueue_script('yoast-acf-content', $base . '/js/lbwp-yoast-acf-content.js');
    wp_localize_script('yoast-acf-content', 'lbwpYoastData', array('post_id' => $_GET['post'], 'post_type' => get_post_type($_GET['post'])));
  }

  /**
   * // Add data to the backend.js
   * @return void
   */
  public function addBackendJsData(){
    // BE CAREFUL USING THIS FILTER! Because the data passed to the js is probably used in multiple places.
    // So if you use it, make sure to only add or chande data an NOT to delete or override data
    $backendJsData = apply_filters('lbwp_backend_js_data', array('rest_route' => get_rest_url()));
    wp_localize_script('lbwp-backend-js', 'lbwpBackendData', $backendJsData);
  }
}
