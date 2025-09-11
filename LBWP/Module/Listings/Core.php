<?php

namespace LBWP\Module\Listings;

use LBWP\Helper\Metabox;
use LBWP\Module\Listings\Component\MenuHandler;
use LBWP\Module\Listings\Component\Posttype;
use LBWP\Module\Listings\Component\Configurator;
use LBWP\Module\Listings\Component\Shortcode;
use LBWP\Core as LbwpCore;

/**
 * This is the core file which manages the listing tool
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
  /**
   * @var \WP_Post the current list element being displayed in a shortcode list
   */
  protected static $currentListElementItem = NULL;
  /**
   * @var array this handles the components
   */
  protected $components = array(
    '\LBWP\Module\Listings\Component\MenuHandler' => NULL,
    '\LBWP\Module\Listings\Component\Posttype' => NULL,
    '\LBWP\Module\Listings\Component\ListFilter' => NULL,
    '\LBWP\Module\Listings\Component\Configurator' => NULL,
    '\LBWP\Module\Listings\Component\Shortcode' => NULL
  );

  /**
	 * Call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
    add_action('widgets_init', array($this, 'loadWidgets'));
	}

  /**
   * The widget to use in sidebars
   */
  public function loadWidgets()
  {
    register_widget('\LBWP\Module\Listings\Widget');
  }

  /**
   * @return \LBWP\Module\Listings\Core the form core
   */
  public static function getInstance()
  {
    return LbwpCore::getModule('Listings');
  }

  /**
   * @return \WP_Post the currently displayed listing item
   */
  public static function getCurrentListElementItem()
  {
    return self::$currentListElementItem;
  }

  /**
   * @param \WP_Post $item the new item to be set globally
   */
  public static function setCurrentListElementItem($item)
  {
    self::$currentListElementItem = $item;
  }

  /**
   * @return bool true, if the module is active
   */
  public static function isActive()
  {
    return LbwpCore::isModuleActive('Listings');
  }

  /**
   * @return Metabox the metabox helper for listing items
   */
  public function getHelper()
  {
    return Metabox::get(Posttype::TYPE_ITEM);
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
   * @return MenuHandler the menu handler instance
   */
  public function getMenuHandler()
  {
    return $this->components['\LBWP\Module\Listings\Component\MenuHandler'];
  }

  /**
   * @return Shortcode the shortcode interpreter instance
   */
  public function getShortcode()
  {
    return $this->components['\LBWP\Module\Listings\Component\Shortcode'];
  }

  /**
   * @return Posttype the post type instance
   */
  public function getPosttype()
  {
    return $this->components['\LBWP\Module\Listings\Component\Posttype'];
  }

  /**
   * @return Configurator the template configurator
   */
  public function getConfigurator()
  {
    return $this->components['\LBWP\Module\Listings\Component\Configurator'];
  }

}