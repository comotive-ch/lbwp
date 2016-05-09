<?php

namespace LBWP\Module\PostType\Reference;

use LBWP\Helper\Metabox;

/**
 * Post type to create and display web related references
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
	/**
	 * Call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
	}

	/**
	 * Registers all the actions and filters and removes some.
	 */
	public function initialize()
  {
		if (is_admin()) {
      // Backend only filters
      add_action('admin_init', array($this, 'addMetaboxes'));
		} else {
      // Frontend only filters
      add_shortcode('lbwp:reference', array($this, 'displayReferences'));
    }

    // Global filters
    add_action('init', array($this, 'registerPostType'));
    add_action('init', array($this, 'registerImageSize'));
	}

  /**
   * Display the references
   */
  public function displayReferences()
  {
    $references = get_posts(array(
      'posts_per_page' => -1,
      'post_type' => 'reference',
      'orderby' => 'post_date'
    ));

    // Add the metadata
    foreach ($references as $key => $reference) {
      $references[$key]->online_since = get_post_meta($reference->ID, 'online_since', true);
      $references[$key]->used_software = get_post_meta($reference->ID, 'used_software', true);
      $references[$key]->project_url = get_post_meta($reference->ID, 'project_url', true);
      $references[$key]->project_desc = wpautop(get_post_meta($reference->ID, 'project_desc', true));
    }

    return apply_filters('reference_shortcode', '<p>Dieses Modul wird von Ihrem Theme nicht unterstützt.</p>', $references);
  }

  /**
   * The metaboxes
   */
  public function addMetaboxes()
  {
    // Create the helper and the metabox
    $boxId = 'reference-data';
    $metaboxHelper = Metabox::get('reference');
    $metaboxHelper->addMetabox($boxId, 'Informationen zur Referenz');

    $metaboxHelper->addParagraph($boxId, 'Screenshot bitte als Beitragsbild definieren.');
    $metaboxHelper->addInputText('online_since', $boxId, 'Online seit: ');
    $metaboxHelper->addInputText('used_software', $boxId, 'Software: ');
    $metaboxHelper->addInputText('project_url', $boxId, 'Projekt URL: ');
    $metaboxHelper->addEditor('project_desc', $boxId, 'Projektbeschreibung: ', 8);
  }

  /**
   * Registers the image size to use in frontend
   */
  public function registerImageSize()
  {
    add_image_size(
      'reference-image',
      $this->config['Reference_Posttype:ImageWidth'],
      $this->config['Reference_Posttype:ImageHeight'],
      true
    );
  }

  /**
   * Registers the posttype
   */
  public function registerPostType()
  {
    // The "anbieter" post type
    register_post_type(
      'reference', array(
        'label' => 'Referenzen',
        'description' => 'Referenzen',
        'public' => true,
        'show_ui' => true,
        'capability_type' => 'post',
        'show_in_nav_menus' => true,
        'show_in_admin_bar' => false,
        'hierarchical' => false,
        'has_archive' => false,
        'menu_icon' => 'dashicons-portfolio',
        'rewrite' => array(
          'slug' => 'referenzen',
          'with_front' => false
        ),
        'supports' => array(
          'title',
          'thumbnail'
        ),
        'labels' => array(
          'name' => 'Referenzen',
          'singular_name' => 'Referenz',
          'menu_name' => 'Referenzen',
          'all_items' => 'Referenzen',
          'add_new' => 'Erstellen',
          'add_new_item' => 'Neue Referenz',
          'edit_item' => 'Referenz bearbeiten',
          'new_item' => 'Neue Referenz',
          'view_item' => 'Referenz anzeigen',
          'search_items' => 'Suche',
          'not_found' => 'Nicht gefunden',
          'not_found_in_trash' => 'Nicht gefunden'
        )
      )
    );
  }
}