<?php

namespace LBWP\Aboon\Base;

/**
 * At the moment very basic stub for aboon component like functions that
 * can be used in multiple themes
 * @package LBWP\Aboon\Base
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Component
{
  /**
   * Should be called on after_theme_setup and no later
   */
  public function __construct()
  {
    $this->setup();
    add_action('init', array($this, 'init'));
    add_action('wp', array($this, 'frontInit'));
    add_action('admin_init', array($this, 'adminInit'));
  }

  public function setup() {}
  abstract public function init();
  abstract public function admininit();
  abstract public function frontInit();
}