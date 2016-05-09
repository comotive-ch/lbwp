<?php

namespace LBWP\Helper\Tracking;
use LBWP\Util\Multilang;

/**
 * Adobe Analytics Helper class
 * @package LBWP\Helper\Tracking
 * @author Michael Sebel <michael@comotive.ch>
 */
class Adobe
{
  /**
   * @var array the basic array to track data
   */
  protected $tracker = array(
    'page' => array(
      'pageInfo' => array(
        'pageName' => ''
      ),
      'category' => array(
        'primaryCategory' => ''
      )
    )
  );
  /**
   * @var array configuration of analytics
   */
  protected $config = array(
    'varName' => 'digitalData',
    'hasSatellite' => false,
    'primaryCategory' => 'web',
    'homePageName' => 'home',
    'trackingUrl' => '',
    'trackingDeps' => array('jquery')
  );
  /**
   * @var Adobe the instance
   */
  protected static $instance = NULL;
  /**
   * The tracking variable name
   */
  const TRACKER_TEMPLATE_TAG = '<!--adobeAnalyticsTracker-->';

  /**
   * Initialize the adobe tracking object
   * @param array $config the configuration override
   */
  public static function init($config)
  {
    if (self::$instance == NULL) {
      self::$instance = new Adobe($config);
      self::$instance->registerFilters();
    }
  }

  /**
   * Adds the needed filters to print the scripts
   */
  protected function __construct($config)
  {
    $this->config = array_merge($this->config, $config);
  }

  /**
   * Registers all the needed filters for the tracking to work
   */
  protected function registerFilters()
  {
    // Add the tracking script with deps
    wp_enqueue_script('adobe-analytics', $this->config['trackingUrl'], $this->config['trackingDeps'], '1.1', false);
    // Fill the tracking object with basic data
    add_filter('wp', array($this, 'fillBaseObject'));
    add_action('wp_head', array($this, 'printTrackerObjectVariable'), 5);
    add_action('output_buffer', array($this, 'replaceTrackerObject'));

    // Print satellite if given
    if ($this->config['hasSatellite']) {
      // Add the tracking sattelite
      add_action('wp_footer', array($this, 'printSatelliteData'), 1000);
    }
  }

  /**
   * @param string $objectName the object key
   * @return array|null the value at this objects key
   */
  public function getObject($objectName)
  {
    return $this->tracker[$objectName];
  }

  /**
   * @param string $objectName the object key
   * @param array $object the new object for this key
   */
  public function setObject($objectName, $object)
  {
    $this->tracker[$objectName] = $object;
  }

  /**
   * Print a var, that can later be replaced by the output buffer
   */
  public function printTrackerObjectVariable()
  {
    echo self::TRACKER_TEMPLATE_TAG;
  }

  /**
   *  Print the satellite initialization code
   */
  public function printSatelliteData()
  {
    echo '
      <script type="text/javascript">
        if (typeof _satellite !== "undefined") {
          _satellite.pageBottom();
        }
      </script>
    ';
  }

  /**
   * Print the digitalData object for adobe analytics
   */
  public function replaceTrackerObject($html)
  {
    $tracking = '
      <script type="text/javascript">
        var ' . $this->config['varName'] . ' = ' . json_encode($this->tracker, JSON_FORCE_OBJECT) . ';
      </script>
    ';

    // Print the object
    return str_replace(self::TRACKER_TEMPLATE_TAG, $tracking, $html);
  }

  /**
   * Fill the tracker object with basic minimum information
   */
  public function fillBaseObject()
  {
    $this->tracker['page']['pageInfo']['pageName'] = $this->getUrlPageName();
    $this->tracker['page']['category']['primaryCategory'] = $this->config['primaryCategory'];
  }

  /**
   * @return string the pageName variable generated from the URI string
   */
  protected function getUrlPageName()
  {
    $pageName = strtolower($this->config['primaryCategory']) . ':';
    // Add all URL parts
    $parts = explode('/', $_SERVER['REQUEST_URI']);

    // Remove empty ones
    $parts = array_filter($parts, function($part) {
      return strlen($part) > 0;
    });

    // If there are no parts, it might as well be the main page
    if (count($parts) == 0) {
      $parts[] = $this->config['homePageName'];
    }

    // Special 404 handling, if there is a not found hint
    if (is_404()) {
      $parts = $this->getNotFoundUrlParts();
    }

    return $pageName . implode(':', $parts);
  }

  /**
   * @return array the 404 page name parts
   */
  protected function getNotFoundUrlParts()
  {
    $parts = array();
    // If multilang, add current language to the parts
    if (Multilang::isActive()) {
      $parts[] = Multilang::getCurrentLang();
    }

    // Then, add the 404 info to the array
    $parts[] = '404';
    return $parts;
  }
} 