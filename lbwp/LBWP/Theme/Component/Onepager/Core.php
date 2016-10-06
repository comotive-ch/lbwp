<?php

namespace LBWP\Theme\Component\Onepager;

use LBWP\Helper\Metabox;
use LBWP\Helper\MetaItem\PostTypeDropdown;
use LBWP\Theme\Base\Component as BaseComponent;
use LBWP\Theme\Component\Onepager\Item\Base as BaseItem;
use LBWP\Util\Date;
use LBWP\Util\Templating;
use LBWP\Util\WordPress;

/**
 * The base class for creating meaningful onepager modules
 * @package LBWP\Theme\Component\Onepager
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Core extends BaseComponent
{
  /**
   * @var array the types that are allowed to use the onepager logic
   */
  protected $types = array('page');

  /**
   * @var array the list of possible items to use
   */
  protected $items = array(
    'simple-content' => array(
      'name' => 'Editor-Inhalt / 2-Spaltiger Inhalt',
      'class' => '\LBWP\Theme\Component\Onepager\Item\SimpleContent'
    )
  );

  /**
   * @var array the basic templates around items
   */
  protected $templates = array(
    'container' => '
      <div class="lbwp-onepager-contaner">
        {items}
      </div>
    ',
    'item' => '
      <section {itemAttributes}>
        <a name="{itemSlug}"></a>
        {itemContent}
      </section>
    '
  );

  /**
   * @var array actual instances of items, keyed by post ids
   */
  protected $instances = array();
  /**
   * @var string the item class
   */
  protected $itemClass = '';
  /**
   * The slug of one pager post items
   */
  const TYPE_SLUG = 'onepager-item';
  /**
   * The shortcode name
   */
  const SHORTCODE_NAME = 'lbwp:onepager';

  /**
   * Internal setup, doesn't need to be overridden
   */
  public function setup()
  {
    add_shortcode(self::SHORTCODE_NAME, array($this, 'getPageHtml'));
    // Register the post type and frontend actions
    add_action('init', array($this, 'addPosttype'));
    add_filter('body_class', array($this, 'addBodyClass'));
    // Add metaboxes (we need it ad admin_init, so explicitly add save_post too)
    add_action('admin_init', array($this, 'addMetaboxes'));
    add_action('save_post', array($this, 'addMetaboxes'));
    add_action('wp_insert_post_data', array($this, 'filterPostContent'));
    add_action('mbh_addNewPostTypeItem', array($this, 'addTypeMetaInfo'), 10, 2);
    // Execute item metaboxes, if an item admin is displayed
    add_action('admin_init', array($this, 'executeItemMetaboxes'));
    add_action('save_post', array($this, 'executeItemMetaboxes'));
  }

  /**
   * Initialize the component. Is likely to be overridden and needs to be
   * called in override method at end, to use all filters
   */
  public function init()
  {
    // Apply filters on templates
    $this->templates['container'] = apply_filters('OnePagerTemplate_container', $this->templates['container']);
    $this->templates['item'] = apply_filters('OnePagerTemplate_item', $this->templates['item']);
    // Let developers add additional items, then load them
    $this->addItems();
  }

  /**
   * Add the invisible post type for one pager object
   */
  public function addPosttype()
  {
    WordPress::registerType(
      self::TYPE_SLUG,
      'Inhalts-Element (Onepager)',
      'Inhalts-Elemente (Onepager)',
      array(
        'public' => false,
        'has_archive' => false,
        'exclude_from_search' => true,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_nav_menus' => false,
        'show_in_menu' => false,
        'supports' => array('title')
      )
    );
  }

  /**
   * Adds the metaboxes, removes post type support for editor if one pager is active
   */
  public function addMetaboxes()
  {
    foreach ($this->types as $type) {
      $helper = Metabox::get($type);
      // Add the checkbox metabox
      $boxId = $type . '_onepager-settings';
      $helper->addMetabox($boxId, 'Onepager', 'side', 'default');
      $helper->addCheckbox('onepager-active', $boxId, 'Onepager-Verwaltung aktivieren (Bisheriger Inhalt geht verloren!)', array(
        'autosave_on_change' => true,
        'description' => 'Aktivieren'
      ));

      // Get current post id from admin context
      $postId = $this->getCurrentPostId();

      // If the checkbox is active, add full stuff
      if ($postId > 0) {
        $onepagerActive = get_post_meta($postId, 'onepager-active', true) == 'on';
        // If the onepager is active, add / remove everything needed
        if ($onepagerActive) {
          remove_post_type_support($type, 'editor');
          // Add the helper to add one pager elements
          $boxId = $type . '_onepager-elements';
          $helper->addMetabox($boxId, 'Inhalte für den Onepager', 'normal', 'high');
          $helper->addPostTypeDropdown('elements', $boxId, 'Inhalte', self::TYPE_SLUG, array(
            'sortable' => true,
            'multiple' => true,
            'itemHtmlCallback' => array($this, 'getChoosenItemHtml'),
            'metaDropdown' => array(
              'key' => 'element-type',
              'data' => $this->getNamedKeys()
            )
          ));
        }
      }
    }
  }

  /**
   * Execute metaboxes for items, if an item is displayed
   */
  public function executeItemMetaboxes()
  {
    $postId = $this->getCurrentPostId();

    // Only do something, if there is an actual post id
    if ($postId > 0) {
      // Now only try something, if there is a post of our type
      $candidate = get_post($postId);
      if ($candidate->post_type == self::TYPE_SLUG) {
        // Does the candidate have a certain, known type?
        $type = get_post_meta($postId, 'item-type', true);
        if (isset($this->items[$type])) {
          /** @var BaseItem $element */
          $class = $this->items[$type]['class'];
          $element = new $class();
          $element->onMetaboxAdd();
        }
      }
    }
  }

  /**
   * @param $classes
   * @return array
   */
  public function addBodyClass($classes)
  {
    $post = WordPress::getPost();
    // Add a class, if it is a one pager
    if (get_post_meta($post->ID, 'onepager-active', true) == 'on') {
      $classes[] = 'is-onepager';
    }

    return $classes;
  }

  /**
   * @return int the current post id or 0 if not available
   */
  protected function getCurrentPostId()
  {
    // Get a post id (depending on get or post, context)
    $postId = intval($_GET['post']);
    if ($postId == 0) {
      $postId = intval($_POST['post_ID']);
    }

    return $postId;
  }

  /**
   * @return array a named array of item keys
   */
  protected function getNamedKeys()
  {
    $result = array();
    foreach ($this->items as $key => $data) {
      $result[$key] = $data['name'];
    }

    return $result;
  }

  /**
   * @param array $data the post data
   * @return array data array filtered
   */
  public function filterPostContent($data)
  {
    $postId = intval($_POST['post_ID']);
    $active = $_POST[$postId . '_onepager-active'] == 'on';
    $hasShortcode = stristr($data['post_content'], self::SHORTCODE_NAME) !== false;

    // Only switch, if active and no shortcode yet
    if ($active && !$hasShortcode) {
      // Insert the shortcode, and make a backup in post meta
      update_post_meta($postId, 'onepager-content-backup', $data['post_content']);
      $data['post_content'] = '[' . self::SHORTCODE_NAME . ']';
    } else if (!$active && $hasShortcode) {
      // Get content from backup
      $data['post_content'] = get_post_meta($postId, 'onepager-content-backup', true);
      delete_post_meta($postId, 'onepager-content-backup');
    }

    return $data;
  }

  /**
   * Add the actual item type to an item after creating a new element
   * @param int $postId the post id of the new item
   * @param string $type the post type to check
   */
  public function addTypeMetaInfo($postId, $type)
  {
    if (isset($_POST['typeKey']) && $_POST['typeKey'] == 'element-type' && $type == self::TYPE_SLUG) {
      update_post_meta($postId, 'item-type', $_POST['typeValue']);
    }
  }

  /**
   * Shortcode to output the actual one pager content
   */
  public function getPageHtml()
  {
    // Get the parent object to load the items
    $parent = WordPress::getPost();
    $items = $this->getItems($parent->ID);

    $html = '';
    if (is_array($items) && count($items) > 0) {
      // Get item html and wrap in template
      foreach ($items as $element) {
        // Set attributes of the item
        $attributes = '';
        $classes = get_post_class($this->itemClass, $element->getPost()->ID);
        if (count($classes) > 0) {
          $attributes .= ' class="' . implode(' ', $classes) . '"';
        }

        // Create the html output
        $html .= Templating::getBlock($this->templates['item'], array(
          '{itemAttributes}' => trim($attributes),
          '{itemSlug}' => $element->getPost()->post_name,
          '{itemContent}' => $element->getHtml(),
        ));
      }
    }

    // Wrap the output into the container
    return Templating::getContainer(
      $this->templates['container'],
      $html,
      '{items}'
    );
  }

  /**
   * @param \WP_Post $item the post item
   * @param array $typeMap a post type mapping
   * @return string html code to represent the item
   */
  public function getChoosenItemHtml($item, $typeMap)
  {
    $image = '';
    if (has_post_thumbnail($item->ID)) {
      $image = '<img src="' . WordPress::getImageUrl(get_post_thumbnail_id($item->ID), 'thumbnail') . '">';
    }

    return '
      <div class="mbh-chosen-inline-element">
        ' . $image . '
        <h2>' . PostTypeDropdown::getPostElementName($item, $typeMap) . '</h2>
        <p class="mbh-post-info">Autor: ' . get_the_author_meta('display_name', $item->post_author) . '</p>
        <p class="mbh-post-info">Letzte Änderung: ' . Date::convertDate(Date::SQL_DATETIME, Date::EU_DATE, $item->post_modified) . '</p>
        <p class="mbh-post-info">Inhalts-Typ: ' . $this->getItemTypeName($item->ID) . '</p>
      </div>
    ';
  }

  /**
   * @param int $postId
   * @return string type name
   */
  public function getItemTypeName($postId)
  {
    $type = get_post_meta($postId, 'item-type', true);
    return $this->items[$type]['name'];
  }

  /**
   * @param int $id the respective post id of a single item
   * @return BaseItem the item object
   */
  public function getItem($id)
  {
    return $this->instances[$id];
  }

  /**
   * @param int $id the post object where items are assigned
   * @return BaseItem[] all items assigned to the page/post
   */
  public function getItems($id)
  {
    // Load the items, if not available yet
    if (count($this->instances) == 0) {
      $elements = get_post_meta($id, 'elements');
      // Load data of each element, but only execute published ones
      foreach ($elements as $postId) {
        $postObject = get_post($postId);
        if ($postObject->post_status == 'publish') {
          $type = get_post_meta($postId, 'item-type', true);
          /** @var BaseItem $element */
          $class = $this->items[$type]['class'];
          $element = new $class();
          $element->setPost($postObject);
          $this->instances[$postId] = $element;
        }
      }
    }

    return $this->instances;
  }

  /**
   * Should be used to fill the items class array or flush it even
   */
  abstract protected function addItems();
}