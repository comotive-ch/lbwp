<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Helper\XlsxHelper;

/**
 * Base for project specific XLSX/CSV imports shown on the PIMport import page below the general imports.
 * Subclasses only define the texts and process the parsed rows, upload, parsing and security checks are done here.
 * Register instances by overriding Import::getCustomImports() or via the lbwp_pim_custom_imports filter.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class CustomImport
{
  const string CAPABILITY = 'edit_pages';
  const array EXTENSIONS = ['xlsx', 'csv'];

  /**
   * Unique identifier, used as submit name, file field prefix and nonce action.
   * @return string
   */
  abstract public function getAction(): string;

  /**
   * Section heading on the import page.
   * @return string
   */
  abstract public function getTitle(): string;

  /**
   * Explanation shown below the heading, e.g. the expected columns.
   * @return string
   */
  abstract public function getDescription(): string;

  /**
   * Process the uploaded data, runs synchronously within the request.
   * @param array $rows data rows keyed by the header of the first row
   * @return string HTML feedback message, use notice() to build it
   */
  abstract protected function importRows(array $rows): string;

  /**
   * Submit button label, override for a more specific text.
   * @return string
   */
  public function getButtonLabel(): string
  {
    return __('Hochladen und importieren', 'lbwp');
  }

  /**
   * Additional form table rows (e.g. options checkboxes), override to add some.
   * Values are available in $_POST within importRows().
   * @return void
   */
  protected function renderOptions(): void
  {
  }

  /**
   * Name of the file input, derived from the action.
   * @return string
   */
  protected function getFileField(): string
  {
    return $this->getAction() . '_file';
  }

  /**
   * Whether the current request is a submission of this import.
   * @return bool
   */
  public function isSubmitted(): bool
  {
    return isset($_POST[$this->getAction()]);
  }

  /**
   * Render the upload form posting back to the import page.
   * @return void
   */
  public function renderForm(): void
  {
    $action = $this->getAction();
    $field = $this->getFileField();
    $accept = implode(',', array_map(fn($ext) => '.' . $ext, static::EXTENSIONS));
    echo '<h2>' . esc_html($this->getTitle()) . '</h2>';
    echo '<p>' . esc_html($this->getDescription()) . '</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field($action, $action . '_nonce');
    echo '<table class="form-table"><tr>';
    echo '<th><label for="' . esc_attr($field) . '">' . esc_html__('XLSX / CSV Datei', 'lbwp') . '</label></th>';
    echo '<td><input type="file" name="' . esc_attr($field) . '" id="' . esc_attr($field) . '" accept="' . esc_attr($accept) . '" /></td>';
    echo '</tr>';
    $this->renderOptions();
    echo '</table>';
    echo '<p><input type="submit" name="' . esc_attr($action) . '" class="button-primary" value="' . esc_attr($this->getButtonLabel()) . '" /></p>';
    echo '</form>';
  }

  /**
   * Verify the request, parse the uploaded file and run the import.
   * @return string HTML feedback message
   */
  public function handleUpload(): string
  {
    $action = $this->getAction();
    if (!current_user_can(static::CAPABILITY) || !wp_verify_nonce($_POST[$action . '_nonce'] ?? '', $action)) {
      return $this->notice('error', __('Keine Berechtigung.', 'lbwp'));
    }

    $file = $_FILES[$this->getFileField()] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
      return $this->notice('error', __('Upload fehlgeschlagen.', 'lbwp'));
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, static::EXTENSIONS, true)) {
      return $this->notice('error', __('Dateityp nicht erlaubt.', 'lbwp'));
    }

    ini_set('memory_limit', '2G');
    set_time_limit(0);
    $data = $ext === 'xlsx' ? $this->readXlsx() : $this->readCsv($file['tmp_name']);
    if (count($data) < 2) {
      return $this->notice('error', __('Die Datei enthält keine Datenzeilen.', 'lbwp'));
    }

    return $this->importRows($this->combineWithHeaders($data));
  }

  /**
   * Read the first sheet of the uploaded XLSX file.
   * @return array list of rows including the header row
   */
  protected function readXlsx(): array
  {
    $xlsx = new XlsxHelper();
    $path = $xlsx->prepareFile($this->getFileField());
    if (!$path) {
      return [];
    }

    $xlsx->read($path);
    $data = $xlsx->getSheetData(0, true, true);
    @unlink($path);
    return $data;
  }

  /**
   * Read a CSV file, detecting delimiter (semicolon, comma, tab) and encoding (UTF-8 or Windows-1252).
   * @param string $path local file path
   * @return array list of rows including the header row
   */
  protected function readCsv(string $path): array
  {
    $content = file_get_contents($path);
    if ($content === false) {
      return [];
    }

    $content = ltrim($content, "\xEF\xBB\xBF");
    if (!mb_check_encoding($content, 'UTF-8')) {
      $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    $firstLine = strtok($content, "\n");
    $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
    $delimiter = array_search(max($counts), $counts, true);

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);
    $data = [];
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
      $row = array_map('trim', $row);
      if (strlen(implode('', $row)) > 0) {
        $data[] = $row;
      }
    }
    fclose($handle);
    return $data;
  }

  /**
   * Turn the raw rows into associative rows keyed by the header row, missing cells become empty strings.
   * @param array $data list of rows, first one being the header
   * @return array associative data rows
   */
  protected function combineWithHeaders(array $data): array
  {
    $headers = $this->uniqueHeaders(array_shift($data));
    $rows = [];
    foreach ($data as $row) {
      $row = array_pad(array_slice(array_values($row), 0, count($headers)), count($headers), '');
      $rows[] = array_combine($headers, $row);
    }

    return $rows;
  }

  /**
   * Trim the headers and replace empty or duplicate ones with "Spalte N", so no column gets lost.
   * @param array $headers raw header row
   * @return array unique header names in column order
   */
  protected function uniqueHeaders(array $headers): array
  {
    $unique = [];
    foreach (array_values($headers) as $index => $header) {
      $header = trim((string) $header);
      if ($header === '' || in_array($header, $unique, true)) {
        $header = 'Spalte ' . ($index + 1);
      }
      $unique[] = $header;
    }

    return $unique;
  }

  /**
   * Build an admin notice as returned by the import handlers.
   * @param string $type success, warning or error
   * @param string $text plain text message
   * @return string HTML notice
   */
  protected function notice(string $type, string $text): string
  {
    return '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($text) . '</p></div>';
  }
}
