<?php

namespace LBWP\Module;

use LBWP\Util\Multilang;
use wpdb;
use LBWP\Core;

/**
 * Base class for all modules of LBWP
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
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
	 * Base constructor must be called to have wpdb/plugin filled automatically
	 */
	public function __construct()
  {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->plugin = Core::getInstance();
    $this->features = $this->plugin->getFeatures();
    $this->config = $this->plugin->getConfig();

    // Reload multilang config as soon as available
    if (Multilang::isActive()) {
      add_action('init', array($this, 'reloadConfig'));
      if (defined('LBWP_LATE_CONFIG_RELOAD')) {
        add_action('wp', array($this, 'reloadConfig'));
      }
    }
	}

  /**
   * Reload the config from plugin
   */
  public function reloadConfig()
  {
    $this->config = $this->plugin->reloadConfig();
  }

	/**
	 * initialization function to be called at the constructor
	 */
	abstract public function initialize();
}