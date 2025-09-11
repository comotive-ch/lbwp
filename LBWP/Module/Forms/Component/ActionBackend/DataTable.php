<?php

namespace LBWP\Module\Forms\Component\ActionBackend;

use LBWP\Core;
use LBWP\Module\Events\Component\EventType;
use LBWP\Module\Forms\Action\DataTable as DataTableAction;
use LBWP\Module\Forms\Component\Base;
use LBWP\Module\Forms\Action\Base as BaseAction;
use LBWP\Module\Forms\Core as FormCore;
use LBWP\Module\Forms\Item\HtmlItem;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\LbwpData;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Util\Date;

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
   * @var string the last set tsid
   */
  public static $lastTsid = '';

  /**
   * Called on init(10), registers the menu, if given
   */
  public function initialize()
  {
    add_action('admin_menu', array($this, 'addTablesMainMenu'));
    add_action('wp_ajax_deleteDataTableRow', array($this, 'deleteDataTableRow'));
    add_action('wp_ajax_editDataTableRow', array($this, 'editDataTableRow'));
    add_filter('ComotiveNL_dynamic_target_get_list', array($this, 'addDynamicTargets'));
    add_filter('ComotiveNL_dynamic_target_field_mapping', array($this, 'getDynamicTargetFieldMap'));
    add_filter('ComotiveNL_dynamic_target_get_list_data', array($this, 'getDynamicTargetData'), 10, 4);
    add_filter('wp_privacy_personal_data_erasers', array($this, 'registerPersonalDataEraser'));
    add_filter('wp_privacy_personal_data_exporters', array($this, 'registerPersonalDataExporter'));
  }

  /**
   * Add the tables main menu
   */
  public function addTablesMainMenu()
  {
    add_menu_page('Datenspeicher', 'Datenspeicher', 'edit_pages', 'data-tables', array($this, 'displayTables'), 'dashicons-index-card', 46);
  }

  /**
   * @param array $data
   * @param array $table
   * @return array the fixed table, if fixes were needed
   */
  public function handleFormNameChanges($data, $table)
  {
    $hasDataTable = false;
    foreach ($data['Actions'] as $key => $action) {
      if ($action['key'] == 'datatable') {
        $hasDataTable = true;
        break;
      }
    }

    // Continue to convert, if there is a data table
    if ($hasDataTable && is_array($table['fields'])) {
      // From the items and the fields in the table, get a before/after map
      $given = $changes = array();
      foreach ($data['Items'] as $item) {
        $given[] = Strings::forceSlugString($item['params'][0]['value']);
      }
      // Get same amount of first fields in list of table to match
      $fields = array_slice(array_values($table['fields']), 0, count($given));
      $fields = array_map(array('LBWP\Util\Strings', 'forceSlugString'), $fields);
      // Get a difference map of changes
      for ($i = 0; $i < count($given); $i++) {
        if ($given[$i] != $fields[$i]) {
          $changes[$fields[$i]] = $given[$i];
        }
      }

      // Reassign numbere indices if the "old" datatable still works with that
      $numberedIndices = false;
      foreach ($changes as $before => $after) {
        if (is_int($before) && is_int($after)) {
          $numberedIndices = true;
          break;
        }
      }

      if ($numberedIndices) {
        foreach ($changes as $before => $after) {
          foreach ($table['data'] as $id => $row) {
            $table['data'][$id][$after] = $row[$before];
            unset($table['data'][$id][$before]);
          }
        }
      }
    }

    return $table;
  }

  /**
   * Display a list of tables
   */
  public function displayTables()
  {
    if (isset($_GET['table']) && intval($_GET['table']) > 0) {
      $this->displayTable();
    } else {
      $this->displayTableOverview();
    }
  }

  /**
   * Displays the table overview list
   */
  protected function displayTableOverview()
  {
    $hasEvents = Core::hasFeature('PublicModules', 'Events');
    $baseUrl = get_admin_url() . 'admin.php?page=data-tables&dss=' . $_GET['dss'];
    if ($hasEvents) {
      $eventHandler = FormCore::getInstance()->getFormHandler();
    }

    $tableMetaInfo = '
      <tr>
        <th><a href="' . $baseUrl . '&order=title">' . __('Title') . '</a></th>
        ' . ($hasEvents ? '<th>' . __('Event', 'lbwp') . '</th>' : '') . '
        <th style="width:10%">' . __('Einträge', 'lbwp') . '</th>
        <th style="width:20%"><a href="' . $baseUrl . '&order=change">' . __('Letzte Änderung', 'lbwp') . '</th>
        <th style="width:10%"><a href="' . $baseUrl . '&order=date">' . __('Erstellt am', 'lbwp') . '</a></th>
      </tr>
    ';

    echo '
      <div class="wrap">
        <h2>' . __('Alle Datenspeicher', 'lbwp') . '</h2>
        <form id="datatables-filter" method="get">
          <input type="hidden" name="order" value="' . $_GET['order'] . '" />
          <input type="hidden" name="page" value="' . $_GET['page'] . '" />
          <p class="search-box">
            <label class="screen-reader-text" for="table-search-input">Datenspeicher suchen:</label>
            <input type="search" id="table-search" name="dss" value="' . $_GET['dss'] . '">
            <input type="submit" id="search-submit" class="button" value="Datenspeicher suchen">
          </p>
          <table class="wp-list-table widefat fixed striped posts float-left-top-margin">
            <thead>' . $tableMetaInfo . '</thead>
            <tbody id="the-list">
    ';

    $list = apply_filters('lbwp_data_table_list_before_display', $this->getTableList());
    // Filter if there is a term
    if (strlen($_GET['dss']) > 0) {
      $term = strtolower($_GET['dss']);
      $list = array_filter($list, function ($name) use ($term) {
        return stristr(mb_strtolower($name), $term) !== false;
      });
    }

    // Order by field
    if (!isset($_GET['order']) || $_GET['order'] == 'date' || $_GET['order'] == '') {
      $list = array_reverse($list, true);
    } else if ($_GET['order'] == 'title') {
      natcasesort($list);
    } else if ($_GET['order'] == 'change') {
      uksort($list, function ($table1, $table2) {
        $compare1 = intval($this->getTable($table1)['changed']);
        $compare2 = intval($this->getTable($table2)['changed']);
        return ($compare1 < $compare2) ? 1 : -1;
      });
    }

    // Display the list if there is data
    if (count($list) > 0) {
      // Add table rows
      foreach ($list as $id => $name) {
        $form = get_post($id);
        if ($form->post_status == 'publish') {
          // Fallback to form name if data table has no name
          if (strlen($name) == 0) {
            $name = $form->post_title;
          }
          // Calculate some data for displaying
          $timestamp = strtotime($form->post_date);
          $table = $this->getTable($form->ID);
          $entries = (is_array($table['data'])) ? count($table['data']) : 0;
          $eventInfo = 'Nicht verknüpft';
          if ($hasEvents) {
            // Get the actual action config, from form id to have an eventual event
            $action = $eventHandler->getActionsOfType($id, 'DataTable', true);
            $eventId = $this->getSaveEventId($action);
            if ($eventId > 0) {
              $eventInfo = '<a href="/wp-admin/post.php?post=' . $eventId . '&action=edit">' . get_post($eventId)->post_title . '</a>';
              $startTimestamp = get_post_meta($eventId, 'event-start', true);
              if ($startTimestamp > 0) {
                $eventInfo .= ', ' . Date::getTime(Date::EU_DATE, $startTimestamp);
              }
            }
          }
          // Show the data row
          echo '
            <tr>
              <td><strong><a href="' . $baseUrl . '&table=' . $id . '">' . $name . '</a></strong></td>
              ' . ($hasEvents ? '<td>' . $eventInfo . '</td>' : '') . '
              <td>' . $entries . '</td>
              <td>' . (($table['changed'] == 0) ? '-' : date(Date::EU_DATETIME, $table['changed'])) . '</td>
              <td><abbr title="' . date(Date::EU_DATETIME, $timestamp) . '">' . date(Date::EU_DATE, $timestamp) . '</abbr></td>
            </tr>
          ';
        }
      }
    } else {
      echo '
        <tr>
          <td colspan="3"><strong>' . __('Es wurde bisher kein Datenspeicher erstellt.', 'lbwp') . '</strong></td>
        </tr>
      ';
    }

    // Close table body, table and wrapper
    echo '
      <tfoot>' . $tableMetaInfo . '</tfoot>
      </tbody></table></form></div>
    ';
  }

  /**
   * Displays a table form slug or by default the first found table
   */
  public function displayTable()
  {
    $formId = intval($_GET['table']);
    $dataDisplay = new DataDisplay($this);
    echo $dataDisplay->getHtml($formId);
  }

  /**
   * @return array the list or an empty list
   */
  public function getTableList()
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
   * @param DataTableAction $action the data table instance
   * @return bool true, if the data entry has been added
   */
  public function addTableEntry($formId, $data, $action)
  {
    // Get the current table
    $tsid = uniqid('', false);
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);
    $eventId = ($action !== null) ? $action->get('event_id') : 0;
    $editTsId = $_POST['editingTsId'];

    // If there are to many datasets, return an error
    if ($this->maximumReached($formId, $action, $data)) {
      return false;
    }

    // The entry can be newly added
    if ($this->validateRowId($table['data'], $editTsId)) {
      $this->editRow($key, $data, $editTsId, $action, $eventId);
    } else {
      $this->addNewRow($key, $data, $tsid, $action, $eventId);
    }

    return true;
  }

  /**
   * @param string $tableKey the table key
   * @param array $data the form data
   * @param string $tsid the storage row id, to be used
   * @param DataTableAction $action the action object
   * @param int $eventId eventual matched event
   */
  protected function addNewRow($tableKey, $data, $tsid, $action, $eventId = 0)
  {
    $row = array();
    $counter = 0;
    foreach ($data as $item) {
      if (isset($item['valueArray'])) {
        // Have an own column for each selected value in the array
        foreach ($item['valueArray'] as $value) {
          $row[$value['key']] = $value['value'];
        }
      } else {
        // Have a single value to be added to the row
        $cellKey = Strings::forceSlugString($item['name']);
        // If storage id cell, update the id
        if ($cellKey == 'tsid') {
          $item['value'] = $tsid;
          self::$lastTsid = $tsid;
        }
        if (!isset($row[$cellKey])) {
          $row[$cellKey] = $item['value'];
        } else {
          $row[$cellKey . '_' . (++$counter)] = $item['value'];
        }
      }
    }

    // Add the new row to the table
    $table = WordPress::getJsonOption($tableKey);
    $table['data'][] = $row;
    $table['changed'] = current_time('timestamp');
    WordPress::updateJsonOption($tableKey, $table);

    // Add event subscription info, if needed
    if ($eventId > 0) {
      $this->addEventSubscriberInfo($eventId, $data, $action, $tsid);
    }
  }

  /**
   * @param string $tableKey the table key
   * @param array $data the form data
   * @param string $tsid the storage row id, to be used
   * @param DataTableAction $action the action object
   * @param int $eventId eventual matched event
   */
  protected function editRow($tableKey, $data, $tsid, $action, $eventId = 0)
  {
    // Set the last tsid for an eventual notification
    self::$lastTsid = $tsid;
    $table = WordPress::getJsonOption($tableKey);
    // Get the existing row and its internal index
    foreach ($table['data'] as $index => $row) {
      if (isset($row['tsid']) && $row['tsid'] == $tsid) {
        // Break, so we have the current values in $index and $row
        break;
      }
    }

    // Reset to an empty row, filling it with new data (that way, removed fields are actually removed)
    $row = array();
    $counter = 0;
    // Override data in that row, except for the tsid
    foreach ($data as $item) {
      if (isset($item['valueArray'])) {
        // Have an own column for each selected value in the array
        foreach ($item['valueArray'] as $value) {
          $row[$value['key']] = $value['value'];
        }
      } else {
        // Have a single value changed in the row
        $cellKey = Strings::forceSlugString($item['name']);
        if ($cellKey != 'tsid') {
          if (!isset($row[$cellKey])) {
            $row[$cellKey] = $item['value'];
          } else {
            $row[$cellKey . '_' . (++$counter)] = $item['value'];
          }
        } else {
          $row[$cellKey] = $tsid;
        }
      }
    }

    // Override the row in our referenced table
    $table['data'][$index] = $row;
    $table['changed'] = current_time('timestamp');
    WordPress::updateJsonOption($tableKey, $table);

    // Remove existing subscriber information, if available, add new one
    if ($eventId > 0) {
      $this->removeSubscriberInfo($eventId, $row);
      $this->addEventSubscriberInfo($eventId, $data, $action, $tsid);
    }
  }

  /**
   * @param array $data the data table array
   * @param string $tsid the storage row id
   * @return bool if the row exists in the table
   */
  protected function validateRowId($data, $tsid)
  {
    // First check if an id is even given
    if (empty($tsid) || strlen($tsid) == 0) {
      return false;
    }

    // If there is, search for the row and eventually return true, if matched
    foreach ($data as $row) {
      if (isset($row['tsid']) && $row['tsid'] == $tsid) {
        return true;
      }
    }

    return false;
  }

  /**
   * Adds subscriber meta data as far as in configuration possible
   * @param int $eventId the event id
   * @param array $data the form data raw
   * @param DataTableAction $action the data table action
   * @param string $tsid the table storage row id
   */
  protected function addEventSubscriberInfo($eventId, $data, $action, $tsid)
  {
    // Get the subscriber id to identify an existing subscriber info
    $subscriberId = uniqid('subn', true);
    $subscriberField = $action->get('emailid_field');
    if (strlen($subscriberField) > 0) {
      $subscriberId = $action->getFieldContent($data, $subscriberField, true);
      // If they are the same (as getFieldContent works), generate an unique one too
      if ($subscriberId == $subscriberField) {
        $subscriberId = uniqid('sube', true);
      }
    }

    // Get number of subscribers from field config (evals 0, if not given, or actually 0)
    $subscribers = intval($action->getFieldContent($data, $action->get('subscribers_field')));
    $subscribeField = $action->get('subscribe_field');
    $subscribeCondition = $action->get('subscribe_condition');

    // See if there are (noth) subscription fields given
    if (strlen($subscribeField) > 0 && strlen($subscribeCondition) > 0) {
      $subscribeValue = $action->getFieldContent($data, $subscribeField);
      if ($subscribeValue == $subscribeCondition) {
        // Valid subscription
        $subscribed = true;
        $subscribers = ($subscribers == 0) ? 1 : $subscribers;
      } else {
        // Explicit unsubscription
        $subscribed = false;
        $subscribers = 0;
      }
    } else {
      // No fields, assume normal subscription and correct eventual zero value to one person
      $subscribed = true;
      $subscribers = ($subscribers == 0) ? 1 : $subscribers;
    }

    // Add the subscription via event object
    EventType::setSubscribeInfo($eventId, $subscriberId, array(
      'tsid' => $tsid,
      'subscribed' => $subscribed,
      'subscribers' => $subscribers,
      'filled' => true
    ));
  }

  /**
   * @param int $formId form to check
   * @param DataTableAction $action the table action incl. config
   * @param array $data eventual form data, for direct checks
   * @return bool true, if maximum is reached
   */
  public function maximumReached($formId, $action, $data = array())
  {
    // Check if there is an action, for programmatic access it might be missing, then assume we can fill the table
    if ($action === null) {
      return false;
    }

    // When there is an action, find out what should happen
    $table = $this->getTable($formId);
    $max = intval($action->get('max'));
    $eventId = intval($action->get('event_id'));

    // Only to checks, if there is a defined maximum of subscribers
    if ($max > 0) {
      // If we have an event id, we can count specifically
      if ($eventId > 0) {
        return $this->checkMaximumOnEvent($eventId, $action, $data, $max);
      } else {
        // No event id, count the table
        return isset($table['data']) && count($table['data']) >= $max;
      }
    }

    // If all passes, we didn't reach an eventually set max
    return false;
  }

  /**
   * @param DataTable $action action or null or false
   * @return int and event id or zero
   */
  protected function getSaveEventId($action)
  {
    if ($action instanceof DataTableAction) {
      return intval($action->get('event_id'));
    }

    return 0;
  }

  /**
   * Check for event meta data here
   * @param int $eventId
   * @param DataTableAction $action
   * @param array $data eventual form data
   * @param int $max the max amount of subscribers
   * @return bool true, if maximum reached
   */
  protected function checkMaximumOnEvent($eventId, $action, $data, $max)
  {
    // Calculate current subscribers
    $currentSubscribers = 0;
    $info = EventType::getSubscribeInfo($eventId);
    foreach ($info as $record) {
      if (isset($record['subscribers']) && (isset($record['subscribed']) && $record['subscribed'])) {
        $currentSubscribers += intval($record['subscribers']);
      }
    }

    // How many new subscribers? Assume one, if in display context (no data)
    $subscribers = 1;
    if (count($data) > 0) {
      $subscribers = intval($action->getFieldContent($data, $action->get('subscribers_field')));
      $subscribers = ($subscribers == 0) ? 1 : $subscribers;
    }

    return ($currentSubscribers + $subscribers) > $max;
  }

  /**
   * Adds an empty table entry at the end of the table
   * @param int $formId the form id
   * @param int $eventId the event id, to couple subscriptions
   */
  public function addEmptyTableEntry($formId, $eventId)
  {
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);
    // Get a key list of the first row
    $keys = array_keys($table['fields']);
    // Create an empty row from that
    $row = array();
    foreach ($keys as $field) {
      $row[$field] = '';
    }

    // Override (or add) the usual keys
    $row['ursprungsformular'] = 'Backend / Manuell';
    $row['user-ip-adresse'] = $_SERVER['REMOTE_ADDR'];
    $row['zeitstempel'] = Date::getTime(Date::EU_DATETIME, current_time('timestamp'));
    $row['tsid'] = uniqid('');

    // If we have an event, add empty subscriber data (as unsubscribed until changed)
    if ($eventId > 0) {
      $subscriberId = uniqid('subb', true);
      // Add the subscription via event object
      EventType::setSubscribeInfo($eventId, $subscriberId, array(
        'tsid' => $row['tsid'],
        'subscribed' => false,
        'subscribers' => 0,
        'filled' => true
      ));
    }

    // Add that new row and save
    $table['data'][] = $row;
    WordPress::updateJsonOption($key, $table);
  }

  /**
   * Generates new fields list for the data table
   * @param int $formId the form
   */
  public function updateTableFields($formId)
  {
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);
    if (isset($_POST['formJson'])) {
      $table = $this->handleFormNameChanges(json_decode($_POST['formJson'], true), $table);
    }
    $table['fields'] = $this->getTableFieldFromForm($formId);
    WordPress::updateJsonOption($key, $table);
  }

  /**
   * @param $formId
   * @param $fields
   */
  public function setTableFields($formId, $fields)
  {
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);
    $table['fields'] = $fields;
    WordPress::updateJsonOption($key, $table);
  }

  /**
   * @param $formId
   * @param $table
   */
  public function saveTable($formId, $table)
  {
    $key = self::TABLE_OPTION_PREFIX . $formId;
    WordPress::updateJsonOption($key, $table);
  }

  /**
   * @param int $formId the form id
   * @return array list of key/values pairs for table fields
   */
  protected function getTableFieldFromForm($formId)
  {
    $fields = array();
    $counter = 0;

    // Get the current items from context
    $formHandler = FormCore::getInstance()->getFormHandler();
    $items = $formHandler->getCurrentItems();
    // TODO Not yet implemented as not needed: Load from ID, if nothing given
    if (!is_array($items) || count($items) == 0) {

    }

    // Set the field keys and values
    foreach ($items as $item) {
      // Skip if certain types of items
      if ($item instanceof HtmlItem) {
        continue;
      }

      // Handle fields differently if multicolumn
      if ($item->get('multicolumn') == 'ja') {
        $key = Strings::forceSlugString($item->get('feldname'));
        $selections = $item->prepareContentValues($item->getContent());
        foreach ($selections as $selection) {
          $suffix = Strings::forceSlugString(html_entity_decode($selection, ENT_QUOTES));
          if ($item->get('multicolumn_label_prefix') == 'ja') {
            $fields[$key . '-' . $suffix] = $item->get('feldname') . ': ' . $selection;
          } else {
            $fields[$key . '-' . $suffix] = $selection;
          }
        }
      } else {
        $key = Strings::forceSlugString($item->get('feldname'));
        if (!isset($fields[$key])) {
          $fields[$key] = $item->get('feldname');
        } else {
          $fields[$key . '_' . (++$counter)] = $item->get('feldname');
        }
      }
    }

    // Always add the default fields at the end
    $fields['ursprungsformular'] = 'Ursprungsformular';
    $fields['user-ip-adresse'] = 'IP-Adresse';
    $fields['zeitstempel'] = 'Datum / Zeit';
    $fields['tsid'] = 'Datensatz-ID';

    return $fields;
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
   * @param int $eventId
   */
  public function flushTable($formId, $eventId = 0)
  {
    $table = $this->getTable($formId);
    $table['data'] = array();
    $table['fields'] = $this->getTableFieldFromForm($formId);
    $table['changed'] = current_time('timestamp');
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $this->flushEventSubscriberInfo($eventId);
    WordPress::updateJsonOption($key, $table);
  }

  /**
   * Flush the table
   * @param int $formId
   * @param int $eventId
   */
  public function deleteTable($formId, $eventId = 0)
  {
    // Remove from list
    $list = $this->getTableList();
    unset($list[$formId]);
    $this->saveTableList($list);
    $this->flushEventSubscriberInfo($eventId);

    // Gracefully delete the option and the cache
    delete_option(self::TABLE_OPTION_PREFIX . $formId);
    wp_cache_delete(self::TABLE_OPTION_PREFIX . $formId, 'options');
    wp_cache_delete(self::LIST_OPTION, 'options');
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
        'data' => array(),
        'changed' => current_time('timestamp'),
        'fields' => array()
      ));
    }
  }

  /**
   * Delete a single row from a data table
   */
  public function deleteDataTableRow()
  {
    $formId = intval($_POST['formId']);
    $eventId = intval($_POST['eventId']);
    $rowIndex = intval($_POST['rowIndex']);

    // Get current table and build new without removed row
    $table = $this->getTable($formId);
    $newData = array();
    foreach ($table['data'] as $key => $row) {
      if ($key != $rowIndex) {
        $newData[] = $row;
      } else {
        $this->removeSubscriberInfo($eventId, $row);
      }
    }

    $table['data'] = $newData;
    $table['changed'] = current_time('timestamp');
    $key = self::TABLE_OPTION_PREFIX . $formId;
    WordPress::updateJsonOption($key, $table);
    exit;
  }

  /**
   * @param int $eventId the event id
   * @param array $row the row data
   */
  protected function removeSubscriberInfo($eventId, $row)
  {
    // If we have and event, subtract the subscriber infos from this row
    if ($eventId > 0 && isset($row['tsid'])) {
      $info = EventType::getSubscribeInfo($eventId);
      $id = '';
      foreach ($info as $id => $subscriber) {
        if (isset($subscriber['tsid']) && $subscriber['tsid'] == $row['tsid']) {
          break;
        }
      }

      // If we found an id, remove it from event meta info
      if (strlen($id) > 0 && isset($info[$id])) {
        EventType::removeSubscribeInfo($eventId, $id);
      }
    }
  }

  /**
   * Edit a single row of a data table with new data
   */
  public function editDataTableRow()
  {
    $formId = intval($_POST['formId']);
    $eventId = intval($_POST['eventInfo']['eventId']);
    $rowIndex = intval($_POST['rowIndex']);

    // Get current table and switch row with new data
    $table = $this->getTable($formId);
    $newData = array();
    foreach ($table['data'] as $key => $row) {
      // Switch out the values respectively
      if ($key == $rowIndex) {
        $index = 0;
        $row = $_POST['rowData'];
      }
      // Add row to new data
      $newData[] = $row;
    }

    if ($eventId > 0) {
      EventType::setSubscribeInfo(
        $eventId,
        $_POST['eventInfo']['subscriberId'],
        array(
          'subscribed' => ($_POST['eventInfo']['subscribed'] == 'false') ? false : true,
          'subscribers' => intval($_POST['eventInfo']['subscribers']),
          'filled' => true
        ),
        true
      );
    }

    $table['data'] = $newData;
    $table['changed'] = current_time('timestamp');
    $key = self::TABLE_OPTION_PREFIX . $formId;
    WordPress::updateJsonOption($key, $table);
    exit;
  }

  /**
   * @param array $targets the targets from the newsletter config
   * @return array list of maybe added or subtracted targets
   */
  public function addDynamicTargets($targets)
  {
    $tables = $this->getTableList();
    $handler = FormCore::getInstance()->getFormHandler();
    // Loop trough tables, do see if there is one with a specified config
    foreach ($tables as $formId => $name) {
      $action = $handler->getActionsOfType($formId, 'DataTable', true);
      $table = $this->getTable($formId);
      if (isset($table['data']) && is_array($table['data']) && $action instanceof BaseAction) {
        if (count($table['data']) > 0 && $action->get('use_segments') == 1) {
          $prefix = 'dynamicTarget_dataTable_' . $formId . '_';
          // First, add the "all" segment which is always available
          $targets[$prefix . 'all'] = 'Datenspeicher (' . $formId . '): ' . $name . ' (Alle)';
          // If there is an event, add more segments
          $eventId = $this->getSaveEventId($action);
          if ($eventId > 0) {
            $targets[$prefix . 'subscribed'] = 'Datenspeicher (' . $formId . '): ' . $name . ' (Angemeldete)';
            $targets[$prefix . 'unsubscribed'] = 'Datenspeicher (' . $formId . '): ' . $name . ' (Abgemeldete)';
            // Add the delta segment (mail to everyone who didn't answer yet) if emailid field is set
            if (strlen($action->get('emailid_field')) > 0) {
              $targets[$prefix . 'noanswer'] = 'Datenspeicher (' . $formId . '): ' . $name . ' (Keine Antwort)';
            }
          }
        }
      }
    }

    return $targets;
  }

  /**
   * @param array $map the initial empty map
   * @return array might be filled with a map
   */
  public function getDynamicTargetFieldMap($map)
  {
    $tables = $this->getTableList();
    $handler = FormCore::getInstance()->getFormHandler();
    // Loop trough tables, do see if there is one with a specified config
    foreach ($tables as $formId => $name) {
      $action = $handler->getActionsOfType($formId, 'DataTable', true);
      if ($action == NULL) continue;
      $table = $this->getTable($formId);
      if (is_array($table['data']) && count($table['data']) > 0 && $action->get('use_segments') == 1) {
        $prefix = 'dynamicTarget_dataTable_' . $formId . '_';
        if (count($table['data']) > 0) {
          $fields = array_keys($table['data'][0]);
          $mappableFields = array();
          foreach ($fields as $value) {
            $mappableFields[$value] = $value;
          }
          // Make all four maps, even if not all are used
          $map[$prefix . 'all'] = array(
            'name' => 'Datenspeicher (' . $formId . '): ' . $name . ' (Alle)',
            'fields' => $mappableFields
          );
          $map[$prefix . 'subscribed'] = array(
            'name' => 'Datenspeicher (' . $formId . '): ' . $name . ' (Angemeldete)',
            'fields' => $mappableFields
          );
          $map[$prefix . 'unsubscribed'] = array(
            'name' => 'Datenspeicher (' . $formId . '): ' . $name . ' (Abgemeldete)',
            'fields' => $mappableFields
          );
          $map[$prefix . 'noanswer'] = array(
            'name' => 'Datenspeicher (' . $formId . '): ' . $name . ' (Keine Antwort)',
            'fields' => $mappableFields
          );
        }
      }
    }

    return $map;
  }

  /**
   * Get actual sendable list from dynamic target
   * @param array $data the data array that needs to be returned and filled
   * @param string $identifier the target identifier
   * @param array $map the field mapping
   * @param array $fallback the field fallbacks
   * @return array
   */
  public function getDynamicTargetData($data, $identifier, $map, $fallback)
  {
    list($type, $class, $formId, $segment) = explode('_', $identifier);

    // Only proceed if it is something the data table can handle
    if ($type == 'dynamicTarget' && $class == 'dataTable' && intval($formId) > 0) {
      // See what segment we should provide from the tables data
      switch ($segment) {
        case 'all':
          $this->getDynamicTargetListAll($data, $formId, $map, $fallback);
          break;
        case 'subscribed':
          $this->getDynamicTargetListSubscribed($data, $formId, $map, $fallback);
          break;
        case 'unsubscribed':
          $this->getDynamicTargetListUnsubscribed($data, $formId, $map, $fallback);
          break;
        case 'noanswer':
          $this->getDynamicTargetListNoAnswer($data, $formId, $map, $fallback);
          break;
      }
    }

    return $data;
  }

  /**
   * Get a dynamic segment that returns all users from the table that are matchable
   * @param array $data the array to be filled with data, by ref for performance
   * @param int $formId the form id that refers to the data table
   * @param array $map for mapping table field keys to actual recipient fields
   * @param array $fallback the field fallbacks
   */
  protected function getDynamicTargetListAll(&$data, $formId, $map, $fallback)
  {
    // Simply add every row that has a valid email address
    $table = $this->getTable($formId);
    foreach ($table['data'] as $row) {
      $this->addToDynamicList($data, $row, $map, $fallback);
    }
  }

  /**
   * Get a dynamic segment that returns only subscribed users from the table that are matchable
   * @param array $data the array to be filled with data, by ref for performance
   * @param int $formId the form id that refers to the data table
   * @param array $map for mapping table field keys to actual recipient fields
   * @param array $fallback the field fallbacks
   */
  protected function getDynamicTargetListSubscribed(&$data, $formId, $map, $fallback)
  {
    $this->getDynamicTargetListFromEventData($data, $formId, $map, $fallback, 'subscribed', true, true);
  }

  /**
   * Get a dynamic segment that returns only unsubscribed users from the table that are matchable
   * @param array $data the array to be filled with data, by ref for performance
   * @param int $formId the form id that refers to the data table
   * @param array $map for mapping table field keys to actual recipient fields
   * @param array $fallback the field fallbacks
   */
  protected function getDynamicTargetListUnsubscribed(&$data, $formId, $map, $fallback)
  {
    $this->getDynamicTargetListFromEventData($data, $formId, $map, $fallback, 'subscribed', false, true);
  }

  /**
   * Get a dynamic segment that returns all users that didn't answer yet
   * @param array $data the array to be filled with data, by ref for performance
   * @param int $formId the form id that refers to the data table
   * @param array $map for mapping table field keys to actual recipient fields
   * @param array $fallback the field fallbacks
   */
  protected function getDynamicTargetListNoAnswer(&$data, $formId, $map, $fallback)
  {
    $this->getDynamicTargetListFromEventData($data, $formId, $map, $fallback, 'filled', false, false);
  }

  /**
   * Get a dynamic segment with the help of a simple event data matching check
   * @param array $data the array to be filled with data, by ref for performance
   * @param int $formId the form id that refers to the data table
   * @param array $map for mapping table field keys to actual recipient fields
   * @param array $fallback the field fallbacks
   * @param string $field the field to check on event data
   * @param mixed $value the value the field needs to have
   */
  protected function getDynamicTargetListFromEventData(&$data, $formId, $map, $fallback, $field, $value, $filled)
  {
    // Get table and event data, go trough events to find matching rows by TSID
    $table = $this->getTable($formId);
    $handler = FormCore::getInstance()->getFormHandler();
    $action = $handler->getActionsOfType($formId, 'DataTable', true);
    $eventId = $this->getSaveEventId($action);

    // Now, if there is an event, get its data
    if ($eventId > 0) {
      $subscribeInfo = EventType::getSubscribeInfo($eventId);
      foreach ($subscribeInfo as $id => $subscription) {
        // Check for the field, its value and also, make sure it was filled (only then, all checks work)
        if (isset($subscription[$field]) && $subscription[$field] == $value && $subscription['filled'] == $filled) {
          if (isset($subscription['tsid']) && strlen($subscription['tsid']) > 0) {
            $row = $this->getRowById($table['data'], $subscription['tsid']);
          } else if (isset($subscription['list-id']) && strlen($subscription['list-id']) > 0) {
            // Fill the fallback with our actual keys from the list (which should always work nicely)
            $row = $this->getListDataSet($subscription['list-id'], $id);
            // Also, make sure to add the list again
            $row['list-id'] = $subscription['list-id'];
          }
          // If there is no email, try getting it from subscription data
          if (!isset($row[$map['email']])) {
            $row[$map['email']] = $subscription['email'];
          }
          // No add the row to the list if possible
          $this->addToDynamicList($data, $row, $map, $fallback);
        }
      }
    }
  }

  /**
   * Get data row for dynamic newsletter segment directly from an original list
   * @param int|string $listId the list id
   * @param int|string $memberId the member id to be retrieved
   * @return array of data or empty array
   */
  public function getListDataSet($listId, $memberId)
  {
    // Only works with local mail as we have the needed data stored
    if (LocalMailService::isWorking()) {
      $service = LocalMailService::getInstance();
      $data = $service->getListData($listId);
      // Find the right data set
      foreach ($data as $id => $row) {
        if ($memberId == $id) {
          return $row;
        }
      }
    }

    // Nothing found
    return array();
  }

  /**
   * Get a row from a table by "tsid" field
   * @param $data
   * @param $id
   * @return array|bool data row or false
   */
  public function getRowById($data, $id)
  {
    foreach ($data as $row) {
      if (isset($row['tsid']) && $row['tsid'] == $id) {
        return $row;
      }
    }

    return array();
  }

  /**
   * Adds a row to a dynamic list segment
   * @param array $data the list to add the recipient
   * @param array $row the data table row
   * @param array $map the field map to have e valid recipient
   * @param array $fallback the field fallbacks
   */
  protected function addToDynamicList(&$data, $row, $map, $fallback)
  {
    $email = $row[$map['email']];
    $memberId = md5($email);
    $recipient = array();

    if (Strings::checkEmail($email)) {
      foreach (array('email', 'salutation', 'firstname', 'lastname', 'list-id') as $key) {
        if (isset($row[$map[$key]]) && strlen($map[$key]) > 0) {
          $recipient[$key] = $row[$map[$key]];
        } else if (isset($row[$key]) && strlen($row[$key]) > 0) {
          $recipient[$key] = $row[$key];
        } else {
          $recipient[$key] = $fallback[$key];
        }
      }

      // Add it with email md5 as memberId if not already given
      if (!isset($data[$memberId])) {
        $data[$memberId] = $recipient;
      }
    }
  }

  /**
   * @param int $eventId the event id
   */
  protected function flushEventSubscriberInfo($eventId)
  {
    if ($eventId > 0) {
      EventType::flushSubscribeInfo($eventId);
    }
  }

  /**
   * @param int $formId the form id
   */
  public function savePrivacyDeleteAfter($formId)
  {
    $days = intval($_POST['privacyDeleteAfter']);
    $table = $this->getTable($formId);
    // First, unset the privacy setting
    if (isset($table['privacy-delete-after'])) {
      unset($table['privacy-delete-after']);
    }
    if ($days > 0) {
      $table['privacy-delete-after'] = $days;
    }
    $this->saveTable($formId, $table);
  }

  /**
   * Register the eraser
   * @param $erasers
   * @return mixed
   */
  public function registerPersonalDataEraser($erasers)
  {
    $erasers['LbwpDataTableEraser'] = array(
      'eraser_friendly_name' => __('WordPress Datenspeicher', 'lbwp'),
      'callback' => array($this, 'personalDataEraser'),
    );
    return $erasers;
  }

  /**
   * Register the eraser
   * @param $erasers
   * @return mixed
   */
  public function registerPersonalDataExporter($erasers)
  {
    $erasers['LbwpDataTableExporter'] = array(
      'exporter_friendly_name' => __('WordPress Datenspeicher', 'lbwp'),
      'callback' => array($this, 'personalDataExporter'),
    );
    return $erasers;
  }

  /**
   * Erases the Data (from the dataTable)
   * @param $email
   * @return array
   */
  public function personalDataEraser($email)
  {
    $tableList = $this->getTableList();
    $messages = array('Datensätze gelöscht');

    foreach ($tableList as $id => $name) {
      $table = $this->getTable($id);
      $data = $table['data'];

      if ($data === NULL) {
        continue;
      }

      foreach ($data as $rowKey => $rows) {
        if (in_array($email, $rows)) {
          array_push($messages, 'Datensatz: ' . $rows['tsid']);
          unset($data[$rowKey]);
        }
      }

      // No empty arrays
      $table['data'] = array_values($data);

      $this->saveTable($id, $table);
    }

    return array(
      'items_removed' => true,
      'items_retained' => true,
      'messages' => $messages,
      'done' => true,
    );
  }

  /**
   * Erases the Data (from the dataTable)
   * @param string $email
   * @return array
   */
  public function personalDataExporter($email)
  {
    set_time_limit(300);
    ini_set('memory_limit', '1024M');

    $tableList = $this->getTableList();
    $items = array();

    foreach ($tableList as $id => $name) {
      $table = $this->getTable($id);
      $data = $table['data'];

      if ($data === NULL) {
        continue;
      }

      foreach ($data as $row) {
        if (in_array($email, $row)) {
          $keyValue = array();
          foreach ($row as $key => $value) {
            $keyValue[] = array('name' => $key, 'value' => $value);
          }
          $items[] = array(
            'group_id' => 'data-storage-' . $id,
            'group_label' => trim('Datenspeicher ' . $name),
            'item_id' => $row['tsid'],
            'data' => $keyValue,
          );
        }
      }
    }

    return array(
      'data' => $items,
      'done' => true
    );
  }
}