<?php

namespace LBWP\Helper;

use LBWP\Util\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Helper to use PhpSpreadsheet for common tasks
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class XlsxHelper
{
  /**
   * @var Spreadsheet
   */
  protected $file = null;

  /**
   * @param $filename
   * @return void
   * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
   */
  public function read($filename)
  {
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';
    $reader = IOFactory::createReader('Xlsx');
    $this->file = $reader->load($filename);
  }

  /**
   * @param int|string $index index or name of sheet
   * @param bool $trim
   * @param bool $skipFirst
   * @return array
   */
  public function getSheetData($index, $trim = false, $filter = false, $skipFirst = false)
  {
    $data = $this->file->getSheet($index)->toArray();
    if ($skipFirst) {
      // Remove first row and reindex to be starting at 0
      unset($data[0]);
      $data = array_values($data);
    }

    if ($trim) {
      foreach ($data as $key => $value) {
        $data[$key] = array_map('trim', $value);
      }
    }

    if ($filter) {
      $data = array_filter($data);
      foreach ($data as $key => $value) {
        if (strlen(implode('', $value)) == 0) {
          unset($data[$key]);
        }
      }
      $data = array_values($data);
    }

    return $data;
  }

  /**
   * Generate an XLSX file from a plain PHP array and stream it as a browser download.
   * @param array  $headers Column header labels for the first row
   * @param array  $rows    Two-dimensional array of data rows
   * @param string $filename Suggested download filename including .xlsx extension
   * @return void
   */
  public static function downloadFromArray(array $headers, array $rows, string $filename): void
  {
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->fromArray([$headers], null, 'A1');
    if (!empty($rows)) {
      $sheet->fromArray($rows, null, 'A2');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
  }

  /**
   * @param string $pointer name of file in $_FILES
   * @return false|string path to moved file
   */
  public function prepareFile($pointer)
  {
    $result = false;
    $file = $_FILES[$pointer];
    if ($file['error'] == 0 && is_readable($file['tmp_name'])) {
      $folder = File::getNewUploadFolder();
      $result = $folder . $file['name'];
      move_uploaded_file($file['tmp_name'], $result);
    }

    return $result;
  }
} 