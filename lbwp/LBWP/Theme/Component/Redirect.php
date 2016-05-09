<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Base\Component as BaseComponent;
use LBWP\Util\String;

/**
 * Simple redirector component
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Redirect extends BaseComponent
{
  /**
   * @var bool if set to true, static links are redirected on load
   */
  protected $rootCheckStatic = true;
  /**
   * @var array list of static redirects
   */
  protected $staticRedirects = array();

  /**
   * Invoke 404 checks as soon as the WP core loaded
   */
  public function setup()
  {
    $this->setStaticRedirects();

    // Come before wordpress basic canonical redirects
    if (!$this->rootCheckStatic) {
      add_action('wp', array($this, 'checkStaticNotFound'), 5);
    } else {
      $this->checkStaticNotFound();
    }

    // Always check dynamic invokes after wp load, since much cpu, wow
    add_action('wp', array($this, 'checkDynamicNotFoundInvoke'), 5);
  }

  /**
   * Nothing to do here
   */
  public function init()
  {

  }

  /**
   * Check for static redirects
   */
  public function checkStaticNotFound()
  {
    $is404 = (is_404() || (is_404() || $this->rootCheckStatic));

    // Check only on 404 or root check, and if there are redirects
    if ($is404 && is_array($this->staticRedirects) && count($this->staticRedirects)) {
      foreach ($this->staticRedirects as $oldUrl => $newUrl) {
        if (String::wildcardSearch($oldUrl, $_SERVER['REQUEST_URI'])) {
          header('Location: ' . $newUrl, NULL, 301);
          exit;
        }
      }
    }
  }

  /**
   * Invokes the abstract check method for dynamic not found redirects
   */
  public function checkDynamicNotFoundInvoke()
  {
    if (is_404()) {
      $this->checkDynamicNotFound();
    }
  }

  /**
   * Method to be implemented to add static redirects to $this->staticRedirects
   */
  abstract public function setStaticRedirects();

  /**
   * Can be overriden to make dynamic redirects, only invoked on 404
   */
  public function checkDynamicNotFound()
  {

  }
} 