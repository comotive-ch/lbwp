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
   * @var string the main box id, to add other fields in onMetaboxAdd
   */
  const MAIN_BOX_ID = 'onepager-item-main';

  /**
   * The base constructor, adding the metabox
   */
  public function __construct()
  {
    $this->mainBoxId = '';
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
    if (!$this->helper->hasMetaboxFields($this->mainBoxId)) {
      $text = '<p>' . __('Für dieses Inhalts-Element gibt es keine erweiterten Einstellungen.', 'lbwp') . '</p>';
      $this->helper->addHtml('info', self::MAIN_BOX_ID, $text);
    }
  }

  /**
   * the function that is executed to generate the items output
   * @return string html code
   */
  abstract public function getHtml();
}