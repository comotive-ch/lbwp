<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Cronjob;
use LBWP\Helper\XlsxHelper;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;
use LBWP\Util\NativeWpImport;

/**
 * PIM Import: admin page for uploading XLSX files, converting to CSV,
 * uploading to S3 and running background import via cron.
 * @package LBWP\ERP\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class Import extends ACFBase
{
  const PIM_TYPE_SLUG = 'lbwp-pid';
  const OPTION_KEY = 'current_pimport';
  const CRON_IDENTIFIER = 'pimport_run';
  /**
   * Datetime columns in wp_posts that need date format normalization
   */
  const DATETIME_COLUMNS = array(
    'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt'
  );

  /**
   * Initialize the backend component
   */
  public function init()
  {
    add_action('admin_menu', array($this, 'registerMenu'));
    add_action('cron_job_' . self::CRON_IDENTIFIER, array($this, 'runImport'));
  }

  /**
   * No blocks needed here
   */
  public function blocks()
  {
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
  }

  /**
   * Register the PIMport admin menu and submenu
   */
  public function registerMenu()
  {
    add_menu_page(
      'PIMport',
      'PIMport',
      'edit_pages',
      'pimport',
      null,
      'dashicons-database-import',
      58
    );

    add_submenu_page(
      'pimport',
      'Import',
      'Import',
      'edit_pages',
      'pimport',
      array($this, 'renderImportPage')
    );
  }

  /**
   * Render the import admin page
   */
  public function renderImportPage()
  {
    $message = '';

    // Handle file upload or reset
    if (isset($_POST['pimport_upload']) && isset($_FILES['pimport_file'])) {
      $message = $this->handleUpload();
    } elseif (isset($_POST['pimport_reset'])) {
      $message = $this->handleReset();
    }

    // Load current import state
    $current = get_option(self::OPTION_KEY, array());

    echo '<div class="wrap">';
    echo '<h1>PIMport</h1>';

    if (!empty($message)) {
      echo $message;
    }

    // Show current import status if available
    if (!empty($current) && !empty($current['file'])) {
      echo '<h2>Aktueller Import</h2>';
      echo '<table class="widefat fixed" style="max-width: 600px;">';
      echo '<tr><th>Datei</th><td>' . esc_html($current['file']) . '</td></tr>';
      echo '<tr><th>Hochgeladen</th><td>' . esc_html($current['uploaded']) . '</td></tr>';
      echo '<tr><th>Status</th><td>' . esc_html($current['status']) . '</td></tr>';
      echo '<tr><th>Datensätze</th><td>' . intval($current['records_imported']) . ' / ' . intval($current['records_total']) . '</td></tr>';
      echo '<tr><th>Zuletzt aktualisiert</th><td>' . esc_html($current['last_updated']) . '</td></tr>';
      echo '</table>';
      if (in_array($current['status'], array('pending', 'running'))) {
        echo '<p><em>Seite aktualisiert sich automatisch alle 15 Sekunden</em></p>';
        echo '<script>setTimeout(function(){ location.reload(); }, 15000);</script>';
      }
      echo '<form method="post" style="margin-top: 10px;">';
      echo '<input type="submit" name="pimport_reset" class="button-secondary" value="Import zurücksetzen und Datei löschen" onclick="return confirm(\'Sind Sie sicher? Die Import-Datei wird gelöscht.\');" />';
      echo '</form>';
      echo '<br>';
    }

    // Upload form
    echo '<h2>XLSX / CSV hochladen</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pimport_file">XLSX / CSV Datei</label></th>';
    echo '<td><input type="file" name="pimport_file" id="pimport_file" accept=".xlsx,.csv" /></td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pimport_upload" class="button-primary" value="Hochladen und Import starten" /></p>';
    echo '</form>';
    echo '</div>';
  }

  /**
   * Handle the uploaded XLSX/CSV file: normalize to semicolon UTF-8 CSV, upload to S3, store option, register cron
   * @return string HTML message
   */
  protected function handleUpload()
  {
    ini_set('memory_limit', '2G');
    set_time_limit(300);
    $file = $_FILES['pimport_file'];

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
      return '<div class="notice notice-error"><p>Upload fehlgeschlagen. Bitte erneut versuchen.</p></div>';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('xlsx', 'csv'))) {
      return '<div class="notice notice-error"><p>Es sind nur .xlsx und .csv Dateien erlaubt.</p></div>';
    }

    if ($ext === 'xlsx') {
      $csvPath = $this->convertXlsxToCsv($file);
    } else {
      $csvPath = $this->normalizeCsv($file);
    }

    if (!$csvPath) {
      return '<div class="notice notice-error"><p>Die hochgeladene Datei konnte nicht verarbeitet werden.</p></div>';
    }

    // Count records for status tracking (stream-based, no full file load)
    $recordCount = 0;
    $countHandle = fopen($csvPath, 'r');
    if ($countHandle) {
      while (fgets($countHandle) !== false) {
        $recordCount++;
      }
      fclose($countHandle);
      $recordCount = max(0, $recordCount - 1); // subtract header
    }

    // Upload CSV to S3
    $s3 = LbwpCore::getModule('S3Upload');
    $s3Url = $s3->uploadDiskFile($csvPath, 'text/csv');

    // Clean up local file
    @unlink($csvPath);

    if (empty($s3Url)) {
      return '<div class="notice notice-error"><p>S3-Upload fehlgeschlagen.</p></div>';
    }

    // Store import state
    $csvFilename = pathinfo($file['name'], PATHINFO_FILENAME) . '.csv';
    update_option(self::OPTION_KEY, array(
      'file' => $csvFilename,
      'url' => $s3Url,
      'status' => 'pending',
      'uploaded' => date('Y-m-d H:i:s'),
      'last_updated' => date('Y-m-d H:i:s'),
      'log' => array(),
      'records_total' => $recordCount,
      'records_imported' => 0
    ), false);

    // Register immediate background cron
    Cronjob::register(array(
      time() => self::CRON_IDENTIFIER
    ));

    return '<div class="notice notice-success"><p>Datei hochgeladen, Import wird im Hintergrund gestartet.</p></div>';
  }

  /**
   * Delete the S3 file and clear the import option
   * @return string HTML message
   */
  protected function handleReset()
  {
    $current = get_option(self::OPTION_KEY, array());

    // Delete file from S3 if URL is set
    if (!empty($current['url'])) {
      $s3 = LbwpCore::getModule('S3Upload');
      $s3->deleteFile($current['url']);
    }

    delete_option(self::OPTION_KEY);
    return '<div class="notice notice-success"><p>Import wurde zurückgesetzt und die Datei gelöscht.</p></div>';
  }

  /**
   * Convert uploaded XLSX to semicolon-delimited UTF-8 CSV
   * @param array $file $_FILES entry
   * @return string|false path to CSV file or false on failure
   */
  protected function convertXlsxToCsv($file)
  {
    $xlsx = new XlsxHelper();
    $localXlsx = $xlsx->prepareFile('pimport_file');
    if (!$localXlsx) {
      return false;
    }

    $xlsx->read($localXlsx);
    $data = $xlsx->getSheetData(0, true, true);
    @unlink($localXlsx);

    $csvPath = File::getNewUploadFolder() . pathinfo($file['name'], PATHINFO_FILENAME) . '.csv';
    $csvHandle = fopen($csvPath, 'w');
    if (!$csvHandle) {
      return false;
    }

    // Write BOM for Excel compatibility, use semicolon delimiter
    fwrite($csvHandle, "\xEF\xBB\xBF");
    foreach ($data as $row) {
      fputcsv($csvHandle, $row, ';');
    }
    fclose($csvHandle);

    return $csvPath;
  }

  /**
   * Normalize an uploaded CSV to semicolon-delimited UTF-8
   * @param array $file $_FILES entry
   * @return string|false path to normalized CSV file or false on failure
   */
  protected function normalizeCsv($file)
  {
    $tmpPath = $file['tmp_name'];
    if (!is_readable($tmpPath)) {
      return false;
    }

    // Read only the first 8KB to detect encoding and delimiter
    $handle = fopen($tmpPath, 'r');
    $sample = fread($handle, 8192);
    fclose($handle);

    // Strip BOM from sample for detection
    $sampleClean = ltrim($sample, "\xEF\xBB\xBF");

    // Detect encoding from sample only
    $encoding = mb_detect_encoding($sampleClean, array('UTF-8', 'ISO-8859-1', 'Windows-1252'), true);
    $needsConversion = ($encoding && $encoding !== 'UTF-8');

    // Detect delimiter from first line of sample
    $firstLine = strtok($sampleClean, "\n");
    $commaCount = substr_count($firstLine, ',');
    $semicolonCount = substr_count($firstLine, ';');
    $tabCount = substr_count($firstLine, "\t");
    $currentDelimiter = ';';
    if ($commaCount > $semicolonCount && $commaCount > $tabCount) {
      $currentDelimiter = ',';
    } elseif ($tabCount > $semicolonCount && $tabCount > $commaCount) {
      $currentDelimiter = "\t";
    }

    // Fast path: already UTF-8 semicolon — just move the file, no processing needed
    if (!$needsConversion && $currentDelimiter === ';') {
      $csvPath = File::getNewUploadFolder() . $file['name'];
      move_uploaded_file($tmpPath, $csvPath);
      return $csvPath;
    }

    // Slow path: need to convert encoding and/or delimiter
    $csvPath = File::getNewUploadFolder() . $file['name'];
    $sourceHandle = fopen($tmpPath, 'r');
    $csvHandle = fopen($csvPath, 'w');
    if (!$sourceHandle || !$csvHandle) {
      return false;
    }

    // Skip BOM in source if present
    $bom = fread($sourceHandle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($sourceHandle);
    }

    fwrite($csvHandle, "\xEF\xBB\xBF");
    while (($row = fgetcsv($sourceHandle, 0, $currentDelimiter)) !== false) {
      if ($needsConversion) {
        $row = array_map(function($v) use ($encoding) {
          return mb_convert_encoding($v, 'UTF-8', $encoding);
        }, $row);
      }
      fputcsv($csvHandle, $row, ';');
    }

    fclose($sourceHandle);
    fclose($csvHandle);

    return $csvPath;
  }

  /**
   * Normalize a post column value (e.g. datetime format)
   * @param string $key column name
   * @param string $value raw value from CSV
   * @return string normalized value
   */
  protected function normalizePostValue($key, $value)
  {
    if (in_array($key, self::DATETIME_COLUMNS)) {
      return $this->normalizeDatetime($value);
    }

    return $value;
  }

  /**
   * Convert various date formats to MySQL datetime (Y-m-d H:i:s)
   * @param string $value raw date string
   * @return string MySQL datetime or original value if unparseable
   */
  protected function normalizeDatetime($value)
  {
    if (empty($value)) {
      return '0000-00-00 00:00:00';
    }

    // Already in MySQL format
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
      return $value;
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
      return date('Y-m-d H:i:s', $timestamp);
    }

    return $value;
  }

  /**
   * Normalize a meta value for ACF compatibility
   * @param string $value raw value from CSV
   * @return string normalized value
   */
  protected function normalizeMetaValue($value)
  {
    // ACF true_false fields expect 1/0
    if ($value === 'True') {
      return '1';
    }
    if ($value === 'False') {
      return '0';
    }

    return $value;
  }

  /**
   * Delete the import CSV from S3
   * @param string $url the S3 URL
   */
  protected function deleteS3File($url)
  {
    if (!empty($url)) {
      $s3 = LbwpCore::getModule('S3Upload');
      $s3->deleteFile($url);
    }
  }

  /**
   * Build a SKU => post_id lookup map via direct DB query
   * @param NativeWpImport $importer
   * @return array
   */
  protected function buildSkuMap($importer)
  {
    $db = $importer->getDb();
    $prefix = $importer->getPrefix();
    $result = $db->query(
      "SELECT pm.meta_value AS sku, pm.post_id
       FROM `{$prefix}postmeta` pm
       INNER JOIN `{$prefix}posts` p ON p.ID = pm.post_id
       WHERE pm.meta_key = 'sku' AND p.post_type = '" . self::PIM_TYPE_SLUG . "'"
    );

    $map = array();
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $map[$row['sku']] = (int)$row['post_id'];
      }
      $result->free();
    }

    return $map;
  }

  /**
   * Background cron handler — performs the actual import
   */
  public function runImport()
  {
    ini_set('memory_limit', '2G');
    set_time_limit(0);

    $current = get_option(self::OPTION_KEY, array());
    if (empty($current) || empty($current['url'])) {
      return;
    }

    // Update status to running
    $current['status'] = 'running';
    update_option(self::OPTION_KEY, $current, false);

    // Download CSV from S3
    $csvData = file_get_contents($current['url']);
    if ($csvData === false) {
      $current['status'] = 'error';
      $current['log'][] = 'CSV-Datei konnte nicht von S3 heruntergeladen werden.';
      update_option(self::OPTION_KEY, $current, false);
      $this->deleteS3File($current['url']);
      return;
    }

    // Strip BOM
    $csvData = ltrim($csvData, "\xEF\xBB\xBF");

    // Parse CSV from string
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csvData);
    rewind($handle);

    // Read header row
    $headers = fgetcsv($handle, 0, ';');
    if (!$headers || empty($headers)) {
      fclose($handle);
      $current['status'] = 'error';
      $current['log'][] = 'CSV-Datei enthält keine Header-Zeile.';
      update_option(self::OPTION_KEY, $current, false);
      $this->deleteS3File($current['url']);
      return;
    }

    // Determine lookup column (first column must be "sku" or "ID")
    $lookupColumn = strtolower(trim($headers[0]));
    $postsColumnsFlipped = array_flip(NativeWpImport::POSTS_COLUMNS);

    // Init NativeWpImport
    global $table_prefix;
    $importer = new NativeWpImport(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, $table_prefix);
    $importer->setAutoCommitThreshold(500);
    $importer->beginTransaction();

    // Build SKU map if lookup is by SKU
    $skuMap = array();
    if ($lookupColumn === 'sku') {
      $skuMap = $this->buildSkuMap($importer);
    }

    // Process rows
    $imported = 0;
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
      if (count($row) !== count($headers)) {
        continue;
      }

      $record = array_combine($headers, $row);
      $post = array();
      $meta = array();

      // Resolve existing post ID
      if ($lookupColumn === 'id' && !empty($record['ID'])) {
        $post['ID'] = (int)$record['ID'];
      } elseif ($lookupColumn === 'sku' && !empty($record['sku'])) {
        if (isset($skuMap[$record['sku']])) {
          $post['ID'] = $skuMap[$record['sku']];
        }
      }

      // Split fields into post columns vs. meta
      foreach ($record as $key => $value) {
        if ($key === 'ID') {
          continue;
        }
        if (isset($postsColumnsFlipped[$key])) {
          $post[$key] = $this->normalizePostValue($key, $value);
        } else {
          $meta[$key] = $this->normalizeMetaValue($value);
        }
      }

      // Default post_type for new posts
      if (!isset($post['ID']) && !isset($post['post_type'])) {
        $post['post_type'] = self::PIM_TYPE_SLUG;
      }

      // Make sure to have defaults that are absolutely needed
      $this->setPostDefaults($post);

      $newId = $importer->update($post, $meta);

      // If this was a new post with SKU, add to map for potential duplicates in same file
      if (!isset($post['ID']) && $lookupColumn === 'sku' && !empty($record['sku'])) {
        $skuMap[$record['sku']] = $newId;
      }

      $imported++;

      // Update progress every 100 records
      if ($imported % 100 === 0) {
        $current['records_imported'] = $imported;
        $current['last_updated'] = date('Y-m-d H:i:s');
        update_option(self::OPTION_KEY, $current, false);
      }
    }

    fclose($handle);
    $importer->commitTransaction();
    unset($importer);

    // Remove CSV from S3 and update status to completed
    $this->deleteS3File($current['url']);
    $current['status'] = 'completed';
    $current['records_imported'] = $imported;
    $current['last_updated'] = date('Y-m-d H:i:s');
    update_option(self::OPTION_KEY, $current, false);
  }

  /**
   * Set default values for posts if not given
   */
  protected function setPostDefaults(&$post)
  {
    if (!isset($post['post_content']))
      $post['post_content'] = '';
    if (!isset($post['post_content_filtered']))
      $post['post_content_filtered'] = '';
    if (!isset($post['post_excerpt']))
      $post['post_excerpt'] = '';
    if (!isset($post['to_ping']))
      $post['to_ping'] = '';
    if (!isset($post['pinged']))
      $post['pinged'] = '';
    if (!isset($post['post_status']))
      $post['post_status'] = 'publish';
  }
}
