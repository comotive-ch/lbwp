<?php

namespace LBWP\Module\Forms\Component\ActionBackend;

use LBWP\Util\String;
use LBWP\Util\WordPress;
use LBWP\Module\Forms\Component\ActionBackend\DataTable as DataTableBackend;

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

    // Run controller
    $this->runController($formId, $tableName, $columns, $rawTable);

    // Return HTML code
    return '
      <div class="wrap">
        <h2>Datenspeicher ' . $tableName . '</h2>
        ' . $this->getUserOptions($formId) . '<br />
        ' . $this->getTableHtml($columns, $rawTable) . '<br />
        <br class="clear">
      </div>
    ';
  }

  /**
   * @param int $formId display various user options
   * @return string html code for options
   */
  protected function getUserOptions($formId)
  {
    return '
      <ul class="subsubsub">
        <li><a href="?page=' . $_GET['page'] . '&flushtable" onclick="return confirm(\'Tabelle wirklich leeren?\')">Tabelle leeren</a> |</li>
        <li><a href="?page=' . $_GET['page'] . '&deletetable" onclick="return confirm(\'Tabelle wirklich löschen?\')">Tabelle löschen</a> |</li>
        <li><a href="?page=' . $_GET['page'] . '&export=csv&type=utf8">Export als CSV (UTF-8)</a> |</li>
        <li><a href="?page=' . $_GET['page'] . '&export=csv&type=iso">Export als CSV (Excel)</a></li>
      </ul>
    ';
  }

  /**
   * @param array $columns the colum names
   * @param array $data the data to display
   * @return string html code
   */
  protected function getTableHtml($columns, $data)
  {
    $html = '<table class="widefat fixed">';

    // If there is no data, show it
    if (count($columns) == 0) {
      $columns[] = 'Keine Daten';
      $data[0]['Keine Daten'] = 'Es befinden sich noch keine Daten in der Tabelle.';
    }

    // Table header
    $htmlColumns = '';
    foreach ($columns as $colName) {
      $htmlColumns .= '<th class="manage-column"><strong>' . $colName . '</strong></th>';
    }

    // Add the content to foot and head
    $html .= '
      <thead><tr>' . $htmlColumns . '</tr></thead>
      <tfoot><tr>' . $htmlColumns . '</tr></tfoot>
    ';

    // Add the actual data
    $html .= '<tbody>';
    foreach ($data as $row) {
      $html .= '<tr' . $this->getAltClass() . '>';
      foreach ($row as $key => $value) {
        $html .= '<td class="' . $key . '">' . $this->prepareValue($value) . '</td>';
      }
      $html .= '</tr>';
    }

    // Close the body and table and return
    return $html . '</tbody></table>';
  }

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
    $value = String::chopString($value, 200, false);
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
   */
  protected function runController($formId, $name, $columns, $data)
  {
    // Export the data
    if (isset($_GET['export'])) {
      if ($_GET['export'] == 'csv' && $_GET['type'] == 'utf8') {
        $this->sendCsv($name, $columns, $data, false);
      }
      if ($_GET['export'] == 'csv' && $_GET['type'] == 'iso') {
        $this->sendCsv($name, $columns, $data, true);
      }
    }

    // Flush the data table and redirect
    if (isset($_GET['flushtable'])) {
      $this->backend->flushTable($formId);
      header('location: ?page=' . $_GET['page']);
      exit;
    }

    // Flush the data table and redirect
    if (isset($_GET['deletetable'])) {
      $this->backend->deleteTable($formId);
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
   */
  protected function sendCsv($name, $columns, $data ,$utf8decode)
  {
    ob_end_clean();
    $filename = String::forceSlugString($name) . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);
    $outstream = fopen("php://output", 'w');
    // Print the fields
    fputcsv($outstream, $columns, ';', '"');
    // Print the data
    foreach ($data as $row) {
      // Crazy utf-8 decoding since excel doesn't know that by default
      foreach ($row as $key => $value) {
        $value = str_replace(array("\n","\r"),'',$value);
        if ($utf8decode) {
          $row[$key] = utf8_decode($value);
        } else {
          $row[$key] = $value;
        }
      }
      fputcsv($outstream, $row, ';', '"');
    }
    fclose($outstream);
    exit;
  }
} 