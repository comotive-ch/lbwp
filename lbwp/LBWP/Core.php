<?php

namespace LBWP;

use LBWP\Helper\Installer;
use LBWP\Module\Base;
use LBWP\Util\Cookie;
use LBWP\Module\Config\Feature;
use LBWP\Module\Config\Settings;

/**
 * Main class for LBWP features
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core
{
  /**
   * @var int Revisionnumber of the plugins (not svn revision, only for updates)
   */
  const REVISION = 124;
  /**
   * @var int CSS/JS file version for cloudfront
   */
  const VERSION = 40;
  /**
   * @var string Superlogin user
   */
  const USER_KEY = 'admin';
  /**
   * @var string Superlogin passphrase
   */
  const USER_PASS = SECURE_AUTH_KEY;
  /**
   * @var string The admins email adress for _really_ important things
   */
  const ADMIN_EMAIL = 'it@comotive.ch';
  /**
   * @var array Module instance array
   */
  protected $module = array();
  /**
   * @var array list of all lbwp features that can be activated/disabled
   */
  protected $features = array();
  /**
   * @var array LBWP module settings
   */
  protected $config = array();
  /**
   * @var array Contains an unchanged copy of "features"
   */
  protected $defaultFeatures = array();
  /**
   * @var array Contains an unchanged copy of "config"
   */
  protected $defaultConfig = array();
  /**
   * @var array Detailed feature data
   */
  protected $featureData = array();
  /**
   * @var string the path to the plugin base
   */
  protected $path = '';
  /**
   * @var bool tells if the superlogin has been checked once
   */
  protected static $isSuperloginChecked = false;
  /**
   * @var bool cached superlogin bool
   */
  protected static $isSuperlogin = false;

  /**
   * Creating the object and register the actions
   */
  public function __construct($path)
  {
    $this->path = $path;
    // Load the detail feature infos
    require $this->path . '/views/includes/Core_featureBase.php';
    require $this->path . '/views/includes/Core_lbwpConfigDefaults.php';
    require $this->path . '/views/includes/FeatureConfig_featureData.php';

    // Copy defaults
    $this->defaultFeatures = $this->features;
    $this->defaultConfig = $this->config;
  }

  /**
   * Initialize plugin, load modules, run updates etc.
   */
  public function initialize()
  {
    // Register the main output buffer for modules to link into
    $isCron = defined('DOING_LBWP_CRON');
    $this->registerActions();
    // Load feature configurations
    $this->loadFeatures();
    $this->loadConfig();
    // Load activated public modules
    $this->loadModules('PublicModules');
    // Load activated admin modules, if admin. if not, load frontend modules
    if (is_admin() || $isCron) {
      $this->loadModules('BackendModules');
      // Also load feature configuration menu if not cron
      if (!$isCron) {
        $this->module['FeatureConfig'] = new Feature();
        $this->module['FeatureConfig']->initialize();
        $this->module['LbwpConfig'] = new Settings();
        $this->module['LbwpConfig']->initialize();
      } else {
        // If cron, load frontend modules that can be crons too
        $this->loadModules('FrontendModules');
      }
    } else {
      $this->loadModules('FrontendModules');
    }

    // Special hack to allow full caching of ajax requests
    if (isset($_GET['forceHtmlCache'])) {
      if ($this->features['FrontendModules']['HTMLCache'] == 1) {
        $classname = $this->featureData['FrontendModules']['sub']['HTMLCache']['class'];
        $this->module['HTMLCache'] = new $classname($this);
        $this->module['HTMLCache']->initialize();
      }
    }

    // Do update once, if needed
    if ($this->needsUpdate(self::REVISION)) {
      $this->update();
    }
  }

  /**
   * Registering actions and backend scripts.
   * Creates an inline outbut buffer that uses a filter for all modules
   * to hook into without starting their own buffer. The last module to
   * use this is HTMLCache, which uses priority 10000.
   */
  protected function registerActions()
  {
    // Global backend CSS
    if (is_admin()) {
      $base = plugin_dir_url($this->path) . 'lbwp/resources';
      wp_enqueue_style('lbwp-backend', $base . '/css/backend.css', array(), self::REVISION);
      wp_enqueue_script('lbwp-backend-js', $base . '/js/backend.js', array('jquery'), self::REVISION);
    }

    // Output buffer for actions to hook in
    ob_start(function($content) {
      return apply_filters('output_buffer', $content);
    });

    add_filter('output_buffer', array('LBWP\Util\WordPress', 'handleSslLinks'), 9900);
  }

  /**
   * @param string $type which modules to load
   */
  protected function loadModules($type)
  {
    foreach ($this->features[$type] as $key => $switch) {
      if ($switch == 1) {
        if (isset($this->featureData[$type]['sub'][$key]['class'])) {
          $classname = $this->featureData[$type]['sub'][$key]['class'];
          $this->module[$key] = new $classname($this);
          $this->module[$key]->initialize();
        }
      }
    }
  }

  /**
   * Loads the features, uses default if no already saved
   */
  protected function loadFeatures()
  {
    $features = get_option('LbwpFeatures');
    if ($features == false && !is_array($features)) {
      // save the default to the db
      update_option('LbwpFeatures', $this->defaultFeatures);
    } else {
      $this->features = $features;
    }
  }

  /**
   * Loads the features, uses default if not already saved
   */
  protected function loadConfig()
  {
    $config = get_option('LbwpConfig');
    if ($config == false && !is_array($config)) {
      // save the default to the db
      update_option('LbwpConfig', $this->defaultConfig);
    } else {
      $this->config = $config;
    }
  }

  /**
   * This might be used for multilang in order to reload with the option_LANG hook
   * @return array config for sophistication in direct return
   */
  public function reloadConfig()
  {
    $this->config = get_option('LbwpConfig');
    return $this->config;
  }

  /**
   * Provides an array of all features having the key as the feature slug
   * and the value an array of subfeatures of 0/1 if activated or not.
   * @return array All feature configurations
   */
  public function getFeatures()
  {
    return $this->features;
  }

  /**
   * Provides an array of all configurations having the key as the config slug
   * and the value an depends on the configuration.
   * @return array All feature configurations
   */
  public function getConfig()
  {
    return $this->config;
  }

  /**
   * Merges new default features into existing arrays
   */
  protected function mergeFeatures()
  {
    foreach ($this->defaultFeatures as $groupkey => $group) {
      // if not existing in features, add it
      if (!isset($this->features[$groupkey])) {
        $this->features[$groupkey] = array();
      }
      foreach ($group as $key => $value) {
        if (!isset($this->features[$groupkey][$key])) {
          $this->features[$groupkey][$key] = $value;
        }
      }
    }
    // Save the features
    update_option('LbwpFeatures', $this->features);
  }

  /**
   * Merges new default features into existing arrays
   */
  protected function mergeConfig()
  {
    foreach ($this->defaultConfig as $key => $value) {
      // if not existing in features, add it
      if (!isset($this->config[$key])) {
        $this->config[$key] = $value;
      }
    }
    // Save the features
    update_option('LbwpConfig', $this->config);
  }

  /**
   * Update method. Gets called once if you change self::PLUGIN_VERSION_SLUG
   */
  protected function update()
  {
    //self::installPlugin();
    $this->mergeFeatures();
    $this->mergeConfig();
    // Plugin specific hooks
    Installer::resetWooCommerceCrons();
    // delete S3Upload from backend
    //unset($this->features['BackendModules']['S3Upload']);
    //update_option('LbwpFeatures',$this->features);
  }

  /**
   * Checks a global option for the current version of a plugin.
   * You can use this to perform one-time tasks on a plugin
   * @param int $version the current version number to check
   * @return bool true/false if an update is needed or not
   */
  function needsUpdate($version)
  {
    $currentVersion = get_option('lbwpPluginVersion');

    // Check the version
    if ($version > $currentVersion) {
      update_option('lbwpPluginVersion', $version);
      return true;
    }

    // No change
    return false;
  }

  /**
   * @return Core Instance of the plugin object
   */
  public static function getInstance()
  {
    global $LBWP;
    return $LBWP;
  }

  /**
   * @param string $name the name of the module (key)
   * @return \LBWP\Module\Base a module or NULL if it doesn't exists / isn't loaded
   */
  public static function getModule($name)
  {
    return self::getInstance()->module[$name];
  }

  /**
   * @param string $name the name of the module (key)
   * @return bool true, if the module is useable
   */
  public static function isModuleActive($name)
  {
    $module = self::getModule($name);
    return ($module instanceof Base);
  }

  /**
   * @return bool true/false if superlogin or not
   */
  public static function isSuperlogin()
  {
    if (!self::$isSuperloginChecked) {
      $hash = Cookie::get('lbwp-superlogin');
      self::$isSuperloginChecked = true;
      if (md5(self::USER_PASS) . md5(self::USER_KEY) == $hash) {
        self::$isSuperlogin = true;
      }
    }
    return self::$isSuperlogin;
  }

  /**
   * Prevent superlogin for the current request
   */
  public static function preventSuperlogin()
  {
    self::$isSuperloginChecked = true;
    self::$isSuperlogin = false;
  }

  /**
   * Allows to check if a certain feature is active. Use this liek
   * Core::hasFeature('FrontendModules', 'HTMLCache')
   * @param string $category the category
   * @param string $feature the feature key
   * @return bool true, if the feature is found and active
   */
  public static function hasFeature($category, $feature)
  {
    $features = self::getInstance()->getFeatures();

    if (isset($features[$category][$feature]) && $features[$category][$feature] == 1) {
      return true;
    }

    return false;
  }

  /**
   * @return string the name of the currently used CDN
   */
  public static function getCdnName()
  {
    return CDN_NAME;
  }

  /**
   * @return string the name of the currently used CDN
   */
  public static function getCdnProtocol()
  {
    return CDN_PROTOCOL;
  }

  /**
   * @return string the cdn file uri
   */
  public static function getCdnFileUri()
  {
    return Core::getCdnProtocol() . '://' . Core::getCdnName() . '/' . ASSET_KEY . '/files';
  }

  /**
   * Installation routine of the plugin
   */
  public static function installPlugin()
  {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    Installer::install();
  }

  /**
   * Uninstallation Routine
   */
  public static function uninstallPlugin()
  {
    Installer::uninstall();
  }
}