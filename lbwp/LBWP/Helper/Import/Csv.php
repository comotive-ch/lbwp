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
   * @return array|bool data table array or false
   */
  public static function getArray($file, $delimiter = ';', $enclosure = '"', $skipFirst = false)
  {
    if (!file_exists($file) || !is_readable($file)) {
      return false;
    }

    $csvData = array();
    if (($handle = fopen($file, 'r')) !== false) {
      while (($data = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
        $csvData[] = $data;
      }
      fclose($handle);
    }

    if ($skipFirst) {
      unset($csvData[0]);
    }

    return $csvData;
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
    header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);
    $outstream = fopen("php://output", 'w');
    // Print the fields
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
      fputcsv($outstream, $row, $delimiter, $escapeChar);
    }
    fclose($outstream);
    exit;
  }
} 