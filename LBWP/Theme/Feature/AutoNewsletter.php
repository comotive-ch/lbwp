<?php

namespace LBWP\Theme\Feature;

/**
 * Class AutoNewsletter
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class AutoNewsletter
{
  /**
   * @var bool setting the feature on/off
   */
  protected static $active = false;

  /**
   * Activates the feature
   */
  public static function init()
  {
    self::$active = true;
  }

  /**
   * @return bool true if activated
   */
  public static function active()
  {
    return self::$active;
  }
} 