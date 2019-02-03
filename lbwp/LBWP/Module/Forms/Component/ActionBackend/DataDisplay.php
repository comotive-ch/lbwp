<?php

namespace LBWP\Module\Forms\Component\ActionBackend;

use LBWP\Core as LbwpCore;
use LBWP\Module\Events\Component\EventType;
use LBWP\Module\Forms\Core as FormCore;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Strings;
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
   * @var array excel formular starting strings
   */
  protected $excelFormulaChars = array('-', '+', '*', '/', '=');

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
    $tableName = $this->getTableName($formId);
    $columns = $this->getColumns($table, $table['fields']);
    $rawTable = $this->getRawTable($table['data'], $table['fields']);

    // Get the actual action config, from form id to have an eventual event
    $handler = FormCore::getInstance()->getFormHandler();
    $action = $handler->getActionsOfType($formId, 'DataTable', true);
    $eventId = $this->getSaveEventId($action);

    // Run controller
    $this->runController($formId, $tableName, $columns, $rawTable, $eventId, $table['fields']);

    // Return HTML code
    return '
      <div class="wrap">
        <h2>Datenspeicher ' . $tableName . '</h2>
        <input type="hidden" id="eventId" value="' . $eventId . '" />
        ' . $this->getUserOptions($formId) . '<br />
        ' . $this->getTableHtml($columns, $rawTable, $formId, $eventId, $table['fields']) . '<br />
        ' . $this->getEventSummaryHtml($formId, $eventId) . '
        ' . $this->getEventUnfilledHtml($formId, $eventId) . '
        <br class="clear">
      </div>
    ';
  }

  /**
   * @param int $formId the form and hence table id
   * @return string the name of the table
   */
  protected function getTableName($formId)
  {
    $list = ArrayManipulation::forceArray(get_option(DataTable::LIST_OPTION));

    if (count($list) > 0) {
      // Add table rows
      foreach ($list as $id => $name) {
        if ($id == $formId) {
          return $name;
        }
      }
    }
  }

  /**
   * @param int $formId display various user options
   * @return string html code for options
   */
  protected function getUserOptions($formId)
  {
    return '
      <ul class="subsubsub">
        <li><a href="?page=' . $_GET['page'] . '&table=' . $_GET['table'] . '&flushtable" onclick="return confirm(\'Tabelle wirklich leeren?\')">Tabelle leeren</a></li>
        <li> | <a href="?page=' . $_GET['page'] . '&table=' . $_GET['table'] . '&deletetable" onclick="return confirm(\'Tabelle wirklich löschen?\')">Tabelle löschen</a></li>
        <li> | <a href="#export-csv-utf8" class="export" data-type="csv" data-encoding="utf8">Export als CSV (UTF-8)</a></li>
        <li> | <a href="#export-csv-iso" class="export" data-type="csv" data-encoding="iso">Export als CSV (Excel)</a></li>
        <li> | <a href="?page=' . $_GET['page'] . '&table=' . $_GET['table'] . '&newrow">Neuen Datensatz anfügen</a></li>
      </ul>
    ';
  }

  /**
   * @param array $columns the colum names
   * @param array $data the data to display
   * @param int $formId the form id
   * @param int $eventId the eventual event id
   * @param int $fields the field names array
   * @return string html code
   */
  protected function getTableHtml($columns, $data, $formId, $eventId, $fields)
  {
    $html = '<table class="widefat" id="lbwp-data-table">';
    // Force fields to be an array
    $fields = ArrayManipulation::forceArray($fields);

    // Load event infos, if needed
    if ($eventId > 0) {
      $url = get_permalink($eventId);
      $info = EventType::getSubscribeInfo($eventId);
    }

    // Table header
    $exportOptions = '';
    $htmlColumns = '<th class="manage-column">&nbsp;</th>';
    foreach ($columns as $colName) {
      $printedName = $colName;
      if (isset($fields[$colName])) {
        $printedName = $fields[$colName];
      }
      $htmlColumns .= '<th class="manage-column"><strong>' . $printedName . '</strong></th>';
      $exportOptions .= '<option value="' . $colName . '" selected="selected">' . $printedName . '</option>' . PHP_EOL;
    }

    // Create the export form
    $exportForm = '
      <form action="?page=' . $_GET['page'] . '&table=' . $_GET['table'] . '" method="POST" id="exportForm">
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
      // Additional options, if it is an event
      $additional = '';
      if ($eventId > 0) {
        $additional = '<a href="' . Strings::attachParam('tsid', $row['tsid'], $url) . '" target="_blank" class="dashicons dashicons-admin-links"></a>';
      }
      // Add the edit/delete features
      $html .= '
        <td class="options">
          <a class="dashicons dashicons-edit edit-row"></a>
          <a class="dashicons dashicons-yes save-row" data-index="' . $id . '" data-formid="' . $formId . '"></a>
          <a class="dashicons dashicons-no delete-row" data-index="' . $id . '" data-formid="' . $formId . '"></a>
          ' . $additional . '
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

    // If there is no data, show an erroneus row
    if (count($data) == 0) {
      $html .= '<tr><td>&nbsp;</td><td colspan="' . count($columns) . '">In dieser Tabelle sind noch keine Daten gespeichert.</td></tr>';
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
    $event = get_post($eventId);

    if ($eventId > 0) {
      // Create a little table for that
      $html .= '
        <h3>Event Zusammenfassung</h3>
        <p>Der Datenspeicher im Formular ist mit dem Event &laquo;' . $event->post_title . '&raquo; verknüpft.</p>
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
      $html .= '</table><br>';
    }

    return $html;
  }

  /**
   * @param int $formId the form id
   * @param int $eventId the event id
   * @return string html to display unfilled answers
   */
  public function getEventUnfilledHtml($formId, $eventId)
  {
    $html = '';

    if ($eventId > 0) {
      // Initialize column info
      $columnInfo = array('email' => 'E-Mail-Adresse');
      // Get the according rows of the event data
      $rows = $this->getEventUnfilledData($eventId, $columnInfo);
      // Add this at the end so it appears at the tables end
      $columnInfo['subscribe-link'] = 'Anmelde-Link';

      // Create Html for that data
      foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columnInfo as $key => $info) {
          if ($key == 'subscribe-link') {
            $html .= '<td><a href="' . $row[$key] . '" target="_blank">Anmelden</a></td>';
          } else {
            $html .= '<td>' . $row[$key] . '</td>';
          }
        }
        $html .= '</tr>';
      }

      // If there were answers, provide the heading
      if (count($rows) > 0) {
        $html = '
          <h3>Ausstehende Antworten</h3>
          <p>
            <a href="?page=' . $_GET['page'] . '&table=' . $_GET['table'] . '&export-unfilled=csv&type=utf8">Export als CSV (UTF-8)</a> | 
            <a href="?page=' . $_GET['page'] . '&table=' . $_GET['table'] . '&export-unfilled=csv&type=iso">Export als CSV (Excel)</a>
          </p>
          <table class="widefat">
            <thead><tr><th>' . implode('</th><th>', $columnInfo) . '</th></tr></thead>
            <tbody>' . $html . '</tbody>
            <tfoot><tr><th>' . implode('</th><th>', $columnInfo) . '</th></tr></tfoot>
          </table>
          <br>
        ';
      }
    }

    return $html;
  }

  /**
   * @param int $eventId the event id
   * @param array $columns the columns to be added
   * @return array the raw data of unfilled people
   */
  protected function getEventUnfilledData($eventId, &$columns)
  {
    // Get all the unanswered data sets
    $rows = $localMailData = array();
    $info = EventType::getSubscribeInfo($eventId);
    foreach ($info as $key => $record) {
      // Is the record even filled out?
      if (!isset($record['filled']) || !$record['filled'] && (isset($record['email']) && isset($record['list-id']))) {
        $row = array();
        // Create the subscription link from data
        $url = get_permalink($eventId);
        $url = Strings::attachParam('list', $record['list-id'], $url);
        $url = Strings::attachParam('ml', $key, $url);
        // File the basic data
        $row['email'] = $record['email'];
        $row['subscribe-link'] = $url;

        // See if there is more info in the list itself
        if (LocalMailService::isWorking()) {
          if (!isset($localMailData[$record['list-id']])) {
            $localMailData[$record['list-id']] = LocalMailService::getInstance()->getListData($record['list-id']);
          }

          // Check if the mail id exists for that record
          if (isset($localMailData[$record['list-id']][$key])) {
            foreach ($localMailData[$record['list-id']][$key] as $key => $value) {
              $row[$key] = $value;
              if (!isset($columns[$key])) {
                $columns[$key] = $key;
              }
            }
          }
        }

        $rows[] = $row;
      }
    }

    return $rows;
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
        'name' => 'Anmeldungen',
        'value' => 0
      ),
      'unsubscribed' => array(
        'name' => 'Abmeldungen',
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
      <script type="text/javascript" src="' . $path . '/js/data-table-backend.js?v=' . LbwpCore::REVISION . '"></script>
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
   * @param array $fields the fields available
   * @return array list of all possible cell keys
   */
  protected function getColumns($table, &$fields)
  {
    if (is_array($fields)) {
      return array_keys($table['fields']);
    }

    $columns = array();
    foreach ($table['data'] as $row) {
      foreach ($row as $key => $value) {
        if (!in_array($key, $columns)) {
          $columns[] = $key;
          // Make a fields array by ref, for backwards comp
          $fields[$key] = $key;
        }
      }
    }

    return $columns;
  }

  /**
   * @param array $data the actual data rows
   * @param array $fields key/value pair of field and its name
   * @return array array containing empty values for all fields
   */
  protected function getRawTable($data, $fields)
  {
    $rawData = array();

    if (is_array($data)) {
      foreach ($data as $row) {
        $rawRow = array();
        foreach ($fields as $key => $value) {
          if (isset($row[$key])) {
            $rawRow[$key] = $row[$key];
          } else {
            $rawRow[$key] = '';
          }
        }
        $rawData[] = $rawRow;
      }
    }

    return $rawData;
  }

  /**
   * @param int $formId controlled form id
   * @param string $name name of the table
   * @param array $columns cell keys
   * @param array $data table raw data
   * @param int $eventId the event id
   * @param array $fields actual field names
   */
  protected function runController($formId, $name, $columns, $data, $eventId = 0, $fields)
  {
    // Export the data
    if (isset($_POST['export'])) {
      if ($_POST['export'] == 'csv' && $_POST['type'] == 'utf8') {
        $this->sendCsv($name, $columns, $data, false, $formId, $eventId, $fields, false);
      }
      if ($_POST['export'] == 'csv' && $_POST['type'] == 'iso') {
        $this->sendCsv($name, $columns, $data, true, $formId, $eventId, $fields, true);
      }
    }

    if (isset($_GET['export-unfilled'])) {
      if ($_GET['export-unfilled'] == 'csv' && $_GET['type'] == 'utf8') {
        $this->sendCsvUnfilled($name, false, $formId, $eventId);
      }
      if ($_GET['export-unfilled'] == 'csv' && $_GET['type'] == 'iso') {
        $this->sendCsvUnfilled($name, true, $formId, $eventId);
      }
    }

    if (isset($_GET['newrow'])) {
      $this->backend->addEmptyTableEntry($formId, $eventId);
      header('location: ?page=' . $_GET['page'] . '&table=' . $_GET['table']);
      exit;
    }

    // Flush the data table and redirect
    if (isset($_GET['flushtable'])) {
      $this->backend->flushTable($formId, $eventId);
      header('location: ?page=' . $_GET['page'] . '&table=' . $_GET['table']);
      exit;
    }

    // Flush the data table and redirect
    if (isset($_GET['deletetable'])) {
      $this->backend->deleteTable($formId, $eventId);
      header('location: ?page=' . $_GET['page']);
      exit;
    }
  }

  /**
   * Download a table of unfilled data records
   * @param string $name name of the table
   * @param bool $utf8decode utf8 decoding
   * @param int $formId the form id, to check for an event
   * @param int $eventId eventual event id
   */
  protected function sendCsvUnfilled($name, $utf8decode, $formId, $eventId)
  {
    // Initialize column info and get the raw data
    $columns = array('email' => 'email');
    $data = $this->getEventUnfilledData($eventId, $columns);
    $columns['subscribe-link'] = 'subscribe-link';

    // Export as actual csv with the existing method, but no event id
    $this->sendCsv('unfilled-' . $name, $columns, $data, $utf8decode, 0, 0, array(), false);
  }

  /**
   * Download a table as CSV (Exits after output)
   * @param string $name name of the table
   * @param array $columns cell keys
   * @param array $data table raw data
   * @param bool $utf8decode utf8 decoding
   * @param int $formId the form id, to check for an event
   * @param int $eventId eventual event id
   * @param array $fields translated field names
   * @param bool $preventFormulas prevent excel from making formulas from values
   */
  protected function sendCsv($name, $columns, $data ,$utf8decode, $formId, $eventId, $fields, $preventFormulas)
  {
    ob_end_clean();
    $filename = Strings::forceSlugString($name) . '.csv';
    $fields = ArrayManipulation::forceArray($fields);
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);
    $outstream = fopen("php://output", 'w');

    // Newly sort columns by the order given
    if (isset($_POST['columns']) && is_array($_POST['columns']) && count($_POST['columns'])) {
      $columns = array_map(array('\LBWP\Util\Strings', 'forceSlugString'), $_POST['columns']);
    }

    // Convert fields, if needed to ascii
    if ($utf8decode && count($fields) > 0) {
      foreach ($fields as $key => $value) {
        $fields[$key] = utf8_decode($value);
      }
    }

    // Translate column names if possible
    $printedColumns = array();
    foreach ($columns as $cellKey) {
      if (isset($fields[$cellKey])) {
        $printedColumns[] = $fields[$cellKey];
      } else {
        $printedColumns[] = $cellKey;
      }
    }

    // Print the fields
    fputcsv($outstream, $printedColumns, ';', '"');
    // Print the data
    foreach ($data as $row) {
      fputcsv($outstream, $this->preparePrintedRow($row, $columns, $utf8decode, $preventFormulas), ';', '"');
    }

    // If there is an event, output a newline and the summary for each line
    if ($eventId > 0) {
      $summary = $this->getRawEventSummary($eventId);
      fputcsv($outstream, array(), ';', '"');
      foreach ($summary as $sum) {
        fputcsv($outstream, $sum, ';', '"');
      }
    }

    // If the customer wants unfilled data as well, add it
    if ($eventId > 0 && apply_filters('DataTable_include_unfilled_in_export', false)) {
      $columns = array('email' => 'email');
      $data = $this->getEventUnfilledData($eventId, $columns);
      $columns['subscribe-link'] = 'subscribe-link';
      fputcsv($outstream, array(), ';', '"');
      fputcsv($outstream, $columns, ';', '"');
      foreach ($data as $row) {
        fputcsv($outstream, $this->preparePrintedRow($row, $columns, $utf8decode, $preventFormulas), ';', '"');
      }
    }

    fclose($outstream);
    exit;
  }

  /**
   * @param $row
   * @param $columns
   * @param $utf8decode
   * @param $preventFormulas
   * @return array
   */
  protected function preparePrintedRow($row, $columns, $utf8decode, $preventFormulas)
  {
    $printedRow = array();
    foreach ($columns as $key) {
      $value = $row[$key];
      if ($preventFormulas && in_array($value[0], $this->excelFormulaChars)) {
        $value = "\t" . $value;
      }
      if ($utf8decode) {
        $printedRow[$key] = utf8_decode($value);
      } else {
        $printedRow[$key] = $value;
      }
    }

    return $printedRow;
  }
} 