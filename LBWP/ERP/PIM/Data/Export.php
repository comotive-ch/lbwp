<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Theme\Component\ACFBase;

/**
 * Provide PIM post type base functions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class Export extends ACFBase
{
  const PIM_TYPE_SLUG = 'lbwp-pid';

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    add_action('admin_menu', array($this, 'registerMenu'));
  }

  /**
   * Register the PIMport admin menu and submenu
   */
  public function registerMenu()
  {
    add_submenu_page(
      'pimport',
      'Export',
      'Export',
      'edit_pages',
      'pexport',
      array($this, 'renderExportPage')
    );
  }

  public function renderExportPage()
  {
    echo 'work in progress';
  }


  /**
   * No blocks needed here
   */
  public function blocks()
  {

  }

  /**
   * Adds field settings
   */
  public function fields()
  {

  }
}