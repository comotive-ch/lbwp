<?php

namespace LBWP\Module\General\FragmentCache\Item;

use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\General\FragmentCache\Definition;

/**
 * Implements global caching of menus
 * @package LBWP\Module\General\FragmentCache\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Menu implements Definition
{
  /**
   * @var string cache prefix and group
   */
  const PREFIX = 'FC_Menu_';
  const GROUP = 'FragmentCache';

  /**
   * Invalidate the menu cache(s) on saving/deleting the menu/items
   */
  public function registerInvalidation()
  {
    add_action('wp_update_nav_menu', array($this, 'clearCachedMenus'));
    add_action('wp_delete_nav_menu', array($this, 'clearCachedMenus'));
  }

  /**
   * Hook into the fragment to register the caching and the providing
   */
  public function registerFrontend()
  {
    add_filter('pre_wp_nav_menu', array($this, 'printMenu'), 100, 2);
    add_filter('wp_nav_menu', array($this, 'cacheMenu'), 100, 2);
  }

  /**
   * @param mixed $menu the menu input (null) returned, if not cached
   * @param array $args the menu args
   * @return string html code, if cached
   */
  public function printMenu($menu, $args)
  {
    $menuHtml = wp_cache_get($this->getMenuKey($args), self::GROUP);

    if (strlen($menuHtml) > 0) {
      $menu = $menuHtml;
    }

    return $menu;
  }

  /**
   * @param string $menu the menu html code
   * @param array $args the menu args
   * @return string the same menu, we just used it to cache it
   */
  public function cacheMenu($menu, $args)
  {
    // Don't cache if explicitly deactivated
    if (isset($args->omit_fragment_cache) && $args->omit_fragment_cache) {
      return $menu;
    }

    // Save the menu for next call
    wp_cache_set($this->getMenuKey($args), $menu, self::GROUP);

    return $menu;
  }

  /**
   * Clear all cached menus on saving or deleting an item
   */
  public function clearCachedMenus()
  {
    MemcachedAdmin::flushByKeyword(self::PREFIX);
  }

  /**
   * @param array $args menu args to make the key individual
   * @return string the key for the cached menu
   */
  protected function getMenuKey($args)
  {
    $args->isHttps = ($_SERVER['HTTPS']) ? 1 : 0;
    return self::PREFIX . md5($_SERVER['REQUEST_URI']) . md5(json_encode($args));
  }
}