<?php

namespace LBWP\Module\Forms\Component\ActionBackend;

use LBWP\Module\Events\Component\EventType;
use LBWP\Module\Forms\Core as FormCore;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Module\Forms\Component\ActionBackend\DataTable as DataTableBackend;
use LBWP\Module\Forms\Action\DataTable as DataTableAction;

/**
 * This handles the display of data and options
 * @package LBWP\Module\Forms\Component\ActionBackend
 * @author Michael Sebel <michael@comotive.ch>
 */
class DataDisplay
{
  /**
   * @var DataTableBackend the data table backend
   */
  protected $backend = NULL;
  /**
   * @var int the alt counter for the table display
   */
  protected $altCounter = 0;

  /**
   * @param DataTableBackend $backend
   */
  public function __construct($backend)
  {
    $this->backend = $backend;
  }

  /**
   * @param $formId
   * @return string html code
   */
  public function getHtml($formId)
  {
    // Prepare the data
    $table = $this->backend->getTable($formId);
    $tableName = WordPress::getBackendPageName($_GET['page']);
    $columns = $this->getColumns($table);
    $rawTable = $this->getRawTable($columns, $table['data']);
    $hasEntries = count($rawTable) > 0;

    // Get the actual action config, from form id to have an eventual event
    $handler = FormCore::getInstance()->getFormHandler();
    $action = $handler->getActionsOfType($formId, 'DataTable', true);
    $eventId = $this->getSaveEventId($action);

    // Run controller
    $this->runController($formId, $tableName, $columns, $rawTable, $eventId);

    // Return HTML code
    return '
      <div class="wrap">
        <h2>Datenspeicher ' . $tableName . '</h2>
        <input type="hidden" id="eventId" value="' . $eventId . '" />
        ' . $this->getUserOptions($formId, $hasEntries) . '<br />
        ' . $this->getTableHtml($columns, $rawTable, $formId, $eventId) . '<br />
        ' . $this->getEventSummaryHtml($formId, $eventId) . '
        <br class="clear">
      </div>
    ';
  }

  /**
   * @param int $formId display various user options
   * @param bool $hasEntries some menus can only be displayed if there are entries
   * @return string html code for options
   */
  protected function getUserOptions($formId, $hasEntries)
  {
    $additionalMenus = '';
    if ($hasEntries) {
      $additionalMenus .= '
        <li> | <a href="?page=' . $_GET['page'] . '&newrow">Neuen Datensatz anfügen</a></li>
      ';
    }

    return '
      <ul class="subsubsub">
        <li><a href="?page=' . $_GET['page'] . '&flushtable" onclick="return confirm(\'Tabelle wirklich leeren?\')">Tabelle leeren</a></li>
        <li> | <a href="?page=' . $_GET['page'] . '&deletetable" onclick="return confirm(\'Tabelle wirklich löschen?\')">Tabelle löschen</a></li>
        <li> | <a href="#export-csv-utf8" class="export" data-type="csv" data-encoding="utf8">Export als CSV (UTF-8)</a></li>
        <li> | <a href="#export-csv-iso" class="export" data-type="csv" data-encoding="iso">Export als CSV (Excel)</a></li>
        ' . $additionalMenus . '
      </ul>
    ';
  }

