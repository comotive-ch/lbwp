<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Helper\XlsxHelper;
use LBWP\Util\File;
use mysqli;

/**
 * Handles importing product group (taxonomy) data and product-group assignments.
 *
 * Two import operations:
 *  - Category tree: creates/updates the full product-group taxonomy tree from XLSX/CSV
 *  - Assignments: bulk-assigns products to product groups from XLSX/CSV
 *
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class ProductGroupImport
{
  const string TAXONOMY_SLUG = 'product-group';
  const string POST_TYPE_SLUG = 'lbwp-pid';
  const string FIELD_KEY_NAME_FR = 'field_pg_name_fr';
  const array LEVEL_COLS_DE = ['Level1De', 'Level2De', 'Level3De', 'Level4De', 'Level5De'];
  const array LEVEL_COLS_FR = ['Level1Fr', 'Level2Fr', 'Level3Fr', 'Level4Fr', 'Level5Fr'];

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

    $result = $this->importAssignments($csvPath);
    @unlink($csvPath);
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
    echo '</tr></table>';
    echo '<p><input type="submit" name="pg_assign_upload" class="button-primary" value="' . esc_attr(__('Hochladen und Zuweisungen importieren', 'lbwp')) . '" /></p>';
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
      $termId = $this->upsertTerm($slug, $nameDe, $nameFr, $parentId);

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
   * @return int term ID on success, 0 on failure
   */
  protected function upsertTerm(string $slug, string $nameDe, string $nameFr, int $parentId): int
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

    return $termId;
  }

  /**
   * Import product-to-group assignments using direct DB operations.
   * Replaces all existing assignments for terms that appear in the CSV.
   * @param string $csvPath path to the local semicolon-delimited UTF-8 CSV
   * @return string HTML feedback message
   */
  protected function importAssignments(string $csvPath): string
  {
    ini_set('memory_limit', '2G');
    set_time_limit(0);

    global $table_prefix;
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($db->connect_errno) {
      return '<div class="notice notice-error"><p>' . __('Datenbankverbindung fehlgeschlagen.', 'lbwp') . '</p></div>';
    }
    $db->set_charset('utf8mb4');

    $termTaxMap = $this->buildTermTaxonomyMap($db, $table_prefix);
    $skuPostMap = $this->buildSkuPostMap($db, $table_prefix);

    $handle = fopen($csvPath, 'r');
    if (!$handle) {
      $db->close();
      return '<div class="notice notice-error"><p>' . __('CSV-Datei konnte nicht gelesen werden.', 'lbwp') . '</p></div>';
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($handle);
    }

    fgetcsv($handle, 0, ';'); // skip header row

    // term_taxonomy_id => [post_id => true] (set semantics to deduplicate)
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

    if (empty($assignments)) {
      $db->close();
      return '<div class="notice notice-warning"><p>' . __('Keine Zuweisungen verarbeitet.', 'lbwp') . '</p></div>';
    }

    $ttids = array_keys($assignments);

    foreach (array_chunk($ttids, 1000) as $chunk) {
      $db->query(
        "DELETE FROM `{$table_prefix}term_relationships`
         WHERE term_taxonomy_id IN (" . implode(',', $chunk) . ")"
      );
    }

    $inserted = 0;
    $buffer = [];

    foreach ($assignments as $ttid => $postIds) {
      foreach (array_keys($postIds) as $postId) {
        $buffer[] = "({$postId},{$ttid},0)";
        if (count($buffer) >= 1000) {
          $db->query(
            "INSERT IGNORE INTO `{$table_prefix}term_relationships`
             (object_id, term_taxonomy_id, term_order) VALUES " . implode(',', $buffer)
          );
          $inserted += count($buffer);
          $buffer = [];
        }
      }
    }

    if (!empty($buffer)) {
      $db->query(
        "INSERT IGNORE INTO `{$table_prefix}term_relationships`
         (object_id, term_taxonomy_id, term_order) VALUES " . implode(',', $buffer)
      );
      $inserted += count($buffer);
    }

    foreach ($assignments as $ttid => $postIds) {
      $db->query(
        "UPDATE `{$table_prefix}term_taxonomy`
         SET count = " . count($postIds) . "
         WHERE term_taxonomy_id = {$ttid}"
      );
    }

    $db->close();

    return '<div class="notice notice-success"><p>' . sprintf(
        __('%d Zuweisungen importiert.', 'lbwp'),
        $inserted
      ) . '</p></div>';
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
