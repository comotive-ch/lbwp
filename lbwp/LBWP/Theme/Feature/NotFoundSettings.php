<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;

/**
 * Very simple class to activate not found settings in the theme
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class NotFoundSettings
{
  /**
   * @var bool main configuration value that is checked for settings callbacks
   */
  protected static $isActive = false;

  /**
   * Call this from theme to activate the settings
   */
  public static function init()
  {
    self::$isActive = true;
  }

  /**
   * @return bool callback function for lbwp settings to provide config UI
   */
  public static function isActive()
  {
    return self::$isActive;
  }

  /**
   * @return string the title for the 404 page
   */
  public static function getTitle()
  {
    $config = LbwpCore::getInstance()->getConfig();
    return apply_filters('the_title', $config['NotFoundSettings:Title']);
  }

  /**
   * @return string the title for the 404 page
   */
  public static function getTitleUnfiltered()
  {
    $config = LbwpCore::getInstance()->getConfig();
    return $config['NotFoundSettings:Title'];
  }

  /**
   * @return string the html content of the 404 page
   */
  public static function getContent()
  {
    $config = LbwpCore::getInstance()->getConfig();
    return apply_filters('the_content', $config['NotFoundSettings:Content']);
  }

  /**
   * @return string the html content of the 404 page
   */
  public static function getContentUnfiltered()
  {
    $config = LbwpCore::getInstance()->getConfig();
    return $config['NotFoundSettings:Content'];
  }
} 