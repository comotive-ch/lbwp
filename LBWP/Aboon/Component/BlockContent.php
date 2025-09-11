<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\WordPress;

/**
 * Provides a custom type to add block content to products
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class BlockContent extends ACFBase
{
  /**
   * @var string
   */
  const TYPE_SLUG_BC = 'lbwp-product-content';

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    $this->registerCustomType();

    if (is_admin()) {
      $this->addBidirectionalRelation('rel-product-to-content', 'rel-content-to-product');
      $this->saveBidirectionalRelations();

      /*add_filter('acf/update_value', function ($value, $postId, $field){
        if($field['name'] === 'rel-content-to-category') {
          $fieldName = 'rel-content-to-category';
          $metaKey = 'rel-category-to-content';

          foreach ($value as $id) {
            $oldValues = get_term_meta($id, $metaKey, true);
            $delete = $oldValues;

            if (is_array($oldValues)) {
              $setValues = array_unique(array_merge($oldValues, $postId));
            } else {
              $setValues = array($postId);
            }

            update_term_meta($id, $metaKey, $setValues);
          }

        }
        return $value;
      }, 10, 3);*/
    }
  }

  /**
   * Registers custom types and taxonomies
   */
  protected function registerCustomType()
  {
    WordPress::registerType(self::TYPE_SLUG_BC, 'Inhalt', 'Inhalte', array(
      'show_in_menu' => 'edit.php?post_type=product',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'rewrite' => false,
      'show_in_rest' => true,
      'supports' => array(
        'title',
        'editor',
        'thumbnail'
      )
    ), 'n');
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_61ea94247f8c3',
      'title' => 'Produktbeschreibung überschreiben',
      'fields' => array(
        array(
          'key' => 'field_61ea947780556',
          'label' => 'Verknüpfte Inhalte',
          'name' => 'rel-product-to-content',
          'type' => 'relationship',
          'instructions' => 'Sobald Inhalte verknüpft werden, wird die Produktbeschreibung damit ersetzt und nicht mehr angezeigt.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'lbwp-product-content',
          ),
          'taxonomy' => '',
          'filters' => array(
            0 => 'search',
          ),
          'elements' => '',
          'min' => '',
          'max' => '',
          'return_format' => 'id',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'product',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));

    acf_add_local_field_group(array(
      'key' => 'group_61ea93a9e25b4',
      'title' => 'Verknüpfungen',
      'fields' => array(
        array(
          'key' => 'field_61ea93c12f00f',
          'label' => 'Zugewiesene Produkte',
          'name' => 'rel-content-to-product',
          'type' => 'relationship',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'product',
          ),
          'taxonomy' => '',
          'filters' => array(
            0 => 'search',
          ),
          'elements' => '',
          'min' => '',
          'max' => '',
          'return_format' => 'id',
        ),
        array(
          'key' => 'field_62cbc402f32b6',
          'label' => 'Zugewiesene Kategorie',
          'name' => 'rel-content-to-category',
          'type' => 'taxonomy',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'taxonomy' => 'product_cat',
          'field_type' => 'multi_select',
          'allow_null' => 0,
          'add_term' => 1,
          'save_terms' => 0,
          'load_terms' => 0,
          'return_format' => 'id',
          'multiple' => 0,
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'lbwp-product-content',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}
} 