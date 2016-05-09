<?php

namespace LBWP\Module\Forms\Component;

/**
 * This class handles menu items for the forms tool
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class MenuHandler extends Base
{
  /**
   * Called after component construction
   */
  public function load()
  {
    if (is_admin()) {
      add_action('admin_menu', array($this, 'addMenus'));
    }
  }

  /**
   * Called at init(50)
   */
  public function initialize() { }

  /**
   * This actually adds the menu items of the forms tool. It uses the core
   * to get the componentes that will display the menus.
   */
  public function addMenus()
  {
    // The main menu
    add_menu_page('Formulare', 'Formulare', 'edit_pages', 'forms', NULL, 'dashicons-welcome-widgets-menus', 44);
  }
} 