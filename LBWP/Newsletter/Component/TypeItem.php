<?php

namespace LBWP\Newsletter\Component;

use LBWP\Util\WordPress;
use LBWP\Helper\Metabox;
use LBWP\Newsletter\Component\Base;
use LBWP\Newsletter\Service\Base as ServiceBase;

/**
 * This class handles the newsletter post type
 * @package LBWP\Newsletter\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class TypeItem extends Base
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
    // Only add the type, if there is a working service
    $service = $this->core->getService();

    // Check if the service is valid and working
    if ($service instanceof ServiceBase && $service->isWorking()) {
      // Add post type and meta fields
      $this->addPostType();
      //$this->addMetaFields();
      add_action('admin_init', array($this, 'addMetaFields'), 10, 2);
    }
  }

  /**
   * Adds the post type
   */
  protected function addPostType()
  {
    WordPress::registerType('lbwp-nl-item', 'Beitrag', 'Beiträge', array(
      'show_in_menu' => 'newsletter',
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'supports' => array(
        'title', 'thumbnail'
      )
    ));
  }

  /**
   * Adds metaboxes and fields
   */
  public function addMetaFields()
  {

    // Get some help :-)
    $helper = Metabox::get('lbwp-nl-item');

    // Metabox for settings
    $boxId = 'item-settings';
    $helper->addMetabox($boxId, 'Newsletter Beitrag');

    // Metabox for text and link
    $helper->addTextarea('newsletterText', $boxId, 'Text', 120, array('required' => true));
    $helper->addInputText('newsletterLink', $boxId, 'Link');
  }
} 