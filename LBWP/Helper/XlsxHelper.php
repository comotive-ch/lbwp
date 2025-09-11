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