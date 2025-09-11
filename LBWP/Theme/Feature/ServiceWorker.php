<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\CoreV2;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * Service Worker for WordPress
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class ServiceWorker
{
  /**
   * @var bool random version on debug mode to cache bust always
   */
  public $debugMode = false;
  /**
   * @var string path to the template we use to build dynamic service-worker.js
   */
  private $template = ABSPATH . 'wp-content/plugins/lbwp/resources/js/lbwp-service-worker-template.js';
  /**
   * @var array config info to service worker file
   */
  private $config = array();

  /**
   * @var ServiceWorker the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct($options = array())
  {
    if (!is_admin() && !is_login()) {
      if ($options['debug'] === true && defined('LOCAL_DEVELOPMENT') && LOCAL_DEVELOPMENT) {
        $this->debugMode = true;
        unset($options['debug']);
      }

      $this->setupConfig($options);

      add_action('wp_head', array($this, 'linkAssets'), 999);
      add_action('rest_api_init', array($this, 'registerApis'));
    }
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new ServiceWorker($options);
  }

  /**
   * @return SecureAssets the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  public function registerApis(){
    register_rest_route('lbwp', 'user/push-subscription', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'saveUserPushSubscription'),
    ));

    register_rest_route('lbwp', 'user/update-push-subscription', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'updateUserPushSubscription'),
    ));

    register_rest_route('lbwp', 'user/remove-push-subscription', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'deleteUserPushSubscription'),
    ));
  }

  /**
   *
   */
  public function template()
  {
    $this->config['cachePaths'] = apply_filters('lbwp_ServiceWorker_precached_resources', '/');
    $this->config['excludePaths'] = apply_filters('lbwp_ServiceWorker_exclude_resources', null);
    $this->config['additionalCode'] = apply_filters('lbwp_ServiceWorker_addition_code', '');

    // Put configs into the service-worker script
    $swContent = file_get_contents($this->template);
    foreach ($this->config as $cName => $cValue) {
      if(str_starts_with($cName, 'webpush_')){
        continue;
      }

      if(empty($cValue)){
        $cValue = '[""]'; // Empty array for JS
      }
      $swContent = str_replace('{' . $cName . '}', $cValue, $swContent);
    }

    echo $swContent;
  }

  /**
   *
   */
  public function linkAssets()
  {
    echo "<script>
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(registrations => {
          
          // Force update ServiceWorker on reload (debug mode only) 
          " .
          ($this->debugMode ?
            "for(let registration of registrations) {
                  registration.unregister();
                }" :
            ''
          )
          . "
          navigator.serviceWorker.register('/service-worker.js', {scope : '/'}).then(function(registration){
             if(registration.installing) {
              console.log('Service worker installing');
            } else if(registration.waiting) {
              console.log('Service worker installed');
            } else if(registration.active) {
              console.log('Service worker active');
            }
          }).catch(function(e){
            console.log('ServiceWorker failed:', e);
          });
        });
        
        var lbwpServiceWorkerWebpushKey = '" . (defined('WEBPUSH_PUBLIC_KEY') ? WEBPUSH_PUBLIC_KEY : 'undefined') . "';
                
        " . apply_filters('lbwp_additional_serviceworker_js', '') . "
      }
    </script>";

    $manifestPath = apply_filters('lbwp_pwa_manifest_path', '/assets/scripts/manifest.json');
    if (file_exists(get_stylesheet_directory() . $manifestPath)) {
      $this->linkManifest($manifestPath);
    }
  }

  /**
   * @return int
   */
  protected function getServiceWorkerVersion()
  {
    $version = intval(str_replace('.', '', CoreV2::getInstance()->getVersion()));
    if ($this->debugMode) {
      $version += mt_rand(1, 100);
    }
    return apply_filters('lbwp_ServiceWorker_version', $version);
  }

  /**
   * @param $path
   */
  private function linkManifest($path)
  {
    $pathUrl = get_stylesheet_directory_uri() . $path;

    $linkHtml = '<link rel="manifest" href="' . $pathUrl . '?ver=' . $this->getServiceWorkerVersion() . '">';
    $manifestIcons = json_decode(file_get_contents(get_stylesheet_directory() . $path))->icons;
    if ($manifestIcons !== null && is_array($manifestIcons)) {
      foreach ($manifestIcons as $icon) {
        $linkHtml .= '
          <link 
            rel="apple-touch-icon" 
            sizes="' . $icon->sizes . '" 
            href="' . get_stylesheet_directory_uri() . '/assets/' . str_replace('../', '', $icon->src) . '">
        ';
      }
    }

    $splashImage = apply_filters('lbwp_pwa_splash_image', '/assets/img/manifest/splash-image');
    $linkHtml .= '<meta name="apple-mobile-web-app-capable" content="yes" />';
    $sizes = [
      '2048x2732',
      '1668x2224',
      '1536x2048',
      '1125x2436',
      '1242x2208',
      '750x1334',
      '640x1136',
    ];

    for ($i = 0; $i < count($sizes); $i++) {
      $size = explode('x', $sizes[$i]);

      if (file_exists(get_stylesheet_directory() . $splashImage . '_' . $size[0] . '.jpg')) {
        $linkHtml .= '
          <link href="' . get_stylesheet_directory_uri() . $splashImage . '_' . $size[0] . '.jpg' . '" sizes="' . $sizes[$i] . '" rel="apple-touch-startup-image" />
        ';
      }
    }

    echo $linkHtml;
  }

  /**
   * @param $themeConfigs
   */
  private function setupConfig($themeConfigs)
  {
    $config = array(
      'version' => $this->getServiceWorkerVersion(),
      'cachePaths' => array(),
      'excludes' => array(),
      'preventCache' => false,
      'webpush_options' => array(),
      'webpush_timeout' => 30,
      'webpush_client_options' => array(),
    );

    $this->config = array_merge($config, $themeConfigs);
  }

  /**
   * @param $urls
   */
  public static function setPrecachePages($urls)
  {
    $pageUrl = get_bloginfo('url');
    $cacheUrls = array();

    foreach ($urls as $urlKey => $url) {
      if (is_array($url)) {
        $getPosts = get_posts($url);
        foreach ($getPosts as $thePost) {
          $cacheUrls[] = get_permalink($thePost);
        }
        $url = $urlKey;
      }

      if (!Strings::startsWith($url, $pageUrl)) {
        $url = $pageUrl . '/' . $url;
      }

      $cacheUrls[] = $url;

      if (!Strings::endsWith($url, '/')) {
        $cacheUrls[] = $url . '/';
      }
    }

    add_filter('lbwp_ServiceWorker_precached_resources', function ($res) use ($cacheUrls) {
      return $res . ',' . implode(',', $cacheUrls);
    });
  }

  /**
   * Set the url / path / string to excludes
   * @param $excludes array with excludes strings. Will be tested wirh "contains"
   */
  public static function setExcludes($excludes)
  {
    add_filter('lbwp_ServiceWorker_exclude_resources', function ($res) use ($excludes) {
      return implode(',', $excludes);
    });
  }

  /**
   *
   */
  private function setPrecachedItems()
  {
    add_filter('script_loader_tag', array($this, 'getEnqueuedScripts'));
    add_filter('style_loader_tag', array($this, 'getEnqueuedStyles'));
    add_filter('lbwp_ServiceWorker_precached_resources', array($this, 'cacheImages'), 10, 2);
  }

  /**
   * @param $tag
   * @return mixed
   */
  public function getEnqueuedScripts($tag)
  {
    $doc = new \DOMDocument();

    if (!empty($tag)) {

      $doc->loadHTML($tag);
      foreach ($doc->getElementsByTagName('script') as $script) {
        if ($script->hasAttribute('src')) {
          $link = $script->getAttribute('src');
          add_filter('lbwp_ServiceWorker_precached_resources', function ($res, $type) use ($link) {

            if ($type === 'static') {
              $res[] = $link;
            }

            return $res;
          }, 10, 2);
        }
      }
    }

    return $tag;
  }

  /**
   * @param $tag
   * @return mixed
   */
  public function getEnqueuedStyles($tag)
  {
    $doc = new \DOMDocument();

    if (!empty($tag)) {

      $doc->loadHTML($tag);
      foreach ($doc->getElementsByTagName('script') as $style) {
        if ($style->hasAttribute('href')) {
          $link = $style->getAttribute('href');
          add_filter('lbwp_ServiceWorker_precached_resources', function ($res, $type) use ($link) {
            if ($type === 'static') {
              $res[] = $link;
            }

            return $res;
          }, 10, 2);
        }
      }
    }

    return $tag;
  }

  /**
   * @param $res
   * @param $type
   * @return mixed
   */
  public function cacheImages($res, $type)
  {
    if ($type === 'static') {
      $themeDir = get_stylesheet_directory();
      $themeUri = get_stylesheet_directory_uri();
      foreach (glob($themeDir . "/assets/img/*") as $file) {
        $res[] = str_replace($themeDir, $themeUri, $file);
        // TODO: SVGs
      }
    }

    return $res;
  }

  /**
   * @param $paths
   */
  protected function excludePaths($paths)
  {
    if (!is_array($paths)) {
      $paths = array($paths);
    }

    foreach ($paths as $path) {
      if (Strings::startsWith($path, '/')) {
        $path = get_site_url() . $path;
      }

      $this->config['excludePaths'][] = $path;
    }
  }

  /**
   * @param $pageId
   * @return false|string
   */
  private function getPath($pageId)
  {
    $pagePath = substr(get_permalink($pageId), strlen(home_url()));

    // Remove the first and last '/'
    if ($pagePath[0] == '/') $pagePath = substr($pagePath, 1);
    if ($pagePath[strlen($pagePath) - 1] == '/') $pagePath = substr($pagePath, 0, strlen($pagePath) - 1);

    return $pagePath;
  }

  /**
   * Send a notification to one user
   * @param $subscription
   * @param $title
   * @param $message
   * @param $icon
   * @return void
   */
  public static function sendNotification($subscription, $title, $message, $url, $icon = '', $userId = 0)
  {
    if(!isset(self::$instance->config['webpush_auth'])){
      SystemLog::add('WebPush', 'debug', 'Webpush auth not set');
      return;
    }

    try {
      $webpush = new WebPush(self::$instance->config['webpush_auth'], self::$instance->config['webpush_options'], self::$instance->config['webpush_timeout'], self::$instance->config['webpush_client_options']);
    }catch (\Exception $e){
      SystemLog::add('WebPush', 'debug', 'Webpush initialization failed', array(
        'error' => $e->getMessage()
      ));
      return;
    }

    $subData = is_array($subscription) ? $subscription : json_decode($subscription, JSON_OBJECT_AS_ARRAY);

    if($subData === null){
      SystemLog::add('WebPush', 'debug', 'subscription is not defined.', array(
        'subscription' => $subscription
      ));
      return;
    }

    $subData['authToken'] = $subData['keys']['auth'];
    $subData['contentEncoding'] = 'aesgcm';
    $webpush->createSubscription($subData);

    try{
      $webpush->notify($message, $title, $url, $icon);
    }catch (\Exception $e){
      SystemLog::add('WebPush', 'debug', 'notification failed', array(
        'error' => $e->getMessage()
      ));

      // TODO: Eventually remove subscription when permission rewoked
    }
  }

  public function saveUserPushSubscription($data){
    $data = $data->get_params();
    $subscriptions = ArrayManipulation::forceArray(get_option('lbwp_user_push_subscriptions'));

    $subscriptions[] = json_decode($data['sub'], true);
    update_option('lbwp_user_push_subscriptions', $subscriptions);

    return true;
  }
}
