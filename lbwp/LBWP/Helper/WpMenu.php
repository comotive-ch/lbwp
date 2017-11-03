<?php

namespace LBWP\Helper;

/**
 * Helper to do extraordinary stuff with menus in themes. Every functions
 * needs to be activated, since some of them are quite intensive.
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 * @author Tom Forrer <tom.forrer@blogwerk.com>
 */
class WpMenu
{
  /**
   * @var bool Currently selected top parent
   */
  protected static $currentTopParentId = false;
  /**
   * Actually fixes a few issues with menus and custom post type objects
   */
  public function activateCustomPostTypeFix()
  {
    add_filter('wp_nav_menu_objects', array($this, 'fixMenuItemsForCustomPostTypes'), 5, 1);
  }

  /**
   * Activates arguments for wp_nav_menu to get submenus
   */
  public function activateQueryableSubmenus()
  {
    add_filter('wp_nav_menu_objects', array($this, 'filterSubNavObjects'), 10, 2);
  }

  /**
   * Activates arguments for wp_nav_menu to get submenus
   */
  public function activateMenuClassFix()
  {
    add_filter('nav_menu_css_class', array($this, 'fixMenuClasses'), 10, 2);
  }

  /**
   * Get the navigation level from the wp_nav_menu HTML (defaults on helper function below class)
   * @param string $navHtml result of wp_nav_menu(array('echo'=>false, 'theme_location' => 'primary', 'container' => false))
   * @param array $args default array('level' => 0, 'levelRange' = 1) : get only first level
   * @return string HTML of the specific level (without subtree)
   */
  public static function getNavigationByLevel($navHtml, $args)
  {
    /*
     *  For navigation level we have a XPath Query:
     * //ul = select all ul elements, does not matter where in the tree
     * //ul/li = all li elements which are descendant of ul
     * //ul/li[@attr="something"] select the li elements which have the attribute attr (denoted by @) and exactly the attribute value "something"
     * //ul/li[contains(@class, "current")] select the li elements which contain the string "current" in their class attribute
     */
    $defaults = array('menu_markup' => 'ul', 'item_markup' => 'li', 'level' => 0, 'levelRange' => 1, 'item_selector' => 'current');
    $args = wp_parse_args($args, $defaults);

    $result = '';
    $levelPath = '/' . $args['menu_markup'] . '/' . $args['item_markup']; // /ul/li
    $current = '[contains(@class, "' . $args['item_selector'] . '")]';

    if ($navHtml != '') {
      $selectXpath = '/' . $levelPath;
      $excludeXpath = '';
      for ($levelIndex = 0; $levelIndex < $args['level']; $levelIndex++) {
        $selectXpath .= $current . $levelPath;
      }
      // generate the exclusion xpath: //ul/li/
      for ($levelIndex = $args['level']; $levelIndex < ($args['levelRange'] + $args['level'] - 1); $levelIndex++) {
        $excludeXpath .= $levelPath;
      }
      if ($excludeXpath != '' || $args['levelRange'] > 0) {
        $excludeXpath = $selectXpath . $excludeXpath . '/' . $args['menu_markup'];
      }
      //Get the html of the subnavigations: apply a query on the $navHtml, and exclude certain subtree elements (all ul elements in the li children)
      $result = self::extractNavigationByXpath($navHtml, $selectXpath, $excludeXpath);
    }
    return $result;
  }

  /**
   * XPath subnavigation parser (defaults on helper function below class)
   * @param string $navHtml the html with all levels obtained from wp_nav_menu('echo=false');
   * @param string $query XPath Query to select a certain element
   * @param string $removeSubTreeQuery XPath Query to remove certain elements from the DOM before querying the XPath Query $query
   * @return string HTML of the prepared and selected subtree
   */
  public static function extractNavigationByXpath($navHtml, $query, $removeSubTreeQuery)
  {
    $html = '';
    if ($navHtml != '') {
      $doc = new \DOMDocument();
      $doc->loadHTML('<?xml encoding="UTF-8">' . $navHtml . '');
      $xpath = new \DOMXpath($doc);

      if ($removeSubTreeQuery != '') {
        $removeNodes = $xpath->query($removeSubTreeQuery);
        foreach ($removeNodes as $node) {
          $node->parentNode->removeChild($node);
        }
      }
      $nodes = $xpath->query($query);

      foreach ($nodes as $node) {
        $html .= $doc->saveHTML($node);
      }
    }
    return $html;
  }

