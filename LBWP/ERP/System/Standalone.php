<?php

namespace LBWP\ERP\System;

use LBWP\Theme\Base\Component;

/**
 * Does cleanup when the PIM is completely standalone
 * @package LBWP\ERP\System
 * @author Michael Sebel <michael@comotive.ch>
 */
class Standalone extends Component
{
  /**
   * Initialize the standalone PIM component
   */
  public function init()
  {
    // Redirect dashboard to the PIM product list
    add_action('load-index.php', array($this, 'redirectDashboard'));
    // Remove default post types and menu items
    add_action('admin_menu', array($this, 'cleanupAdminMenu'));
    // Unregister default post types
    add_action('init', array($this, 'unregisterDefaultPostTypes'), 99);
    // Remove comment support
    add_filter('comments_open', '__return_false', 20, 2);
    add_filter('pings_open', '__return_false', 20, 2);
  }

  /**
   * Redirect the dashboard to the PIM product list
   */
  public function redirectDashboard()
  {
    wp_redirect(admin_url('edit.php?post_type=lbwp-pid'));
    exit;
  }

  /**
   * Remove posts, pages, comments and appearance menus from admin
   */
  public function cleanupAdminMenu()
  {
    remove_menu_page('index.php');
    remove_menu_page('edit.php');
    remove_menu_page('edit.php?post_type=page');
    remove_menu_page('edit-comments.php');
    remove_menu_page('themes.php');
  }

  /**
   * Unregister default post types that are not needed
   */
  public function unregisterDefaultPostTypes()
  {
    unregister_post_type('post');
    unregister_post_type('page');
  }
}