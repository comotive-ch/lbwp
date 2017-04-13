<?php

namespace LBWP\Module\Listings\Component;

use LBWP\Helper\Metabox;
use LBWP\Helper\MetaItem\CrossReference;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This class registers the posttype and provides methods for working with it
 * @package LBWP\Module\Listings\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Posttype extends Base
{
  /**
   * @var string the post types
   */
  const TYPE_LIST = 'lbwp-list';
  const TYPE_ITEM = 'lbwp-listitem';
  /**
   * Called after component construction
   */
  public function load()
  {
    if (is_admin()) {

    }
  }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Register post types
    $this->registerTypes();
    // Add meta configurations for lists
    add_action('admin_init', array($this, 'addListingMetaboxes'), 500);
    add_action('admin_init', array($this, 'addItemMetaboxes'), 500);
    // Filter the metaboxes on items depeding on lists
    add_filter('filter_metabox_helper_' . self::TYPE_ITEM, array($this, 'filterItemMetaboxes'), 10, 2);
  }

  /**
   * Register the list and items post types
   */
  protected function registerTypes()
  {
    // The list itself
    WordPress::registerType(self::TYPE_LIST, 'Liste', 'Listen', array(
      'show_in_menu' => 'listings',
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'supports' => array('title', 'editor')
    ), '');

    // The list item
    WordPress::registerType(self::TYPE_ITEM, 'Listeneintrag', 'Listeneinträge', array(
      'show_in_menu' => 'listings',
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'supports' => array('title', 'editor', 'thumbnail')
    ), 'n');
  }

  /**
   * Add listing metaboxes to connections a list with a template and items
   */
  public function addListingMetaboxes()
  {
    $helper = Metabox::get(self::TYPE_LIST);
    $boxId = 'listing-configuration';
    $helper->addMetabox($boxId, __('Einstellungen', 'lbwp'));
    $helper->hideEditor($boxId);

    // Custom item to configure a template
    $helper->addDropdown('template-id', $boxId, __('Design der Liste', 'lbwp'), array(
      'multiple' => false,
      'sortable' => false,
      'items' => $this->getTemplateItems()
    ));

    // Add cross referencing with list > items
    $helper->addPostCrossReference($boxId, __('Einträge', 'lbwp'), self::TYPE_LIST, self::TYPE_ITEM);
    $helper->addInputText('additional-class', $boxId, 'Zusätzliche CSS Klasse (Optional)');
  }

  /**
   * Add metabox to assign the item to a list
   */
  public function addItemMetaboxes()
  {
    $helper = Metabox::get(self::TYPE_ITEM);
    $boxId = 'list-item-assignment';
    $helper->addMetabox($boxId, __('Zugewiesene Liste(n)', 'lbwp'));
    $helper->hideEditor($boxId);

    // Add cross referencing with item > lists
    $helper->addPostCrossReference($boxId, __('Listen', 'lbwp'), self::TYPE_ITEM, self::TYPE_LIST);
  }

  /**
   * Get the list of templates from the configurator
   * @return array key/value for template selection
   */
  protected function getTemplateItems()
  {
    $templateOptions = array();
    $templates = $this->core->getConfigurator()->getTemplates();

    // Reform the templates
    foreach ($templates as $template) {
      $templateOptions[$template['key']] = $template['name'];
    }

    return $templateOptions;
  }

  /**
   * This removes all metaboxes that are not needed, because the current item hasn't an according list selected
   * @param array $metaboxes registered metaboxes
   * @param \WP_Post $post the post object to display the metaboxes
   * @return array filtered list of metaboxes
   */
  public function filterItemMetaboxes($metaboxes, $post)
  {
    $filteredList = array();
    $templates = array();

    // Get list key and the assigned lists from cross reference
    $listKey = CrossReference::getKey(self::TYPE_ITEM, self::TYPE_LIST);
    $lists = get_post_meta($post->ID, $listKey, true);

    // Go trough lists to find out templates
    if (is_array($lists) && count($lists) > 0){
      foreach ($lists as $listId) {
        $templates[] = get_post_meta($listId, 'template-id', true);
      }
    }

    // Make sure not to filter boxes without the item prefix
    foreach ($metaboxes as $id => $metabox) {
      if (!Strings::startsWith($id, Configurator::BOX_PREFIX)) {
        $filteredList[$id] = $metabox;
      }
    }

    // Go trough metaboxes and filter them down
    foreach ($templates as $templateId) {
      $boxId = $this->core->getConfigurator()->getTemplateKey($templateId);
      if (isset($metaboxes[$boxId])) {
        $filteredList[$boxId] = $metaboxes[$boxId];
      }
    }

    return $filteredList;
  }
} 