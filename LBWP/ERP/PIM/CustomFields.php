<?php

namespace LBWP\ERP\PIM;

use LBWP\ERP\PIM\Base as PimBase;
use LBWP\Theme\Component\ACFBase;

/**
 * Allow Customer to create his own custom field boxes and fields
 * @package LBWP\ERP\PIM
 * @author Michael Sebel <michael@comotive.ch>
 */
class CustomFields extends ACFBase
{
  const OPTIONS_PAGE_SLUG = 'pim-custom-fields';

  /**
   * ACF field type mapping from our simplified types to ACF types
   */
  const TYPE_MAP = array(
    'text' => 'text',
    'number' => 'number',
    'date' => 'date_picker',
    'media' => 'image',
    'bool' => 'true_false',
    'wysiwyg' => 'wysiwyg',
  );

  /**
   * Register the options page under the PIM menu
   */
  public function acfInit()
  {
    parent::acfInit();

    acf_add_options_sub_page(array(
      'page_title' => 'Eigene Felder',
      'menu_title' => 'Eigene Felder',
      'capability' => 'administrator',
      'menu_slug' => self::OPTIONS_PAGE_SLUG,
      'parent_slug' => 'edit.php?post_type=' . PimBase::TYPE_SLUG,
    ));
  }

  /**
   * No blocks needed here
   */
  public function blocks()
  {
  }

  /**
   * Register the settings field groups and the dynamic product field groups
   */
  public function fields()
  {
    $this->registerSettingsFieldGroup();
    $this->registerRelationshipsSettingsFieldGroup();
    $this->registerDynamicFieldGroups();
    $this->registerDynamicRelationshipFieldGroup();
  }

