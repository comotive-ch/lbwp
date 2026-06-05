<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Helper\XlsxHelper;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;

/**
 * Base class for importing external CSV files into dedicated DB tables, with
 * read-only backend views. Extend this class and override getTables() with
 * the actual configuration; FTP credentials are overridden as constants.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class CsvTables extends ACFBase
{
  const string FTP_HOST = '';
  const string FTP_USER = '';
  const string FTP_PASS = '';
  const bool FTP_SSL = false;
  const int FTP_PORT = 21;

  const string MENU_SLUG = 'pimtables';
  const string VERSION_OPTION_PREFIX = 'pim_csv_table_v_';
  const int ROWS_PER_PAGE = 50;
  const int INSERT_BATCH_SIZE = 500;

  /**
   * Register admin menu, cron hooks, and run DB migrations when needed.
   */
  public function init(): void
  {
    add_action('admin_menu', [$this, 'registerMenus']);
    add_action('admin_init', [$this, 'maybeHandleExport']);
    $this->registerCronActions();
    if (is_admin()) {
      $this->maybeRunDbDelta();
    }
  }

  /** @inheritDoc */
  public function blocks(): void {}

  /** @inheritDoc */
  public function fields(): void {}

  /**
   * Table configuration — override in subclass with actual tables.
   *
   * Config keys per table:
   *   key           (string)  Unique identifier slug (used in menu slug and option names)
   *   label         (string)  Admin menu label
   *   table         (string)  DB table name without prefix (e.g. 'pim_prices')
   *   version       (int)     Increment to trigger dbDelta on next load
   *   cron_action   (string|null) WordPress action name for automatic FTP import, null to disable
   *   ftp_path      (string|null) Remote path on FTP server; null for upload-only tables
   *   csv_delimiter (string)  CSV field separator (default ';')
   *   primary_key   (string)  Column name used as the unique key for upsert
   *   columns       (array)   Map of column_name => type ('int', 'varchar', 'text')
   *
   * @return array[]
   */
  protected function getTables(): array
  {
    return [
      [
        'key'           => 'example_prices',
        'label'         => 'Beispiel Preisliste',
        'table'         => 'pim_example_prices',
        'version'       => 1,
        'cron_action'   => 'cron_daily_3',
        'ftp_path'      => 'exports/prices.csv',
        'csv_delimiter' => ';',
        'primary_key'   => 'article_no',
        'columns'       => [
          'article_no'  => 'varchar',
          'description' => 'text',
          'price_net'   => 'int',
          'price_gross' => 'varchar',
          'stock'       => 'int',
        ],
      ],
      [
        'key'           => 'example_specs',
        'label'         => 'Beispiel Spezifikationen',
        'table'         => 'pim_example_specs',
        'version'       => 1,
        'cron_action'   => null,
        'ftp_path'      => null,
        'csv_delimiter' => ',',
        'primary_key'   => 'spec_id',
        'columns'       => [
          'spec_id'     => 'varchar',
          'product_ref' => 'varchar',
          'spec_name'   => 'varchar',
          'spec_value'  => 'text',
        ],
      ],
    ];
  }

  /**
   * Register the top-level PIMTables menu and one submenu entry per table.
   */
  public function registerMenus(): void
  {
    add_menu_page(
      __('PIMTables', 'lbwp'),
      __('PIMTables', 'lbwp'),
      'edit_pages',
      self::MENU_SLUG,
      function () {
        $tables = $this->getTables();
        if (!empty($tables)) {
          wp_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '_' . $tables[0]['key']));
          exit;
        }
      },
      'dashicons-list-view',
      59
    );

    foreach ($this->getTables() as $config) {
      $key = $config['key'];
      add_submenu_page(
        self::MENU_SLUG,
        esc_html($config['label']),
        esc_html($config['label']),
        'edit_pages',
        self::MENU_SLUG . '_' . $key,
        function () use ($config) {
          $this->renderTablePage($config);
        }
      );
    }
  }

  /**
   * Hook each table's cron_action to the FTP import runner.
   */
  private function registerCronActions(): void
  {
    foreach ($this->getTables() as $config) {
      if (empty($config['cron_action']) || empty($config['ftp_path'])) {
        continue;
      }
      add_action($config['cron_action'], function () use ($config) {
        $this->runFtpImport($config);
      });
    }
  }

  /**
   * Compare stored version options against config versions; run dbDelta for any mismatch.
   */
  private function maybeRunDbDelta(): void
  {
    foreach ($this->getTables() as $config) {
      $optionKey = self::VERSION_OPTION_PREFIX . $config['key'];
      if ((int) get_option($optionKey, 0) === (int) $config['version']) {
        continue;
      }
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      dbDelta($this->buildTableSql($config));
      update_option($optionKey, (int) $config['version'], false);
    }
  }

  /**
   * Build the CREATE TABLE SQL string (dbDelta-compatible) from a table config.
   * @param array $config Table configuration entry from getTables()
   * @return string
   */
  private function buildTableSql(array $config): string
  {
    global $wpdb;

    $table = $wpdb->prefix . $config['table'];
    $charset = $wpdb->get_charset_collate();
    $lines = ['id INT UNSIGNED NOT NULL AUTO_INCREMENT'];

    foreach ($config['columns'] as $col => $type) {
      $sqlType = match ($type) {
        'int'    => 'INT',
        'double' => 'DOUBLE',
        'text'   => 'TEXT',
        default  => 'VARCHAR(500)',
      };
      $nullClause = match ($sqlType) {
        'TEXT'   => 'NULL',
        'INT',
        'DOUBLE' => 'NOT NULL DEFAULT 0',
        default  => "NOT NULL DEFAULT ''",
      };
      $lines[] = "`{$col}` {$sqlType} {$nullClause}";
    }

    $lines[] = 'PRIMARY KEY  (id)';

    if (!empty($config['primary_key'])) {
      $pk = $config['primary_key'];
      $lines[] = "UNIQUE KEY  uq_{$config['key']}_{$pk} (`{$pk}`)";
    }

    return "CREATE TABLE `{$table}` (\n  " . implode(",\n  ", $lines) . "\n) {$charset};";
  }

  /**
   * Render the full admin page for a single table: status message, data table, import actions.
   * @param array $config Table configuration entry from getTables()
   */
  public function renderTablePage(array $config): void
  {
    if (!current_user_can('edit_pages')) {
      wp_die(__('Keine Berechtigung', 'lbwp'));
    }

    $message = '';

    if (isset($_POST['pim_csv_upload']) && check_admin_referer('pim_csv_upload_' . $config['key'])) {
      $message = $this->handleUpload($config, !empty($_POST['pim_csv_partial']));
    } elseif (isset($_POST['pim_csv_ftp_now']) && check_admin_referer('pim_csv_ftp_' . $config['key'])) {
      $message = $this->handleFtpImportNow($config);
    }

    $page = max(1, intval($_GET['csv_page'] ?? 1));

    echo '<div class="wrap">';
    echo '<h1>' . esc_html($config['label']) . '</h1>';

    if (!empty($message)) {
      echo $message;
    }

    $this->renderDataTable($config, $page);
    $this->renderImportActions($config);
    echo '</div>';
  }

  /**
   * Render the read-only WP-style data table with prev/next pagination.
   * @param array $config Table configuration entry from getTables()
   * @param int $page Current page number (1-based)
   */
  private function renderDataTable(array $config, int $page): void
  {
    global $wpdb;

    $table = $wpdb->prefix . $config['table'];
    $offset = ($page - 1) * self::ROWS_PER_PAGE;
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    $safeCols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($config['columns'])));
    $rows = $wpdb->get_results(
      $wpdb->prepare("SELECT {$safeCols} FROM `{$table}` LIMIT %d OFFSET %d", self::ROWS_PER_PAGE, $offset),
      ARRAY_A
    );

    $pageUrl = admin_url('admin.php?page=' . self::MENU_SLUG . '_' . $config['key']);

    echo '<p>' . sprintf(__('%d Datensätze gesamt', 'lbwp'), $total) . '</p>';
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
    foreach (array_keys($config['columns']) as $col) {
      echo '<th>' . esc_html($col) . '</th>';
    }
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="' . count($config['columns']) . '">' . __('Keine Daten vorhanden', 'lbwp') . '</td></tr>';
    } else {
      foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($config['columns']) as $col) {
          echo '<td>' . esc_html($row[$col] ?? '') . '</td>';
        }
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
    echo '<div class="tablenav bottom"><div class="tablenav-pages" style="margin: 6px 0;">';
    if ($page > 1) {
      echo '<a class="button" href="' . esc_url($pageUrl . '&csv_page=' . ($page - 1)) . '">&laquo; ' . __('Zurück', 'lbwp') . '</a> ';
    }
    if (($offset + self::ROWS_PER_PAGE) < $total) {
      echo '<a class="button" href="' . esc_url($pageUrl . '&csv_page=' . ($page + 1)) . '">' . __('Weiter', 'lbwp') . ' &raquo;</a>';
    }
    echo '</div></div>';
  }

  /**
   * Render the upload form and (if configured) the FTP import-now button.
   * @param array $config Table configuration entry from getTables()
   */
  private function renderImportActions(array $config): void
  {
    echo '<hr>';
    echo '<h2>' . __('CSV exportieren', 'lbwp') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('pim_csv_export_' . $config['key']);
    echo '<input type="submit" name="pim_csv_export" class="button-secondary" value="' . __('CSV exportieren', 'lbwp') . '" />';
    echo '</form>';

    echo '<h2>' . __('CSV hochladen', 'lbwp') . '</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('pim_csv_upload_' . $config['key']);
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pim_csv_file">' . __('CSV Datei', 'lbwp') . '</label></th>';
    echo '<td>';
    echo '<input type="file" name="pim_csv_file" id="pim_csv_file" accept=".csv,.xlsx" />';
    echo '<br><label style="margin-top: 6px; display: inline-block;">';
    echo '<input type="checkbox" name="pim_csv_partial" value="1" /> ';
    echo __('Teilimport (fehlende Zeilen nicht löschen)', 'lbwp');
    echo '</label></td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pim_csv_upload" class="button-primary" value="' . __('Importieren', 'lbwp') . '" /></p>';
    echo '</form>';

    if (empty($config['ftp_path'])) {
      return;
    }

    echo '<h2>' . __('FTP Import', 'lbwp') . '</h2>';
    echo '<p>' . sprintf(__('FTP Pfad: <code>%s</code>', 'lbwp'), esc_html($config['ftp_path'])) . '</p>';
    echo '<form method="post">';
    wp_nonce_field('pim_csv_ftp_' . $config['key']);
    echo '<input type="submit" name="pim_csv_ftp_now" class="button-secondary" ';
    echo 'value="' . __('Jetzt via FTP importieren (Vollimport)', 'lbwp') . '" ';
    echo 'onclick="return confirm(\'' . esc_js(__('FTP Import starten? Alle fehlenden Zeilen werden gelöscht.', 'lbwp')) . '\');" />';
    echo '</form>';
  }

  /**
   * Called at admin_init; detects an export POST on a PIMTables page and streams the CSV before any HTML is sent.
   */
  public function maybeHandleExport(): void
  {
    if (empty($_POST['pim_csv_export'])) {
      return;
    }

    $page = sanitize_key($_GET['page'] ?? '');
    $prefix = self::MENU_SLUG . '_';
    if (!str_starts_with($page, $prefix)) {
      return;
    }

    $key = substr($page, strlen($prefix));
    $config = null;
    foreach ($this->getTables() as $table) {
      if ($table['key'] === $key) {
        $config = $table;
        break;
      }
    }

    if ($config === null || !current_user_can('edit_pages')) {
      return;
    }

    check_admin_referer('pim_csv_export_' . $key);
    $this->handleExport($config);
  }

  /**
   * Stream the full table contents as a CSV download and exit.
   * @param array $config Table configuration entry from getTables()
   */
  private function handleExport(array $config): void
  {
    global $wpdb;

    $table = $wpdb->prefix . $config['table'];
    $delimiter = $config['csv_delimiter'] ?? ';';
    $safeCols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($config['columns'])));
    $rows = $wpdb->get_results("SELECT {$safeCols} FROM `{$table}`", ARRAY_A);

    $filename = $config['table'] . '_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_keys($config['columns']), $delimiter);
    foreach ($rows as $row) {
      fputcsv($out, $row, $delimiter);
    }
    fclose($out);
    exit;
  }

  /**
   * Handle an uploaded CSV file and run the import.
   * @param array $config Table configuration entry from getTables()
   * @param bool $partial When true, missing rows are not deleted (Teilimport)
   * @return string HTML notice markup
   */
  private function handleUpload(array $config, bool $partial): string
  {
    if (!isset($_FILES['pim_csv_file']) || $_FILES['pim_csv_file']['error'] !== UPLOAD_ERR_OK) {
      return '<div class="notice notice-error"><p>' . __('Upload fehlgeschlagen.', 'lbwp') . '</p></div>';
    }

    $file = $_FILES['pim_csv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['csv', 'xlsx'])) {
      return '<div class="notice notice-error"><p>' . __('Nur CSV- und XLSX-Dateien sind erlaubt.', 'lbwp') . '</p></div>';
    }

    if ($ext === 'xlsx') {
      $localPath = $this->convertXlsxToCsv($file, $config['csv_delimiter'] ?? ';');
      if ($localPath === false) {
        return '<div class="notice notice-error"><p>' . __('XLSX-Konvertierung fehlgeschlagen.', 'lbwp') . '</p></div>';
      }
    } else {
      $localPath = File::getNewUploadFolder() . sanitize_file_name($file['name']);
      if (!move_uploaded_file($file['tmp_name'], $localPath)) {
        return '<div class="notice notice-error"><p>' . __('Datei konnte nicht gespeichert werden.', 'lbwp') . '</p></div>';
      }
    }

    $result = $this->processCsvFile($config, $localPath, $partial);
    @unlink($localPath);
    return $result;
  }

  /**
   * Convert an uploaded XLSX file to a CSV at the configured delimiter.
   * @param array $file $_FILES entry for the uploaded file
   * @param string $delimiter CSV delimiter to use in the output file
   * @return string|false Local path to the generated CSV, or false on failure
   */
  private function convertXlsxToCsv(array $file, string $delimiter): string|false
  {
    $xlsx = new XlsxHelper();
    $localXlsx = $xlsx->prepareFile('pim_csv_file');
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
      fputcsv($csvHandle, $row, $delimiter);
    }
    fclose($csvHandle);

    return $csvPath;
  }

  /**
   * Download from FTP and run a full (non-partial) import immediately.
   * @param array $config Table configuration entry from getTables()
   * @return string HTML notice markup
   */
  private function handleFtpImportNow(array $config): string
  {
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    $localPath = $this->downloadFromFtp($config);
    if ($localPath === false) {
      return '<div class="notice notice-error"><p>' . __('FTP Download fehlgeschlagen. Siehe System-Log.', 'lbwp') . '</p></div>';
    }

    $result = $this->processCsvFile($config, $localPath, false);
    @unlink($localPath);
    return $result;
  }

  /**
   * Download from FTP and run a full import, called via cron action.
   * @param array $config Table configuration entry from getTables()
   */
  public function runFtpImport(array $config): void
  {
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    $localPath = $this->downloadFromFtp($config);
    if ($localPath === false) {
      return;
    }

    $this->processCsvFile($config, $localPath, false);
    @unlink($localPath);
  }

  /**
   * Parse a CSV file and upsert rows into the target table within a transaction.
   * For full imports (partial=false) rows missing from the CSV are deleted.
   * @param array $config Table configuration entry from getTables()
   * @param string $filePath Absolute local path to the CSV file
   * @param bool $partial When true, rows absent from the CSV are kept
   * @return string HTML notice markup
   */
  private function processCsvFile(array $config, string $filePath, bool $partial): string
  {
    global $wpdb;

    $table = $wpdb->prefix . $config['table'];
    $delimiter = $config['csv_delimiter'] ?? ';';

    $handle = fopen($filePath, 'r');
    if (!$handle) {
      return '<div class="notice notice-error"><p>' . __('Datei konnte nicht geöffnet werden.', 'lbwp') . '</p></div>';
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($handle);
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
      fclose($handle);
      return '<div class="notice notice-error"><p>' . __('CSV-Header konnte nicht gelesen werden.', 'lbwp') . '</p></div>';
    }
    $header = array_map('trim', $header);

    $colMap = $this->buildColumnMap($config['columns'], $header);
    if (is_string($colMap)) {
      fclose($handle);
      return '<div class="notice notice-error"><p>' . $colMap . '</p></div>';
    }

    $hasPk = !empty($config['primary_key']);
    $batch = [];
    $seenPks = [];
    $inserted = 0;

    $wpdb->query('START TRANSACTION');

    if (!$hasPk && !$partial) {
      $wpdb->query("TRUNCATE TABLE `{$table}`");
    }

    try {
      while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row = [];
        foreach ($colMap as $col => $pos) {
          $raw = $csvRow[$pos] ?? '';
          $row[$col] = match ($config['columns'][$col]) {
            'int'    => (int) $raw,
            'double' => (float) $raw,
            default  => (string) $raw,
          };
        }
        if ($hasPk) {
          $seenPks[] = $row[$config['primary_key']];
        }
        $batch[] = $row;

        if (count($batch) >= self::INSERT_BATCH_SIZE) {
          $hasPk
            ? $this->upsertBatch($table, $batch, $config['columns'])
            : $this->insertBatch($table, $batch, $config['columns']);
          $inserted += count($batch);
          $batch = [];
        }
      }
      fclose($handle);

      if (!empty($batch)) {
        $hasPk
          ? $this->upsertBatch($table, $batch, $config['columns'])
          : $this->insertBatch($table, $batch, $config['columns']);
        $inserted += count($batch);
      }

      if ($hasPk && !$partial && !empty($seenPks)) {
        $this->deleteMissingRows($table, $config['primary_key'], $seenPks, $config['columns'][$config['primary_key']] ?? 'varchar');
      }

      $wpdb->query('COMMIT');
      SystemLog::add('CsvTables', 'info', "Import {$config['key']}: {$inserted} Zeilen verarbeitet.");
      return '<div class="notice notice-success"><p>' . sprintf(__('%d Datensätze importiert.', 'lbwp'), $inserted) . '</p></div>';

    } catch (\Throwable $e) {
      $wpdb->query('ROLLBACK');
      SystemLog::add('CsvTables', 'error', "Import {$config['key']} fehlgeschlagen: " . $e->getMessage());
      return '<div class="notice notice-error"><p>' . __('Import fehlgeschlagen. Siehe System-Log.', 'lbwp') . '</p></div>';
    }
  }

  /**
   * Map configured column names to their index positions in the CSV header row.
   * @param array $columns Column config map (name => type)
   * @param array $header Parsed CSV header row
   * @return array|string Associative map of column => CSV index, or error message string
   */
  private function buildColumnMap(array $columns, array $header): array|string
  {
    $map = [];
    foreach (array_keys($columns) as $col) {
      $pos = array_search($col, $header, true);
      if ($pos === false) {
        return sprintf(__('Spalte "%s" nicht in CSV gefunden.', 'lbwp'), esc_html($col));
      }
      $map[$col] = $pos;
    }
    return $map;
  }

  /**
   * Execute a batched INSERT … ON DUPLICATE KEY UPDATE for a set of rows.
   * @param string $table Full table name including prefix
   * @param array $batch Rows to insert, each an associative array of column => value
   * @param array $columnTypes Column config map (name => type)
   */
  private function upsertBatch(string $table, array $batch, array $columnTypes): void
  {
    global $wpdb;

    $cols = array_keys($columnTypes);
    $colList = '`' . implode('`, `', $cols) . '`';
    $updateClauses = implode(', ', array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $cols));

    $valueRows = [];
    foreach ($batch as $row) {
      $parts = [];
      foreach ($cols as $col) {
        $val = $row[$col] ?? '';
        $parts[] = match ($columnTypes[$col]) {
          'int'    => (int) $val,
          'double' => (float) $val,
          default  => "'" . esc_sql((string) $val) . "'",
        };
      }
      $valueRows[] = '(' . implode(', ', $parts) . ')';
    }

    $wpdb->query(
      "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(', ', $valueRows) .
      " ON DUPLICATE KEY UPDATE {$updateClauses}"
    );
  }

  /**
   * Execute a plain batched INSERT for tables without a primary key.
   * @param string $table Full table name including prefix
   * @param array $batch Rows to insert, each an associative array of column => value
   * @param array $columnTypes Column config map (name => type)
   */
  private function insertBatch(string $table, array $batch, array $columnTypes): void
  {
    global $wpdb;

    $cols = array_keys($columnTypes);
    $colList = '`' . implode('`, `', $cols) . '`';

    $valueRows = [];
    foreach ($batch as $row) {
      $parts = [];
      foreach ($cols as $col) {
        $val = $row[$col] ?? '';
        $parts[] = match ($columnTypes[$col]) {
          'int'    => (int) $val,
          'double' => (float) $val,
          default  => "'" . esc_sql((string) $val) . "'",
        };
      }
      $valueRows[] = '(' . implode(', ', $parts) . ')';
    }

    $wpdb->query("INSERT INTO `{$table}` ({$colList}) VALUES " . implode(', ', $valueRows));
  }

  /**
   * Delete rows whose primary key is absent from the imported set, using a
   * temporary table to handle arbitrarily large datasets without huge NOT IN clauses.
   * @param string $table Full table name including prefix
   * @param string $pkCol Primary key column name
   * @param array $seenPks All primary key values present in the import file
   * @param string $pkType Column type ('int' or 'varchar')
   */
  private function deleteMissingRows(string $table, string $pkCol, array $seenPks, string $pkType): void
  {
    global $wpdb;

    $colDef = ($pkType === 'int') ? 'INT NOT NULL' : 'VARCHAR(500) NOT NULL';
    $tmpTable = $wpdb->prefix . 'pim_csv_del_tmp';

    $wpdb->query("CREATE TEMPORARY TABLE IF NOT EXISTS `{$tmpTable}` (pk {$colDef}, PRIMARY KEY (pk))");
    $wpdb->query("TRUNCATE TABLE `{$tmpTable}`");

    foreach (array_chunk($seenPks, self::INSERT_BATCH_SIZE) as $chunk) {
      if ($pkType === 'int') {
        $values = implode(', ', array_map(fn($v) => '(' . (int) $v . ')', $chunk));
      } else {
        $values = implode(', ', array_map(fn($v) => "('" . esc_sql((string) $v) . "')", $chunk));
      }
      $wpdb->query("INSERT IGNORE INTO `{$tmpTable}` (pk) VALUES {$values}");
    }

    $wpdb->query(
      "DELETE t FROM `{$table}` t LEFT JOIN `{$tmpTable}` tmp ON t.`{$pkCol}` = tmp.pk WHERE tmp.pk IS NULL"
    );
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$tmpTable}`");
  }

  /**
   * Open an FTP(S) connection, download the configured file and return its local path.
   * @param array $config Table configuration entry from getTables()
   * @return string|false Local file path on success, false on any error
   */
  private function downloadFromFtp(array $config): string|false
  {
    $connection = static::FTP_SSL
      ? @ftp_ssl_connect(static::FTP_HOST, static::FTP_PORT)
      : @ftp_connect(static::FTP_HOST, static::FTP_PORT);

    if ($connection === false) {
      SystemLog::add('CsvTables', 'error', "FTP-Verbindung fehlgeschlagen: {$config['key']}", static::FTP_HOST . ':' . static::FTP_PORT);
      return false;
    }

    if (!@ftp_login($connection, static::FTP_USER, static::FTP_PASS)) {
      SystemLog::add('CsvTables', 'error', "FTP-Login fehlgeschlagen: {$config['key']}", static::FTP_HOST);
      ftp_close($connection);
      return false;
    }

    ftp_pasv($connection, true);

    $localPath = File::getNewUploadFolder() . basename($config['ftp_path']);
    $success = @ftp_get($connection, $localPath, $config['ftp_path'], FTP_BINARY);
    ftp_close($connection);

    if (!$success || !file_exists($localPath)) {
      SystemLog::add('CsvTables', 'error', "FTP-Download fehlgeschlagen: {$config['key']}", $config['ftp_path']);
      return false;
    }

    return $localPath;
  }
}
