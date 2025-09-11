<?php

namespace LBWP\Module\Listings\Component;

use LBWP\Helper\MetaItem\CrossReference;
use LBWP\Util\ArrayManipulation;

/**
 * This class handles filtering list items in the backend
 * @package LBWP\Module\Listings\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class ListFilter extends Base
{
  /**
   * Called after component construction
   */
  public function load() { }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    if (is_admin()) {
      add_action('restrict_manage_posts', array($this, 'showListDropdown'));
      add_filter('parse_query', array($this, 'reduceListItems'));
    }
  }

  /**
   * Show all lists as filter dropdown
   */
  public function showListDropdown()
  {
    // Only display restriction for list items
    global $typenow;
    if ($typenow == Posttype::TYPE_ITEM) {
      // Start output of a select box
      $html = '<select name="pt-list-filter" id="pt-list-filter" class="postform">';
      $html.= '<option value="">' . __('Einträge aller Listen', 'lbwp') . '</option>';
      // Get all lists to be displayed
      $lists = get_posts(array(
        'post_type' => Posttype::TYPE_LIST,
        'posts_per_page' => -1,
        'orderby' => 'post_title',
        'order' => 'ASC'
      ));

      // Add the options
      foreach ($lists as $list) {
        $selected = selected($list->ID, $_REQUEST['pt-list-filter'], false);
        $html .= '
          <option value="' . $list->ID . '"' . $selected . '>
            ' . sprintf(__('Liste &laquo;%s&raquo;'), $list->post_title) . '
          </option>';
      }

      // Close select and print
      $html .= '</select>';
      echo $html;
    }
  }

  /**
   * @param \WP_Query $wpQuery filterable query
   * @return \WP_Query with additional filters, if a reduction is needed
   */
  public function reduceListItems($wpQuery)
  {
    // Only filter, if param is given and the post list query is in charge
    if (
      isset($_REQUEST['pt-list-filter']) && intval($_REQUEST['pt-list-filter']) > 0 &&
      $wpQuery->get('post_type') == Posttype::TYPE_ITEM && $wpQuery->is_main_query()
    ) {
      $listId = intval($_REQUEST['pt-list-filter']);
      $metaKey = CrossReference::getKey(Posttype::TYPE_LIST, Posttype::TYPE_ITEM);
      $postIds = ArrayManipulation::forceArray(get_post_meta($listId, $metaKey, true));
      // Set the query to only use these posts
      $wpQuery->set('post__in', $postIds);
    }

    return $wpQuery;
  }
} 