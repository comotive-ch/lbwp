<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Base\Component;
use LBWP\Theme\Base\CoreV2;

/**
 * SVG Helper Base component
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class SvgBase extends Component
{
  /**
   * @var string a default path is set in constructor
   */
  protected static $path = '';
  /**
   * @var string an override path (needed for parent/child themes)
   */
  protected static $overridePath = '';
  /**
   * @var string the template to use
   */
  protected static $template = '
    <div class="lbwp-svg-icon {classes}">
      {svgData}
    </div>
  ';

  /**
   * @param CoreV2 $theme
   */
  public function __construct(\LBWP\Theme\Base\CoreV2 $theme)
  {
    parent::__construct($theme);
    self::setPath(get_template_directory() . '/assets/img/svg/');
    self::setOverridePath(get_stylesheet_directory() . '/assets/img/svg/');
  }

  /**
   * @param $path
   */
  protected static function setPath($path)
  {
    self::$path = $path;
  }

  /**
   * @param $path
   */
  protected static function setOverridePath($path)
  {
    self::$overridePath = $path;
  }

  /**
   * Not used right now
   */
  public function init() {}

  /**
   * @param $name
   * @param string $classes
   * @return
   */
  public static function icon($name, $classes = '', $echo = true)
  {
    $icon = '';
    $html = self::$template;
    $file = self::$path . $name . '.svg';
    $override = self::$overridePath . $name . '.svg';
    // Replace the classes and the icon itself
    if (file_exists($override)) {
      $icon = file_get_contents($override);
    } else if (file_exists($file)) {
      $icon = file_get_contents($file);
    }

    if (strlen($icon) > 0) {
      $html = str_replace('{svgData}', $icon, $html);
      $html = str_replace('{classes}', $classes, $html);
      if ($echo) {
        echo $html;
      } else {
        return $html;
      }
    }

    return '';
  }
}
