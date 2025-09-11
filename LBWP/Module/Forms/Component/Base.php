<?php

namespace LBWP\Module\Forms\Component;

use wpdb;
use LBWP\Module\Forms\Core;

/**
 * Base component for the forms tool
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
{
  /**
   * @var wpdb the wordpress database
   */
  protected $wpdb = NULL;
  /**
   * @var Core the forms module core
   */
  protected $core = NULL;

  /**
   * @param Core $core
   */
  public function __construct(Core $core)
  {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->core = $core;
    // Register a function to call at init
    add_action('init', array($this, 'initialize'), 50);
  }

  /**
   * Called after object construction (can be overridden if needed)
   */
  public function load() {  }

  /**
   * Called in init(50)
   */
  abstract public function initialize();
}