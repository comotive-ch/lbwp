<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Util\File;

/**
 * Provides a JS and configuration for responsive iframes
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class ResponsiveIframes
{
  /**
   * This defines an example, that is always completely overridden on init
   * @var array Contains all configurations
   */
  protected $options = array(
    'selectors' => '.single.post iframe, .type-page iframe',
    'containerClasses' => 'lbwp-iframe-container ratio-16x9',
    'containerTag' => 'div'
  );
  /**
   * @var ResponsiveWidgets the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = array_merge($this->options, $options);

    if (!is_admin()) {
      // Add the config object to the footer before the file
      add_action('wp_footer', array($this, 'addConfigurationObject'));
      // Enqueue the JS file in footer
      wp_enqueue_script(
        'lbwp-responsive-iframes',
        File::getResourceUri() . '/js/lbwp-responsive-iframes.js',
        array('jquery'),
        LbwpCore::REVISION,
        true
      );
    }
  }


  public function addConfigurationObject()
  {
    echo '
      <script type="text/javascript">
        var lbwpResponsiveIframeConfig = ' . json_encode($this->options) . ';
      </script>
    ';
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new ResponsiveIframes($options);
  }
}
