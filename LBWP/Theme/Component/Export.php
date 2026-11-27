<?php

namespace LBWP\Theme\Component;

use LBWP\Core as LbwpCore;
use LBWP\Theme\Base\Component;
use LBWP\Util\File;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports users with selectable fields of the users table and user meta keys as an Excel file (one row per user).
 * Added as "Export" to the users menu in the backend.
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Export extends Component
{
  /**
   * Slug of the admin page
   */
  const string PAGE_SLUG = 'lbwp-user-export';
  /**
   * Action of the export request on admin-post.php
   */
  const string ACTION = 'lbwp_user_export';
  /**
   * Nonce action of the export form
   */
  const string NONCE = 'lbwp-user-export';
  /**
   * Capability needed to see the page and run the export
   */
  const string CAPABILITY = 'list_users';
  /**
   * Prefixes of the selectable values, to tell user fields and meta keys apart
   */
  const string PREFIX_FIELD = 'field:';
  const string PREFIX_META = 'meta:';
  /**
   * Fields of the users table that are never offered nor exported
   */
  const array BLACKLIST_FIELDS = ['user_pass', 'user_activation_key'];
  /**
   * User meta keys that are never offered nor exported, as they hold credentials (WordPress, LBWP and common 2FA plugins)
   * or tracking data like IP addresses, devices and visited URLs
   */
  const array BLACKLIST_META = [
    'session_tokens',
    '_application_passwords',
    'pw-reset-key',
    '_two_factor_totp_key',
    '_two_factor_backup_codes',
    'wp_2fa_totp_key',
    'wp_2fa_backup_codes',
    'googleauthenticator_secret',
    'community-events-location',
    'lbwp_login_devices_v2',
    '_wc_order_attribution_user_agent',
    '_wc_order_attribution_referrer',
    '_wc_order_attribution_session_entry',
  ];
  /**
   * Virtual user field with the readable role names of the current site, as the serialized capabilities meta is not offered
   */
  const string ROLES_FIELD = 'roles';
  /**
   * Number of users loaded per query
   */
  const int BATCH_SIZE = 500;
  /**
   * Maximum characters Excel allows in a single cell
   */
  const int MAX_CELL_LENGTH = 32767;

  /**
   * Registers the admin page and the export handler
   * @return void
   */
  public function init(): void
  {
    add_action('admin_menu', [$this, 'addMenuPage']);
    add_action('admin_post_' . self::ACTION, [$this, 'runExport']);
  }

  /**
   * Adds the export page to the users menu
   * @return void
   */
  public function addMenuPage(): void
  {
    $hook = add_users_page(
      __('Export', 'lbwp'),
      __('Export', 'lbwp'),
      self::CAPABILITY,
      self::PAGE_SLUG,
      [$this, 'renderPage']
    );

    add_action('admin_print_scripts-' . $hook, [$this, 'enqueuePageAssets']);
  }

  /**
   * Enqueues select2 and the page script, only on the export page
   * @return void
   */
  public function enqueuePageAssets(): void
  {
    $base = File::getResourceUri();
    wp_enqueue_script('select2-js', $base . '/js/select2/select2.min.js', ['jquery'], LbwpCore::REVISION);
    wp_enqueue_style('select2-css', $base . '/js/select2/select2.min.css', [], LbwpCore::REVISION);
    wp_add_inline_script('select2-js', $this->getPageScript());
    wp_add_inline_style('select2-css', '#lbwp-user-export-fields + .select2 .select2-selection { max-height: 60vh; overflow-y: auto; }');
  }

  /**
   * Script of the page: select2 init, select/deselect all, and posting the selection as one JSON field,
   * because thousands of selected meta keys would exceed max_input_vars as separate fields
   * @return string javascript code
   */
  protected function getPageScript(): string
  {
    return <<<JS
      jQuery(function ($) {
        const select = $('#lbwp-user-export-fields');
        select.select2({ width: '100%', closeOnSelect: false });
        $('#lbwp-user-export-all').on('click', function () {
          select.find('option').prop('selected', true);
          select.trigger('change');
        });
        $('#lbwp-user-export-none').on('click', function () {
          select.val(null).trigger('change');
        });
        $('#lbwp-user-export-form').on('submit', function (event) {
          const selection = select.val() || [];
          if (selection.length === 0) {
            event.preventDefault();
            return;
          }
          $('#lbwp-user-export-selection').val(JSON.stringify(selection));
        });
      });
    JS;
  }

  /**
   * Renders the export page with the field selection
   * @return void
   */
  public function renderPage(): void
  {
    echo '
      <div class="wrap">
        <h1>' . esc_html__('Benutzer exportieren', 'lbwp') . '</h1>
        <p>' . esc_html__('Wähle die Felder aus, die exportiert werden sollen. Es wird eine Excel-Datei mit einer Zeile pro Benutzer erstellt.', 'lbwp') . '</p>
        <form id="lbwp-user-export-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">
          <input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">
          <input type="hidden" name="selection" id="lbwp-user-export-selection" value="">
          ' . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . '
          <p>
            <button type="button" class="button" id="lbwp-user-export-all">' . esc_html__('Alle auswählen', 'lbwp') . '</button>
            <button type="button" class="button" id="lbwp-user-export-none">' . esc_html__('Alle abwählen', 'lbwp') . '</button>
          </p>
          <select id="lbwp-user-export-fields" multiple>
            ' . $this->getOptionGroup(__('Benutzerfelder', 'lbwp'), self::PREFIX_FIELD, $this->getUserFields()) . '
            ' . $this->getOptionGroup(__('Metafelder', 'lbwp'), self::PREFIX_META, $this->getMetaKeys()) . '
          </select>
          <p><button type="submit" class="button button-primary">' . esc_html__('Exportieren', 'lbwp') . '</button></p>
        </form>
      </div>
    ';
  }

  /**
   * Builds a preselected option group
   * @param string $label label of the group
   * @param string $prefix prefix of the option values
   * @param array $names field names or meta keys
   * @return string html of the option group
   */
  protected function getOptionGroup(string $label, string $prefix, array $names): string
  {
    $html = '<optgroup label="' . esc_attr($label) . '">';
    foreach ($names as $name) {
      $html .= '<option value="' . esc_attr($prefix . $name) . '" selected>' . esc_html($name) . '</option>';
    }

    return $html . '</optgroup>';
  }

  /**
   * Gets the column names of the users table without blacklisted fields, in table order, plus the roles field
   * @return array field names
   */
  protected function getUserFields(): array
  {
    global $wpdb;
    $fields = $wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->users);
    $fields = array_values(array_diff($fields, self::BLACKLIST_FIELDS));
    $fields[] = self::ROLES_FIELD;

    return $fields;
  }

  /**
   * Gets all distinct user meta keys without blacklisted keys and keys holding serialized values, sorted alphabetically
   * @return array meta keys
   */
  protected function getMetaKeys(): array
  {
    global $wpdb;
    $keys = $wpdb->get_col('SELECT DISTINCT meta_key FROM ' . $wpdb->usermeta . ' ORDER BY meta_key');
    return array_values(array_diff($keys, self::BLACKLIST_META, $this->getSerializedMetaKeys()));
  }

  /**
   * Gets the meta keys of which at least one value is serialized, with the same checks as is_serialized().
   * (?-i) forces case sensitive matching, as REGEXP follows the case insensitive collation of the column
   * @return array meta keys
   */
  protected function getSerializedMetaKeys(): array
  {
    global $wpdb;
    return $wpdb->get_col('
      SELECT DISTINCT meta_key FROM ' . $wpdb->usermeta . '
      WHERE (meta_value REGEXP \'(?-i)^[aOC]:[0-9]+:\' AND meta_value LIKE \'%}\')
      OR (meta_value REGEXP \'(?-i)^s:[0-9]+:"\' AND meta_value LIKE \'%";\')
      OR meta_value REGEXP \'(?-i)^[bid]:[0-9.E+-]+;$\'
      OR meta_value = \'N;\'
    ');
  }

  /**
   * Validates the request and streams the Excel file
   * @return void
   */
  public function runExport(): void
  {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die(esc_html__('Du hast keine Berechtigung für diesen Export.', 'lbwp'), 403);
    }

    check_admin_referer(self::NONCE);
    [$fields, $metaKeys] = $this->getSelection();
    if (count($fields) === 0 && count($metaKeys) === 0) {
      wp_die(esc_html__('Bitte wähle mindestens ein Feld aus.', 'lbwp'), 400);
    }

    set_time_limit(60);
    ini_set('memory_limit', '1024M');
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';

    $spreadsheet = new Spreadsheet();
    $this->fillSheet($spreadsheet->getActiveSheet(), $fields, $metaKeys);
    $this->streamFile($spreadsheet);
  }

  /**
   * Reads the posted selection and keeps only existing user fields and meta keys
   * @return array list of [user fields, meta keys]
   */
  protected function getSelection(): array
  {
    $posted = json_decode(wp_unslash($_POST['selection'] ?? ''), true);
    if (!is_array($posted)) {
      return [[], []];
    }

    $posted = array_filter($posted, 'is_string');
    $fields = $this->filterSelection($posted, self::PREFIX_FIELD, $this->getUserFields());
    $metaKeys = $this->filterSelection($posted, self::PREFIX_META, $this->getMetaKeys());

    return [$fields, $metaKeys];
  }

  /**
   * Gets the selected names with the given prefix, in the order of the allowed names
   * @param array $posted posted values with prefixes
   * @param string $prefix prefix to filter by
   * @param array $allowed allowed names
   * @return array selected and allowed names
   */
  protected function filterSelection(array $posted, string $prefix, array $allowed): array
  {
    $selected = [];
    foreach ($posted as $value) {
      if (str_starts_with($value, $prefix)) {
        $selected[substr($value, strlen($prefix))] = true;
      }
    }

    return array_values(array_filter($allowed, fn($name) => isset($selected[$name])));
  }

  /**
   * Writes the header and one row per user into the sheet, loading users in batches
   * @param Worksheet $sheet the target sheet
   * @param array $fields selected user fields
   * @param array $metaKeys selected meta keys
   * @return void
   */
  protected function fillSheet(Worksheet $sheet, array $fields, array $metaKeys): void
  {
    $columns = array_map(
      fn($index) => Coordinate::stringFromColumnIndex($index + 1),
      array_keys(array_merge($fields, $metaKeys))
    );

    $this->writeRow($sheet, $columns, 1, array_merge($fields, $metaKeys));
    $sheet->getStyle('A1:' . end($columns) . '1')->getFont()->setBold(true);
    $sheet->freezePane('A2');

    $row = 2;
    $lastId = 0;
    while (count($users = $this->getUserBatch($lastId)) > 0) {
      $meta = count($metaKeys) > 0 ? $this->getMetaBatch(array_keys($users), $metaKeys) : [];
      $roles = in_array(self::ROLES_FIELD, $fields) ? $this->getRolesBatch(array_keys($users)) : [];
      foreach ($users as $id => $user) {
        $values = array_map(fn($field) => $field === self::ROLES_FIELD ? ($roles[$id] ?? '') : $user[$field], $fields);
        foreach ($metaKeys as $key) {
          $values[] = isset($meta[$id][$key]) ? implode("\n", $meta[$id][$key]) : '';
        }
        $this->writeRow($sheet, $columns, $row++, $values);
        $lastId = $id;
      }
    }
  }

  /**
   * Writes a row of values as text cells, so values like "=…" or leading zeros are kept as they are
   * @param Worksheet $sheet the target sheet
   * @param array $columns column letters by index
   * @param int $row row number
   * @param array $values values by column index
   * @return void
   */
  protected function writeRow(Worksheet $sheet, array $columns, int $row, array $values): void
  {
    foreach (array_values($values) as $index => $value) {
      $value = (string) $value;
      if ($value === '') {
        continue;
      }
      if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
        $value = mb_substr($value, 0, self::MAX_CELL_LENGTH);
      }
      $sheet->setCellValueExplicit($columns[$index] . $row, $value, DataType::TYPE_STRING);
    }
  }

  /**
   * Loads the next batch of users with all columns of the users table
   * @param int $lastId users with a higher id are loaded
   * @return array user rows by user id
   */
  protected function getUserBatch(int $lastId): array
  {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . $wpdb->users . ' WHERE ID > %d ORDER BY ID LIMIT %d',
      $lastId,
      self::BATCH_SIZE
    ), ARRAY_A);

    return array_column($rows ?: [], null, 'ID');
  }

  /**
   * Loads the selected meta values of a batch of users, multiple values of the same key are kept
   * @param array $userIds ids of the users
   * @param array $metaKeys selected meta keys
   * @return array meta values by user id and meta key
   */
  protected function getMetaBatch(array $userIds, array $metaKeys): array
  {
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT user_id, meta_key, meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id IN (' . $placeholders . ') ORDER BY umeta_id',
      ...$userIds
    ), ARRAY_A);

    $wanted = array_flip($metaKeys);
    $meta = [];
    foreach ($rows as $row) {
      if (isset($wanted[$row['meta_key']])) {
        $meta[(int) $row['user_id']][$row['meta_key']][] = $row['meta_value'];
      }
    }

    return $meta;
  }

  /**
   * Loads the roles of a batch of users on the current site as readable, translated role names.
   * Capabilities granted directly to a user are stored in the same meta and are skipped
   * @param array $userIds ids of the users
   * @return array comma separated role names by user id
   */
  protected function getRolesBatch(array $userIds): array
  {
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT user_id, meta_value FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s AND user_id IN (' . $placeholders . ')',
      $wpdb->get_blog_prefix() . 'capabilities',
      ...$userIds
    ), ARRAY_A);

    $names = wp_roles()->role_names;
    $roles = [];
    foreach ($rows as $row) {
      $capabilities = maybe_unserialize($row['meta_value']);
      if (!is_array($capabilities)) {
        continue;
      }
      $slugs = array_intersect(array_keys(array_filter($capabilities)), array_keys($names));
      $roles[(int) $row['user_id']] = implode(', ', array_map(fn($slug) => translate_user_role($names[$slug]), $slugs));
    }

    return $roles;
  }

  /**
   * Sends the spreadsheet as xlsx download and ends the request
   * @param Spreadsheet $spreadsheet the filled spreadsheet
   * @return void
   */
  protected function streamFile(Spreadsheet $spreadsheet): void
  {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="benutzer-export-' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');

    IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
    exit;
  }
}
