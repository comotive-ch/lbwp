<?php

namespace LBWP\ERP\PIM;

use LBWP\Theme\Component\ACFBase;
use LBWP\Util\WordPress;

/**
 * Provide PIM post type base functions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class Base extends ACFBase
{
  const TYPE_SLUG = 'lbwp-pid';
  const PRODUCT_GROUP_SLUG = 'product-group';

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    add_filter('duplicateable_post_types', array($this, 'addDuplicateability'));

    $this->addTypeConfig();
  }

  /**
   * @param $allowedTypes
   * @return mixed
   */
  public function addDuplicateability($allowedTypes)
  {
    $allowedTypes[] = self::TYPE_SLUG;
    return $allowedTypes;
  }

  /**
   * @return void
   */
  public function setup()
  {
    parent::setup();

    WordPress::registerTaxonomy(
      self::PRODUCT_GROUP_SLUG,
      'Hauptgruppe',
      'Hauptgruppen',
      '',
      array('public' => false),
      self::TYPE_SLUG
    );
  }

  /**
   * Post type and taxonomy configuration
   * @return void
   */
  protected function addTypeConfig()
  {
    WordPress::registerType(self::TYPE_SLUG, 'Produkt', 'Produkte', array(
      'menu_icon' => 'dashicons-media-spreadsheet',
      'labels' => array(
        'menu_name' => 'PIM',
        'add_new_item' => 'Neues Produkt'
      ),
      'supports' => array('title'),
      'menu_position' => 57,
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'has_archive' => false
    ));
  }

  /**
   * No blocks needed here
   */
  public function blocks()
  {

  }

  /**
   * Adds field settings
   */
  public function fields()
  {

  }
}