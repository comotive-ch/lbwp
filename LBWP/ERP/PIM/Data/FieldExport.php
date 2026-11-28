<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Core as LbwpCore;
use LBWP\ERP\PIM\Base as PimBase;
use LBWP\ERP\PIM\CustomFields;
use LBWP\ERP\PIM\ReadonlyFields;
use LBWP\Util\File;

/**
 * Exports the selected custom fields (PIM > Eigene Felder) of all products as CSV, SKU always in the first column.
 * Streams a semicolon separated UTF-8 file in the format the PIM import reads, relationships as SKU lists,
 * so the file can be edited and re-imported. Not an XLSX, as the in-memory writer can't handle ~100k rows.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class FieldExport
{
  const string ACTION = 'pexport_fields';
  const string CAPABILITY = 'edit_pages';
  const string SELECT_ID = 'lbwp-pim-field-export-fields';
  const string SKU_KEY = 'sku';
  const string LIST_SEPARATOR = ';';
  const int CHUNK_SIZE = 2000;
  const array EXCLUDED_TYPES = ['taxonomy', 'message', 'tab', 'accordion', 'group', 'repeater', 'flexible_content'];

  /**
   * @var array|null cached slug => field definition of all exportable fields
   */
  protected ?array $fields = null;

  /**
   * Register the admin-post handler that streams the download.
   * @return void
   */
  public function registerHandler(): void
  {
    add_action('admin_post_' . self::ACTION, [$this, 'download']);
  }

  /**
   * Enqueue select2 and the field selection script, called on the export page only.
   * @return void
   */
  public function enqueueAssets(): void
  {
    $base = File::getResourceUri();
    wp_enqueue_script('select2-js', $base . '/js/select2/select2.min.js', ['jquery'], LbwpCore::REVISION);
    wp_enqueue_style('select2-css', $base . '/js/select2/select2.min.css', [], LbwpCore::REVISION);
    wp_add_inline_script('select2-js', $this->getPageScript());
  }

  /**
   * Script initializing select2 and the select all / none / writable buttons.
   * @return string
   */
  protected function getPageScript(): string
  {
    $id = self::SELECT_ID;
    return <<<JS
      jQuery(function ($) {
        const select = $('#{$id}');
        select.select2({ width: '100%', closeOnSelect: false });
        $('[data-field-export-select]').on('click', function (event) {
          event.preventDefault();
          const mode = $(this).data('field-export-select');
          select.find('option').each(function () {
            $(this).prop('selected', mode === 'all' || (mode === 'writable' && $(this).data('writable') === 1));
          });
          select.trigger('change');
        });
      });
    JS;
  }

  /**
   * Render the field selection and download form posting to admin-post.php.
   * @return void
   */
  public function renderForm(): void
  {
    echo '<h2>' . esc_html__('Produktdaten exportieren', 'lbwp') . '</h2>';
    echo '<p>' . esc_html__('Exportiert die ausgewählten eigenen Felder aller Produkte als CSV-Datei. Die SKU ist immer die erste Spalte. Vorausgewählt sind alle Felder ohne Schreibschutz.', 'lbwp') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
    wp_nonce_field(self::ACTION);
    $this->renderFieldSelect();
    echo '<p><input type="submit" class="button-primary" value="' . esc_attr__('Produktdaten herunterladen', 'lbwp') . '" /></p>';
    echo '</form>';
  }

  /**
   * Render the multi select with all exportable fields, writable ones preselected.
   * @return void
   */
  protected function renderFieldSelect(): void
  {
    $locked = ReadonlyFields::getLockedIdentifiers();
    echo '<p><select id="' . esc_attr(self::SELECT_ID) . '" name="fields[]" multiple="multiple" style="width:100%">';
    foreach ($this->getFields() as $slug => $field) {
      $writable = !in_array($slug, $locked, true);
      echo '<option value="' . esc_attr($slug) . '" data-writable="' . ($writable ? 1 : 0) . '"' . selected($writable, true, false) . '>'
        . esc_html($field['label']) . '</option>';
    }
    echo '</select></p>';
    echo '<p>';
    echo '<a href="#" class="button" data-field-export-select="all">' . esc_html__('Alle', 'lbwp') . '</a> ';
    echo '<a href="#" class="button" data-field-export-select="writable">' . esc_html__('Nur ohne Schreibschutz', 'lbwp') . '</a> ';
    echo '<a href="#" class="button" data-field-export-select="none">' . esc_html__('Keine', 'lbwp') . '</a>';
    echo '</p>';
  }

  /**
   * All exportable ACF fields of the custom field groups, taxonomy and structural fields excluded.
   * @return array slug => ['label' => string, 'type' => string]
   */
  protected function getFields(): array
  {
    if ($this->fields !== null) {
      return $this->fields;
    }

    $this->fields = [];
    foreach (acf_get_field_groups(['post_type' => PimBase::TYPE_SLUG]) as $group) {
      if (!str_starts_with($group['key'], CustomFields::GROUP_KEY_PREFIX)) {
        continue;
      }
      foreach (acf_get_fields($group) ?: [] as $field) {
        if ($this->isExportable($field)) {
          $this->fields[$field['name']] = [
            'label' => $group['title'] . ' › ' . $field['label'] . ' (' . $field['name'] . ')',
            'type' => $field['type'],
          ];
        }
      }
    }

    return $this->fields;
  }

  /**
   * Check if a field holds a plain value that can be exported, the SKU is always exported anyway.
   * @param array $field the ACF field
   * @return bool
   */
  protected function isExportable(array $field): bool
  {
    return !empty($field['name'])
      && $field['name'] !== self::SKU_KEY
      && !in_array($field['type'], self::EXCLUDED_TYPES, true);
  }

  /**
   * Verify the request and stream the CSV download of the selected fields.
   * @return void
   */
  public function download(): void
  {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die(__('Keine Berechtigung.', 'lbwp'), 403);
    }
    check_admin_referer(self::ACTION);

    $fields = $this->getSelectedFields();
    if (empty($fields)) {
      wp_die(__('Bitte mindestens ein Feld auswählen.', 'lbwp'), '', ['back_link' => true]);
    }

    ini_set('memory_limit', '2G');
    set_time_limit(0);
    $this->streamCsv($fields);
    exit;
  }

  /**
   * Selected fields from the request, restricted to known fields and kept in their configured order.
   * @return array slug => field definition
   */
  protected function getSelectedFields(): array
  {
    $requested = array_map('sanitize_text_field', array_map('wp_unslash', (array)($_POST['fields'] ?? [])));
    return array_intersect_key($this->getFields(), array_flip($requested));
  }

  /**
   * Send the download headers and write all product rows chunk by chunk to the output.
   * @param array $fields slug => field definition of the exported fields
   * @return void
   */
  protected function streamCsv(array $fields): void
  {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    $filename = 'pim-produktdaten-' . current_time('Y-m-d') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    $this->writeRow($output, array_merge([self::SKU_KEY], array_keys($fields)));

    $skuMap = $this->getSkuMap();
    foreach (array_chunk($skuMap, self::CHUNK_SIZE, true) as $chunk) {
      $meta = $this->getMetaValues(array_keys($chunk), array_keys($fields));
      foreach ($chunk as $postId => $sku) {
        $this->writeRow($output, $this->buildRow($sku, $meta[$postId] ?? [], $fields, $skuMap));
      }
      flush();
    }

    fclose($output);
  }

  /**
   * Write a single CSV row with the delimiter and escape character the PIM import reads.
   * @param resource $output file handle
   * @param array $row values
   * @return void
   */
  protected function writeRow($output, array $row): void
  {
    fputcsv($output, $row, ';', '"', '\\');
  }

  /**
   * Build the row of a single product.
   * @param string $sku the product SKU
   * @param array $meta meta key => raw value of the product
   * @param array $fields slug => field definition of the exported fields
   * @param array $skuMap post ID => SKU of all products
   * @return array
   */
  protected function buildRow(string $sku, array $meta, array $fields, array $skuMap): array
  {
    $row = [$sku];
    foreach ($fields as $slug => $field) {
      $row[] = $this->formatValue((string)($meta[$slug] ?? ''), $field, $skuMap);
    }

    return $row;
  }

  /**
   * Convert a raw meta value to its import format, relationships become SKU lists.
   * @param string $value raw meta value
   * @param array $field field definition
   * @param array $skuMap post ID => SKU of all products
   * @return string
   */
  protected function formatValue(string $value, array $field, array $skuMap): string
  {
    $data = maybe_unserialize($value);
    if (!is_array($data)) {
      return $value;
    }

    if ($field['type'] === 'relationship') {
      $data = array_filter(array_map(fn($id) => $skuMap[(int)$id] ?? '', $data));
    }

    return implode(self::LIST_SEPARATOR, array_filter($data, 'is_scalar'));
  }

  /**
   * Load the SKU of every published product that has one, ordered by SKU.
   * @return array post ID => SKU
   */
  protected function getSkuMap(): array
  {
    global $wpdb;

    $sql = $wpdb->prepare(
      "SELECT p.ID, pm.meta_value
       FROM {$wpdb->posts} p
       INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
       WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value != ''
       ORDER BY pm.meta_value",
      self::SKU_KEY,
      PimBase::TYPE_SLUG
    );

    $map = [];
    foreach ($wpdb->get_results($sql, ARRAY_N) ?: [] as [$postId, $sku]) {
      $map[(int)$postId] = (string)$sku;
    }

    return $map;
  }

  /**
   * Load the given meta keys of a chunk of products.
   * @param array $postIds product IDs
   * @param array $metaKeys meta keys to load
   * @return array post ID => [meta key => raw value]
   */
  protected function getMetaValues(array $postIds, array $metaKeys): array
  {
    global $wpdb;

    $idList = implode(',', array_map('intval', $postIds));
    $keyList = implode(',', array_fill(0, count($metaKeys), '%s'));
    $sql = $wpdb->prepare(
      "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
       WHERE post_id IN ({$idList}) AND meta_key IN ({$keyList})",
      ...$metaKeys
    );

    $meta = [];
    foreach ($wpdb->get_results($sql, ARRAY_N) ?: [] as [$postId, $key, $value]) {
      $meta[(int)$postId][$key] = $value;
    }

    return $meta;
  }
}
