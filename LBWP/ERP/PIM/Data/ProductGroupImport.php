<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Helper\XlsxHelper;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Util\File;
use mysqli;

/**
 * Handles importing product group (taxonomy) data and product-group assignments.
 *
 * Two import operations:
 *  - Category tree: creates/updates the full product-group taxonomy tree from XLSX/CSV
 *  - Assignments: bulk-assigns products to product groups from XLSX/CSV (replace, add or delete)
 *
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class ProductGroupImport
{
  const string TAXONOMY_SLUG = 'product-group';
  const string POST_TYPE_SLUG = 'lbwp-pid';
  const string FIELD_KEY_NAME_FR = 'field_pg_name_fr';
  const string FIELD_KEY_ORDER = 'field_pg_order';
  const array LEVEL_COLS_DE = ['Level1De', 'Level2De', 'Level3De', 'Level4De', 'Level5De'];
  const array LEVEL_COLS_FR = ['Level1Fr', 'Level2Fr', 'Level3Fr', 'Level4Fr', 'Level5Fr'];
  const string MODE_REPLACE = 'replace';
  const string MODE_ADD = 'add';
  const string MODE_DELETE = 'delete';

  /**
   * Handle category tree file upload and run synchronous import.
   * @return string HTML feedback message
   */
  public function handleCategoryTreeUpload(): string
  {
    $file = $_FILES['pg_tree_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
      return '<div class="notice notice-error"><p>' . __('Upload fehlgeschlagen.', 'lbwp') . '</p></div>';
    }

    $csvPath = $this->convertToCsv('pg_tree_file', $file);
    if (!$csvPath) {
      return '<div class="notice notice-error"><p>' . __('Datei konnte nicht verarbeitet werden.', 'lbwp') . '</p></div>';
    }

    $result = $this->importCategoryTree($csvPath);
    @unlink($csvPath);
    MemcachedAdmin::flushFullCacheHelper();
    return $result;
  }

  /**
   * Handle assignment file upload and run synchronous import.
   * @return string HTML feedback message
   */
  public function handleAssignmentUpload(): string
  {
    $file = $_FILES['pg_assign_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
      return '<div class="notice notice-error"><p>' . __('Upload fehlgeschlagen.', 'lbwp') . '</p></div>';
    }

    $csvPath = $this->convertToCsv('pg_assign_file', $file);
    if (!$csvPath) {
      return '<div class="notice notice-error"><p>' . __('Datei konnte nicht verarbeitet werden.', 'lbwp') . '</p></div>';
    }

    $mode = $_POST['pg_assign_mode'] ?? self::MODE_REPLACE;
    if (!in_array($mode, [self::MODE_REPLACE, self::MODE_ADD, self::MODE_DELETE], true)) {
      $mode = self::MODE_REPLACE;
    }

    $result = $this->importAssignments($csvPath, $mode);
    @unlink($csvPath);
    MemcachedAdmin::flushFullCacheHelper();
    return $result;
  }

  /**
   * Render the two product group import forms.
   * @return void
   */
  public function renderForms(): void
  {
    echo '<h2>' . __('Produktgruppen-Baum importieren', 'lbwp') . '</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pg_tree_file">' . __('XLSX / CSV Datei', 'lbwp') . '</label></th>';
    echo '<td><input type="file" name="pg_tree_file" id="pg_tree_file" accept=".xlsx,.csv" /></td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pg_tree_upload" class="button-primary" value="' . esc_attr(__('Hochladen und Kategoriebaum importieren', 'lbwp')) . '" /></p>';
    echo '</form><hr>';

    echo '<h2>' . __('Produktgruppen-Zuweisung importieren', 'lbwp') . '</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pg_assign_file">' . __('XLSX / CSV Datei', 'lbwp') . '</label></th>';
    echo '<td><input type="file" name="pg_assign_file" id="pg_assign_file" accept=".xlsx,.csv" /></td>';
    echo '</tr><tr>';
    echo '<th>' . __('Modus', 'lbwp') . '</th>';
    echo '<td><fieldset>';
    echo '<label><input type="radio" name="pg_assign_mode" value="' . self::MODE_REPLACE . '" checked /> ' . __('Ersetzen', 'lbwp') . '</label>';
    echo '<p class="description">' . __('Alle bisherigen Zuweisungen der in der Datei vorkommenden Produktgruppen werden ersetzt.', 'lbwp') . '</p>';
    echo '<label><input type="radio" name="pg_assign_mode" value="' . self::MODE_ADD . '" /> ' . __('Nur hinzufügen', 'lbwp') . '</label>';
    echo '<p class="description">' . __('Bestehende Zuweisungen bleiben erhalten, die Zuweisungen aus der Datei werden ergänzt.', 'lbwp') . '</p>';
    echo '<label><input type="radio" name="pg_assign_mode" value="' . self::MODE_DELETE . '" /> ' . __('Löschen', 'lbwp') . '</label>';
    echo '<p class="description">' . __('Die in der Datei aufgeführten Zuweisungen werden entfernt, alle anderen bleiben erhalten.', 'lbwp') . '</p>';
    echo '</fieldset></td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pg_assign_upload" class="button-primary" value="' . esc_attr(__('Hochladen und Zuweisungen verarbeiten', 'lbwp')) . '" /></p>';
    echo '</form>';
  }

  /**
   * Import/update the full category tree from a local CSV file using WP taxonomy functions.
   * @param string $csvPath path to the local semicolon-delimited UTF-8 CSV
   * @return string HTML feedback message
   */
  protected function importCategoryTree(string $csvPath): string
  {
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    $handle = fopen($csvPath, 'r');
    if (!$handle) {
      return '<div class="notice notice-error"><p>' . __('CSV-Datei konnte nicht gelesen werden.', 'lbwp') . '</p></div>';
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($handle);
    }

    $headers = fgetcsv($handle, 0, ';');
    if (!$headers) {
      fclose($handle);
      return '<div class="notice notice-error"><p>' . __('CSV enthält keine Header-Zeile.', 'lbwp') . '</p></div>';
    }
    $headers = array_map('trim', $headers);

    $parentAtLevel = array_fill(0, 5, 0);
    $count = 0;
    $order = 0;

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
      if (count($row) !== count($headers)) {
        continue;
      }

      $record = array_combine($headers, $row);
      $slug = trim($record['Slug'] ?? '');
      if (empty($slug)) {
        continue;
      }

      $level = -1;
      $nameDe = '';
      $nameFr = '';

      foreach (self::LEVEL_COLS_DE as $i => $col) {
        if (!empty(trim($record[$col] ?? ''))) {
          $level = $i;
          $nameDe = trim($record[$col]);
          $nameFr = trim($record[self::LEVEL_COLS_FR[$i]] ?? '');
          break;
        }
      }

      if ($level === -1 || empty($nameDe)) {
        continue;
      }

      $parentId = $level > 0 ? $parentAtLevel[$level - 1] : 0;
      $termId = $this->upsertTerm($slug, $nameDe, $nameFr, $parentId, ++$order);

      if ($termId > 0) {
        $parentAtLevel[$level] = $termId;
        for ($j = $level + 1; $j < 5; $j++) {
          $parentAtLevel[$j] = 0;
        }
        $count++;
      }
    }

    fclose($handle);

    return '<div class="notice notice-success"><p>' . sprintf(
        __('%d Produktgruppen importiert/aktualisiert.', 'lbwp'),
        $count
      ) . '</p></div>';
  }

  /**
   * Create or update a single taxonomy term and its French translation meta.
   * @param string $slug term slug used as unique identifier
   * @param string $nameDe German term name stored as the core term name
   * @param string $nameFr French name stored in ACF termmeta field name-fr
   * @param int $parentId parent term ID, 0 for top-level
   * @param int $order sequential position of this term within the imported file
   * @return int term ID on success, 0 on failure
   */
  protected function upsertTerm(string $slug, string $nameDe, string $nameFr, int $parentId, int $order): int
  {
    $existing = get_term_by('slug', $slug, self::TAXONOMY_SLUG);

    if ($existing) {
      wp_update_term($existing->term_id, self::TAXONOMY_SLUG, [
        'name' => $nameDe,
        'parent' => $parentId,
      ]);
      $termId = $existing->term_id;
    } else {
      $result = wp_insert_term($nameDe, self::TAXONOMY_SLUG, [
        'slug' => $slug,
        'parent' => $parentId,
      ]);
      if (is_wp_error($result)) {
        return 0;
      }
      $termId = (int)$result['term_id'];
    }

    update_term_meta($termId, 'name-fr', $nameFr);
    update_term_meta($termId, '_name-fr', self::FIELD_KEY_NAME_FR);
    update_term_meta($termId, 'order', $order);
    update_term_meta($termId, '_order', self::FIELD_KEY_ORDER);

    return $termId;
  }

  /**
   * Process product-to-group assignments using direct DB operations.
   * Replace mode swaps all assignments of the terms in the CSV, add mode only inserts the given ones
   * and delete mode removes exactly the given product/group pairs.
   * @param string $csvPath path to the local semicolon-delimited UTF-8 CSV
   * @param string $mode one of the MODE_* constants
   * @return string HTML feedback message
   */
  protected function importAssignments(string $csvPath, string $mode = self::MODE_REPLACE): string
  {
    ini_set('memory_limit', '2G');
    set_time_limit(0);

    global $table_prefix;
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($db->connect_errno) {
      return '<div class="notice notice-error"><p>' . __('Datenbankverbindung fehlgeschlagen.', 'lbwp') . '</p></div>';
    }
    $db->set_charset('utf8mb4');

    $assignments = $this->readAssignments(
      $csvPath,
      $this->buildTermTaxonomyMap($db, $table_prefix),
      $this->buildSkuPostMap($db, $table_prefix)
    );

    if ($assignments === false) {
      $db->close();
      return '<div class="notice notice-error"><p>' . __('CSV-Datei konnte nicht gelesen werden.', 'lbwp') . '</p></div>';
    }

    if (empty($assignments)) {
      $db->close();
      return '<div class="notice notice-warning"><p>' . __('Keine Zuweisungen verarbeitet.', 'lbwp') . '</p></div>';
    }

    $ttids = array_keys($assignments);
    $postIds = $this->getAssignmentPostIds($assignments);

    if ($mode === self::MODE_DELETE) {
      $affected = $this->deleteAssignments($db, $table_prefix, $assignments);
      $message = __('%d Zuweisungen gelöscht.', 'lbwp');
    } else {
      if ($mode === self::MODE_REPLACE) {
        $postIds = array_merge($postIds, $this->clearTermAssignments($db, $table_prefix, $ttids));
      }
      $affected = $this->insertAssignments($db, $table_prefix, $assignments);
      $message = __('%d Zuweisungen importiert.', 'lbwp');
    }

    $this->recountTerms($db, $table_prefix, $ttids);
    $db->close();

    $this->invalidateCaches($postIds, $ttids);

    return '<div class="notice notice-success"><p>' . sprintf($message, $affected) . '</p></div>';
  }

  /**
   * Read the assignment CSV (column 1: group slug, column 2: SKU) into deduplicated id pairs.
   * Rows with unknown slugs or SKUs are skipped.
   * @param string $csvPath path to the local semicolon-delimited UTF-8 CSV
   * @param array $termTaxMap slug => term_taxonomy_id
   * @param array $skuPostMap sku => post_id
   * @return array|false term_taxonomy_id => [post_id => true], or false if the file can't be read
   */
  protected function readAssignments(string $csvPath, array $termTaxMap, array $skuPostMap): array|false
  {
    $handle = fopen($csvPath, 'r');
    if (!$handle) {
      return false;
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($handle);
    }

    fgetcsv($handle, 0, ';'); // skip header row

    $assignments = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
      $slug = trim($row[0] ?? '');
      $sku = trim($row[1] ?? '');
      if (empty($slug) || empty($sku)) {
        continue;
      }
      if (!isset($termTaxMap[$slug]) || !isset($skuPostMap[$sku])) {
        continue;
      }
      $assignments[$termTaxMap[$slug]][$skuPostMap[$sku]] = true;
    }
    fclose($handle);

    return $assignments;
  }

  /**
   * Remove all product assignments of the given terms.
   * @param mysqli $db native DB connection
   * @param string $prefix table prefix
   * @param int[] $ttids term_taxonomy_ids to clear
   * @return int[] ids of the posts that lost an assignment
   */
  protected function clearTermAssignments(mysqli $db, string $prefix, array $ttids): array
  {
    $postIds = [];
    foreach (array_chunk($ttids, 1000) as $chunk) {
      $result = $db->query(
        "SELECT DISTINCT object_id FROM `{$prefix}term_relationships`
         WHERE term_taxonomy_id IN (" . implode(',', $chunk) . ")"
      );
      if ($result) {
        while ($row = $result->fetch_row()) {
          $postIds[] = (int)$row[0];
        }
        $result->free();
      }

      $db->query(
        "DELETE FROM `{$prefix}term_relationships`
         WHERE term_taxonomy_id IN (" . implode(',', $chunk) . ")"
      );
    }

    return $postIds;
  }

  /**
   * Collect the unique post ids referenced by the assignment map.
   * @param array $assignments term_taxonomy_id => [post_id => true]
   * @return int[] post ids
   */
  protected function getAssignmentPostIds(array $assignments): array
  {
    $postIds = [];
    foreach ($assignments as $postIdSet) {
      $postIds += $postIdSet;
    }

    return array_keys($postIds);
  }

  /**
   * Invalidate the object caches bypassed by the direct DB writes, otherwise the backend
   * keeps showing the old assignments and counts.
   * @param int[] $postIds posts whose relationships changed
   * @param int[] $ttids term_taxonomy_ids whose counts changed
   * @return void
   */
  protected function invalidateCaches(array $postIds, array $ttids): void
  {
    $postIds = array_unique($postIds);
    foreach (array_chunk($postIds, 1000) as $chunk) {
      clean_object_term_cache($chunk, self::POST_TYPE_SLUG);
    }
    clean_term_cache($ttids);
  }

  /**
   * Insert the given assignments in batches, existing ones are ignored.
   * @param mysqli $db native DB connection
   * @param string $prefix table prefix
   * @param array $assignments term_taxonomy_id => [post_id => true]
   * @return int number of processed assignments
   */
  protected function insertAssignments(mysqli $db, string $prefix, array $assignments): int
  {
    $inserted = 0;
    foreach (array_chunk($this->getAssignmentTuples($assignments), 1000) as $chunk) {
      $db->query(
        "INSERT IGNORE INTO `{$prefix}term_relationships`
         (object_id, term_taxonomy_id, term_order) VALUES " . implode(',', array_map(fn($tuple) => '(' . $tuple . ',0)', $chunk))
      );
      $inserted += count($chunk);
    }

    return $inserted;
  }

  /**
   * Delete exactly the given product/group pairs in batches.
   * @param mysqli $db native DB connection
   * @param string $prefix table prefix
   * @param array $assignments term_taxonomy_id => [post_id => true]
   * @return int number of actually deleted assignments
   */
  protected function deleteAssignments(mysqli $db, string $prefix, array $assignments): int
  {
    $deleted = 0;
    foreach (array_chunk($this->getAssignmentTuples($assignments), 1000) as $chunk) {
      $db->query(
        "DELETE FROM `{$prefix}term_relationships`
         WHERE (object_id, term_taxonomy_id) IN (" . implode(',', array_map(fn($tuple) => '(' . $tuple . ')', $chunk)) . ")"
      );
      $deleted += max(0, $db->affected_rows);
    }

    return $deleted;
  }

  /**
   * Flatten the assignment map into "post_id,term_taxonomy_id" SQL tuples.
   * @param array $assignments term_taxonomy_id => [post_id => true]
   * @return string[] list of "post_id,term_taxonomy_id" strings
   */
  protected function getAssignmentTuples(array $assignments): array
  {
    $tuples = [];
    foreach ($assignments as $ttid => $postIds) {
      foreach (array_keys($postIds) as $postId) {
        $tuples[] = (int)$postId . ',' . (int)$ttid;
      }
    }

    return $tuples;
  }

  /**
   * Recalculate the term counts from the actual relationships, in add and delete mode the file
   * only holds part of a term's products.
   * @param mysqli $db native DB connection
   * @param string $prefix table prefix
   * @param int[] $ttids term_taxonomy_ids to recount
   * @return void
   */
  protected function recountTerms(mysqli $db, string $prefix, array $ttids): void
  {
    foreach (array_chunk(array_map('intval', $ttids), 1000) as $chunk) {
      $db->query(
        "UPDATE `{$prefix}term_taxonomy` tt
         SET tt.count = (
           SELECT COUNT(*) FROM `{$prefix}term_relationships` tr
           WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
         )
         WHERE tt.term_taxonomy_id IN (" . implode(',', $chunk) . ")"
      );
    }
  }

  /**
   * Build a slug-to-term_taxonomy_id map for the product-group taxonomy.
   * @param mysqli $db native DB connection
   * @param string $prefix table prefix
   * @return array slug => term_taxonomy_id
   */
  protected function buildTermTaxonomyMap(mysqli $db, string $prefix): array
  {
    $result = $db->query(
      "SELECT t.slug, tt.term_taxonomy_id
       FROM `{$prefix}terms` t
       INNER JOIN `{$prefix}term_taxonomy` tt ON tt.term_id = t.term_id
       WHERE tt.taxonomy = '" . self::TAXONOMY_SLUG . "'"
    );

    $map = [];
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $map[$row['slug']] = (int)$row['term_taxonomy_id'];
      }
      $result->free();
    }

    return $map;
  }

  /**
   * Build a SKU-to-post_id map for all PIM products.
   * @param mysqli $db native DB connection
   * @param string $prefix table prefix
   * @return array sku => post_id
   */
  protected function buildSkuPostMap(mysqli $db, string $prefix): array
  {
    $result = $db->query(
      "SELECT pm.meta_value AS sku, pm.post_id
       FROM `{$prefix}postmeta` pm
       INNER JOIN `{$prefix}posts` p ON p.ID = pm.post_id
       WHERE pm.meta_key = 'sku' AND p.post_type = '" . self::POST_TYPE_SLUG . "'"
    );

    $map = [];
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $map[$row['sku']] = (int)$row['post_id'];
      }
      $result->free();
    }

    return $map;
  }

  /**
   * Convert an uploaded XLSX or CSV file to a semicolon-delimited UTF-8 CSV on disk.
   * @param string $fileKey the $_FILES key for XLSX handling
   * @param array $file the $_FILES entry
   * @return string|false local path to the CSV file, or false on failure
   */
  protected function convertToCsv(string $fileKey, array $file): string|false
  {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
      return $this->convertXlsxToCsv($fileKey, $file);
    }

    return $this->normalizeCsv($file);
  }

  /**
   * Convert an uploaded XLSX file to a semicolon-delimited UTF-8 CSV.
   * @param string $fileKey the $_FILES key
   * @param array $file the $_FILES entry
   * @return string|false path to the CSV file, or false on failure
   */
  protected function convertXlsxToCsv(string $fileKey, array $file): string|false
  {
    $xlsx = new XlsxHelper();
    $localXlsx = $xlsx->prepareFile($fileKey);
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

    fwrite($csvHandle, "\xEF\xBB\xBF");
    foreach ($data as $row) {
      fputcsv($csvHandle, $row, ';');
    }
    fclose($csvHandle);

    return $csvPath;
  }

  /**
   * Normalize an uploaded CSV to semicolon-delimited UTF-8 and save to disk.
   * @param array $file the $_FILES entry
   * @return string|false path to the normalized CSV file, or false on failure
   */
  protected function normalizeCsv(array $file): string|false
  {
    $tmpPath = $file['tmp_name'];
    if (!is_readable($tmpPath)) {
      return false;
    }

    $handle = fopen($tmpPath, 'r');
    $sample = fread($handle, 8192);
    fclose($handle);

    $sampleClean = ltrim($sample, "\xEF\xBB\xBF");
    $encoding = mb_detect_encoding($sampleClean, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    $needsConversion = ($encoding && $encoding !== 'UTF-8');

    $firstLine = strtok($sampleClean, "\n");
    $commaCount = substr_count($firstLine, ',');
    $semicolonCount = substr_count($firstLine, ';');
    $tabCount = substr_count($firstLine, "\t");
    $delimiter = ';';
    if ($commaCount > $semicolonCount && $commaCount > $tabCount) {
      $delimiter = ',';
    } elseif ($tabCount > $semicolonCount && $tabCount > $commaCount) {
      $delimiter = "\t";
    }

    $csvPath = File::getNewUploadFolder() . $file['name'];

    if (!$needsConversion && $delimiter === ';') {
      move_uploaded_file($tmpPath, $csvPath);
      return $csvPath;
    }

    $sourceHandle = fopen($tmpPath, 'r');
    $csvHandle = fopen($csvPath, 'w');
    if (!$sourceHandle || !$csvHandle) {
      return false;
    }

    $bom = fread($sourceHandle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($sourceHandle);
    }

    fwrite($csvHandle, "\xEF\xBB\xBF");
    while (($row = fgetcsv($sourceHandle, 0, $delimiter)) !== false) {
      if ($needsConversion) {
        $row = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', $encoding), $row);
      }
      fputcsv($csvHandle, $row, ';');
    }

    fclose($sourceHandle);
    fclose($csvHandle);

    return $csvPath;
  }
}