  /**
   * Filter the wp_nav_menu items: select current branch (of the menu tree), select from level (with depth)
   * Additional wp_nav_menu arguments:
   * array(
   *   'level_start'       => 0,     // 0 is everything, 1 is without the top level
   *   'level_depth'       => 0,     // 0 is one level, 1 is an additional level to level_start
   *   'select_branch'     => false, // select only the current branch
   *   'expand_noncurrent' => true   // removes children of the non current branches if false
   *   'check_immediate_parent' => false   // removes children if not immediate parents of each other
   * );
   *
   * @param array $items the items selected for the menu
   * @param \stdClass $args will only do something, if 'level_start' or 'select_branch' is passed to the wp_nav_menu arguments
   * @return array the new item list
   */
  public function filterSubNavObjects($items, $args)
  {
    $parents = array();
    $itemsByDepth = array();
    $current = 0;
    $currentTopParent = 0;
    $currentParents = array();
    $currentItems = array();

    // only filter items if the following arguments are set: select_branch | level_start
    if (isset($args->select_branch) || isset($args->level_start)) {
      $levelStart = isset($args->level_start) ? $args->level_start : 0;
      $levelDepth = isset($args->level_depth) ? $args->level_depth : 0;
      $selectBranch = isset($args->select_branch) ? $args->select_branch : false;
      $expandNonCurrent = isset($args->expand_noncurrent) ? $args->expand_noncurrent : true;
      $checkImmediateParent = isset($args->check_immediate_parent) ? $args->check_immediate_parent : false;

      // build tree map (parents array), detect current items
      foreach ($items as $item) {
        // fix the current_item_ancestor and current_item_parent fields: they are not always set (but the classes are)
        foreach ($item->classes as $class) {
          // sometimes with "-" notation, sometimes with "_" notation: not consistent
          $classComponents = array_merge(explode('_', $class), explode('-', $class));
          if (in_array('current', $classComponents)) {
            if (in_array('ancestor', $classComponents)) {
              $item->current_item_ancestor = true;
            } else if (in_array('parent', $classComponents)) {
              $item->current_item_parent = true;
            } else {
              $item->current = true;
            }
          }
        }
        if ($item->current || $item->current_item_ancestor || $item->current_item_parent) {
          $current = $item->ID;
          if ($item->menu_item_parent == 0) {
            $currentParents[] = $item->ID;
          }
        }
        if ($item->current) {
          $currentItems[] = $item;
        }


        // build parent hierarchy tree
        $parents[$item->ID] = $item->menu_item_parent;
      }

      /*
       * pick the first top parent: if multiple branches were marked as current, we don't know how to differentiate. the first item in the menu structure gets chosen as current.
       * sometimes it doesn't work when the parent items of items of custom post types are not marked by "current_item_ancestor" | "current_item_parent":
       * then we pick the first parent of the current item
       */
      if (count($currentParents) > 0) {
        $currentTopParent = $currentParents[0];
      } else if (count($currentItems) > 0) {
        $currentTopParent = $currentItems[0]->menu_item_parent;
      }

      // Provide context for tools to get the top parent
      self::$currentTopParentId = $currentTopParent;

      // check items if they should be removed
      foreach ($items as $item) {
        $removeItem = false;

        // bubble up to top item in branch
        $id = $item->ID;

        $itemDepth = 0;
        while ($parents[$id] != 0) {
          $id = intval($parents[$id]);
          $itemDepth++;
        }

        $removeItem = (
          // Remove if not same current top and we select branches (only correct submenus of current)
          ($selectBranch && $id != $currentTopParent) ||
          // Remove if we want to remove non current elements from the top
          (!$selectBranch && !$expandNonCurrent && $id != $currentTopParent && $itemDepth > $levelStart) ||
          // When parsing third and deeper hierarchies, check for immediate parent, if not matching, remove them,
          // because else we'd show *all* third level items instead of just currents from their immediate parent
          (!$expandNonCurrent && $checkImmediateParent && !($currentItems[0]->menu_item_parent == $item->menu_item_parent)) ||
          // Remove if mismatching depth
          ($itemDepth < $levelStart) ||
          ($itemDepth > $levelStart + $levelDepth)
        );

        if ($removeItem) {
          // item be gone!
          unset($items[array_search($item, $items)]);
        } else {
          // store the items by depth
          if (!isset($itemsByDepth[$itemDepth])) {
            $itemsByDepth[$itemDepth] = array();
          }
          $itemsByDepth[$itemDepth][] = $item;
        }

      }

      // pre sort then nav items by depth (according to menu_order), because class-wp-walker.php:213 assumes the first item to be the "topmost" element in the hierarchy
      $result = array();
      $depths = array_keys($itemsByDepth);
      sort($depths);
      foreach ($depths as $depth) {
        $currentDepthItems = $itemsByDepth[$depth];
        usort($currentDepthItems, array($this, 'sortNavObjects'));
        // merge the sorted items of the current depth with the result ( array + array won't work, array_merge does not replace the values with same indexes)
        $result = array_merge($result, $currentDepthItems);
      }
      $items = $result;
    }

    return $items;
  }

