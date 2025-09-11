<?php

namespace LBWP\Aboon\Backend;

use LBWP\Helper\WooCommerce\Util;
use LBWP\Theme\Base\Component;
use LBWP\Util\WordPress;

/**
 * NOT HPOS COMPATIBLE
 * Allows better filtering, tagging and so on in orders an subscriptions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class Filtering extends Component
{
  const TYPE_SUBS = 'shop_subscription';
  const TAX_SUBS_CAT = 'shop-subs-tag';

  /**
   * Declare incompat with HPOS
   * @return void
   */
  public function setup()
  {
    Util::setHposIncompatible();
    parent::setup();
  }

  /**
   * Initialize the component
   */
  public function init()
  {
    WordPress::registerTaxonomy(self::TAX_SUBS_CAT, 'Abo Kategorie', 'Abo Kategorien', '', array(), self::TYPE_SUBS);
    // Restrict the recipes by category
    WordPress::restrictPostTable(array(
      'type' => self::TYPE_SUBS,
      'taxonomy' => self::TAX_SUBS_CAT,
      'all_label' => 'Alle Kategorien',
      'hide_empty' => false,
      'show_count' => false,
      'orderby' => 'name',
    ));

    add_filter('manage_edit-shop_subscription_columns', array($this, 'addSubscriptionColumns'), 20);
    add_action('manage_shop_subscription_posts_custom_column', array($this, 'renderSubscriptionCustomCells'), 20);
  }

  /**
   * @param $columns
   * @return mixed
   */
  public function addSubscriptionColumns($columns)
  {
    unset($columns['trial_end_date']);
    unset($columns['orders']);
    $columns['tag-names'] = 'Kategorien';
    return $columns;
  }

  /**
   * @param string $column the rendered column
   */
  public function renderSubscriptionCustomCells($column)
  {
    if ($column == 'tag-names') {
      global $post;
      echo implode(', ', WordPress::getTermFieldList($post->ID, self::TAX_SUBS_CAT, 'name'));
    }
  }
}