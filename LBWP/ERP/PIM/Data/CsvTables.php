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
    add_action('wp_ajax_pim_csv_search', [$this, 'handleSearchAjax']);
    add_action('wp_ajax_pim_csv_delete_row', [$this, 'handleDeleteRowAjax']);
    add_action('wp_ajax_pim_csv_update_row', [$this, 'handleUpdateRowAjax']);
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
   *   csv_delimiter        (string)  CSV field separator (default ';')
   *   primary_key          (string)  Column name used as the unique key for upsert
   *   columns              (array)   Map of column_name => type ('int', 'varchar', 'text')
   *   columns_translation  (array)   Optional map of human-readable CSV header => technical column name.
   *                                  When set, both the technical name and the translated name are accepted
   *                                  during import. Export uses the human-readable name as the header.
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
        'columns_translation'  => [
          'Artikel Nummer' => 'article_no',
          'Beschreibung' => 'description',
          'Nettopreis in CHF' => 'price_net',
          'Kunden-Endpreis in CHF' => 'price_gross'
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
      $message = $this->handleUpload($config, sanitize_key($_POST['pim_csv_mode'] ?? 'full'));
    } elseif (isset($_POST['pim_csv_delete_confirm']) && check_admin_referer('pim_csv_delete_confirm_' . $config['key'])) {
      $message = $this->handleDeleteConfirm($config);
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
    $safeCols = 'id, ' . implode(', ', array_map(fn($c) => "`{$c}`", array_keys($config['columns'])));
    $rows = $wpdb->get_results(
      $wpdb->prepare("SELECT {$safeCols} FROM `{$table}` LIMIT %d OFFSET %d", self::ROWS_PER_PAGE, $offset),
      ARRAY_A
    );

    $pageUrl = admin_url('admin.php?page=' . self::MENU_SLUG . '_' . $config['key']);
    $headers = $this->getExportHeaders($config);
    $colNames = array_keys($config['columns']);
    $colCount = count($colNames);
    $statusText = sprintf(__('%d Datensätze gesamt', 'lbwp'), $total);

    echo '<div id="pim-row-message" style="margin-bottom:4px;"></div>';
    echo '<p id="pim-table-status">' . esc_html($statusText) . '</p>';
    echo '<table id="pim-data-table" class="wp-list-table widefat fixed striped"><thead>';
    echo '<tr><th style="width:56px;"></th>';
    foreach ($headers as $label) {
      echo '<th>' . esc_html($label) . '</th>';
    }
    echo '</tr><tr><th></th>';
    foreach ($colNames as $col) {
      echo '<th style="padding:0px 2px 2px 0px;">';
      echo '<input class="pim-col-search" type="text" data-col="' . esc_attr($col) . '" ';
      echo 'placeholder="' . esc_attr(__('Suche…', 'lbwp')) . '" ';
      echo 'style="width:100%;box-sizing:border-box;font-weight:normal;padding-left:5px;" />';
      echo '</th>';
    }
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="' . ($colCount + 1) . '">' . __('Keine Daten vorhanden', 'lbwp') . '</td></tr>';
    } else {
      foreach ($rows as $row) {
        echo $this->renderRow($row, (int) $row['id'], $colNames);
      }
    }

    echo '</tbody></table>';
    echo '<div id="pim-pagination" class="tablenav bottom" style="clear:none;height:40px;">';
    echo '<div class="tablenav-pages" style="float:left;">';
    if ($page > 1) {
      echo '<a class="button" href="' . esc_url($pageUrl . '&csv_page=' . ($page - 1)) . '">&laquo; ' . __('Zurück', 'lbwp') . '</a> ';
    }
    if (($offset + self::ROWS_PER_PAGE) < $total) {
      echo '<a class="button" href="' . esc_url($pageUrl . '&csv_page=' . ($page + 1)) . '">' . __('Weiter', 'lbwp') . ' &raquo;</a>';
    }
    echo '</div>';
    echo '<button type="button" id="pim-search-reset" class="button" style="display:none;float:left;margin-left:8px;">';
    echo esc_html(__('Filter zurücksetzen', 'lbwp'));
    echo '</button>';
    echo '</div>';

    $this->renderModal($config);

    $colMeta = [];
    foreach ($colNames as $i => $col) {
      $colMeta[] = ['name' => $col, 'label' => $headers[$i] ?? $col, 'type' => $config['columns'][$col]];
    }
    $jsData = wp_json_encode([
      'ajaxUrl'       => admin_url('admin-ajax.php'),
      'nonce'         => wp_create_nonce('pim_csv_search'),
      'rowNonce'      => wp_create_nonce('pim_csv_row_ops'),
      'key'           => $config['key'],
      'colCount'      => $colCount + 1,
      'noDataMsg'     => __('Keine Daten gefunden.', 'lbwp'),
      'statusOrig'    => $statusText,
      'colMeta'       => $colMeta,
      'confirmDelete' => __('Diesen Datensatz wirklich löschen?', 'lbwp'),
      'deleteSuccess' => __('Datensatz gelöscht.', 'lbwp'),
      'deleteFailed'  => __('Löschen fehlgeschlagen.', 'lbwp'),
      'saveSuccess'   => __('Datensatz gespeichert.', 'lbwp'),
      'errInt'        => __('Ganzzahl erforderlich.', 'lbwp'),
      'errDouble'     => __('Dezimalzahl erforderlich.', 'lbwp'),
      'errVarchar'    => __('Maximal 500 Zeichen.', 'lbwp'),
    ]);

    echo <<<SCRIPT
