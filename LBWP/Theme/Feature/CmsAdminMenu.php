<?php

namespace LBWP\Theme\Feature;

/**
 * Possibility to add other CMS links
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@sebel.ch>
 */
class CmsAdminMenu
{
  /**
   * @var array
   */
  protected static $menu = array();

  /**
   * Adds the filter where we can add the menu
   * @param $menu
   */
  public static function add($menu)
  {
    self::$menu = $menu;
    add_action('admin_bar_menu', array('\LBWP\Theme\Feature\CmsAdminMenu', 'createMenu'), 100);
  }

  /**
   * Create the actual menu
   * @param \WP_Admin_Bar $adminBar the admin bar
   */
  public static function createMenu($adminBar)
  {
    $adminBar->add_menu(array(
      'id' => 'cms-admin-menu',
      'title' => __('Redaktionssysteme', 'lbwp')
    ));

    foreach (self::$menu as $domain) {
      if ($domain == getLbwpHost()) {
        continue;
      }

      $adminBar->add_node(array(
        'id' => 'cms-admin-menu-' . md5($domain),
        'title' =>  $domain,
        'parent' => 'cms-admin-menu',
        'href' => 'http://' . $domain . '/wp-admin/'
      ));
    }
  }
} 