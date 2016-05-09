<?php

namespace LBWP\Module\Forms;

use LBWP\Module\Forms\Component\MenuHandler;
use LBWP\Module\Forms\Component\Posttype;
use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Core as LbwpCore;
use LBWP\Module\Forms\Component\ActionBackend\DataTable as DataTableBackend;

/**
 * This is the core file which manages the form tool
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
  /**
   * @var MenuHandler this handles the menus
   */
  protected $components = array(
    '\LBWP\Module\Forms\Component\MenuHandler' => NULL,
    '\LBWP\Module\Forms\Component\Posttype' => NULL,
    '\LBWP\Module\Forms\Component\FormHandler' => NULL,
    '\LBWP\Module\Forms\Component\FormEditor' => NULL,
    '\LBWP\Module\Forms\Component\ActionBackend\DataTable' => NULL
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
   * @return \LBWP\Module\Forms\Core the form core
   */
  public static function getInstance()
  {
    return LbwpCore::getModule('Forms');
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
    register_widget('\LBWP\Module\Forms\Widget');
  }

  /**
   * @return MenuHandler the menu handler instance
   */
  public function getMenuHandler()
  {
    return $this->components['\LBWP\Module\Forms\Component\MenuHandler'];
  }

  /**
   * @return Posttype the post type instance
   */
  public function getPosttype()
  {
    return $this->components['\LBWP\Module\Forms\Component\Posttype'];
  }

  /**
   * @return FormHandler the shortcode handler
   */
  public function getFormHandler()
  {
    return $this->components['\LBWP\Module\Forms\Component\FormHandler'];
  }

  /**
   * @return DataTableBackend the datatable backend
   */
  public function getDataTableBackend()
  {
    return $this->components['\LBWP\Module\Forms\Component\ActionBackend\DataTable'];
  }
}