  /**
   * @param array $columns the colum names
   * @param array $data the data to display
   * @param int $formId the form id
   * @param int $eventId the eventual event id
   * @return string html code
   */
  protected function getTableHtml($columns, $data, $formId, $eventId)
  {
    $html = '<table class="widefat">';

    // If there is no data, show it
    if (count($columns) == 0) {
      $columns[] = 'Keine Daten';
      $data[0]['Keine Daten'] = 'Es befinden sich noch keine Daten in der Tabelle.';
    }

    // Load event infos, if needed
    if ($eventId > 0) {
      $info = EventType::getSubscribeInfo($eventId);
    }

    // Table header
    $exportOptions = '';
    $htmlColumns = '<th class="manage-column">&nbsp;</th>';
    foreach ($columns as $colName) {
      $htmlColumns .= '<th class="manage-column"><strong>' . $colName . '</strong></th>';
      $exportOptions .= '<option value="' . $colName . '" selected="selected">' . $colName . '</option>' . PHP_EOL;
    }

    // Create the export form
    $exportForm = '
      <form action="?page=' . $_GET['page'] . '" method="POST" id="exportForm">
        <input type="hidden" id="exportType" name="export" value="" />
        <input type="hidden" id="exportEncoding" name="type" value="" />
        <p>Bitte wählen und sortieren Sie die gewünschten Felder für den Export.</p>
        <p><select name="columns[]" multiple="multiple" class="col-select">' . $exportOptions . '</select></p>
        <p>
          <input type="submit" class="button-primary export-start" value="Export starten" />
          <input type="button" class="button export-cancel" value="Abbrechen" />
        </p>
      </form>
    ';

    // Add the content to foot and head
    $html .= '
      <thead><tr>' . $htmlColumns . '</tr></thead>
      <tfoot><tr>' . $htmlColumns . '</tr></tfoot>
    ';

    // Add the actual data
    $html .= '<tbody>';
    foreach ($data as $id => $row) {
      $class = $this->getAltClass();
      $html .= '<tr' . $class . '>';
      // Add the edit/delete features
      $html .= '
        <td class="options">
          <a class="dashicons dashicons-edit edit-row"></a>
          <a class="dashicons dashicons-yes save-row" data-index="' . $id . '" data-formid="' . $formId . '"></a>
          <a class="dashicons dashicons-no delete-row" data-index="' . $id . '" data-formid="' . $formId . '"></a>
        </td>
      ';
      // Add the actual data
      foreach ($row as $key => $value) {
        $html .= '
          <td class="' . $key . '">
            <span>' . $this->prepareValue($value) . '</span>
            <textarea class="data-table-area" data-key="' . $key . '">' . $value . '</textarea>
          </td>';
      }
      $html .= '</tr>';

      // If there is an event, make a hidden edit row for it
      if ($eventId > 0) {
        // Try getting the subscriber id from tsid
        $subscriberId = $this->getSubscriberIdByTsId($row['tsid'], $info);
        $data = $info[$subscriberId];
        // Set preselections
        $subscribers = intval($data['subscribers']);
        $subscribed = (isset($data['subscribed']) && $data['subscribed']);
        $html .= '<tr' . $class . ' style="display:none">';
        // Use the first empty cell to have the subscriber id there
        $html .= '<td><input type="hidden" class="subscriberId" value="' . $subscriberId . '" /></td>';
        // Make a colspan over all rows
        $html .= '
          <td colspan="' . count($row) . '">
            <strong>Event-Daten:</strong>
            <label><input type="radio" name="subscribe-' . $id . '" data-key="subscribe-yes" ' . checked(true, $subscribed, false) . ' /> Anmeldung oder </label>
            <label><input type="radio" name="subscribe-' . $id . '" data-key="subscribe-no" ' . checked(false, $subscribed, false) . ' /> Abmeldung für </label>
            <label><input type="text" data-key="subscribers" value="' . $subscribers . '" style="width:50px;"> Personen</label>
          </td>
        ';
        $html .= '</tr>';
      }
    }

    // Close the body and table and return
    return $this->getAssets() . $exportForm . $html . '</tbody></table>';
  }

  /**
   * @param string $tsId table storage id
   * @param array $info full info array (byRef for performance)
   * @return string the subscriber id matching the tsid, if given
   */
  protected function getSubscriberIdByTsId($tsId, &$info)
  {
    foreach ($info as $id => $data) {
      if (isset($data['tsid']) && $tsId == $data['tsid']) {
        return $id;
      }
    }

    return '';
  }

  /**
   * @param int $formId the form id
   * @param int $eventId the event id
   * @return string html to display events summary
   */
  protected function getEventSummaryHtml($formId, $eventId)
  {
    $html = '';

    // Get the a counting array from events subscribeinfo metadata
    $summary = $this->getRawEventSummary($eventId);

    if ($eventId > 0) {
      // Create a little table for that
      $html .= '
      <h4>Event Zusammenfassung</h4>
      <table class="widefat" style="width:210px">
    ';
      foreach ($summary as $key => $sum) {
        $html .= '
        <tr class="sum-' . $key . '">
          <td style="text-align:right;"><strong>' . $sum['value'] . '</strong></td>
          <td>' . $sum['name'] . '</td>
        </tr>
      ';
      }
      $html .= '</table>';
    }

    return $html;
  }

  /**
   * @param int $eventId the event id
   * @return array a raw summary data object
   */
  protected function getRawEventSummary($eventId)
  {
    // Create skeleton to count into and return
    $data = array(
      'subscribed' => array(
        'name' => 'Angemeldete Personen',
        'value' => 0
      ),
      'unsubscribed' => array(
        'name' => 'Abgemeldete Personen',
        'value' => 0
      ),
      'unfilled' => array(
        'name' => 'Ausstehende Antworten',
        'value' => 0
      )
    );

    // Get the event subscriber info
    $info = EventType::getSubscribeInfo($eventId);
    foreach ($info as $record) {
      // Is the record even filled out?
      if (!isset($record['filled']) || !$record['filled']) {
        ++$data['unfilled']['value'];
      } else {
        // A filled out record, see if subscribed
        if (isset($record['subscribed']) && $record['subscribed']) {
          $subscribers = (intval($record['subscribers']) == 0) ? 1 : intval($record['subscribers']);
          $data['subscribed']['value'] += $subscribers;
        } else {
          ++$data['unsubscribed']['value'];
        }
      }
    }

    return $data;
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
   * @return string
   */
  protected function getAssets()
  {
    $html = '';
    $path = File::getResourceUri();

    // Javascript to edit and remove rows
    $html .= '
      <script type="text/javascript" src="' . $path . '/js/data-table-backend.js"></script>
      <script type="text/javascript" src="' . $path . '/js/chosen/chosen.jquery.min.js"></script>
      <script type="text/javascript" src="' . $path . '/js/chosen/chosen.sortable.jquery.js"></script>
      <script type="text/javascript" src="/wp-includes/js/jquery/ui/jquery.ui.widget.min.js"></script>
      <script type="text/javascript" src="/wp-includes/js/jquery/ui/jquery.ui.mouse.min.js"></script>
      <script type="text/javascript" src="/wp-includes/js/jquery/ui/jquery.ui.sortable.min.js"></script>
    ';

    // Some simple styles
    $html .= '
      <link rel="stylesheet" href="' . $path . '/js/chosen/chosen.min.css" />
      <style type="text/css">
        .data-table-area { display:none; height:80px; width:inherit; }
        td.options { width:50px; white-space: nowrap; }
        td.options .dashicons:hover { cursor:pointer; }
        td.options .save-row { display:none; }
        .subsubsub { margin-bottom:5px; }
        #exportForm {
          display:none;
          width:100%;
          clear:both;
          margin:20px 0px 20px 2px;
        }
      </style>
    ';

    return $html;
  }