  /**
   * Register the repeater-based settings UI on the options page
   */
  protected function registerSettingsFieldGroup()
  {
    acf_add_local_field_group(array(
      'key' => 'group_pim_custom_fields_settings',
      'title' => 'Eigene Felder konfigurieren',
      'fields' => array(
        array(
          'key' => 'field_pim_cf_sections',
          'label' => 'Sektionen',
          'name' => 'pim_cf_sections',
          'type' => 'repeater',
          'layout' => 'block',
          'button_label' => 'Sektion hinzufügen',
          'sub_fields' => array(
            array(
              'key' => 'field_pim_cf_section_name',
              'label' => 'Sektionsname',
              'name' => 'section_name',
              'type' => 'text',
              'required' => 1,
            ),
            array(
              'key' => 'field_pim_cf_section_fields',
              'label' => 'Felder',
              'name' => 'section_fields',
              'type' => 'repeater',
              'layout' => 'table',
              'button_label' => 'Feld hinzufügen',
              'sub_fields' => array(
                array(
                  'key' => 'field_pim_cf_field_name',
                  'label' => 'Name',
                  'name' => 'field_name',
                  'type' => 'text',
                  'required' => 1,
                ),
                array(
                  'key' => 'field_pim_cf_field_slug',
                  'label' => 'Slug',
                  'name' => 'field_slug',
                  'type' => 'text',
                  'required' => 1,
                ),
                array(
                  'key' => 'field_pim_cf_field_type',
                  'label' => 'Typ',
                  'name' => 'field_type',
                  'type' => 'select',
                  'required' => 1,
                  'choices' => array(
                    'text' => 'Text',
                    'number' => 'Zahl',
                    'date' => 'Datum',
                    'media' => 'Media',
                    'bool' => 'Ja/Nein',
                    'wysiwyg' => 'WYSIWYG',
                  ),
                  'default_value' => 'text',
                ),
              ),
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => self::OPTIONS_PAGE_SLUG,
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'active' => true,
    ));
  }

  /**
   * Register the relationships repeater on the options page
   */
  protected function registerRelationshipsSettingsFieldGroup()
  {
    acf_add_local_field_group(array(
      'key' => 'group_pim_custom_relationships_settings',
      'title' => 'Verknüpfungen konfigurieren',
      'fields' => array(
        array(
          'key' => 'field_pim_cr_relations',
          'label' => 'Verknüpfungen',
          'name' => 'pim_cr_relations',
          'type' => 'repeater',
          'layout' => 'table',
          'button_label' => 'Verknüpfung hinzufügen',
          'sub_fields' => array(
            array(
              'key' => 'field_pim_cr_rel_name',
              'label' => 'Name',
              'name' => 'rel_name',
              'type' => 'text',
              'required' => 1,
              'instructions' => 'z.B. «Ähnliche Produkte» oder «Grössenvarianten»',
            ),
            array(
              'key' => 'field_pim_cr_rel_slug',
              'label' => 'Slug',
              'name' => 'rel_slug',
              'type' => 'text',
              'required' => 1,
            ),
            array(
              'key' => 'field_pim_cr_rel_post_type',
              'label' => 'Post Type',
              'name' => 'rel_post_type',
              'type' => 'select',
              'required' => 0,
              'instructions' => 'Standard: Produkt-zu-Produkt',
              'choices' => $this->getPostTypeChoices(),
              'default_value' => PimBase::TYPE_SLUG,
              'allow_null' => 0,
            ),
            array(
              'key' => 'field_pim_cr_rel_multiple',
              'label' => 'Mehrfach',
              'name' => 'rel_multiple',
              'type' => 'true_false',
              'ui' => 1,
              'default_value' => 1,
              'instructions' => 'Mehrere Einträge erlauben',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => self::OPTIONS_PAGE_SLUG,
          ),
        ),
      ),
      'menu_order' => 1,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'active' => true,
    ));
  }

  /**
   * Get all registered post types as slug => label choices for the select field
   * @return array
   */
  protected function getPostTypeChoices()
  {
    $choices = array();
    $postTypes = get_post_types(array('show_ui' => true), 'objects');
    foreach ($postTypes as $postType) {
      $choices[$postType->name] = $postType->labels->singular_name . ' (' . $postType->name . ')';
    }
    return $choices;
  }

  /**
   * Read the saved section config and dynamically register ACF field groups on the product post type
   */
  protected function registerDynamicFieldGroups()
  {
    $sectionCount = intval(get_option('options_pim_cf_sections'));

    if ($sectionCount === 0) {
      return;
    }

    for ($i = 0; $i < $sectionCount; $i++) {
      $sectionName = get_option('options_pim_cf_sections_' . $i . '_section_name');
      if (empty($sectionName)) {
        continue;
      }

      $fieldCount = intval(get_option('options_pim_cf_sections_' . $i . '_section_fields'));
      if ($fieldCount === 0) {
        continue;
      }

      $fields = array();
      for ($j = 0; $j < $fieldCount; $j++) {
        $prefix = 'options_pim_cf_sections_' . $i . '_section_fields_' . $j . '_';
        $fieldName = get_option($prefix . 'field_name');
        $fieldSlug = get_option($prefix . 'field_slug');
        $fieldType = get_option($prefix . 'field_type');

        if (empty($fieldName) || empty($fieldSlug) || empty($fieldType)) {
          continue;
        }

        $acfType = isset(self::TYPE_MAP[$fieldType]) ? self::TYPE_MAP[$fieldType] : 'text';
        $fieldConfig = array(
          'key' => 'field_pim_dyn_' . $i . '_' . $j,
          'label' => $fieldName,
          'name' => $fieldSlug,
          'type' => $acfType,
        );

        // Type-specific configuration
        if ($acfType === 'date_picker') {
          $fieldConfig['display_format'] = 'd.m.Y';
          $fieldConfig['return_format'] = 'Y-m-d';
        } elseif ($acfType === 'image') {
          $fieldConfig['return_format'] = 'id';
          $fieldConfig['preview_size'] = 'thumbnail';
        } elseif ($acfType === 'true_false') {
          $fieldConfig['ui'] = 1;
        } elseif ($acfType === 'wysiwyg') {
          $fieldConfig['tabs'] = 'all';
          $fieldConfig['toolbar'] = 'full';
          $fieldConfig['media_upload'] = 1;
          $fieldConfig['delay'] = 0;
        }

        $fields[] = $fieldConfig;
      }

      if (empty($fields)) {
        continue;
      }

      acf_add_local_field_group(array(
        'key' => 'group_pim_dyn_section_' . $i,
        'title' => $sectionName,
        'fields' => $fields,
        'location' => array(
          array(
            array(
              'param' => 'post_type',
              'operator' => '==',
              'value' => PimBase::TYPE_SLUG,
            ),
          ),
        ),
        'menu_order' => 10 + $i,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'left',
        'active' => true,
      ));
    }
  }

  /**
   * Read the saved relationship config and register a single "Verknüpfungen" field group on the product post type
   */
  protected function registerDynamicRelationshipFieldGroup()
  {
    $relationCount = intval(get_option('options_pim_cr_relations'));

    if ($relationCount === 0) {
      return;
    }

    $fields = array();
    for ($i = 0; $i < $relationCount; $i++) {
      $prefix = 'options_pim_cr_relations_' . $i . '_';
      $relName = get_option($prefix . 'rel_name');
      $relSlug = get_option($prefix . 'rel_slug');
      $relPostType = get_option($prefix . 'rel_post_type');
      $relMultiple = intval(get_option($prefix . 'rel_multiple'));

      if (empty($relName) || empty($relSlug)) {
        continue;
      }

      // Default to lbwp-pid if no custom post type is set
      $targetPostType = !empty($relPostType) ? $relPostType : PimBase::TYPE_SLUG;

      $fields[] = array(
        'key' => 'field_pim_rel_' . $i,
        'label' => $relName,
        'name' => $relSlug,
        'type' => 'relationship',
        'post_type' => array($targetPostType),
        'filters' => array('search'),
        'return_format' => 'id',
        'min' => 0,
        'max' => $relMultiple ? 0 : 1,
      );
    }

    if (empty($fields)) {
      return;
    }

    acf_add_local_field_group(array(
      'key' => 'group_pim_dyn_relationships',
      'title' => 'Verknüpfungen',
      'fields' => $fields,
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => PimBase::TYPE_SLUG,
          ),
        ),
      ),
      'menu_order' => 100,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'active' => true,
    ));
  }
}
