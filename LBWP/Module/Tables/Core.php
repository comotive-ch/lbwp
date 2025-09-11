<?php

namespace LBWP\Module\Tables;

use LBWP\Core as LbwpCore;
use LBWP\Module\Tables\Component\MenuHandler;
use LBWP\Module\Tables\Component\Posttype;
use LBWP\Module\Tables\Component\TableHandler;

/**
 * This is the core file which manages the table tool
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
  /**
   * @var MenuHandler this handles the menus
   */
  protected $components = array(
    '\LBWP\Module\Tables\Component\MenuHandler' => NULL,
    '\LBWP\Module\Tables\Component\Posttype' => NULL,
    '\LBWP\Module\Tables\Component\TableHandler' => NULL,
    '\LBWP\Module\Tables\Component\TableEditor' => NULL
  );

  /**
	 * Call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
	}

  /**
   * @return \LBWP\Module\Forms\Core the form core
   */
  public static function getInstance()
  {
    return LbwpCore::getModule('Tables');
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
    return $this->components['\LBWP\Module\Tables\Component\MenuHandler'];
  }

  /**
   * @return Posttype the post type instance
   */
  public function getPosttype()
  {
    return $this->components['\LBWP\Module\Tables\Component\Posttype'];
  }

  /**
   * @return TableHandler the shortcode handler
   */
  public function getTableHandler()
  {
    return $this->components['\LBWP\Module\Tables\Component\TableHandler'];
  }
}