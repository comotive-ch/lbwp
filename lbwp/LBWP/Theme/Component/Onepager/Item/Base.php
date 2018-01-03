<?php

namespace LBWP\Theme\Component\Onepager\Item;
use LBWP\Helper\Metabox;
use LBWP\Theme\Component\Onepager\Core;

/**
 * Base class for one pager items
 * @package LBWP\Theme\Component\Onepager\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
{
  /**
   * @var \WP_Post the post object of the item
   */
  protected $post = NULL;
  /**
   * @var Metabox the metabox helper for this element
   */
  protected $helper = NULL;
  /**
   * @var bool tells if the onepager uses menus potentially
   */
  protected $useMenus = false;
  /**
   * @var bool tells if the onepager uses menus potentially
   */
  protected $useSliders = false;
  /**
   * @var string the cached html of an element
   */
  protected $cachedHtml = false;
    /**
   * @var array some additonal classes for the wrapper element
   */
  protected $additionalClasses = array();

  /**
   * @var string the main box id, to add other fields in onMetaboxAdd
   */
  const MAIN_BOX_ID = 'onepager-item-main';
  /**
   * @var string the menu box id, if given
   */
  const MENU_BOX_ID = 'onepager-item-menu';

  /**
   * Base constructor of a onepager item
   * @param string $key the element type key
   * @param Core $handler the handler reference
   * @param array $settings a settings array
   */
  public function __construct($key, $handler, $settings)
  {
    $this->useMenus = $settings['useMenus'];
    $this->useSliders = $settings['useSliders'];
    $this->key = $key;
    $this->handler = $handler;
    $this->helper = Metabox::get(Core::TYPE_SLUG);
    $this->helper->addMetabox(self::MAIN_BOX_ID, __('Inhalts-Element Einstellungen', 'lbwp'));
  }

  /**
   * @param \WP_Post $post the post object of the item
   */
  public function setPost($post)
  {
    $this->post = $post;
  }

  /**
   * @return \WP_Post the post object of this item
   */
  public function getPost()
  {
    return $this->post;
  }

  /**
   * Can be overriden to add more metaboxes, defaults to a message, that there are no settings
   * Called, when metaboxes for an item are displayed/saved
   */
  public function onMetaboxAdd()
  {
    // Add a box for menu settings on the item, if the parent has menus active
    if ($this->useMenus) {
      $this->helper->addMetabox(self::MENU_BOX_ID, __('Menu-Einstellungen', 'lbwp'));
      $this->helper->addHtml('info', self::MENU_BOX_ID, __('Sofern das Element in einer Seite angezeigt wird, die Menus aktiviert hat, gelten folgende Einstellungen:', 'lbwp'));
      $this->helper->addCheckbox('show-in-menu', self::MENU_BOX_ID, 'Dieses Element im Menu anzeigen');
      $this->helper->addInputText('menu-name', self::MENU_BOX_ID, 'Name des Menupunktes');
    }

    // See if there are class settings to be used
    $classes = $this->handler->getCoreClassItems($this->key);
    if (count($classes) > 0) {
      $this->helper->addDropdown('core-classes', self::MAIN_BOX_ID, __('Darstellungs-Optionen'), array(
        'items' => $classes,
        'multiple' => true
      ));
    }

    // See if sliders are activated
    if ($this->useSliders) {
      $this->helper->addInputText('slider-id', self::MAIN_BOX_ID, 'Slider-ID', array(
        'description' => '
          Sie können mehrere Elemente zu einem Slider zusammenfassen indem sie allen die gleiche Slider-ID geben.
          Die ID kann Buchstaben, Bindestriche und Zahlen enthalten und muss lediglich über alle Elemente gleich benannt werden.'
      ));
    }

    // Show a message if there are no settings
    if (!$this->helper->hasMetaboxFields(self::MAIN_BOX_ID)) {
      $text = '<p>' . __('Für dieses Inhalts-Element gibt es keine erweiterten Einstellungen.', 'lbwp') . '</p>';
      $this->helper->addHtml('info', self::MAIN_BOX_ID, $text);
    }
  }

  /**
   * @return string title from "meta-title" field, if given, else the post title
   */
  protected function getInternalOrMetaTitle()
  {
    $metaTitle = trim(get_post_meta($this->post->ID, 'meta-title', true));
    if (strlen($metaTitle) > 0) {
      return $metaTitle;
    }

    // Fallback to item title (interal)
    return $this->post->post_title;
  }

  /**
   * @param string $class the class to be checked
   * @return bool true, if the class is set
   */
  protected function hasCoreClass($class)
  {
    $classes = get_post_meta($this->post->ID, 'core-classes');
    return in_array($class, $classes);
  }

  /**
   * @return \WP_Post the parent post object
   */
  protected function getParent()
  {
    return get_post($this->post->post_parent);
  }

  /**
   * @return int the parent post id
   */
  protected function getParentId()
  {
    return $this->post->post_parent;
  }

  /**
   * @param string $attributes
   * @return string sames attributes
   */
  public function filterMenuItemAttributes($attributes)
  {
    return $attributes;
  }

  /**
   * @return array return additional classes
   */
  public function getAdditionalClasses()
  {
    return $this->additionalClasses;
  }

  /**
   * @return string
   */
  public function getAfterMenuItemHtml()
  {
    return '';
  }

  /**
   * @return string
   */
  public function getBeforeMenuItemHtml()
  {
    return '';
  }

  /**
   * Can be called, if html is received, so getHtml is called only once per item
   * @return string
   */
  public function getHtmlCached()
  {
    if ($this->cachedHtml === false) {
      $this->cachedHtml = $this->getHtml();
    }

    return $this->cachedHtml;
  }

  /**
   * @return bool tells the menu feature if it needs to show in menu
   */
  public function showInMenu()
  {
    return true;
  }

  /**
   * the function that is executed to generate the items output
   * @return string html code
   */
  abstract public function getHtml();
}