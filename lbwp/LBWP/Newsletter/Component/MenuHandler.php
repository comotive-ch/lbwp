<?php

namespace LBWP\Newsletter\Component;

use LBWP\Newsletter\Component\Base;
use LBWP\Newsletter\Service\Base as ServiceBase;

/**
 * This class handles menu items for the newsletter tool
 * @package LBWP\Newsletter\Component
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
      add_action('admin_menu', array($this, 'addMenus'), 100);
    }
  }

  /**
   * Called at init(50)
   */
  public function initialize() { }

  /**
   * This actually adds the menu items of the newsletter tool. It uses the core
   * to get the componentes that will display the menus.
   */
  public function addMenus()
  {
    // The settings submenu
    add_submenu_page(
      'comotive-newsletter',
      'Newsletter &raquo; Einstellungen',
      'Dienst-Einstellungen',
      'administrator',
      'newsletter-settings',
      array($this->core->getSettings(), 'displayBackend')
    );

    // Remove first menu, if settings are not ready
    if (!$this->core->isWorkingServiceAvailable() || $this->core->isEditorDeactivated()) {
      global $submenu;
      unset($submenu['comotive-newsletter'][0]);
    }
  }
} 