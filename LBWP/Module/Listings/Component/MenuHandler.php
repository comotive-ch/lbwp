<?php

namespace LBWP\Module\Listings\Component;

/**
 * This class handles menu items for the listing tool
 * @package LBWP\Module\Listings\Component
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
   * This actually adds the menu items of the listings tool. It uses the core
   * to get the components that will display the menus. Posttypes hook into it.
   */
  public function addMenus()
  {
    // The main menu // oder list-view
    add_menu_page('Auflistungen', 'Auflistungen', 'edit_pages', 'listings', NULL, 'dashicons-format-aside', 43);
  }
} 