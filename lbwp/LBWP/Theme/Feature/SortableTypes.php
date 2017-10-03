<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use LBWP\Util\WordPress;

/**
 * Allows to have a (multi)sort interface for all type objects
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class SortableTypes
{
  /**
   * @var FocusPoint the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array();
  /**
   * @var string page parameter prefix
   */
  const PAGE_PREFIX = 'type-sort-';

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return FocusPoint the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new SortableTypes($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {

    // In admin, load the specific scripts and filters on the actual page we need them
    if (is_admin()) {
      // Provide the actual menu items (all with same callback)
      add_action('admin_menu', array($this, 'addSortingMenus'), 50);
      add_action('wp_ajax_save_post_type_order', array($this, 'saveItemOrder'));

      // If we are on the actual sorting page, include some libraries
      if (isset($_GET['page']) && Strings::startsWith($_GET['page'], self::PAGE_PREFIX)) {
        // We use the media styles and some jquery ui as a basis
        wp_enqueue_style('media-views');
        wp_enqueue_script('jquery-ui-sortable');
        // Add the multisort control (needs jquery ui)
        wp_enqueue_script('jquery-multisort');
        // Add our own script to handle the actrual work
        $path = File::getResourceUri() . '/js/components/lbwp-sortable-types.js';
        wp_enqueue_script('sortable-types-js', $path, array('jquery-multisort'), LbwpCore::REVISION);
      }
    }
  }

  /**
   * Saves a package of items and their respecting new order
   */
  public function saveItemOrder()
  {
    if (isset($_POST['packages']) && is_array($_POST['packages'])) {
      foreach ($_POST['packages'] as $package) {
        $id = intval($package['id']);
        $order = intval($package['order']);
        // Only save if both are above zero
        if ($id > 0 && $order > 0) {
          edit_post(array(
            'post_ID' => $id,
            'menu_order' => $order
          ));
        }
      }
    }

    // Send a success only response with no futher data
    WordPress::sendJsonResponse(array(
      'success' => true
    ));
  }

  /**
   * Adds the sorting menus to each sortable post type
   */
  public function addSortingMenus()
  {
    foreach ($this->config as $config) {
      add_submenu_page('edit.php?post_type=' . $config['type'], 'Sortierung', 'Sortierung', 'publish_posts', self::PAGE_PREFIX . $config['type'], array($this, 'displaySortInterface'));
    }
  }

  /**
   * Displays the sort interface
   */
  public function displaySortInterface()
  {
    $type = substr($_GET['page'], strlen(self::PAGE_PREFIX));
    $typeObject = get_post_type_object($type);

    echo '
      <div class="wrap">
        <div class="saving-message"></div>
        <h1>Sortierung &laquo;' . $typeObject->label . '&raquo; <a class="page-title-action save-item-order">Sortierung speichern</a></h1>
        <p>Zur Selektion von mehreren Elementen können die CTRL und/oder Shift-Taste verwenden, danach per Drag & Drop die Sortierung ändern.</p>
        <div class="media-frame type-sort-frame wp-core-ui mode-grid">
          <div class="media-frame-content" data-columns="10">
            <div class="attachments-browser">
              <ul class="attachments">
                ' . $this->getItemList($type) . '
              </ul>
            </div>
          </div>
        </div>
      </div>
    ';
  }

  /**
   * @param string $type the post type we handle right now
   * @return string html for the list of elements including data
   */
  protected function getItemList($type)
  {
    $html = '';

    // Get all the items of that type
    $items = get_posts(array(
      'post_type' => $type,
      'posts_per_page' => -1,
      'orderby' => 'menu_order',
      'order' => 'ASC'
    ));

    // Create the list items
    foreach ($items as $item) {
      $url = get_the_post_thumbnail_url($item->ID, 'thumbnail');
      $html .= '
        <li class="attachment save-ready" data-order="' . $item->menu_order . '" data-id="' . $item->ID . '">
          <div class="attachment-preview">
            <div class="thumbnail">
              <div class="centered"><img src="' . $url . '" alt="' . $item->post_title . '"></div>
            </div>
          </div>
          <div class="attachment-title">
            <span>' . $item->post_title . '</span>
          </div>
          <button type="button" class="button-link check" tabindex="-1">
            <span class="media-modal-icon"></span>
            <span class="screen-reader-text">' . __('Deselect') . '</span>
          </button>
        </li>
      ';
    }

    return $html;
  }
}




