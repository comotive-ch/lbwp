<?php

namespace LBWP\Module\Forms\Component\ActionBackend;

use LBWP\Module\Forms\Action\DataTable as DataTableAction;
use LBWP\Module\Forms\Component\Base;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provides a backend for form data tables
 * @package LBWP\Module\Forms\Component\ActionBackend
 * @author Michael Sebel <michael@comotive.ch>
 */
class DataTable extends Base
{
  /**
   * @var string the list option key
   */
  const LIST_OPTION = 'LbpwForm_DataTables';
  /**
   * @var string the table datacontainer option prefix. Will be suffixed with the form ID
   */
  const TABLE_OPTION_PREFIX = 'LbwpForm_DataTable_';

  /**
   * Called on init(10), registers the menu, if given
   */
  public function initialize()
  {
    add_action('admin_menu', array($this, 'addTableMenus'));
  }

  /**
   * Adding table menus if there are
   */
  public function addTableMenus()
  {
    $list = $this->getTableList();

    // Create a menu only if there are tables to show
    if (count($list) > 0) {
      // Add the main menu
      add_menu_page('Datenspeicher', 'Datenspeicher', 'edit_pages', 'data-tables', array($this, 'displayTable'), 'dashicons-index-card', 46);

      // Add the submenus
      foreach ($list as $id => $name) {
        add_submenu_page('data-tables', $name, $name, 'edit_pages', 'data-table-' . $id, array($this, 'displayTable'));
      }

      // Remove the first submenu, as usual
      global $submenu;
      unset($submenu['data-tables'][0]);
    }
  }

  /**
   * Displays a table form slug or by default the first found table
   */
  public function displayTable()
  {
    $formId = intval(str_replace('data-table-', '', $_GET['page']));
    $dataDisplay = new DataDisplay($this);
    echo $dataDisplay->getHtml($formId);
  }

  /**
   * @return array the list or an empty list
   */
  protected function getTableList()
  {
    return ArrayManipulation::forceArray(get_option(self::LIST_OPTION));
  }

  /**
   * @param array $list list of tables (id:name)
   */
  protected function saveTableList($list)
  {
    update_option(self::LIST_OPTION, $list);
  }

  /**
   * @param int $formId the form id
   * @param string $name the table name
   */
  public function updateTableList($formId, $name)
  {
    // Create or update the list entry
    $list = $this->getTableList();
    $list[$formId] = $name;
    $this->saveTableList($list);

    // Make sure the table is initialized
    $this->initDataTable($formId, $name);
  }

  /**
   * @param int $formId the form id
   * @param array $data data array from form
   * @param int $max number of entries
   * @return bool true, if the data entry has been added
   */
  public function addTableEntry($formId, $data, $max = 0)
  {
    // Get the current table
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);

    // If there are to many datasets, return an error
    if ($max > 0 && count($table['data']) >= $max) {
      return false;
    }

    // The entry can be added
    $row = array();
    foreach ($data as $item) {
      $cellKey = Strings::forceSlugString($item['name']);
      $row[$cellKey] = $item['value'];
    }

    // And save back to the table
    $table['data'][] = $row;
    WordPress::updateJsonOption($key, $table);

    return true;
  }

  /**
   * @param int $formId form to check
   * @param int $max the maximum number of data rows
   * @return bool true, if maximum is reached
   */
  public function maximumReached($formId, $max)
  {
    $table = $this->getTable($formId);
    if ($max > 0 && count($table['data']) >= $max) {
      return true;
    }

    return false;
  }

  /**
   * @param int $formId the form whose table to get
   * @return array the table data array
   */
  public function getTable($formId)
  {
    $key = self::TABLE_OPTION_PREFIX . $formId;
    return WordPress::getJsonOption($key);
  }

  /**
   * Flush the table's data
   * @param int $formId
   */
  public function flushTable($formId)
  {
    $table = $this->getTable($formId);
    $table['data'] = array();
    $key = self::TABLE_OPTION_PREFIX . $formId;
    WordPress::updateJsonOption($key, $table);
  }

  /**
   * Flush the table
   * @param int $formId
   */
  public function deleteTable($formId)
  {
    // Remove from list
    $list = $this->getTableList();
    unset($list[$formId]);
    $this->saveTableList($list);

    // Gracefull delete the option
    delete_option(self::TABLE_OPTION_PREFIX . $formId);
  }

  /**
   * @param int $formId the form id
   * @param string $name the table name
   */
  protected function initDataTable($formId, $name)
  {
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);

    // Create empty dataset, if no data is returned
    if ($table == false) {
      WordPress::updateJsonOption($key, array(
        'id' => $formId,
        'name' => $name,
        'data' => array()
      ));
    }
  }
} 