<script>
(function () {
  const cfg = {$jsData};
  const tbody      = document.querySelector('#pim-data-table tbody');
  const status     = document.getElementById('pim-table-status');
  const pagination = document.getElementById('pim-pagination');
  const resetBtn   = document.getElementById('pim-search-reset');
  const rowMsg     = document.getElementById('pim-row-message');
  const modal      = document.getElementById('pim-edit-modal');
  const modalFields = document.getElementById('pim-modal-fields');
  const modalMsg   = document.getElementById('pim-modal-message');
  const origTbody  = tbody.innerHTML;
  let timer = null;
  let editingTr = null;

  // ── Search ────────────────────────────────────────────────────
  function getTerms() {
    const t = {};
    document.querySelectorAll('.pim-col-search').forEach(el => {
      const v = el.value.trim();
      if (v) t[el.dataset.col] = v;
    });
    return t;
  }

  function doSearch() {
    const terms = getTerms();
    const active = Object.keys(terms).length > 0;
    resetBtn.style.display = active ? '' : 'none';
    if (!active) {
      tbody.innerHTML = origTbody;
      status.textContent = cfg.statusOrig;
      pagination.style.display = '';
      return;
    }
    pagination.style.display = 'none';
    fetch(cfg.ajaxUrl, {
      method: 'POST',
      body: new URLSearchParams({ action: 'pim_csv_search', nonce: cfg.nonce, table_key: cfg.key, terms: JSON.stringify(terms) }),
    }).then(r => r.json()).then(data => {
      if (!data.success) return;
      tbody.innerHTML = data.data.tbody;
      status.textContent = data.data.status;
    });
  }

  document.querySelectorAll('.pim-col-search').forEach(el =>
    el.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(doSearch, 400); })
  );
  resetBtn.addEventListener('click', () => {
    document.querySelectorAll('.pim-col-search').forEach(el => { el.value = ''; });
    doSearch();
  });

  // ── Notifications ─────────────────────────────────────────────
  function showRowMsg(text, type) {
    rowMsg.innerHTML = '<div class="notice notice-' + type + '" style="display:inline-block;padding:6px 12px;margin:0;"><p>' + text + '</p></div>';
    setTimeout(() => { rowMsg.innerHTML = ''; }, 4000);
  }

  // ── Delete ───────────────────────────────────────────────────
  document.addEventListener('click', ev => {
    const btn = ev.target.closest('.pim-btn-delete');
    if (!btn) return;
    if (!confirm(cfg.confirmDelete)) return;
    const tr = btn.closest('tr');
    fetch(cfg.ajaxUrl, {
      method: 'POST',
      body: new URLSearchParams({ action: 'pim_csv_delete_row', nonce: cfg.rowNonce, table_key: cfg.key, id: tr.dataset.id }),
    }).then(r => r.json()).then(data => {
      if (!data.success) { showRowMsg(cfg.deleteFailed, 'error'); return; }
      tr.remove();
      showRowMsg(cfg.deleteSuccess, 'success');
    });
  });

  // ── Edit / modal ─────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  document.addEventListener('click', ev => {
    const btn = ev.target.closest('.pim-btn-edit');
    if (!btn) return;
    editingTr = btn.closest('tr');
    modalMsg.innerHTML = '';
    modalFields.innerHTML = '';
    cfg.colMeta.forEach(col => {
      const td = editingTr.querySelector('td[data-col="' + col.name + '"]');
      const val = td ? td.dataset.val : '';
      let inp;
      if (col.type === 'text') {
        inp = '<textarea name="' + col.name + '" style="width:100%;height:80px;resize:vertical;">' + esc(val) + '</textarea>';
      } else if (col.type === 'int') {
        inp = '<input type="number" step="1" name="' + col.name + '" value="' + esc(val) + '" style="width:100%;" />';
      } else if (col.type === 'double') {
        inp = '<input type="number" step="any" name="' + col.name + '" value="' + esc(val) + '" style="width:100%;" />';
      } else {
        inp = '<input type="text" maxlength="500" name="' + col.name + '" value="' + esc(val) + '" style="width:100%;" />';
      }
      modalFields.innerHTML +=
        '<div style="display:flex;gap:12px;margin-bottom:8px;">' +
          '<div style="width:160px;flex-shrink:0;font-weight:600;padding-top:8px;">' + esc(col.label) + '</div>' +
          '<div style="flex:1;">' + inp +
            '<div id="pim-err-' + col.name + '" style="display:none;color:#b32d2e;font-size:12px;margin-top:2px;"></div>' +
          '</div>' +
        '</div>';
    });
    modal.style.display = 'flex';
  });

  function closeModal() { modal.style.display = 'none'; editingTr = null; }
  document.getElementById('pim-modal-cancel').addEventListener('click', closeModal);
  modal.addEventListener('click', ev => { if (ev.target === modal) closeModal(); });

  document.getElementById('pim-modal-save').addEventListener('click', () => {
    let valid = true;
    cfg.colMeta.forEach(col => {
      const errEl = document.getElementById('pim-err-' + col.name);
      const inp   = modalFields.querySelector('[name="' + col.name + '"]');
      if (!inp || !errEl) return;
      errEl.style.display = 'none';
      const val = inp.value;
      if (col.type === 'int' && !/^-?\d+$/.test(val.trim())) {
        errEl.textContent = cfg.errInt; errEl.style.display = ''; valid = false;
      } else if (col.type === 'double' && (val.trim() === '' || isNaN(Number(val)))) {
        errEl.textContent = cfg.errDouble; errEl.style.display = ''; valid = false;
      } else if (col.type === 'varchar' && val.length > 500) {
        errEl.textContent = cfg.errVarchar; errEl.style.display = ''; valid = false;
      }
    });
    if (!valid) return;

    const body = new URLSearchParams({ action: 'pim_csv_update_row', nonce: cfg.rowNonce, table_key: cfg.key, id: editingTr.dataset.id });
    cfg.colMeta.forEach(col => {
      const inp = modalFields.querySelector('[name="' + col.name + '"]');
      body.set('fields[' + col.name + ']', inp ? inp.value : '');
    });

    fetch(cfg.ajaxUrl, { method: 'POST', body })
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          modalMsg.innerHTML = '<div class="notice notice-error" style="padding:6px 12px;margin-top:8px;"><p>' + esc(data.data || 'Fehler') + '</p></div>';
          return;
        }
        const vals = data.data.values;
        cfg.colMeta.forEach(col => {
          const td = editingTr.querySelector('td[data-col="' + col.name + '"]');
          if (!td) return;
          const v = String(vals[col.name] ?? '');
          td.dataset.val = v;
          td.textContent = v.length > 20 ? v.substring(0, 20) + '…' : v;
        });
        closeModal();
        showRowMsg(cfg.saveSuccess, 'success');
      });
  });
})();
</script>
SCRIPT;
  }

  /**
   * Render a single data table row with edit/delete action buttons and full data-val attributes.
   * @param array $row Associative row from wpdb (must include 'id')
   * @param int $rowId The row's AUTO_INCREMENT id
   * @param array $colNames Ordered list of configured column names
   * @return string HTML for the <tr> element
   */
  private function renderRow(array $row, int $rowId, array $colNames): string
  {
    $editTitle   = esc_attr(__('Bearbeiten', 'lbwp'));
    $deleteTitle = esc_attr(__('Löschen', 'lbwp'));
    $html  = '<tr data-id="' . $rowId . '">';
    $html .= '<td style="white-space:nowrap;">';
    $html .= '<button type="button" class="pim-btn-edit button-link" title="' . $editTitle . '" style="padding:2px 4px;">';
    $html .= '<span class="dashicons dashicons-edit"></span></button> ';
    $html .= '<button type="button" class="pim-btn-delete button-link" title="' . $deleteTitle . '" style="padding:2px 4px;color:#b32d2e;">';
    $html .= '<span class="dashicons dashicons-trash"></span></button>';
    $html .= '</td>';
    foreach ($colNames as $col) {
      $val     = (string) ($row[$col] ?? '');
      $display = mb_strlen($val) > 20 ? mb_substr($val, 0, 20) . '…' : $val;
      $html .= '<td data-col="' . esc_attr($col) . '" data-val="' . esc_attr($val) . '">' . esc_html($display) . '</td>';
    }
    $html .= '</tr>';
    return $html;
  }

  /**
   * Output the edit-modal HTML and its CSS. Called once per page from renderDataTable().
   * @param array $config Table configuration entry from getTables()
   */
  private function renderModal(array $config): void
  {
    $labelEdit   = esc_html(__('Datensatz bearbeiten', 'lbwp'));
    $labelSave   = esc_html(__('Speichern', 'lbwp'));
    $labelCancel = esc_html(__('Abbrechen', 'lbwp'));

    echo <<<HTML
<style>
#pim-edit-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center;}
#pim-modal-box{background:#fff;border-radius:4px;padding:24px 28px;width:640px;max-width:90vw;max-height:85vh;overflow-y:auto;position:relative;box-shadow:0 4px 24px rgba(0,0,0,.3);}
#pim-modal-box h2{margin-top:0;padding-bottom:12px;border-bottom:1px solid #ddd;}
#pim-modal-actions{margin-top:16px;display:flex;gap:8px;}
</style>
<div id="pim-edit-modal" style="display:none;">
  <div id="pim-modal-box">
    <h2>{$labelEdit}</h2>
    <div id="pim-modal-fields"></div>
    <div id="pim-modal-actions">
      <button type="button" id="pim-modal-save" class="button button-primary">{$labelSave}</button>
      <button type="button" id="pim-modal-cancel" class="button">{$labelCancel}</button>
    </div>
    <div id="pim-modal-message"></div>
  </div>
</div>
HTML;
  }

  /**
   * Render the upload form and (if configured) the FTP import-now button.
   * @param array $config Table configuration entry from getTables()
   */
  private function renderImportActions(array $config): void
  {
    echo '<hr>';
    echo '<h2>' . __('XLSX exportieren', 'lbwp') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('pim_csv_export_' . $config['key']);
    echo '<input type="submit" name="pim_csv_export" class="button-secondary" value="' . __('XLSX exportieren', 'lbwp') . '" />';
    echo '</form>';

    if (!empty($config['cron_action'])) {
      echo '<p class="description" style="margin-top:20px;">' . __('Diese Tabelle wird automatisch importiert, die manuellen Import-Funktionen sind deaktiviert.', 'lbwp') . '</p>';
      return;
    }

    echo '<h2>' . __('XLSX/CSV hochladen', 'lbwp') . '</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('pim_csv_upload_' . $config['key']);
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pim_csv_file">' . __('CSV/XLSX Datei', 'lbwp') . '</label></th>';
    echo '<td><input type="file" name="pim_csv_file" id="pim_csv_file" accept=".csv,.xlsx" /></td>';
    echo '</tr><tr>';
    echo '<th>' . __('Import-Modus', 'lbwp') . '</th>';
    echo '<td>';
    echo '<label style="display:block;margin-bottom:4px;"><input type="radio" name="pim_csv_mode" value="full" checked /> ' . __('Vollimport – fehlende Zeilen werden gelöscht', 'lbwp') . '</label>';
    if (!empty($config['primary_key'])) {
      echo '<label style="display:block;margin-bottom:4px;"><input type="radio" name="pim_csv_mode" value="partial" /> ' . __('Teilimport – fehlende Zeilen bleiben erhalten', 'lbwp') . '</label>';
    }
    echo '<label style="display:block;"><input type="radio" name="pim_csv_mode" value="delete" /> ' . __('Löschimport – nur die enthaltenen Zeilen löschen', 'lbwp') . '</label>';
    echo '</td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pim_csv_upload" class="button-primary" value="' . __('Hochladen', 'lbwp') . '" /></p>';
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
   * WP AJAX handler for column search. Returns tbody HTML and a status string as JSON.
   */
  public function handleSearchAjax(): void
  {
    check_ajax_referer('pim_csv_search', 'nonce');

    if (!current_user_can('edit_pages')) {
      wp_die('', '', ['response' => 403]);
    }

    $key = sanitize_key($_POST['table_key'] ?? '');
    $rawTerms = json_decode(wp_unslash($_POST['terms'] ?? '{}'), true);

    $config = null;
    foreach ($this->getTables() as $candidate) {
      if ($candidate['key'] === $key) {
        $config = $candidate;
        break;
      }
    }

    if ($config === null) {
      wp_send_json_error('Table not found');
    }

    global $wpdb;
    $table = $wpdb->prefix . $config['table'];
    $safeCols = 'id, ' . implode(', ', array_map(fn($c) => "`{$c}`", array_keys($config['columns'])));

    $where = [];
    $params = [];
    foreach ((array) $rawTerms as $col => $term) {
      $term = trim((string) $term);
      if ($term === '' || !array_key_exists($col, $config['columns'])) {
        continue;
      }
      $where[] = "`{$col}` LIKE %s";
      $params[] = '%' . $wpdb->esc_like($term) . '%';
    }

    if (empty($where)) {
      wp_send_json_error('No search terms');
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` {$whereClause}", ...$params));
    $rows = $wpdb->get_results(
      $wpdb->prepare("SELECT {$safeCols} FROM `{$table}` {$whereClause} LIMIT 200", ...$params),
      ARRAY_A
    );

    $colNames = array_keys($config['columns']);
    $colCount = count($colNames);
    $tbody = '';

    if (empty($rows)) {
      $tbody = '<tr><td colspan="' . ($colCount + 1) . '">' . esc_html(__('Keine Daten gefunden.', 'lbwp')) . '</td></tr>';
    } else {
      foreach ($rows as $row) {
        $tbody .= $this->renderRow($row, (int) $row['id'], $colNames);
      }
    }

    $showing = count($rows);
    $status = $showing < $total
      ? sprintf(__('%d von %d Resultaten', 'lbwp'), $showing, $total)
      : sprintf(__('%d Resultate', 'lbwp'), $total);

    wp_send_json_success(['tbody' => $tbody, 'status' => $status]);
  }

  /**
   * WP AJAX handler to delete a single row by its AUTO_INCREMENT id.
   */
  public function handleDeleteRowAjax(): void
  {
    check_ajax_referer('pim_csv_row_ops', 'nonce');

    if (!current_user_can('edit_pages')) {
      wp_die('', '', ['response' => 403]);
    }

    $key = sanitize_key($_POST['table_key'] ?? '');
    $id  = (int) ($_POST['id'] ?? 0);

    if ($id <= 0) {
      wp_send_json_error('Invalid ID');
    }

    $config = $this->findTableConfig($key);
    if ($config === null) {
      wp_send_json_error('Table not found');
    }

    global $wpdb;
    $deleted = $wpdb->delete($wpdb->prefix . $config['table'], ['id' => $id], ['%d']);

    if ($deleted === false) {
      wp_send_json_error('Delete failed');
    }

    SystemLog::add('CsvTables', 'info', "Row {$id} deleted from {$config['key']}.");
    wp_send_json_success();
  }

  /**
   * WP AJAX handler to update a single row's column values. Returns updated values as JSON.
   */
  public function handleUpdateRowAjax(): void
  {
    check_ajax_referer('pim_csv_row_ops', 'nonce');

    if (!current_user_can('edit_pages')) {
      wp_die('', '', ['response' => 403]);
    }

    $key    = sanitize_key($_POST['table_key'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $fields = (array) ($_POST['fields'] ?? []);

    if ($id <= 0) {
      wp_send_json_error('Invalid ID');
    }

    $config = $this->findTableConfig($key);
    if ($config === null) {
      wp_send_json_error('Table not found');
    }

    global $wpdb;
    $table = $wpdb->prefix . $config['table'];
    $sets = [];
    $params = [];
    $updated = [];

    foreach ($config['columns'] as $col => $type) {
      $raw = $fields[$col] ?? '';
      switch ($type) {
        case 'int':
          $val = (int) $raw;
          $sets[]  = "`{$col}` = %d";
          $params[] = $val;
          break;
        case 'double':
          $val = (float) $raw;
          $sets[]  = "`{$col}` = %f";
          $params[] = $val;
          break;
        default:
          $val = (string) $raw;
          if ($type === 'varchar' && mb_strlen($val) > 500) {
            wp_send_json_error(sprintf(__('Feld "%s" ist zu lang (max. 500 Zeichen).', 'lbwp'), $col));
          }
          $sets[]  = "`{$col}` = %s";
          $params[] = $val;
      }
      $updated[$col] = $val;
    }

    $params[] = $id;
    $result = $wpdb->query($wpdb->prepare(
      "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE id = %d",
      ...$params
    ));

    if ($result === false) {
      wp_send_json_error(__('Speichern fehlgeschlagen.', 'lbwp'));
    }

    SystemLog::add('CsvTables', 'info', "Row {$id} updated in {$config['key']}.");
    wp_send_json_success(['values' => $updated]);
  }

  /**
   * Find a table config entry by its key.
   * @param string $key Table key from getTables()
   * @return array|null Config array, or null if not found
   */
  private function findTableConfig(string $key): ?array
  {
    foreach ($this->getTables() as $config) {
      if ($config['key'] === $key) {
        return $config;
      }
    }
    return null;
  }

  /**
   * Stream the full table contents as an XLSX download and exit.
   * @param array $config Table configuration entry from getTables()
   */
  private function handleExport(array $config): void
  {
    global $wpdb;

    require_once WP_PLUGIN_DIR . '/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';

    $table = $wpdb->prefix . $config['table'];
    $safeCols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($config['columns'])));
    $rows = $wpdb->get_results("SELECT {$safeCols} FROM `{$table}`", ARRAY_A);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $columns = array_keys($config['columns']);
    $headers = $this->getExportHeaders($config);
    foreach ($headers as $i => $label) {
      $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '1';
      $sheet->setCellValue($cell, $label);
    }

    foreach ($rows as $rowIndex => $row) {
      $excelRow = $rowIndex + 2;
      foreach ($columns as $colIndex => $col) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . $excelRow;
        $sheet->setCellValue($cell, $row[$col] ?? '');
      }
    }

    $filename = $config['table'] . '_' . date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
  }

  /**
   * Handle an uploaded CSV/XLSX file and run the appropriate import mode.
   * @param array $config Table configuration entry from getTables()
   * @param string $mode 'full', 'partial', or 'delete'
   * @return string HTML notice markup
   */
  private function handleUpload(array $config, string $mode): string
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

    if ($mode === 'delete') {
      $result = $this->handleDeletePreview($config, $localPath);
    } else {
      $result = $this->processCsvFile($config, $localPath, $mode === 'partial');
    }

    @unlink($localPath);
    return $result;
  }

  /**
   * Parse a CSV for delete mode: find matching DB rows, store their IDs in a transient,
   * and return a preview table with a confirmation form.
   * @param array $config Table configuration entry from getTables()
   * @param string $filePath Absolute local path to the CSV file
   * @return string HTML notice + preview table + confirm form markup
   */
  private function handleDeletePreview(array $config, string $filePath): string
  {
    global $wpdb;

    $ids = $this->findMatchingRowIds($config, $filePath);
    if (is_string($ids)) {
      return '<div class="notice notice-error"><p>' . esc_html($ids) . '</p></div>';
    }
    if (empty($ids)) {
      return '<div class="notice notice-warning"><p>' . __('Keine übereinstimmenden Zeilen gefunden.', 'lbwp') . '</p></div>';
    }

    $table = $wpdb->prefix . $config['table'];
    $safeCols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($config['columns'])));
    $idList = implode(', ', array_map('intval', $ids));
    $rows = $wpdb->get_results("SELECT {$safeCols} FROM `{$table}` WHERE id IN ({$idList})", ARRAY_A);

    $token = bin2hex(random_bytes(16));
    set_transient('pim_csv_del_' . $config['key'] . '_' . $token, $ids, 30 * MINUTE_IN_SECONDS);

    $count = count($ids);
    $headers = $this->getExportHeaders($config);
    $pageUrl = admin_url('admin.php?page=' . self::MENU_SLUG . '_' . $config['key']);
    $confirmMsg = esc_js(sprintf(__('%d Zeilen werden unwiderruflich gelöscht. Fortfahren?', 'lbwp'), $count));

    ob_start();
    echo '<div class="notice notice-warning"><p>';
    echo sprintf(__('<strong>%d Zeilen</strong> werden gelöscht. Bitte prüfen und bestätigen:', 'lbwp'), $count);
    echo '</p></div>';
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
    foreach ($headers as $label) {
      echo '<th>' . esc_html($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
      echo '<tr>';
      foreach (array_keys($config['columns']) as $col) {
        $val = $row[$col] ?? '';
        $display = mb_strlen($val) > 20 ? mb_substr($val, 0, 20) . '…' : $val;
        echo '<td>' . esc_html($display) . '</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<form method="post" action="' . esc_url($pageUrl) . '" style="margin-top:12px;">';
    wp_nonce_field('pim_csv_delete_confirm_' . $config['key']);
    echo '<input type="hidden" name="pim_delete_token" value="' . esc_attr($token) . '" />';
    echo '<input type="submit" name="pim_csv_delete_confirm" class="button-primary" ';
    echo 'value="' . esc_attr(sprintf(__('%d Zeilen löschen', 'lbwp'), $count)) . '" ';
    echo 'onclick="return confirm(\'' . $confirmMsg . '\');" />';
    echo '</form>';
    return ob_get_clean();
  }

  /**
   * Execute a confirmed delete-mode deletion by reading the IDs from the stored transient.
   * @param array $config Table configuration entry from getTables()
   * @return string HTML notice markup
   */
  private function handleDeleteConfirm(array $config): string
  {
    global $wpdb;

    $token = sanitize_text_field($_POST['pim_delete_token'] ?? '');
    if (empty($token)) {
      return '<div class="notice notice-error"><p>' . __('Ungültiges Löschtoken.', 'lbwp') . '</p></div>';
    }

    $transientKey = 'pim_csv_del_' . $config['key'] . '_' . $token;
    $ids = get_transient($transientKey);
    delete_transient($transientKey);

    if (empty($ids)) {
      return '<div class="notice notice-error"><p>' . __('Löschvorgang abgelaufen oder ungültig. Bitte erneut hochladen.', 'lbwp') . '</p></div>';
    }

    $table = $wpdb->prefix . $config['table'];
    $idList = implode(', ', array_map('intval', $ids));
    $deleted = $wpdb->query("DELETE FROM `{$table}` WHERE id IN ({$idList})");

    SystemLog::add('CsvTables', 'info', "Löschimport {$config['key']}: {$deleted} Zeilen gelöscht.");
    return '<div class="notice notice-success"><p>' . sprintf(__('%d Datensätze gelöscht.', 'lbwp'), $deleted) . '</p></div>';
  }

  /**
   * Parse a CSV and return the DB `id` values of all rows that match.
   * With a primary key: match by PK value. Without: match by full row equality via temp table join.
   * @param array $config Table configuration entry from getTables()
   * @param string $filePath Absolute local path to the CSV file
   * @return int[]|string Array of matched DB ids, or an error message string
   */
  private function findMatchingRowIds(array $config, string $filePath): array|string
  {
    global $wpdb;

    $table = $wpdb->prefix . $config['table'];
    $delimiter = $config['csv_delimiter'] ?? ';';
    $hasPk = !empty($config['primary_key']);

    $handle = fopen($filePath, 'r');
    if (!$handle) {
      return __('Datei konnte nicht geöffnet werden.', 'lbwp');
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
      rewind($handle);
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
      fclose($handle);
      return __('CSV-Header konnte nicht gelesen werden.', 'lbwp');
    }
    $header = array_map('trim', $header);

    if (!empty($config['columns_translation'])) {
      $header = $this->applyHeaderTranslation($header, $config['columns_translation']);
    }

    $colMap = $this->buildColumnMap($config['columns'], $header);
    if (is_string($colMap)) {
      fclose($handle);
      return $colMap;
    }

    $csvRows = [];
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
      $csvRows[] = $row;
    }
    fclose($handle);

    if (empty($csvRows)) {
      return [];
    }

    if ($hasPk) {
      return $this->findIdsByPrimaryKey($table, $config, $csvRows);
    }

    return $this->findIdsByFullRowMatch($table, $config, $csvRows);
  }

  /**
   * Find DB row IDs by matching on the configured primary key column.
   * @param string $table Full table name including prefix
   * @param array $config Table configuration entry from getTables()
   * @param array $csvRows Parsed CSV rows as associative arrays
   * @return int[]
   */
  private function findIdsByPrimaryKey(string $table, array $config, array $csvRows): array
  {
    global $wpdb;

    $pk = $config['primary_key'];
    $pkType = $config['columns'][$pk] ?? 'varchar';
    $pkValues = array_column($csvRows, $pk);
    $ids = [];

    foreach (array_chunk($pkValues, self::INSERT_BATCH_SIZE) as $chunk) {
      if ($pkType === 'int') {
        $placeholders = implode(', ', array_map('intval', $chunk));
      } else {
        $placeholders = implode(', ', array_map(fn($v) => "'" . esc_sql((string) $v) . "'", $chunk));
      }
      $found = $wpdb->get_col("SELECT id FROM `{$table}` WHERE `{$pk}` IN ({$placeholders})");
      array_push($ids, ...array_map('intval', $found));
    }

    return $ids;
  }

  /**
   * Find DB row IDs by exact full-row match using a temporary table join.
   * @param string $table Full table name including prefix
   * @param array $config Table configuration entry from getTables()
   * @param array $csvRows Parsed CSV rows as associative arrays
   * @return int[]
   */
  private function findIdsByFullRowMatch(string $table, array $config, array $csvRows): array
  {
    global $wpdb;

    $cols = array_keys($config['columns']);
    $charset = $wpdb->get_charset_collate();
    $colDefs = implode(', ', array_map(function (string $col) use ($config): string {
      return '`' . $col . '` ' . match ($config['columns'][$col]) {
        'int'    => 'INT NOT NULL DEFAULT 0',
        'double' => 'DOUBLE NOT NULL DEFAULT 0',
        'text'   => 'TEXT NULL',
        default  => "VARCHAR(500) NOT NULL DEFAULT ''",
      };
    }, $cols));

    $tmpTable = $wpdb->prefix . 'pim_csv_del_match_tmp';
    $wpdb->query("CREATE TEMPORARY TABLE IF NOT EXISTS `{$tmpTable}` ({$colDefs}) {$charset}");
    $wpdb->query("TRUNCATE TABLE `{$tmpTable}`");

    foreach (array_chunk($csvRows, self::INSERT_BATCH_SIZE) as $chunk) {
      $this->insertBatch($tmpTable, $chunk, $config['columns']);
    }

    $joinClauses = implode(' AND ', array_map(fn($c) => "t.`{$c}` = m.`{$c}`", $cols));
    $ids = $wpdb->get_col("SELECT DISTINCT t.id FROM `{$table}` t INNER JOIN `{$tmpTable}` m ON {$joinClauses}");
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$tmpTable}`");

    return array_map('intval', $ids);
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

    if (!empty($config['columns_translation'])) {
      $header = $this->applyHeaderTranslation($header, $config['columns_translation']);
    }

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
   * Replace CSV header cells that match a columns_translation key with the technical column name.
   * Unrecognised header cells are left as-is so buildColumnMap can still produce a useful error.
   * @param array $header Parsed CSV header row
   * @param array $translation Map of human-readable label => technical column name
   * @return array Header with translated values substituted
   */
  private function applyHeaderTranslation(array $header, array $translation): array
  {
    return array_map(fn($cell) => $translation[$cell] ?? $cell, $header);
  }

  /**
   * Return export header labels: human-readable names from columns_translation (reversed),
   * or the technical column names when no translation is configured.
   * @param array $config Table configuration entry from getTables()
   * @return array Ordered list of header labels matching the columns order
   */
  private function getExportHeaders(array $config): array
  {
    if (empty($config['columns_translation'])) {
      return array_keys($config['columns']);
    }
    $reversed = array_flip($config['columns_translation']);
    return array_map(fn($col) => $reversed[$col] ?? $col, array_keys($config['columns']));
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
