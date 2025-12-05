<?php

namespace LBWP\ERP\Inventory;

use LBWP\Core;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\WooCommerce\Util;
use LBWP\Helper\ZipDistance;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Theme\Component\ACFBase;
use LBWP\ERP\PIM\Base as PimBase;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provide inventory functions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class SimpleBookKeep extends ACFBase
{

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    $this->eventuallyExportDirectBookings();
  }

  /**
   * @return void
   */
  protected function eventuallyExportDirectBookings()
  {
    if (isset($_GET['page']) && isset($_GET['export']) && $_GET['page'] == 'aboon-direct-book') {
      $data = array(
        array('Datum', 'Buchungstext', 'Soll', 'Haben', 'Betrag', 'MwstCode')
      );
      $table = get_field('bookings', 'option');
      foreach ($table as $row) {
        $data[] = array(
          $row['date'], $row['text'], $row['soll'], $row['haben'], $row['value'], $row['taxcode'],
        );
      }

      Csv::downloadExcel($data, 'direct-bookings');
    }
  }

  /**
   * Add a sub page below our custom type for inventory
   * @return void
   */
  public function acfInit()
  {
    parent::acfInit();

    acf_add_options_sub_page(array(
      'page_title' => 'Buchhaltung',
      'menu_title' => 'Buchhaltung',
      'capability' => 'administrator',
      'menu_slug' => 'aboon-direct-book',
      'parent_slug' => 'edit.php?post_type=' . PimBase::TYPE_SLUG
    ));
  }


  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_659fa4e4bad9d',
      'title' => 'Direkte Buchungen',
      'fields' => array(
        array(
          'key' => 'field_659fa4e5f7194',
          'label' => 'Buchungen',
          'name' => 'bookings',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 1,
          'rows_per_page' => 10,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'sub_fields' => array(
            array(
              'key' => 'field_659fa525f7196',
              'label' => 'Datum',
              'name' => 'date',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa53af7197',
              'label' => 'Buchungstext',
              'name' => 'text',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa545f7198',
              'label' => 'Soll',
              'name' => 'soll',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa553f7199',
              'label' => 'Haben',
              'name' => 'haben',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa563f719a',
              'label' => 'Betrag',
              'name' => 'value',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa572f719b',
              'label' => 'MwStCode',
              'name' => 'taxcode',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
          ),
        ),
        array(
          'key' => 'field_659fa50ff7195',
          'label' => 'Export',
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
          'message' => '<a href="/wp-admin/edit.php?post_type=' . PimBase::TYPE_SLUG . '&page=aboon-direct-book&export">CSV Export starten</a>',
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
        array(
          'key' => 'field_64811dcee2684',
          'label' => 'Notizen',
          'name' => 'direct-booking-notes',
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
          'rows' => 14,
          'placeholder' => '',
          'new_lines' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-direct-book',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  public function blocks()
  {

  }
}