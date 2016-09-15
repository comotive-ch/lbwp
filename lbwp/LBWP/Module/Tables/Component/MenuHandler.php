<?php

namespace LBWP\Module\Tables\Component;

/**
 * This class handles menu items for the tables tool
 * @package LBWP\Module\Tables\Component
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
   * This actually adds the menu items of the tables tool. It uses the core
   * to get the componentes that will display the menus.
   */
  public function addMenus()
  {
    // The main menu
    add_menu_page('Tabellen', 'Tabellen', 'edit_pages', 'tables', NULL, 'dashicons-media-spreadsheet', 44);
  }
} 