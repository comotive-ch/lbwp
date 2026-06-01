<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Cronjob;
use LBWP\Helper\XlsxHelper;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;
use LBWP\Util\NativeWpImport;
use mysqli;

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
    $pgImport = new ProductGroupImport();

    if (isset($_POST['pimport_upload']) && isset($_FILES['pimport_file'])) {
      $message = $this->handleUpload();
    } elseif (isset($_POST['pimport_reset'])) {
      $message = $this->handleReset();
    } elseif (isset($_POST['pg_tree_upload'])) {
      $message = $pgImport->handleCategoryTreeUpload();
    } elseif (isset($_POST['pg_assign_upload'])) {
      $message = $pgImport->handleAssignmentUpload();
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
    echo '<h2>Produkt Stammdaten importieren</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pimport_file">XLSX / CSV Datei</label></th>';
    echo '<td><input type="file" name="pimport_file" id="pimport_file" accept=".xlsx,.csv" /></td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pimport_upload" class="button-primary" value="Hochladen und Import starten" /></p>';
    echo '</form><hr>';

    $pgImport->renderForms();

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
  protected function normalizeMetaValue(string $value): string
  {
    $value = trim($value, '"');

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
  public function runImport(): void
  {
    ini_set('memory_limit', '2G');
    set_time_limit(0);

    $current = get_option(self::OPTION_KEY, array());
    if (empty($current) || empty($current['url'])) {
      return;
    }

    $current['status'] = 'running';
    update_option(self::OPTION_KEY, $current, false);

    $csvData = file_get_contents($current['url']);
    if ($csvData === false) {
      $current['status'] = 'error';
      $current['log'][] = 'CSV-Datei konnte nicht von S3 heruntergeladen werden.';
      update_option(self::OPTION_KEY, $current, false);
      $this->deleteS3File($current['url']);
      return;
    }

    $csvData = ltrim($csvData, "\xEF\xBB\xBF");

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csvData);
    rewind($handle);
    unset($csvData);

    $headers = fgetcsv($handle, 0, ';');
    if (!$headers || empty($headers)) {
      fclose($handle);
      $current['status'] = 'error';
      $current['log'][] = 'CSV-Datei enthält keine Header-Zeile.';
      update_option(self::OPTION_KEY, $current, false);
      $this->deleteS3File($current['url']);
      return;
    }

    $headers = array_map('trim', $headers);
    $lookupColumn = strtolower($headers[0]);
    $postsColumnsFlipped = array_flip(NativeWpImport::POSTS_COLUMNS);

    global $table_prefix;
    $importer = new NativeWpImport(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, $table_prefix);
    $importer->setAutoCommitThreshold(500);

    $skuMap = array();
    if ($lookupColumn === 'sku') {
      $skuMap = $this->buildSkuMap($importer);
    }

    $relationSlugs = $this->loadRelationSlugs();
    $pendingRelations = [];
    $bufferDb = null;

    if (!empty($relationSlugs)) {
      $bufferDb = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
      $bufferDb->set_charset('utf8mb4');
      $this->prepareRelationsBuffer($bufferDb, $table_prefix);
    }

    $importer->beginTransaction();

    $imported = 0;

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
      if (count($row) !== count($headers)) {
        continue;
      }

      $record = array_combine($headers, $row);
      $post = array();
      $meta = array();
      $relationData = [];

      if ($lookupColumn === 'id' && !empty($record['ID'])) {
        $post['ID'] = (int)$record['ID'];
      } elseif ($lookupColumn === 'sku' && !empty($record['sku'])) {
        if (isset($skuMap[$record['sku']])) {
          $post['ID'] = $skuMap[$record['sku']];
        }
      }

      foreach ($record as $key => $value) {
        if ($key === 'ID') {
          continue;
        }
        if (!empty($relationSlugs) && isset($relationSlugs[$key])) {
          $skus = array_values(array_filter(array_map('trim', explode(';', $value))));
          if (!empty($skus)) {
            $relationData[$key] = $skus;
          }
        } elseif (isset($postsColumnsFlipped[$key])) {
          $post[$key] = $this->normalizePostValue($key, $value);
        } else {
          $meta[$key] = $this->normalizeMetaValue($value);
        }
      }

      if (!isset($post['ID']) && !isset($post['post_type'])) {
        $post['post_type'] = self::PIM_TYPE_SLUG;
      }

      $this->setPostDefaults($post);

      $newId = $importer->update($post, $meta);

      if (!isset($post['ID']) && $lookupColumn === 'sku' && !empty($record['sku'])) {
        $skuMap[$record['sku']] = $newId;
      }

      foreach ($relationData as $slug => $skus) {
        $pendingRelations[$slug][$newId] = $skus;
      }

      $imported++;

      if ($imported % 100 === 0) {
        $current['records_imported'] = $imported;
        $current['last_updated'] = date('Y-m-d H:i:s');
        update_option(self::OPTION_KEY, $current, false);
      }
    }

    fclose($handle);
    $importer->commitTransaction();

    if (!empty($pendingRelations) && $bufferDb !== null) {
      $this->flushRelationsToBuffer($bufferDb, $pendingRelations, $table_prefix);
      $this->resolveAndWriteRelations($importer, $bufferDb, $skuMap, $relationSlugs, $table_prefix);
      $bufferDb->close();
    }

    unset($importer);

    $this->deleteS3File($current['url']);
    $current['status'] = 'completed';
    $current['records_imported'] = $imported;
    $current['last_updated'] = date('Y-m-d H:i:s');
    update_option(self::OPTION_KEY, $current, false);
    // Do a full flush of the cache
    MemcachedAdmin::flushFullCacheHelper();
  }

  /**
   * Load all configured relationship slugs from ACF options, keyed by slug with their ACF field key as value
   * @return array slug => ACF field key (e.g. 'related_products' => 'field_pim_rel_0')
   */
  protected function loadRelationSlugs(): array
  {
    $count = intval(get_option('options_pim_cr_relations', 0));
    $slugs = array();

    for ($i = 0; $i < $count; $i++) {
      $slug = get_option('options_pim_cr_relations_' . $i . '_rel_slug');
      if (!empty($slug)) {
        $slugs[$slug] = 'field_pim_rel_' . $i;
      }
    }

    return $slugs;
  }

  /**
   * Create (if absent) and truncate the permanent relation buffer table.
   * Uses a separate connection so these writes never participate in the main import transaction.
   * @param mysqli $db the buffer connection
   * @param string $prefix table prefix
   */
  protected function prepareRelationsBuffer(mysqli $db, string $prefix): void
  {
    $db->query(
      "CREATE TABLE IF NOT EXISTS `{$prefix}pim_relations_buffer` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id INT UNSIGNED NOT NULL,
        rel_slug VARCHAR(191) NOT NULL,
        sku VARCHAR(191) NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_slug_post (rel_slug, post_id),
        KEY idx_slug_sku (rel_slug, sku)
      ) ENGINE=InnoDB"
    );
    $db->query("TRUNCATE TABLE `{$prefix}pim_relations_buffer`");
  }

  /**
   * Bulk-insert all accumulated pending relations into the permanent buffer table.
   * Committed in 5 000-row transaction batches to bound undo-log size.
   * @param mysqli $db the buffer connection (autocommit, separate from main import)
   * @param array $pending slug => [postId => [skus]]
   * @param string $prefix table prefix
   */
  protected function flushRelationsToBuffer(mysqli $db, array $pending, string $prefix): void
  {
    $table = "`{$prefix}pim_relations_buffer`";
    $db->begin_transaction();
    $values = [];
    $count = 0;

    foreach ($pending as $slug => $postSkuSets) {
      $slugE = $db->real_escape_string($slug);
      foreach ($postSkuSets as $postId => $skus) {
        foreach ($skus as $order => $sku) {
          $skuE = $db->real_escape_string($sku);
          $values[] = "({$postId}, '{$slugE}', '{$skuE}', {$order})";
          $count++;

          if ($count >= 5000) {
            $db->query("INSERT INTO {$table} (post_id, rel_slug, sku, sort_order) VALUES " . implode(',', $values));
            $values = [];
            $count = 0;
            $db->commit();
            $db->begin_transaction();
          }
        }
      }
    }

    if (!empty($values)) {
      $db->query("INSERT INTO {$table} (post_id, rel_slug, sku, sort_order) VALUES " . implode(',', $values));
    }
    $db->commit();
  }

  /**
   * Resolve buffered relation SKUs to post IDs using the in-memory SKU map, then
   * write ACF-compatible postmeta. Uses a diff approach: only INSERT new rows and
   * UPDATE changed ones — unchanged relations are skipped entirely.
   * Writes are committed every 2 000 posts to bound undo-log size.
   * @param NativeWpImport $importer main import connection (for postmeta writes)
   * @param mysqli $bufferDb buffer table connection
   * @param array $skuMap sku => post_id (fully populated after the main loop)
   * @param array $relationSlugs slug => ACF field key
   * @param string $prefix table prefix
   */
  protected function resolveAndWriteRelations(
    NativeWpImport $importer,
    mysqli $bufferDb,
    array $skuMap,
    array $relationSlugs,
    string $prefix
  ): void {
    $db = $importer->getDb();

    foreach (array_keys($relationSlugs) as $slug) {
      $fieldKey   = $relationSlugs[$slug];
      $slugE      = $db->real_escape_string($slug);
      $underSlugE = $db->real_escape_string('_' . $slug);
      $fieldKeyE  = $db->real_escape_string($fieldKey);
      $bufSlugE   = $bufferDb->real_escape_string($slug);

      $result = $bufferDb->query(
        "SELECT post_id, sku, sort_order FROM `{$prefix}pim_relations_buffer`
         WHERE rel_slug = '{$bufSlugE}'
         ORDER BY post_id, sort_order"
      );

      if (!$result) {
        continue;
      }

      // Resolve SKUs → related post IDs using in-memory map (no extra JOIN needed)
      $postRelations = [];
      while ($row = $result->fetch_assoc()) {
        $pid = (int) $row['post_id'];
        if (isset($skuMap[$row['sku']])) {
          $postRelations[$pid][] = $skuMap[$row['sku']];
        }
      }
      $result->free();

      if (empty($postRelations)) {
        continue;
      }

      $postIds = array_keys($postRelations);

      // Fetch existing meta for the diff
      $existing = [];
      foreach (array_chunk($postIds, 2000) as $chunk) {
        $res = $db->query(
          "SELECT post_id, meta_id, meta_value FROM `{$prefix}postmeta`
           WHERE meta_key = '{$slugE}' AND post_id IN (" . implode(',', $chunk) . ")"
        );
        if ($res) {
          while ($row = $res->fetch_assoc()) {
            $existing[(int) $row['post_id']] = [
              'meta_id'    => (int) $row['meta_id'],
              'meta_value' => $row['meta_value'],
            ];
          }
          $res->free();
        }
      }

      $toInsert = [];
      $toUpdate = [];

      foreach ($postRelations as $pid => $relatedIds) {
        $serialized = serialize($relatedIds);
        if (!isset($existing[$pid])) {
          $toInsert[$pid] = $serialized;
        } elseif ($existing[$pid]['meta_value'] !== $serialized) {
          $toUpdate[] = ['meta_id' => $existing[$pid]['meta_id'], 'value' => $serialized];
        }
      }

      // INSERT new rows (value row + ACF key reference row), batch-committed
      if (!empty($toInsert)) {
        $db->begin_transaction();
        $values   = [];
        $rowCount = 0;

        foreach ($toInsert as $pid => $serialized) {
          $values[] = "({$pid}, '{$slugE}', '" . $db->real_escape_string($serialized) . "')";
          if (!empty($fieldKey)) {
            $values[] = "({$pid}, '{$underSlugE}', '{$fieldKeyE}')";
          }
          $rowCount++;

          if ($rowCount >= 2000) {
            $db->query("INSERT INTO `{$prefix}postmeta` (post_id, meta_key, meta_value) VALUES " . implode(',', $values));
            $values   = [];
            $rowCount = 0;
            $db->commit();
            $db->begin_transaction();
          }
        }

        if (!empty($values)) {
          $db->query("INSERT INTO `{$prefix}postmeta` (post_id, meta_key, meta_value) VALUES " . implode(',', $values));
        }
        $db->commit();
      }

      // UPDATE changed rows only, reusing a prepared statement
      if (!empty($toUpdate)) {
        $stmt = $db->prepare("UPDATE `{$prefix}postmeta` SET meta_value = ? WHERE meta_id = ?");
        $db->begin_transaction();
        $count = 0;

        foreach ($toUpdate as $item) {
          $stmt->bind_param('si', $item['value'], $item['meta_id']);
          $stmt->execute();
          $count++;

          if ($count >= 2000) {
            $db->commit();
            $db->begin_transaction();
            $count = 0;
          }
        }

        $db->commit();
        $stmt->close();
      }
    }
  }

  /**
   * Set default values for posts if not given
   * @param array $post post data array, passed by reference
   */
  protected function setPostDefaults(array &$post): void
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
    if (!isset($post['post_date']))
      $post['post_date'] = date('Y-m-d H:i:s');
    if (!isset($post['post_modified']))
      $post['post_modified'] = $post['post_date'];
    if (!isset($post['post_date_gmt']))
      $post['post_date_gmt'] = $post['post_date'];
    if (!isset($post['post_modified_gmt']))
      $post['post_modified_gmt'] = $post['post_modified'];
  }
}
