<?php

namespace LBWP\Module\Events;

use LBWP\Core as LbwpCore;
use LBWP\Util\File;
use LBWP\Module\Events\Component\EventType;
use LBWP\Module\Events\Component\SeriesType;
use LBWP\Module\Events\Component\Ticketing;
use LBWP\Module\Events\Component\Frontend;
use LBWP\Module\Events\Component\Shortcode;

/**
 * This is the core file which manages the events tool
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
  /**
   * @var array this contains the submodules
   */
  protected $components = array(
    '\LBWP\Module\Events\Component\EventType' => NULL,
    '\LBWP\Module\Events\Component\SeriesType' => NULL,
    '\LBWP\Module\Events\Component\Ticketing' => NULL,
    '\LBWP\Module\Events\Component\Frontend' => NULL,
    '\LBWP\Module\Events\Component\Shortcode' => NULL
  );

  /**
	 * Call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();

    // Register the needed scripts and styles
    $uri = File::getResourceUri();
    wp_register_style('lbwp-events-fe-css', $uri . '/css/events/frontend.css', array(), LbwpCore::REVISION, 'all');
    wp_register_style('lbwp-events-be-css', $uri . '/css/events/backend.css', array(), LbwpCore::REVISION, 'all');
    wp_register_script('lbwp-events-fe-js', $uri . '/js/events/frontend.js', array('jquery'), LbwpCore::REVISION, true);
    wp_register_script('lbwp-events-be-js', $uri . '/js/events/backend.js', array('jquery'), LbwpCore::REVISION, true);
    // Load widgets
    add_action('widgets_init', array($this, 'loadWidgets'));
	}

  /**
   * @return \LBWP\Module\Events\Core the form core
   */
  public static function getInstance()
  {
    return LbwpCore::getModule('Events');
  }

  /**
   * Initialize the module, load subclasses etc.
   */
  public function initialize()
  {
    // Load the components
    foreach ($this->components as $class => $null) {
      $this->components[$class] = new $class($this);
      $this->components[$class]->load();
    }
  }

  /**
   * The widget to use in sidebars
   */
  public function loadWidgets()
  {
    register_widget('\LBWP\Module\Events\Widget');
  }

  /**
   * @return bool true, if event cleanup is active
   */
  public function isEventCleanupActive()
  {
    return ($this->config['Events:CleanupEvents'] == 1);
  }

  /**
   * @return Frontend the frontend
   */
  public static function getFrontend()
  {
    return self::getInstance()->getFrontendComponent();
  }

  /**
   * @return Shortcode the shortcode handler
   */
  public static function getShortcode()
  {
    return self::getInstance()->getShortcodeComponent();
  }

  /**
   * @return Frontend the frontend
   */
  public function getFrontendComponent()
  {
    return $this->components['\LBWP\Module\Events\Component\Frontend'];
  }

  /**
   * @return EventType the event post type object
   */
  public function getEventType()
  {
    return $this->components['\LBWP\Module\Events\Component\EventType'];
  }

  /**
   * @return SeriesType the series post type object
   */
  public function getSeriesType()
  {
    return $this->components['\LBWP\Module\Events\Component\SeriesType'];
  }

  /**
   * @return Ticketing the ticketing component
   */
  public function getTicketing()
  {
    return $this->components['\LBWP\Module\Events\Component\Ticketing'];
  }

  /**
   * @return Shortcode the shortcode component
   */
  public function getShortcodeComponent()
  {
    return $this->components['\LBWP\Module\Events\Component\Shortcode'];
  }

}