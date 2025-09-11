<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Component\ACFBase;
use LBWP\Util\ArrayManipulation;

/**
 * Provides additional Product data tabs and features
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class ProductData extends ACFBase
{

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    add_filter('woocommerce_product_tabs', array($this, 'customizeProductTabs'), 1000);
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_6138e1829e7b8',
      'title' => 'Produkt-Tabs und weitere Inhalte',
      'fields' => array(
        array(
          'key' => 'field_6138e1d993c60',
          'label' => 'Einstellungen',
          'name' => 'tabs-settings',
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
            'alphabetical-order' => 'Tabs alphabetisch anordnen',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_6138e19793c5d',
          'label' => 'Tabs',
          'name' => 'tabs',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'collapsed' => '',
          'min' => 0,
          'max' => 0,
          'layout' => 'table',
          'button_label' => '',
          'sub_fields' => array(
            array(
              'key' => 'field_6138e1b093c5e',
              'label' => 'Tab Titel',
              'name' => 'tab-title',
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
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'maxlength' => '',
            ),
            array(
              'key' => 'field_6138e1bf93c5f',
              'label' => 'Tab Inhalt',
              'name' => 'tab-content',
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
          ),
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
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks()
  {
  }

  /**
   * Customize the product tabs
   *
   * @param array $tabs all the tabs
   * @return array the tabs
   */
  public function customizeProductTabs($tabs)
  {
    global $product;
    $pId = $product->get_id();
    $customTabs = get_field('tabs', $pId);
    $tabsSettings = get_field('tabs-settings', $pId);

    if (empty($customTabs)) {
      return $tabs;
    }

    foreach ($customTabs as $cTab) {
      $tabs[] = array(
        'title' => $cTab['tab-title'],
        'priority' => 10,
        'callback' => function () use ($cTab) {
          echo $cTab['tab-content'];
        }
      );
    }

    if (in_array('alphabetical-order', (is_array($tabsSettings) ? $tabsSettings : array()))) {
      ArrayManipulation::sortByStringField($tabs, 'title');
    }

    return $tabs;
  }
} 