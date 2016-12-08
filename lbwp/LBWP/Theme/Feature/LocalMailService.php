<?php

namespace LBWP\Theme\Feature;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Metabox;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Newsletter\Core as NLCore;

/**
 * Provides the service for local mail sending in a theme
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class LocalMailService
{
  /**
   * @var array configuration defaults
   */
  protected $config = array();
  /**
   * @var LocalMailService the instance
   */
  protected static $instance = NULL;
  /**
   * @var bool determines if the mail service has been initialized and configured
   */
  protected static $initialized = false;
  /**
   * @var array Pre-defined variables to be mapped to fields
   */
  protected $variables = array();
  /**
   * The name/slug of the mailing list type
   */
  const LIST_TYPE_NAME = 'lbwp-mailing-list';
  /**
   * Maximum number of rows in list data shown
   */
  const MAX_ROWS_DISPLAYED = 25;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return LocalMailService the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$initialized = true;
    self::$instance = new LocalMailService($options);
    self::$instance->initialize();
  }

  /**
   * @return bool determines if the service is working
   */
  public static function isWorking()
  {
    return self::$initialized;
  }

  /**
   * Called from NL plugin / service layer, if the service is actually used
   */
  public function initialize()
  {
    add_action('init', array($this, 'registerType'));
    add_action('admin_init', array($this, 'addMetaboxes'));
    add_action('save_post_' . self::LIST_TYPE_NAME, array($this, 'addMetaboxes'));
    add_filter('lbwpFormActions', array(NLCore::getInstance(), 'addFormActions'));
  }

  /**
   * Register the type for sending lists
   */
  public function registerType()
  {
    WordPress::registerType(self::LIST_TYPE_NAME, 'Versandliste', 'Versandlisten', array(
      'exclude_from_search' => false,
      'publicly_queryable' => false,
      'show_in_admin_bar' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-email-alt',
      'menu_position' => 43,
      'supports' => array('title', 'editor')
    ), '');
  }

  /**
   * Add metabox functionality
   */
  public function addMetaboxes()
  {
    $postId = $this->getCurrentPostId();

    // Only do something, if a post ID is given
    $helper = Metabox::get(self::LIST_TYPE_NAME);
    // Basic field definitions that need to be set first
    $boxId = 'basic-config';
    $helper->addMetabox($boxId, 'Listentyp und Daten-Format');
    $helper->addDropdown('list-type', $boxId, 'Listen-Typ', array(
      'description' => 'In der Regel können statische Listen verwendet werden. Automatische Listen müssen technisch umgesetzt werden.',
      'items' => array(
        'static' => 'Statische Liste (CSV Upload)',
        'dynamic' => 'Automatische Liste aus dynamischer Quelle'
      )
    ));
    $helper->addDropdown('optin-type', $boxId, 'Opt-In-Typ', array(
      'items' => array(
        'default' => 'Direkte Anmeldung ohne Bestätigung',
        'double' => 'Anmeldung erst bei Bestätigung der E-Mail-Adresse (Noch nicht implementiert)'
      )
    ));
    $helper->addInputText('field-config', $boxId, 'Feld-IDs', array(
      'description' => '
      Kommagetrennte Liste der Feld-IDs in der gleichen Reihenfolge wie sie in geuploadeten CSV Dateien oder der automatische Liste vorkommen.
      Die Felder sollten nur Kleinbuchstaben und keine Sonderzeichen beinhalten. Beispiel: email,vorname,nachname,anrede,strasse,ort.'
    ));
    // Hide the editor that is only active for uploads to work
    $helper->hideEditor($boxId);

    if ($postId > 0) {
      // Get the current field config
      $fields = get_post_meta($postId, 'field-config', true);
      $fields = array_map('trim', explode(',', $fields));

      // If there are fields to be mapped
      if (count($fields) > 0) {
        // Predefine the item selections for the default fields
        $selection = array();
        foreach ($fields as $fieldId) {
          $selection[$fieldId] = $fieldId;
        }

        $boxId = 'field-definitions';
        $helper->addMetabox($boxId, 'Felder-Mapping');
        $helper->addDropdown($this->variables['email'], $boxId, 'E-Mail-Feld', array('items' => $selection));
        $helper->addDropdown($this->variables['firstname'], $boxId, 'Vorname-Feld', array('items' => $selection));
        $helper->addDropdown($this->variables['lastname'], $boxId, 'Nachname-Feld', array('items' => $selection));
        $helper->addDropdown($this->variables['salutation'], $boxId, 'Anrede-Feld', array('items' => $selection));
      }

      // Switch on the type to display the data/upload box
      $type = get_post_meta($postId, 'list-type', true);
      $emailField = get_post_meta($postId, $this->variables['email'], true);

      if (strlen($emailField) > 0 && count($fields) > 0) {
        switch ($type) {
          case 'static':
            $this->addMetaboxForStaticLists($fields);
            break;
          case 'dynamic':
            $this->addMetaboxForDynamicLists($fields);
            break;
        }
      }
    }
  }

  /**
   * Displays metaboxes for static lists
   * @param array $fields list of all field keys
   */
  protected function addMetaboxForStaticLists($fields)
  {
    $helper = Metabox::get(self::LIST_TYPE_NAME);
    $boxId = 'static-list-box';
    $helper->addMetabox($boxId, 'Statische Liste');

    // Get current and possibly new list selection
    $postId = $this->getCurrentPostId();
    $lastImportedList = get_post_meta($postId, 'last-imported-list', true);
    $selectedList = get_post_meta($postId, 'selected-list', true);

    // Import the list, if it changed
    if (intval($selectedList) > 0 && $lastImportedList != $selectedList) {
      // Make a local file to actually import data
      $fileUrl = wp_get_attachment_url($selectedList);
      $fileName = File::getFileOnly($fileUrl);
      $tempFile = get_temp_dir() . $fileName;
      file_put_contents($tempFile, file_get_contents($fileUrl));
      $data = Csv::getArray($tempFile);
      // Now do the actual list data import, if possible
      if (is_array($data) && count($data) > 0 && count($data[0]) >= count($fields)) {
        $info = $this->importStaticListData($postId, $data, $fields, $fileName);
      } else {
        $info = 'Datei ' . $fileName . ' konnte nicht importiert werden (Leer oder ungültiges Format)';
      }

      // Set the current list as last imported, also add an info that the import worked
      $lastImportedList = $selectedList;
      update_post_meta($postId, 'last-imported-list', $selectedList);
      update_post_meta($postId, 'last-import-info', $info);
    }

    // Display the metabox field to upload the list
    $helper->addDropdown('import-type', $boxId, 'Importverhalten', array('items' => array(
      'flush' => 'Bestehende Daten vor dem Import löschen',
      'preserve' => 'Bestehende Daten nicht löschen, existierende Datensätze nicht überschreiben',
      'override' => 'Bestehende Daten nicht löschen, existierende Datensätze überschreiben',
    )));
    $helper->addMediaUploadField('selected-list', $boxId, 'CSV-Import-Datei');

    // Display the data information, if an import took place
    if (strlen($lastImportedList) > 0) {
      $info  = get_post_meta($postId, 'last-import-info', true);
      $helper->addHtml('info', $boxId, '<p>' . $info . '</p>');
      $helper->addHtml('table', $boxId, $this->getStaticListData($postId, $fields));
    }
  }

  /**
   * @param int $postId the post id of the list
   * @param array $fields the field names
   * @return string html table
   */
  protected function getStaticListData($postId, $fields)
  {
    $html = '';
    $countFields = count($fields);

    // First, display how many items are in the table
    $listData = ArrayManipulation::forceArray(get_post_meta($postId, 'list-data', true));
    $rowCount = count($listData);
    if ($rowCount > self::MAX_ROWS_DISPLAYED) {
      $html .= 'Es sind aktuell ' . $rowCount . ' Datensätze vorhanden. Es werden nur ' . self::MAX_ROWS_DISPLAYED . ' davon angezeigt';
    } else {
      $html .= 'Es sind aktuell ' . $rowCount . ' Datensätze vorhanden.';
    }

    // Create the table
    $html .= '<table class="mbh-generic-table">';

    // Create table headings from fields
    $html .= '<tr>';
    foreach ($fields as $field) {
      $html .= '<th>' . $field . '</th>';
    }
    $html .= '</tr>';

    // Display maximum number of records
    array_slice($listData, 0, self::MAX_ROWS_DISPLAYED, true);

    foreach ($listData as $record) {
      $html .= '<tr>';
      $countCells = 0;
      foreach ($record as $field) {
        if ($countFields >= ++$countCells) {
          $html .= '<td>' . $field . '</td>';
        }
      }
      $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
  }

  /**
   * @param int $listId the list id
   * @return array list of translations
   */
  protected function getTranslations($listId)
  {
    return array(
      get_post_meta($listId, $this->variables['email'], true) => $this->variables['email'],
      get_post_meta($listId, $this->variables['firstname'], true) => $this->variables['firstname'],
      get_post_meta($listId, $this->variables['lastname'], true) => $this->variables['lastname'],
      get_post_meta($listId, $this->variables['salutation'], true) => $this->variables['salutation'],
    );
  }

  /**
   * @param int $postId the post id to import to
   * @param array $data CSV data to import
   * @param array $fields the field names in order to map the data to
   * @param string $file the file name that is imported
   * @return string the message
   */
  protected function importStaticListData($postId, $data, $fields, $file)
  {
    // Translate integrated fields into their respective config
    $translations = $this->getTranslations($postId);

    // Translate fields, and determine the index of the email field
    $emailIndex = -1;
    foreach ($fields as $key => $field) {
      if (isset($translations[$field])) {
        $fields[$key] = $translations[$field];
      }
      if ($this->variables['email'] == $field) {
        $emailIndex = $key;
      }
    }

    // If there is no email index, return with error
    if ($emailIndex < 0) {
      return 'Datei ' . $file . ' konnte nicht importiert werden: E-Mail Feld nicht vorhanden.';
    }

    // Finally, do the actual import
    $listData = ArrayManipulation::forceArray(get_post_meta($postId, 'list-data', true));
    $importType = get_post_meta($postId, 'import-type', true);
    // If flushing import, delete list data completely before re-importing
    if ($importType == 'flush') {
      $listData = array();
      // Further mode is hence, override, no isset checks needed
      $importType = 'override';
    }

    // Import (and possibly override) the new data
    foreach ($data as $record) {
      // Validate the record by checking email syntax
      if (Strings::checkEmail($record[$emailIndex])) {
        $recordId = md5($record[$emailIndex]);
        // Skip import, if type is preserve (not override) and records is available already
        if ($importType == 'preserve' && isset($listData[$recordId])) {
          continue;
        }

        // Import a new record if we come here
        $dataset = array();
        foreach ($record as $index => $value) {
          $dataset[$fields[$index]] = utf8_encode($value);
        }
        $listData[$recordId] = $dataset;
      }
    }

    // Save the new list data and return with success
    update_post_meta($postId, 'list-data', $listData);
    return 'Datei ' . $file . ' importiert am ' . Date::getTime(Date::EU_DATETIME) . ' Uhr';
  }

  /**
   * Displays metaboxes for dynamic lists
   * @param array $fields list of all field keys
   */
  protected function addMetaboxForDynamicLists($fields)
  {
    $helper = Metabox::get(self::LIST_TYPE_NAME);
    $boxId = 'dynamic-list-box';
    $helper->addMetabox($boxId, 'Dynamische Liste');
    $helper->addHtml('info', $boxId, '<p>Dynamische Listen werden noch nicht unterstützt.</p>');
  }

  /**
   * @param string $variables the field variables
   */
  public function setVariables($variables)
  {
    $this->variables = $variables;
  }

  /**
   * @return array key/value pair of list ids and names
   */
  public function getLists()
  {
    $data = array();
    $lists = get_posts(array(
      'post_type' => self::LIST_TYPE_NAME,
      'orderby' => 'title',
      'order' => 'ASC',
      'posts_per_page' => -1
    ));

    foreach ($lists as $list) {
      $data[$list->ID] = $list->post_title;
    }

    return $data;
  }

  /**
   * @param string $recordId the record id (md5 of email)
   * @param int $listId the list id to save to
   * @param string $data the data array to be added
   * @return bool always true
   */
  public function subscribe($recordId, $listId, $data)
  {
    // Get the list data and field configuration
    $fields = get_post_meta($listId, 'field-config', true);
    $fields = array_map('trim', explode(',', $fields));
    $listData = ArrayManipulation::forceArray(get_post_meta($listId, 'list-data', true));
    $translations = $this->getTranslations($listId);

    // Translate the fields into the variable fields
    foreach ($fields as $key => $field) {
      if (isset($translations[$field])) {
        $fields[$key] = $translations[$field];
      }
    }

    $record = array();
    foreach ($fields as $fieldId) {
      if (isset($data[$fieldId])) {
        $record[$fieldId] = $data[$fieldId];
      } else {
        $record[$fieldId] = '';
      }
    }

    // Add the data record (or replace it by id)
    $listData[$recordId] = $record;
    update_post_meta($listId, 'list-data', $listData);
    return true;
  }

  /**
   * @param string $recordId the record to be removed
   * @param int $listId on which list should it be removed
   * @return bool always true, even if not existant record
   */
  public function unsubscribe($recordId, $listId)
  {
    // Load list, remove record and save back to DB
    $listData = ArrayManipulation::forceArray(get_post_meta($listId, 'list-data', true));
    unset($listData[$recordId]);
    update_post_meta($listId, 'list-data', $listData);
    return true;
  }

  /**
   * @return int the current post id or 0 if not available
   */
  protected function getCurrentPostId()
  {
    // Get a post id (depending on get or post, context)
    $postId = intval($_GET['post']);
    if ($postId == 0) {
      $postId = intval($_POST['post_ID']);
    }

    return $postId;
  }
}


