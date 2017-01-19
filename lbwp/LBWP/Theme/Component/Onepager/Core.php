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
      <div class="lbwp-onepager-container">
        {items}
      </div>
    ',
    'item' => '
      <section {itemAttributes}>
        <a name="{itemSlug}" class="item-anchor"></a>
        {itemContent}
      </section>
    ',
    'menu' => '
      <nav class="lbwp-onepager-menu">
        <ul>
          {items}
        </ul>
      </nav>
    ',
    'menu-item' => '
      <li {itemAttributes}>
        {itemBeforeLink}
        <a href="{itemLink}">{itemName}</a>
        {itemAfterLink}
      </li>
    '
  );
  /**
   * Can be used to provide a dropdown (multi) of classes on a onepager element.
   * Use "core" key to add classes for every item and the item-key for specific classes.
   * Example usage of two core classes and classes for certain types
   * array(
   *   // for all elements
   *   'core' => array(
   *     'tiles-background' => 'Hintergrund mit Kacheln',
   *     'negative-top-margin' => 'In vorheriges Element schieben (Negativ-Margin)'
   *   ),
   *   // Only for the simple-content element
   *   'simple-content' => array(
   *     'gallery-small' => 'Galerie: Kleine Bilder',
   *     'gallery-large' => 'Galerie: Slider in voller Breite'
   *   )
   * )
   * @var array
   */
  protected $classSettings = array();
  /**
   * @var array actual instances of items, keyed by post ids
   */
  protected $instances = array();
  /**
   * @var string the item class
   */
  protected $itemClass = '';
  /**
   * @var bool tells if the current menu has menus enabled
   */
  protected $currentBackendHasMenusEnabled = false;
  /**
   * @var bool needs to be overridden, if menu features are being used
   */
  protected $useMenus = false;
  /**
   * @var bool use direct children only to be selected from a onepager
   */
  protected $directChildrenOnly = true;
  /**
   * @var bool tells if a menu has already been displayed
   */
  protected $displayedMenu = false;
  /**
   * @var string can be overridden: If set, the page template is preselected automatically
   */
  protected $autoSetPageTemplate = '';
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
    add_filter('mbh_addNewPostTypeItemHtmlCallback', array($this, 'getNewItemCallback'));
    // Execute item metaboxes, if an item admin is displayed
    add_action('admin_init', array($this, 'executeItemMetaboxes'));
    add_action('save_post', array($this, 'executeItemMetaboxes'));
    add_action('save_post_page', array($this, 'addAutoTemplate'));
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
   * @return callable
   */
  public function getNewItemCallback()
  {
    return array($this, 'getChoosenItemHtml');
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
        $this->currentBackendHasMenusEnabled = get_post_meta($postId, 'onepager-activate-menu', true) == 'on';
        // If the onepager is active, add / remove everything needed
        if ($onepagerActive) {
          remove_post_type_support($type, 'editor');
          // Define arguments for the drodpwon
          $args = array(
            'sortable' => true,
            'multiple' => true,
            'containerClasses' => 'chosen-dropdown-item one-pager-content',
            'itemHtmlCallback' => $this->getNewItemCallback(),
            'metaDropdown' => array(
              'key' => 'element-type',
              'data' => $this->getNamedKeys()
            )
          );

          // Only display direct children, if configured
          if ($this->directChildrenOnly) {
            $args['parent'] = $postId;
          }

          // Add the helper to add one pager elements
          $boxId = $type . '_onepager-elements';
          $helper->addMetabox($boxId, 'Inhalte für den Onepager', 'normal', 'high');
          $helper->addPostTypeDropdown('elements', $boxId, 'Inhalte', self::TYPE_SLUG, $args);

          // IF menus are active, add another metabox for it
          if ($this->useMenus) {
            $boxId = $type . '_onepager-menu';
            $helper->addMetabox($boxId, 'Menu für den Onepager', 'normal', 'high');
            $helper->addCheckbox('onepager-activate-menu', $boxId, 'Automatisches Menu anzeigen');
            $helper->addCheckbox('onepager-has-home-menu', $boxId, 'Zusätzlicher erster Menupunkt (z.B Home)');
            $helper->addInputText('onepager-home-menu-name', $boxId, 'Titel des ersten Menupunkt');
            $helper->addDropdown('onepager-home-type', $boxId, 'Verhalten des Menupunktes', array(
              'items' => array(
                'scroll-top' => 'Nach oben springen/scrollen',
                'reload' => 'Seite neu laden'
              )
            ));
          }
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
          $element = new $class($this->useMenus, $type, $this);
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
   * Automatically set the template, if needed
   * @param int $postId
   */
  public function addAutoTemplate($postId)
  {
    $isActive = get_post_meta($postId, 'onepager-active', true) == 'on' || $_POST[$postId . '_onepager-active'] == 'on';
    $alreadyDone = get_post_meta($postId, 'automatically_set_template', true);
    if ($isActive && !$alreadyDone && strlen($this->autoSetPageTemplate) > 0) {
      update_post_meta($postId, '_wp_page_template', $this->autoSetPageTemplate);
      update_post_meta($postId, 'automatically_set_template', true);
    }
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
   * @param array $items
   * @return string
   */
  public function getItemsHtml($items)
  {
    $html = '';
    // If menus are active and there is a menu to display
    if ($this->useMenus && !$this->displayedMenu) {
      $html .= $this->getMenuHtml();
    }

    if (is_array($items) && count($items) > 0) {
      // Get item html and wrap in template
      foreach ($items as $element) {
        // Create the html output
        $elementHtml = $element->getHtml();
        if (strlen($elementHtml) > 0) {
          // Set attributes of the item
          $attributes = '';
          $item = $element->getPost();
          $classes = get_post_class($this->itemClass, $item->ID);
          $classes[] = get_post_meta($item->ID, 'item-type', true);
          // Add and merge core classes, if given
          $coreClasses = get_post_meta($item->ID, 'core-classes');
          if (is_array($coreClasses) && count($coreClasses) > 0) {
            $classes = array_merge($classes, $coreClasses);
          }
          if (count($classes) > 0) {
            $attributes .= ' class="' . implode(' ', $classes) . '"';
          }

          $html .= Templating::getBlock($this->templates['item'], array(
            '{itemAttributes}' => trim($attributes),
            '{itemSlug}' => $item->post_name,
            '{itemContent}' => $elementHtml,
          ));
        }
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
   * Shortcode to output the actual one pager content
   */
  public function getPageHtml()
  {
    // Get the parent object to load the items
    $parent = WordPress::getPost();
    $items = $this->getItems($parent->ID);
    return $this->getItemsHtml($items);
  }

  /**
   * @return string the menu html of a onepager, if given
   */
  public function getMenuHtml()
  {
    // Get the parent object to load the items
    $parent = WordPress::getPost();

    if ($this->displayedMenu || get_post_meta($parent->ID, 'onepager-activate-menu', true) != 'on') {
      return '';
    }

    // Get the actual items
    $html = '';
    $items = $this->getItems($parent->ID);

    // Create the home item if needed
    if (get_post_meta($parent->ID, 'onepager-activate-menu', true) == 'on') {
      // Decide how it works
      switch (get_post_meta($parent->ID, 'onepager-home-type', true)) {
        case 'scroll-top':
          $url = 'javascript:window.scrollTo(0,0);';
          break;
        case 'reload':
        default:
          $url = get_permalink($parent->ID);
          break;
      }

      // Create the menu item
      $html .= Templating::getBlock($this->templates['menu-item'], array(
        '{itemAttributes}' => 'class="menu-item home"',
        '{itemLink}' => $url,
        '{itemName}' => get_post_meta($parent->ID, 'onepager-home-menu-name', true),
        '{itemBeforeLink}' => '',
        '{itemAfterLink}' => '',
      ));
    }

    if (is_array($items) && count($items) > 0) {
      // Get item html and wrap in template
      foreach ($items as $element) {
        $item = $element->getPost();
        // Skip if not configured to show
        if (get_post_meta($item->ID, 'show-in-menu', true) != 'on') {
          continue;
        }

        // Create the attributes
        $attributes = 'class="menu-item ' . get_post_meta($item->ID, 'item-type', true) . ' ' . $item->post_name . '"';

        // Create the html block
        $html .= Templating::getBlock($this->templates['menu-item'], array(
          '{itemAttributes}' => trim($element->filterMenuItemAttributes($attributes)),
          '{itemLink}' => '#' . $item->post_name,
          '{itemName}' => get_post_meta($item->ID, 'menu-name', true),
          '{itemBeforeLink}' => $element->getBeforeMenuItemHtml(),
          '{itemAfterLink}' => $element->getAfterMenuItemHtml(),
        ));
      }
    }

    // Remember, that we already did this once
    $this->displayedMenu = true;

    // Wrap the output into the container
    return Templating::getContainer(
      $this->templates['menu'],
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

    $additional = '';
    if ($this->currentBackendHasMenusEnabled) {
      // See if it should be shown in the menu
      $showInMenu = __('Nein', 'lbwp');
      $menuName = '';
      if (get_post_meta($item->ID, 'show-in-menu', true) == 'on') {
        $showInMenu = __('Ja', 'lbwp');
        $menuName = '<p class="mbh-post-info">' . __('Name des Menupunktes', 'lbwp') . ': ' . get_post_meta($item->ID, 'menu-name', true) . '</p>';
      }
      $additional .= '<p class="mbh-post-info">' . __('Im Menu anzeigen', 'lbwp') . ': ' . $showInMenu . '</p>' . $menuName;
    }

    // Edit link for modals
    $editLink = admin_url('post.php?post=' . $item->ID . '&action=edit&ui=show-as-modal');

    return '
      <div class="mbh-chosen-inline-element">
        ' . $image . '
        <h2><a href="' . $editLink . '" class="open-modal">' . PostTypeDropdown::getPostElementName($item, $typeMap) . '</a></h2>
        <ul class="mbh-item-actions">
          <li><a href="' . $editLink . '" class="open-modal">' . __('Bearbeiten', 'lbwp') . '</a></li>
          <li><a href="#" data-id="' . $item->ID . '" class="trash-element trash">' . __('Löschen', 'lbwp') . '</a></li>
        </ul>
        <p class="mbh-post-info">' . __('Autor', 'lbwp') . ': ' . get_the_author_meta('display_name', $item->post_author) . '</p>
        <p class="mbh-post-info">' . __('Letzte Änderung', 'lbwp') . ': ' . Date::convertDate(Date::SQL_DATETIME, Date::EU_DATE, $item->post_modified) . '</p>
        <p class="mbh-post-info">' . __('Inhalts-Typ', 'lbwp') . ': ' . $this->getItemTypeName($item->ID) . '</p>
        ' . $additional . '
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
        if ($postObject->post_status == 'publish' || current_user_can('edit_posts')) {
          $type = get_post_meta($postId, 'item-type', true);
          /** @var BaseItem $element */
          $class = $this->items[$type]['class'];
          $element = new $class($this->useMenus, $type, $this);
          $element->setPost($postObject);
          $this->instances[$postId] = $element;
        }
      }
    }

    return $this->instances;
  }

  /**
   * @param array $elements list of one pager item ids
   */
  public function getItemsByIdList($elements)
  {
    $items = array();
    foreach ($elements as $postId) {
      $postObject = get_post($postId);
      $type = get_post_meta($postId, 'item-type', true);
      /** @var BaseItem $element */
      $class = $this->items[$type]['class'];
      $element = new $class(false, $type, $this);
      $element->setPost($postObject);
      $items[$postId] = $element;
    }

    return $items;
  }

  /**
   * @param string $key the item key
   * @return array a listof useable core classes
   */
  public function getCoreClassItems($key)
  {
    $classes = array();
    $keyList = array('core', $key);

    // See if there are mergeable classes for the item
    foreach ($keyList as $classKey) {
      if (isset($this->classSettings[$classKey])) {
        $classes = array_merge($this->classSettings[$classKey], $classes);
      }
    }

    return $classes;
  }

  /**
   * Should be used to fill the items class array or flush it even
   */
  abstract protected function addItems();
}