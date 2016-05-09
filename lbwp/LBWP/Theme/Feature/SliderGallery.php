<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\File;
use LBWP\Core as LbwpCore;

/**
 * TODO container and template helpers are not yet built
 * Servers as a container for slider galleries and frameworks. Helps
 * with including frameworks and make galleries with theme code.
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 */
class SliderGallery
{
  /**
   * @var SliderGallery the sticky post config object
   */
  protected static $instance = NULL;
  /**
   * @var array the config array that can be overridden
   */
  protected $config = array(
    'framework' => 'slick-1.5',
    'container' => 'slick-gallery',
    'template' => ''
  );

  /**
   * Can only be instantiated by calling init method
   */
  protected function __construct()
  {

  }

  /**
   * Initialise while overriding settings defaults
   * @param array $config to override
   */
  public static function init($config = array())
  {
    self::$instance = new SliderGallery();
    self::$instance->load($config);
  }

  /**
   * @param array $data contains a list of key/value pairs that are replaced in the template
   * @param array $config
   * @return string html for the gallery
   */
  public static function getGallery($data, $config = array())
  {
    return self::$instance->getGalleryHtml($data, $config);
  }

  /**
   * @return SliderGallery the slider gallery helper object
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $data list of items
   * @param array $config override configs
   * @return string html for the gallery
   */
  public function getGalleryHtml($data, $config)
  {
    $html = '';
    $config = array_merge($this->config, $config);

    foreach ($data as $item) {
      $element = $config['template'];
      foreach ($item as $key => $value) {
        $element = str_replace('{' . $key . '}', $value, $element);
      }
      $html .= $element;
    }

    // Wrap it in a container and return
    $html = '<div class="slider-gallery ' . $config['container'] . '">' . $html . '</div>';
    return $html;
  }

  /**
   * Register the framework and prepare class
   * @param array $config to override
   */
  protected function load($config)
  {
    // Override the config, if given
    $this->config = array_merge($this->config, $config);
    // Register the needed framework
    add_action('wp_enqueue_scripts', array($this, 'loadAssets'));
  }

  /**
   * Load the needed assets and frameworks for the selected gallery
   */
  public function loadAssets()
  {
    $path = File::getResourceUri();
    // We have multiple frameworks that can be used
    switch ($this->config['framework']) {
      case 'slick-1.5':
        wp_enqueue_script('slick-1.5', $path . '/js/slick-carousel/1.5.x/slick.min.js', array('jquery'), LbwpCore::REVISION, true);
        wp_enqueue_style('slick-1.5-base', $path . '/js/slick-carousel/1.5.x/slick.css', array(), LbwpCore::REVISION, 'all');
        wp_enqueue_style('slick-1.5-theme', $path . '/js/slick-carousel/1.5.x/slick-theme.css', array(), LbwpCore::REVISION, 'all');
        break;
    }
  }
}