<?php

namespace LBWP\Module\General\FragmentCache;

use LBWP\Module\General\FragmentCache\Definition;

/**
 * The fragment cache handles permanent caching of fragments like menus, widgets etc.
 * This one is proudly inspired by andrey savchenko, @rarst
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
  /**
   * @var array classes to implement a fragment
   */
  protected $fragments = array(
    'menu' => '\LBWP\Module\General\FragmentCache\Item\Menu',
  );

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Called at init, runs all the fragments
   */
  public function initialize()
  {
    /** @var Definition $fragment the fragment implementation */
    /*
    if (is_admin()) {
      foreach ($this->fragments as $fragmentClass) {
        $fragment = new $fragmentClass();
        $fragment->registerInvalidation();
      }
    } else {
      foreach ($this->fragments as $fragmentClass) {
        $fragment = new $fragmentClass();
        $fragment->registerFrontend();
      }
    }
    */
  }
}