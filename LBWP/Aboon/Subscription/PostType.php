<?php

namespace LBWP\Aboon\Subscription;

use LBWP\Util\WordPress;

/**
 * Registers the non-public "Abonnemente" custom post type used to track Payrexx subscriptions.
 * @package LBWP\Aboon\Subscription
 * @author Michael Sebel <michael@comotive.ch>
 */
class PostType
{
  /**
   * @var string the post type slug
   */
  public const string SLUG = 'lbwp-subscription';

  /**
   * Registers the post type as a submenu under the WooCommerce admin menu.
   * @return void
   */
  public function register(): void
  {
    WordPress::registerType(self::SLUG, 'Abonnement', 'Abonnemente', [
      'public' => false,
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'has_archive' => false,
      'show_ui' => true,
      'show_in_menu' => 'woocommerce',
      'show_in_nav_menus' => false,
      'show_in_rest' => false,
      'rewrite' => false,
      'query_var' => false,
      'supports' => ['title'],
      'capability_type' => 'post',
    ]);
  }
}
