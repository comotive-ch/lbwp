<?php

namespace LBWP\Module;

use wpdb;
use LBWP\Core;

/**
 * Base class for singleton modules
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class SimpleSingleton
{
  /**
   * @var array All lbwp configurations
   */
  protected $config = array();
  /**
   * @var SimpleSingleton
   */
  protected static $instance = null;


  /**
   * @return SimpleSingleton the instance, if initialized
   */
  public static function getInstance()
  {
    if (self::$instance === null) {
      self::init();
    }

    return self::$instance;
  }

  /**
   * Initializes and runs the singleton class
   */
  public static function init()
  {
    $class = get_called_class();
    self::$instance = new $class();
    self::$instance->run();
  }

	/**
	 * initialization function to be called at the constructor
	 */
	abstract protected function run();
}