<?php

namespace LBWP\Module;

use wpdb;
use LBWP\Core;

/**
 * Base class for singleton modules called in CmsFeatures
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class BaseSingleton
{
	/**
	 * @var wpdb WordPress database object
	 */
	protected $wpdb = NULL;
	/**
	 * @var Core the plugin instance
	 */
	protected $plugin = NULL;
  /**
   * @var array All feature configurations
   */
  protected $features = array();
  /**
   * @var array All lbwp configurations
   */
  protected $config = array();
  /**
   * @var BaseSingleton[]
   */
  protected static $instance = array();

	/**
	 * Base constructor must be called to have wpdb/plugin filled automatically
	 */
	protected function __construct()
  {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->plugin = Core::getInstance();
    $this->features = $this->plugin->getFeatures();
    $this->config = $this->plugin->getConfig();
	}

  /**
   * @return BaseSingleton the instance, if initialized
   */
  public static function getInstance()
  {
    $class = get_called_class();
    if (!isset(self::$instance[$class])) {
      self::init();
    }

    return self::$instance[$class];
  }

  /**
   * Initializes and runs the singleton class
   */
  public static function init()
  {
    $class = get_called_class();
    self::$instance[$class] = new $class();
    self::$instance[$class]->run();
  }

	/**
	 * initialization function to be called at the constructor
	 */
	abstract protected function run();
}