  /**
   * @return bool the current top parent, if seleted, else false
   */
  public static function getCurrentTopParentId()
  {
    return self::$currentTopParentId;
  }

  /**
   * Fix the menu items:
   * - if a nav object is a term which is associated to the current post (in singular view),
   *   mark them as current
   * - if a nav object has the same url as a post type archive (and we are in a post type archive),
   *   mark it as current
   *
   * @param array $items nav objects
   * @return array
   */
  public function fixMenuItemsForCustomPostTypes($items)
  {
    $customPostTypes = get_post_types(array('_builtin' => false, 'publicly_queryable' => true));
    if (is_singular($customPostTypes)) {

      // read the post type name
      $queriedObject = get_queried_object();
      $postType = $queriedObject->post_type;

      // fetch the post type taxonomies
      $taxonomies = get_object_taxonomies($postType);

      // filter the taxonomies: sometimes only specific post-type <-> taxonomy term associations are needed, see ensi
      $taxonomies = apply_filters('current_taxonomies_for_nav_objects', $taxonomies, $queriedObject, $postType);

      if (is_array($taxonomies) && count($taxonomies) > 0) {
        $terms = wp_get_object_terms(get_the_ID(), $taxonomies, array('fields' => 'ids'));

        // mark nav objects pointing to terms in the current post taxonomies as current
        foreach ($items as $index => $item) {
          if (in_array($item->object, $taxonomies) && in_array($item->object_id, $terms)) {
            $items[$index]->current_item_parent = true;
            $items = static::makeItemAncestorsCurrentRecursive($items, $index);
          }
        }
      }
    } elseif (is_post_type_archive()) {

      // read the post type name
      $queriedObject = get_queried_object();
      $postType = $queriedObject->name;

      // fetch the post type archive url
      $url = get_post_type_archive_link($postType);

      // mark the items with a post type archive url as current
      foreach ($items as $index => $item) {
        if ($item->url == $url) {
          $items[$index]->current_item_parent = true;
          $items = static::makeItemAncestorsCurrentRecursive($items, $index);
        }
      }
    }
    return $items;
  }

  /**
   * Sort Navigation Menu Items compare callback function for usort
   * @param object $navObject1
   * @param object $navObject2
   * @return int
   */
  public function sortNavObjects($navObject1, $navObject2)
  {
    if ($navObject1->menu_order == $navObject2->menu_order) {
      return 0;
    }
    return $navObject1->menu_order < $navObject2->menu_order ? -1 : 1;
  }

  /**
   * Fix the nav object css classes
   *
   * @param array $classes The CSS classes that are applied to the menu item's <li>.
   * @param object $item The current menu item.
   * @return array
   */
  public function fixMenuClasses($classes, $item)
  {
    if ($item->current_item_ancestor && !in_array('current-menu-ancestor', $classes)) {
      $classes[] = 'current-menu-ancestor';
    }
    if ($item->current_item_parent && !in_array('current-menu-parent', $classes)) {
      $classes[] = 'current-menu-parent';
    }
    return $classes;
  }

  /**
   * mark the ancestral line of nav objects with current_item_ancestor
   *
   * @param array $items nav objects
   * @param int $index current item
   * @return array nav objects
   */
  public static function makeItemAncestorsCurrentRecursive($items, $index)
  {
    if (isset($items[$index])) {
      // mark the current item as an ancestor (only if its not already marked as parent)
      if (!$items[$index]->current_item_parent) {
        $items[$index]->current_item_ancestor = true;
      }

      // fetch the parent index (by filtering on the parent id)
      $parentId = $items[$index]->menu_item_parent;
      $filteredParentItemsIndexes = array_keys(array_filter($items, function ($element) use ($parentId) {
        return $element->ID == $parentId;
      }));

      // recursion
      if (count($filteredParentItemsIndexes) > 0) {
        $items = static::makeItemAncestorsCurrentRecursive($items, $filteredParentItemsIndexes[0]);
      }
    }
    return $items;
  }
} 