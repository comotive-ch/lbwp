<?php

namespace LBWP\Helper\Import;

use LBWP\Util\Strings;

/**
 * Wrapper to load content from a CSV file and convert encoding
 * @package LBWP\Helper\Import
 * @author Michael Sebel <michael@comotive.ch>
 */
class Csv {

  /**
   * @param string $file the file name
   * @param string $delimiter the delimiter to use
   * @param string $enclosure the enclosures to use
   * @param bool $skipFirst skip the first line
   * @param bool $filter uses array filter on the lines
   * @return array|bool data table array or false
   */
  public static function getArray($file, $delimiter = ';', $enclosure = '"', $skipFirst = false, $filter = false)
  {
    if (!file_exists($file) || !is_readable($file)) {
      return false;
    }

    $csvData = array();
    if (($handle = fopen($file, 'r')) !== false) {
      while (($data = fgets($handle)) !== false) {
        if ($filter) {
          $csvData[] = array_filter(str_getcsv($data, $delimiter, $enclosure));
        } else {
          $csvData[] = str_getcsv($data, $delimiter, $enclosure);
        }
      }
      fclose($handle);
    }

    if ($skipFirst) {
      unset($csvData[0]);
    }

    return $csvData;
  }

  public static function getArrayFromString($string, $delimiter = ';', $enclosure = '"', $skipFirst = false, $filter = false)
  {
    $csvData = array();
    foreach (explode(PHP_EOL, $string) as $line) {
      if ($filter) {
        $csvData[] = array_filter(str_getcsv($line, $delimiter, $enclosure));
      } else {
        $csvData[] = str_getcsv($line, $delimiter, $enclosure);
      }
    }

    if ($skipFirst) {
      unset($csvData[0]);
    }

    return $csvData;
  }

  /**
   * @param string $file
   * @return bool
   */
  public static function assumeDelimiter($file)
  {
    if (!file_exists($file) || !is_readable($file)) {
      return false;
    }

    $raw = file_get_contents($file);
    // Count semicolons and commas
    $semicolons = substr_count($raw, ';');
    $commas = substr_count($raw, ',');

    return ($semicolons > $commas) ? ';' : ',';
  }

  /**
   * @param array $data
   */
  public static function convertNonUtf8Data(&$data)
  {
    foreach ($data as $rowIndex => $row) {
      foreach ($row as $colIndex => $cell) {
        $data[$rowIndex][$colIndex] = utf8_encode($cell);
      }
    }
  }

  /**
   * @param string $raw data
   * @param bool $single true checks only for the first line
   * @return string
   */
  public static function guessDelimiter($raw, $single)
  {
    // Use only a single line for cases where the actual content has more commas in the content then semicolons to delimitr
    if ($single) {
      $raw = explode(PHP_EOL, $raw)[0];
    }

    $stats =  array();
    $stats['semicolon'] = substr_count($raw, ';');
    $stats['comma'] = substr_count($raw, ',');
    $stats['tab'] = substr_count($raw, "\t");

    // Get the key with the most characters
    asort($stats, SORT_NUMERIC);
    $stats = array_reverse($stats, true);
    $keys = array_keys($stats);
    $key = array_shift($keys);

    // Select the key
    if ($key === 'comma') {
      return ',';
    } else if ($key === 'tab') {
      return "\t";
    }

    return ';';
  }


  /**
   * @param $data
   * @param string $filename
   * @param string $delimiter
   * @param string $escapeChar
   * @param bool $utf8decode
   */
  public static function downloadFile($data, $filename = 'download', $delimiter = ';', $escapeChar = '"', $utf8decode = true)
  {
    ob_end_clean();
    $filename = Strings::forceSlugString($filename) . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Type: text/csv; charset=' . get_option('blog_charset'), true);
    $outstream = fopen("php://output", 'w');
    // Print the fields
    // Print the data
    foreach ($data as $row) {
      // Crazy utf-8 decoding since excel doesn't know that by default
      foreach ($row as $key => $value) {
        $value = str_replace("\r\n",', ',$value);
        if ($utf8decode) {
          $row[$key] = utf8_decode($value);
        } else {
          $row[$key] = $value;
        }
      }
      fputcsv($outstream, $row, $delimiter, $escapeChar);
    }
    fclose($outstream);
    exit;
  }

  /**
   * @param $data array the data for the excel file
   * @param $filename
   * @return void
   */
  public static function downloadExcel($data, $filename = 'download'){
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';
    $excelDoc = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $excelDoc->getActiveSheet();
    $filename .= '.xlsx';

    // write the rows
    $sheet->fromArray($data);

    // Resize cells, becaus ***pretty***
    foreach ($sheet->getColumnIterator() as $column) {
      $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
    }

    $maxWidth = 100;
    $sheet->calculateColumnWidths();
    foreach ($sheet->getColumnDimensions() as $colDim) {
      if (!$colDim->getAutoSize()) {
        continue;
      }
      $colWidth = $colDim->getWidth();
      if ($colWidth > $maxWidth) {
        $colDim->setAutoSize(false);
        $colDim->setWidth($maxWidth);
      }
    }

    ob_end_clean();
    // write and save the file
    //header('Content-Type: application/vnd.ms-excel;');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($excelDoc);
    $writer->save('php://output');
    exit();
  }

  /**
   * @param $data
   * @param $path
   * @param $delimiter
   * @param $escapeChar
   * @param $utf8decode
   * @return void
   */
  public static function write($data, $path, $delimiter = ';', $escapeChar = '"', $utf8decode = false)
  {
    $outstream = fopen($path, 'w');
    foreach ($data as $row) {
      // Crazy utf-8 decoding since excel doesn't know that by default
      foreach ($row as $key => $value) {
        $value = str_replace("\r\n",', ',$value);
        if ($utf8decode) {
          $row[$key] = utf8_decode($value);
        } else {
          $row[$key] = $value;
        }
      }
      fputcsv($outstream, $row, $delimiter, $escapeChar);
    }
    fclose($outstream);
  }
} 