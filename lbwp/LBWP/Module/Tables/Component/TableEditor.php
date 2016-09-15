<?php

namespace LBWP\Module\Tables\Component;

use LBWP\Core as LbwpCore;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\WordPress;
use LBWP\Util\File;

/**
 * The table editor base component
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class TableEditor extends Base
{
  /**
   * @var TableHandler the table handler instance
   */
  protected $handler = NULL;
  /**
   * Called at init(50), for now only executed in local development
   */
  public function initialize()
  {
    // Easy access to table handler
    $this->handler = $this->core->getTableHandler();

    // UI functions and assets are only needed on the actual edit page
    if (is_admin() && $this->isTableEditPage() && !isset($_GET['devmode'])) {
      $this->prepareInterface();
      $this->registerAssets();
    }

    // Save functions and ajax is needed in admin generally
    if (is_admin()) {
      add_action('wp_insert_post_data', array($this->handler, 'handleHtmlConversion'));
      add_action('save_post_' . Posttype::TABLE_SLUG, array($this->handler, 'saveTableJson'));
      add_action('wp_ajax_getTableInterfaceHtml', array($this, 'getInterfaceHtml'));
      add_action('wp_ajax_getBackendTableHtml', array($this, 'getBackendTableHtml'));
    }
  }

  /**
   * Prepares the interface by removing / adding things from the view
   */
  protected function prepareInterface()
  {
    remove_post_type_support(Posttype::TABLE_SLUG, 'editor');
    add_action('admin_footer', array($this, 'provideTranslations'));
  }

  /**
   * Add the needed css and js assets / libraries
   */
  protected function registerAssets()
  {
    // First, load all JS used for the form editor
    $baseUrl = File::getResourceUri() . '/js/table-editor';
    wp_enqueue_script('lbwp-table-editor-core', $baseUrl . '/LbwpTableEditor.Core.js', array('jquery'), LbwpCore::REVISION);
    wp_enqueue_script('lbwp-table-editor-interface', $baseUrl . '/LbwpTableEditor.Interface.js', array('lbwp-table-editor-core'), LbwpCore::REVISION);
    wp_enqueue_script('lbwp-table-editor-cells', $baseUrl . '/LbwpTableEditor.Cells.js', array('lbwp-table-editor-core'), LbwpCore::REVISION);

    // Ditch on possible jquery ui css
    add_action('admin_enqueue_scripts', array($this, 'preventJqueryUiLoading'));

    // Also load the CSS machine
    $baseUrl = File::getResourceUri() . '/css/table-editor';
    wp_enqueue_style('lbwp-table-editor-css', $baseUrl . '/app.css', array(), LbwpCore::REVISION);
  }

  /**
   * Prevent the loading of jquery ui themes
   */
  public function preventJqueryUiLoading()
  {
    wp_dequeue_style('jquery-ui-theme-lbwp');
    wp_dequeue_style('jquery-ui-style');
    wp_enqueue_style('jquery-ui-css');
    wp_enqueue_style('jquery-ui');
  }

  /**
   * @return bool if this is a form edit page
   */
  protected function isTableEditPage()
  {
    $post = WordPress::guessCurrentPost();
    return $_GET['action'] == 'edit' && $post->post_type == Posttype::TABLE_SLUG || $_GET['post_type'] == Posttype::TABLE_SLUG;
  }

  /**
   * Provides translations strings for the JS UI
   */
  public function provideTranslations()
  {
    echo '
      <script type="text/javascript">
        if (typeof(LbwpFormEditor) == "undefined") { var LbwpFormEditor = {}; }
        // Add text resources
        LbwpTableEditor.Text = {
          saveButton : "' . esc_js(__('Tabelle Speichern', 'lbwp')) . '",
          editorLoading : "' . esc_js(__('Tabelle wird geladen', 'lbwp')) . '",
        };
      </script>
    ';
  }

  /**
   * Ajax Callback to return the interface HTML block
   */
  public function getInterfaceHtml()
  {
    $tableId = intval($_REQUEST['tableId']);
    $isNew = ($_REQUEST['isNew'] == 'true') ? 1 : 0;

    $html = '
      <div class="table-editor">
        <div class="settings-container">
          ' . $this->getSettingsHtml($isNew) . '
        </div>
        <div class="table-container">
          ' . __('Tabelle wird geladen...', 'lbwp') . '
        </div>
      </div>
      <span class="data-containers">
        <textarea name="tableJson" id="tableJson"></textarea>
        <input type="hidden" name="isNewTable" value="' . $isNew . '">
      </span>
    ';

    $table = $this->handler->getTable($tableId);

    WordPress::sendJsonResponse(array(
      'tableHtml' => $html,
      'tableConfig' => $this->handler->getConfig(),
      'tableJson' => $table,
      'hasData' => true
    ));
  }

  /**
   * Get html of the backend table
   */
  public function getBackendTableHtml()
  {
    $table = ArrayManipulation::forceArray($_POST['json']);
    // Return empty string, if there is no data yet
    if (!is_array($table['data'])) {
      return '';
    }

    // Begin the table and classes
    $html = '<table class="' . TableHandler::getTableClasses($table['settings']) . '"><tbody>';

    // Add a row, just for the settings and move buttons
    $html .= '<tr class="first-settings-row">';
    $html .= '<td class="empty-settings-cell">&nbsp;<!--empty--></td>';
    foreach ($table['data'][0] as $colIndex => $column) {
      $html .= '
        <td class="col-settings-cell" data-col-settings="' . $colIndex . '">
          <a class="dashicons dashicons-admin-generic column-settings"></a>
          <a class="dashicons dashicons-no column-delete"></a>
          <a class="dashicons dashicons-arrow-left-alt column-move-left"></a>
          <a class="dashicons dashicons-arrow-right-alt column-move-right"></a>
        </td>
      ';  
    }
    $html .= '</tr>';

    // Go trough the whole table now to generate it
    foreach ($table['data'] as $rowIndex => $row) {
      $html .= '<tr data-row="' . $rowIndex . '">';
      // Add the settings cell
      $html .= '
        <td data-row-settings="' . $rowIndex . '" class="row-settings-cell">
          <a class="dashicons dashicons-admin-generic row-settings"></a>
          <a class="dashicons dashicons-no row-delete"></a>
          <a class="dashicons dashicons-arrow-up-alt row-move-up"></a>
          <a class="dashicons dashicons-arrow-down-alt row-move-down"></a>
        </td>
      ';

      // Look at all the cells we have
      foreach ($row as $cellIndex => $cell) {
        $html .= '
          <td data-cell="' . $rowIndex . '.' . $cellIndex . '" class="' . TableHandler::getCellClasses($cell) . '">
            <div class="cell-content">' . $cell['content'] . '</div>
            <div class="cell-options">
              <a class="edit-cell-content">' . __('Bearbeiten', 'lbwp') . '</a>
              <a class="edit-cell-settings">' . __('Einstellungen', 'lbwp') . '</a>
            </div>
          </td>
        ';
      }
      $html .= '</tr>';
    }

    // Close the table
    $html .= '</tbody></table>';
    WordPress::sendJsonResponse(array('html' => $html));
  }

  /**
   * @param bool $isNew is this a new table?
   * @return string html code
   */
  protected function getSettingsHtml($isNew)
  {
    $html = '';

    if ($isNew == 1) {
      // Get the table templates
      $options = '';
      foreach ($this->handler->getTemplates() as $id => $template) {
        $options .= '<option value="' . $id . '">' . $template['templateName'] . '</option>';
      }

      $html .= '
        <div class="setting-block table-template">
          <label class="setting-label">
            ' . __('Vorlage:', 'lbwp') . '
          </label>
          <div class="setting-container">
            <select name="tableTemplate">
              ' . $options . '
            </select>
          </div>
        </div>
        <div class="button-block">
          <button class="button-primary save-table-button">' . __('Tabelle speichern', 'lbwp') . '</button>
        </div>
      ';
    } else {
      $html .= '
        <div class="setting-block table-template">
          <label class="setting-label">
            ' . __('Neue Zeile', 'lbwp') . '
          </label>
          <div class="setting-container">
            <select name="newRow" class="new-rowcol-select">
              <option value="bottom">' . __('am unteren Ende der Tabelle', 'lbwp') . '</option>
              <option value="top">' . __('am oberen Ende der Tabelle', 'lbwp') . '</option>
            </select>
            <button class="button add-new-row">' . __('Hinzufügen', 'lbwp') . '</button>
          </div>
          <label class="setting-label">
            ' . __('Neue Spalte', 'lbwp') . '
          </label>
          <div class="setting-container">
            <select name="newColumn" class="new-rowcol-select">
              <option value="right">' . __('am rechten Rand der Tabelle', 'lbwp') . '</option>
              <option value="left">' . __('am linken Rand der Tabelle', 'lbwp') . '</option>
            </select>
            <button class="button add-new-col">' . __('Hinzufügen', 'lbwp') . '</button>
          </div>
        </div>
        <div class="button-block">
          <button class="button table-preview-button">' . __('Vorschau', 'lbwp') . '</button>
          <button class="button table-settings-button">' . __('Einstellungen', 'lbwp') . '</button>
          <button class="button-primary save-table-button">' . __('Tabelle speichern', 'lbwp') . '</button>
        </div>
      ';
    }

    return $html;
  }
} 