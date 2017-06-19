<?php

namespace LBWP\Module\Forms\Component\ActionBackend;

use LBWP\Module\Events\Component\EventType;
use LBWP\Module\Forms\Action\DataTable as DataTableAction;
use LBWP\Module\Forms\Component\Base;
use LBWP\Module\Forms\Core as FormCore;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Util\ArrayManipulation;
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
    add_action('admin_menu', array($this, 'addTableMenus'));
    add_action('wp_ajax_deleteDataTableRow', array($this, 'deleteDataTableRow'));
    add_action('wp_ajax_editDataTableRow', array($this, 'editDataTableRow'));
    add_filter('ComotiveNL_dynamic_target_get_list', array($this, 'addDynamicTargets'));
    add_filter('ComotiveNL_dynamic_target_field_mapping', array($this, 'getDynamicTargetFieldMap'));
    add_Filter('ComotiveNL_dynamic_target_get_list_data', array($this, 'getDynamicTargetData'), 10, 4);
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
   * @param DataTableAction $action the data table instance
   * @return bool true, if the data entry has been added
   */
  public function addTableEntry($formId, $data, $action)
  {
    // Get the current table
    $tsid = uniqid('', false);
    $key = self::TABLE_OPTION_PREFIX . $formId;
    $table = WordPress::getJsonOption($key);
    $eventId = $action->get('event_id');
    $editTsId = $_POST['editingTsId'];

    // If there are to many datasets, return an error
    if ($this->maximumReached($formId, $action, $data)) {
      return false;
    }

    // The entry can be newly  added
    if ($this->validateRowId($table['data'], $editTsId)) {
      $this->editRow($table, $data, $editTsId, $action, $eventId);
    } else {
      $this->addNewRow($table, $data, $tsid, $action, $eventId);
    }

    // And save back to the table
    WordPress::updateJsonOption($key, $table);
    return true;
  }

  /**
   * @param array $table the full table by reference
   * @param array $data the form data
   * @param string $tsid the storage row id, to be used
   * @param DataTableAction $action the action object
   * @param int $eventId eventual matched event
   */
  protected function addNewRow(&$table, $data, $tsid, $action, $eventId = 0)
  {
    $row = array();
    foreach ($data as $item) {
      $cellKey = Strings::forceSlugString($item['name']);
      // If storage id cell, update the id
      if ($cellKey == 'tsid') {
        $item['value'] = $tsid;
        self::$lastTsid = $tsid;
      }
      $row[$cellKey] = $item['value'];
    }

    // Add the new row to the table
    $table['data'][] = $row;

    // Add event subscription info, if needed
    if ($eventId > 0) {
      $this->addEventSubscriberInfo($eventId, $data, $action, $tsid);
    }
  }

  /**
   * @param array $table the full table by reference
   * @param array $data the form data
   * @param string $tsid the storage row id, to be used
   * @param DataTableAction $action the action object
   * @param int $eventId eventual matched event
   */
  protected function editRow(&$table, $data, $tsid, $action, $eventId = 0)
  {
    // Set the last tsid for an eventual notification
    self::$lastTsid = $tsid;
    // Get the existing row and its internal index
    foreach ($table['data'] as $index => $row) {
      if (isset($row['tsid']) && $row['tsid'] == $tsid) {
        // Break, so we have the current values in $index and $row
        break;
      }
    }

    // Override data in that row, except for the tsid
    foreach ($data as $item) {
      $cellKey = Strings::forceSlugString($item['name']);
      if ($cellKey != 'tsid') $row[$cellKey] = $item['value'];
    }

    // Override the row in our referenced table
    $table['data'][$index] = $row;

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
      $subscriberId = $action->getFieldContent($data, $subscriberField);
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
        return count($table['data']) >= $max;
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
    $keys = array_keys($table['data'][0]);
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
      if (count($table['data']) > 0 && $action->get('use_segments') == 1) {
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
          $this->getDynamicTargetListAll($data, $formId, $map, $fallback); break;
        case 'subscribed':
          $this->getDynamicTargetListSubscribed($data, $formId, $map, $fallback); break;
        case 'unsubscribed':
          $this->getDynamicTargetListUnsubscribed($data, $formId, $map, $fallback); break;
        case 'noanswer':
          $this->getDynamicTargetListNoAnswer($data, $formId, $map, $fallback); break;
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
    $this->getDynamicTargetListFromEventData($data, $formId, $map, $fallback, 'subscribed', true);
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
    $this->getDynamicTargetListFromEventData($data, $formId, $map, $fallback, 'subscribed', false);
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
    $this->getDynamicTargetListFromEventData($data, $formId, $map, $fallback, 'filled', false);
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
  protected function getDynamicTargetListFromEventData(&$data, $formId, $map, $fallback, $field, $value)
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
        if (isset($subscription[$field]) && $subscription[$field] == $value) {
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
} 