<?php

namespace LBWP\Theme\Widget;

use LBWP\Helper\WpMenu;
use \LBWP\Theme\Base\Widget;

/**
 * Show an automatic submenu of the currently selected menu tree
 * Only works, if the menu is properly configured
 * @package LBWP\Theme\Widget
 * @author Michael Sebel <michael@comotive.ch>
 */
class AutomaticSubMenu extends Widget
{
  protected $baseId = 'AutomaticSubMenu';
  protected $widgetName = 'Automatisches Untermenu';
  protected $textDomain = 'lbwp';

  /**
   * configure the field of the widget
   */
  public function init()
  {
    $this->registerTextFormField('title', array(
      'label' => 'Titel (optional)'
    ));
    $this->registerCheckboxFormField('automatic_title', array(
      'label' => 'Titel anhand Menu-Name generieren'
    ));
    $this->registerDropdownFormField('menu_slug', array(
      'label' => 'Darzustellendes Menu',
      'itemsCallback' => array($this, 'getMenuOptions')
    ));
  }

  /**
   * Build menu html from configuration
   * @param array $args
   * @param array $instance
   * @return mixed
   */
  public function html($args = array(), $instance = array())
  {
    // Activate queriable submenus
    $wpMenu = new WpMenu();
    $wpMenu->activateQueryableSubmenus();
    $wpMenu->activateMenuClassFix();

    // Display the menu
    $menu = wp_nav_menu(array(
      'echo' => false,
      'container' => false,
      'theme_location' => $instance['menu_slug'],
      'select_branch' => true,
      'expand_noncurrent' => true,
      'omit_fragment_cache' => true,
      'menu_class' => 'automatic-sub-menu',
      'level_start' => 1,
      'level_depth' => 1
    ));

    // If there is no menu, return empty string, so widget won't be displayed
    if ($menu === false) {
      return '';
    }

    if ($instance['automatic_title'] == 'on') {
      $title = $this->getTitleFromContext($instance['menu_slug']);
    } else {
      $title = $instance['title'];
    }

    // Display the title
    if (strlen($title) > 0) {
      $title = $args['before_title'] . $title . $args['after_title'];
    }

    // If there is content to display, display it
    return $title . $menu;
  }

  /**
   * Try to get the title of the currently selected menu, depending on context
   * @param string $location the menu to query
   * @return string the menu title
   */
  protected function getTitleFromContext($location)
  {
    $topMenuId = WpMenu::getCurrentTopParentId();
    $navMenuId = get_nav_menu_locations()[$location];
    $menuItems = wp_get_nav_menu_items($navMenuId);

    foreach ($menuItems as $menuItem) {
      if ($menuItem->ID == $topMenuId) {
        if (isset($menuItem->title) && strlen($menuItem->title) > 0) {
          return $menuItem->title;
        }
      }
    }

    // If we reach this point, all the magic didn't work
    return '';
  }

  /**
   * @return array all menus registered
   */
  public function getMenuOptions()
  {
    $registeredMenus = get_registered_nav_menus();
    $menus = array();

    foreach ($registeredMenus as $slug => $registeredMenu) {
      $menus[$slug] = $registeredMenu;
    }

    return $menus;
  }

} 