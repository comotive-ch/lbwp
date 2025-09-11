<?php

namespace LBWP\Theme\Base;

use wpdb;
use LBWP\Theme\Base\Core;

/**
 * Base class for theme modules
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Module
{
  /**
   * @var mixed the theme instance
   */
  protected $core;
  /**
   * @var wpdb the wordpress database
   */
  protected $wpdb;

  /**
   * Creates the module and registers the init() call at action "init"
   * @param Core $core the theme
   */
  public function __construct($core)
  {
    global $wpdb;
    $this->core = $core;
    $this->wpdb = $wpdb;
    add_action('init', array($this, 'init'));
  }

  /**
   * Called after object construction (can be overridden if needed)
   */
  public function load() {  }

  /**
   * Needs to be implemented by the module to initialize itself on init(10) hook
   */
  abstract public function init();
} 