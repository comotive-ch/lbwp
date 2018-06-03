<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\File;
use LBWP\Core as LbwpCore;

/**
 * Provides possibility to show an information banner.
 * Auto registers and loads needed JS/CSS libraries.
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class InformationBanner
{
  /**
   * @var InformationBanner the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'infoBannerContent' => '',
    'infoBannerButton' => '',
    'infoBannerVersion' => 1
  );

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
    // If no button content is empty, set a default
    if (strlen($this->config['infoBannerButton']) == 0) {
      $this->config['infoBannerButton'] = __('OK', 'lbwp');
    }
  }

  /**
   * @return InformationBanner the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new InformationBanner($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    add_action('wp_enqueue_scripts', array($this, 'addAssets'));
    add_action('wp_footer', array($this, 'printScripts'));
  }

  /**
   * Add the needed core css and js assets to make it work
   */
  public function addAssets()
  {
    $url = File::getResourceUri();
    wp_enqueue_script('lbwp-information-banner-js', $url . '/js/components/lbwp-information-banner.js', array('jquery'), LbwpCore::REVISION, true);
    wp_enqueue_script('jquery-cookie');
  }

  /**
   * Prints the info banner config and html for easy use
   */
  public function printScripts()
  {
    $ts = current_time('timestamp');
    $config = array(
      'isActive' => true,
      'showFrom' => $ts - 864000,
      'showUntil' => $ts + 864000,
      'cookieId' => 'lbwpInfoBanner_v' . $this->config['infoBannerVersion']
    );

    echo '
      <script type="text/javascript">
        lbwpInfoBannerConfig = ' . json_encode($config) . ';
      </script>
      <div class="lbwp-info-banner" style="display:none;">
        <div class="info-banner-content">
          ' . wpautop($this->config['infoBannerContent']) . '
        </div>
        <a href="#" class="lbwp-close-info-banner">
          <span>' . $this->config['infoBannerButton'] . '</span>
        </a>
      </div>
    ';
  }
}