  /**
   * @return string
   */
  protected function getAltClass()
  {
    if (++$this->altCounter % 2 == 0) {
      return '';
    } else {
      return ' class="alternate"';
    }
  }

  /**
   * @param string $value strip and secure values from forms
   * @return string a displayable value
   */
  protected function prepareValue($value)
  {
    $value = Strings::chopString($value, 200, false);
    $value = nl2br(strip_tags($value));
    return $value;
  }

  /**
   * @param array $table the full data table
   * @return array list of all possible cell keys
   */
  protected function getColumns($table)
  {
    $columns = array();
    foreach ($table['data'] as $row) {
      foreach ($row as $key => $value) {
        if (!in_array($key, $columns)) {
          $columns[] = $key;
        }
      }
    }

    return $columns;
  }

  /**
   * @param array $columns list of possible data entries
   * @param array $data the actual data rows
   * @return array array containing empty values for all fields
   */
  protected function getRawTable($columns, $data)
  {
    $rawData = array();

    foreach ($data as $row) {
      $rawRow = array();
      foreach ($columns as $key) {
        if (isset($row[$key])) {
          $rawRow[$key] = $row[$key];
        } else {
          $rawRow[$key] = '';
        }
      }
      $rawData[] = $rawRow;
    }

    return $rawData;
  }

  /**
   * @param int $formId controlled form id
   * @param string $name name of the table
   * @param array $columns cell keys
   * @param array $data table raw data
   * @param int $eventId the event id
   */
  protected function runController($formId, $name, $columns, $data, $eventId = 0)
  {
    // Export the data
    if (isset($_POST['export'])) {
      if ($_POST['export'] == 'csv' && $_POST['type'] == 'utf8') {
        $this->sendCsv($name, $columns, $data, false, $formId, $eventId);
      }
      if ($_POST['export'] == 'csv' && $_POST['type'] == 'iso') {
        $this->sendCsv($name, $columns, $data, true, $formId, $eventId);
      }
    }

    if (isset($_GET['newrow'])) {
      $this->backend->addEmptyTableEntry($formId, $eventId);
      header('location: ?page=' . $_GET['page']);
      exit;
    }

    // Flush the data table and redirect
    if (isset($_GET['flushtable'])) {
      $this->backend->flushTable($formId, $eventId);
      header('location: ?page=' . $_GET['page']);
      exit;
    }

    // Flush the data table and redirect
    if (isset($_GET['deletetable'])) {
      $this->backend->deleteTable($formId, $eventId);
      header('location: ' . get_admin_url());
      exit;
    }
  }

  /**
   * Download a table as CSV (Exits after output)
   * @param string $name name of the table
   * @param array $columns cell keys
   * @param array $data table raw data
   * @param bool $utf8decode utf8 decoding
   * @param int $formId the form id, to check for an event
   * @param int $eventId eventual event id
   */
  protected function sendCsv($name, $columns, $data ,$utf8decode, $formId, $eventId)
  {
    ob_end_clean();
    $filename = Strings::forceSlugString($name) . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);
    $outstream = fopen("php://output", 'w');

    // Newly sort columns by the order given
    if (isset($_POST['columns']) && is_array($_POST['columns']) && count($_POST['columns'])) {
      $columns = array_map(array('\LBWP\Util\Strings', 'forceSlugString'), $_POST['columns']);
    }

    // Print the fields
    fputcsv($outstream, $columns, ';', '"');
    // Print the data
    foreach ($data as $row) {
      // Crazy utf-8 decoding since excel doesn't know that by default
      $printedRow = array();
      foreach ($columns as $key) {
        $value = $row[$key];
        $value = str_replace(array("\n","\r"),'',$value);
        if ($utf8decode) {
          $printedRow[$key] = utf8_decode($value);
        } else {
          $printedRow[$key] = $value;
        }
      }
      fputcsv($outstream, $printedRow, ';', '"');
    }

    // If there is an event, output a newline and the summary for each line
    if ($eventId > 0) {
      $summary = $this->getRawEventSummary($eventId);
      fputcsv($outstream, array(), ';', '"');
      foreach ($summary as $sum) {
        fputcsv($outstream, $sum, ';', '"');
      }
    }

    fclose($outstream);
    exit;
  }
} 