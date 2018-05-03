<?php

namespace LBWP\Theme\Feature;

/**
 * Provides configurations for the form module
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class LbwpFormSettings
{
  /**
   * @var array Contains all configurations
   */
  protected $options = array(
    'removeCoreFrontendCss' => false
  );
  /**
   * @var LBwpFormSettings the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct() { }

  /**
   * @param array $options
   */
  public static function setOptions($options)
  {
    self::$instance->options = array_merge(self::$instance->options, $options);
  }

  /**
   * @param $option
   * @return mixed
   */
  public static function get($option)
  {
    return self::$instance->options[$option];
  }

  /**
   * initialize the class
   */
  public static function init()
  {
    self::$instance = new LbwpFormSettings();
  }
}
