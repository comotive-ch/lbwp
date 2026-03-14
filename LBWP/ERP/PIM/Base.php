<?php

namespace LBWP\ERP\PIM;

use LBWP\ERP\PIM\Base as PimBase;
use LBWP\Theme\Component\ACFBase;
use LBWP\Theme\Feature\BetterTables\BetterPostTables;
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

    $this->addTablesConfig();
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

    $this->addTypeConfig();
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
        'menu_name' => 'PIMdata',
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
   * Add config for better tables
   */
  public function addTablesConfig()
  {
    new BetterPostTables([
      'postType' => self::TYPE_SLUG,
      'defaultFields' => [
        'ID',
        'post_title',
        'post_status'
      ],
      'customLabels' => [
        'sku' => 'SKU',
        'provider-name' => 'Lieferant'
      ],
      'customColumns' => [
        'sku',
        'provider-name'
      ]
    ]);
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
    acf_add_local_field_group(array(
      'key' => 'group_649019f6c6405',
      'title' => 'Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_64822d4ee267f',
          'label' => 'Hersteller Artikelnummer',
          'name' => 'provider-sku',
          'aria-label' => '',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_64822d499263f',
          'label' => 'Eigene Artikelnummer',
          'name' => 'sku',
          'aria-label' => '',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_64833d4ee267f',
          'label' => 'Lieferant',
          'name' => 'provider-name',
          'aria-label' => '',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_64822dbfe2683',
          'label' => 'Kommentar',
          'name' => 'kommentar',
          'aria-label' => '',
          'type' => 'textarea',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'rows' => 3,
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_66323b4a0b4d9',
          'label' => 'Einstellungen',
          'name' => 'status',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'external-warehouse' => 'Nicht im eigenen Lager geführt / externer Direktlieferant',
            'auto-shop-product' => 'Automatisch Shop-Produkt synchronisieren',
          ),
          'default_value' => array(),
          'return_format' => 'value',
          'allow_custom' => 0,
          'layout' => 'vertical',
          'toggle' => 0,
          'save_custom' => 0
        ),
        array(
          'key' => 'field_66bdf47c162ae',
          'label' => 'Synchronisation Einstellungen',
          'name' => 'sync-settings',
          'aria-label' => '',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_66323b4a0b4d9',
                'operator' => '==',
                'value' => 'auto-shop-product',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_66bdf43a162ad',
              'label' => 'Info',
              'name' => '',
              'aria-label' => '',
              'type' => 'message',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'message' => 'Der Abgleich findet nur einmal am Tag statt um 23 Uhr. <a href="' . get_bloginfo('url') . '/wp-content/plugins/lbwp/views/cron/daily.php?specific=23" target="_blank">Jetzt synchronisieren</a>',
              'new_lines' => 'wpautop',
              'esc_html' => 0,
            ),
            array(
              'key' => 'field_66bdf537162af',
              'label' => 'Artikelnummer (SKU)',
              'name' => 'sku',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf563162b0',
              'label' => 'Öffentlicher Titel',
              'name' => 'public-title',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf5aa162b1',
              'label' => 'Produktbild',
              'name' => 'image-id',
              'aria-label' => '',
              'type' => 'image',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'return_format' => 'id',
              'library' => 'all',
              'min_width' => '',
              'min_height' => '',
              'min_size' => '',
              'max_width' => '',
              'max_height' => '',
              'max_size' => '',
              'mime_types' => '',
              'preview_size' => 'medium',
            ),
            array(
              'key' => 'field_66bdf5be162b2',
              'label' => 'Kurzbeschreibung',
              'name' => 'short-description',
              'aria-label' => '',
              'type' => 'wysiwyg',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'tabs' => 'all',
              'toolbar' => 'full',
              'media_upload' => 1,
              'delay' => 0,
            ),
            array(
              'key' => 'field_66bdf5d0162b3',
              'label' => 'Produktbeschreibung',
              'name' => 'description',
              'aria-label' => '',
              'type' => 'wysiwyg',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'tabs' => 'all',
              'toolbar' => 'full',
              'media_upload' => 1,
              'delay' => 0,
            ),
            array(
              'key' => 'field_66bdf5ee162b4',
              'label' => 'Kategorien',
              'name' => 'categories',
              'aria-label' => '',
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
              'add_term' => 0,
              'save_terms' => 0,
              'load_terms' => 0,
              'return_format' => 'id',
              'field_type' => 'multi_select',
              'allow_null' => 1,
              'bidirectional' => 0,
              'multiple' => 0,
              'bidirectional_target' => array(),
            ),
            array(
              'key' => 'field_66bdf798162b5',
              'label' => 'Preisdefinition',
              'name' => 'pricedeinition',
              'aria-label' => '',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'fixed-product' => 'Fixer Preis (1 zu 1 ins Produkt übernehmen)',
                'fixed' => 'Zielmarge in Fixbetrag',
                'percent' => 'Zielmarge in Prozent',
              ),
              'default_value' => false,
              'return_format' => 'value',
              'multiple' => 0,
              'allow_null' => 0,
              'ui' => 0,
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_66bdf8416a8bd',
              'label' => 'Preis',
              'name' => 'price',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'fixed-product',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '0.01',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf8c46a8be',
              'label' => 'Wunschmarge in %',
              'name' => 'percent-margin',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'percent',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '0.01',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf8f66a8bf',
              'label' => 'Runden auf Kommastellen',
              'name' => 'percent-rounding',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'percent',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => 0,
              'max' => 2,
              'placeholder' => '',
              'step' => 1,
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf9326a8c0',
              'label' => 'Zielmarge',
              'name' => 'fixed-margin',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'fixed',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '0.01',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf9ce6a8c2',
              'label' => 'Runden auf Kommastellen',
              'name' => 'fixed-rounding',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'fixed',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => 0,
              'max' => 2,
              'placeholder' => '',
              'step' => 1,
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_76323b4a0b5f1',
              'label' => 'Weitere Einstellungen',
              'name' => 'additional-settings',
              'aria-label' => '',
              'type' => 'checkbox',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'manage-stock' => 'Lagerverwaltung aktivieren',
                'allow-backorders' => 'Lieferrückstand erlauben',
                'compare-stock-booking' => 'Lagerbestand mit Buchbestand abgleichen',
              ),
              'default_value' => array(),
              'return_format' => 'value',
              'allow_custom' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => PimBase::TYPE_SLUG,
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }
}