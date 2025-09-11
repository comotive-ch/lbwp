<?php

namespace LBWP\Module\Frontend;

use LBWP\Util\File;

/**
 * Creates and includes all needed css/js to use an automatic fancybox
 * the first step is a javascript that finds all linked images (that
 * are linked with another image) and sets a class and the second step
 * will just make a fancybox creation call with that class.
 * @author Michael Sebel <michael@comotive.ch>
 */
class SimpleFancybox extends \LBWP\Module\Base
{
  /**
   * @var int Module version for resource files
   */
  const VERSION = 26;
  /**
   * @var array configuration array (you can use more additional parameters "maxWidth" and "helpers" without a configured default value)
   */
  protected static $settings = array(
    'margin' => 10,
    'padding' => 10,
    'grouping' => 'automatic', // automatic|gallery|none
    'ifGalleryRegisterAutoImages' => true,
    'alwaysAddGalleryItemClasses' => true,
    'shortcodeForceFileLinks' => false,
    'shortcodeForceImageSize' => '',
    'automaticImagesAsGroup' => true,
    'swipeOnlyActive' => false,
    'swipeOnlyAddHandles' => false,
    'swipeOnlyDetermination' => 'width', // width|client|always
    'swipeOnlyBreakpointWidth' => 0,
    'swipeOnlyUseFancybox' => false,
    'calcFixHeight' => false,
    'calcModeHandlesVerticalPosition' => 'none', // none|image
    'showNumberOfImages' => false,
    'textNumberOfImages' => '{index} / {total}',
    'effectOpen' => 'fade',
    'effectClose' => 'fade',
    'effectNext' => 'elastic',
    'effectPrev' => 'elastic'
  );

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize($force = false)
  {
    if (!is_admin() || $force) {
      // Add js output for configuration in footer (admin too, if forced)
      add_action('wp_footer', array($this, 'addSettings'));
      if ($force) {
        add_action('admin_footer', array($this, 'addSettings'));
      }

      // Filter the shortcode attributes, if needed
      add_filter('shortcode_atts_gallery', array($this, 'overrideGalleryAttributes'));

      // And some code in the footer
      wp_enqueue_style('lbwp-fancybox-css', $this->getBasepath() . '/fancybox.min.css', array(), self::VERSION, 'all');
      wp_enqueue_script('lbwp-fancybox', $this->getBasepath() . '/fancybox.js', array('jquery'), self::VERSION, true);
      wp_enqueue_script('lbwp-auto-fancybox', $this->getBasepath() . '/lbwp-fancybox.js', array('jquery'), self::VERSION, true);
    }
  }

  /**
   * @param array $args arguments
   * @return mixed
   */
  public function overrideGalleryAttributes($args)
  {
    // Force file links, if needed
    if (self::$settings['shortcodeForceFileLinks']) {
      $args['link'] = 'file';
    }

    // Always override with a certain image size
    if (strlen(self::$settings['shortcodeForceImageSize']) > 0) {
      $args['size'] = self::$settings['shortcodeForceImageSize'];
    }

    return $args;
  }

  /**
   * Let themes override the configuration
   * @param array $config the configuration
   */
  public static function configure($config)
  {
    self::$settings = array_merge(self::$settings, $config);
  }

  /**
   * Add inline javascript configuration
   */
  public function addSettings()
  {
    echo '
      <script type="text/javascript">
        var FancyBoxConfig = ' . json_encode(self::$settings) . ';
      </script>
    ';
  }

  /**
   * @return string the base url path for loading resource files
   */
  protected function getBasepath()
  {
    return File::getResourceUri() . '/libraries/fancybox';
  }
}