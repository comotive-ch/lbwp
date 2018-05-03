<?php

namespace LBWP\Theme\Feature;

use LBWP\Helper\Cronjob;
use LBWP\Helper\Html2Text;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Metabox;
use LBWP\Helper\Mail\Base as MailService;
use LBWP\Module\Events\Component\EventType;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\TempLock;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Util\Multilang;
use LBWP\Newsletter\Core as NLCore;
use LBWP\Newsletter\Service\LocalMail\Implementation as ServiceImplementation;
use LBWP\Core as LbwpCore;

/**
 * Provides the service for local mail sending in a theme
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class LocalMailService
{
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
   * @var array configuration defaults
   */
  protected $config = array(
    'mailServiceId' => 'localmail',
    'mailServiceConfig' => array(),
    'maxRowsDisplayInBackend' => 500,
    'maxMailsPerSendPeriod' => 30,
    'unsubscribeSalt' => '9t2hoeg24tgrhg'
  );

  /**
   * @var array list of useable mail services
   */
  protected $services = array(
    'localmail' => array(
      'class' => '\LBWP\Helper\Mail\Local'
    ),
    'amazon-ses' => array(
      'class' => '\LBWP\Helper\Mail\AmazonSES'
    )
  );

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
    if (LbwpCore::isModuleActive('NewsletterBase')) {
      self::$initialized = true;
      self::$instance = new LocalMailService($options);
      self::$instance->initialize();
    }
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
    $newsletter = NLCore::getInstance();
    add_action('wp', array($this, 'handleUnsubscribe'));
    // Only register these, if needed
    if ($newsletter->getService() instanceof ServiceImplementation) {
      add_filter('lbwpFormActions', array($newsletter, 'addFormActions'));
      add_action('cron_job_localmail_sending', array($this, 'tryAndSendMails'));
      add_action('init', array($this, 'registerType'));
      add_action('admin_init', array($this, 'addMetaboxes'));
      add_action('save_post_' . self::LIST_TYPE_NAME, array($this, 'addMetaboxes'));
      add_filter('standard-theme-modify-data', array($this, 'replaceDefaultVariables'));
      add_filter('cs_wordpress_filter_post_data', array($this, 'filterEventNewsletterItem'), 10, 3);
    }

    // If configured, provide a UI to display unsubscribe url settings, and filter them into our config
    if ($this->config['useDynamicUnsubscribeUrls']) {
      add_action('customize_register', array($this, 'addCustomizerSettings'));
      $this->setDynamicUnsubscribeUrls();
    }
  }

  /**
   * Add various configurations to theme
   * @param \WP_Customize_Manager $customizer the customizer
   */
  public function addCustomizerSettings($customizer)
  {
    // Options
    $customizer->add_section('section_localmail', array(
      'title' => 'Lokaler Mailversand'
    ));
    $customizer->add_setting('unsubscribe_page_id', array(
      'default' => 0,
      'sanitize_callback' => array($this, 'sanitizePageId'),
    ));
    $customizer->add_control('unsubscribe_page_id', array(
      'type' => 'dropdown-pages',
      'section' => 'section_localmail',
      'label' => 'Abmeldeseite',
      'description' => 'Seite auf der jemand landet, der sich vom Newsletter abmeldet',
    ));
  }

  /**
   * @param int $pageId
   * @param \stdClass $setting
   * @return int sanitized setting value
   */
  public function sanitizePageId($pageId, $setting)
  {
    // Ensure $input is an absolute integer.
    $page_id = absint($pageId);
    // If $page_id is an ID of a published page, return it; otherwise, return the default.
    return ('publish' == get_post_status($pageId) ? $pageId : $setting->default);
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

    // Try getting the list type (can fail on first save)
    $type = get_post_meta($postId, 'list-type', true);

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

    if (strlen($type) == 0 || $type == 'static') {
      $helper->addDropdown('optin-type', $boxId, 'Opt-In-Typ', array(
        'items' => array(
          'default' => 'Direkte Anmeldung ohne Bestätigung',
          'none' => 'Keine Anmeldung (von aussen) möglich',
          'double' => 'Anmeldung erst bei Bestätigung der E-Mail-Adresse (Noch nicht implementiert)'
        )
      ));
      $helper->addInputText('field-config', $boxId, 'Feld-IDs', array(
        'description' => '
          Kommagetrennte Liste der Feld-IDs in der gleichen Reihenfolge wie sie in geuploadeten CSV Dateien vorkommen.
          Die Felder sollten nur Kleinbuchstaben und keine Sonderzeichen beinhalten. Beispiel: email,vorname,nachname,anrede,strasse,ort.
        '
      ));
    } else {
      $helper->addInputText('config-key', $boxId, 'Konfigurations-Schlüssel', array(
        'description' => 'Vom Entwickler genannter Konfigurations-Schlüssel für die automatische Liste'
      ));
    }
    // Hide the editor that is only active for uploads to work
    $helper->hideEditor($boxId);

    if ($postId > 0) {
      // Get the post and continue only if correct type
      if (get_post($postId)->post_type != self::LIST_TYPE_NAME) {
        return;
      }

      // Get the current field config
      $fields = get_post_meta($postId, 'field-config', true);
      $fields = array_map('trim', explode(',', $fields));

      // If there are fields to be mapped
      if (count($fields) > 0 && strlen($fields[0]) > 0) {
        // Predefine the item selections for the default fields
        $selection = array('empty' => 'Keine zuordnung');
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

      if ($type == 'dynamic' || (strlen($emailField) > 0 && count($fields) > 0)) {
        switch ($type) {
          case 'static':
            $this->addMetaboxForStaticLists($fields);
            break;
          case 'dynamic':
            $key = get_post_meta($postId, 'config-key', true);
            $this->addMetaboxForDynamicLists($postId, $key);
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
      $tempFile = File::getNewUploadFolder() . $fileName;
      $fileData = '';

      // If there is a local file system, try getting the file locally for temporary input
      if (CDN_TYPE == CDN_TYPE_NONE) {
        $filePath = get_attached_file($selectedList, true);
        $fileData = file_get_contents($filePath);
      }

      // If no data was read, try getting it from url
      if (strlen($fileData) == 0 ) {
        $fileData = file_get_contents($fileUrl);
      }

      // Put file data into the local temp file
      file_put_contents($tempFile, $fileData);
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
   * @param int $listId
   * @return array list data
   */
  public function getListData($listId)
  {
    $data = array();
    switch (get_post_meta($listId, 'list-type', true))
    {
      case 'dynamic':
        $key = get_post_meta($listId, 'config-key', true);
        $data = apply_filters('Lbwp_LMS_Data_' . $key, array(), $listId);
        break;
      case 'static':
      default:
        $data = ArrayManipulation::forceArray(get_post_meta($listId, 'list-data', true));
        break;
    }

    // Make sure to not use integer ids, but email hashes for best compat to other features
    foreach ($data as $key => $record) {
      if (is_numeric($key) && isset($record['email']) && strlen($record['email']) > 0) {
        $data[md5($record['email'])] = $record;
        unset($data[$key]);
      }
    }

    return $data;
  }

  /**
   * @param int $listId the post id of the list
   * @param array $fields the field names
   * @return string html table
   */
  protected function getStaticListData($listId, $fields = array())
  {
    $html = '';
    $rowId = 0;
    $countFields = count($fields);

    // First, display how many items are in the table
    $listData = $this->getListData($listId);
    $rowCount = count($listData);
    if ($rowCount > $this->config['maxRowsDisplayInBackend']) {
      $html .= 'Es sind aktuell ' . $rowCount . ' Datensätze vorhanden. Es werden nur ' . $this->config['maxRowsDisplayInBackend'] . ' davon angezeigt';
    } else {
      $html .= 'Es sind aktuell ' . $rowCount . ' Datensätze vorhanden.';
    }

    // If there are no fields, it might just be a dynamic list
    if ($countFields == 0 && $rowCount > 0) {
      reset($listData);
      $firstKey = key($listData);
      foreach ($listData[$firstKey] as $key => $value) {
        $fields[] = $key;
        $countFields++;
      }
    }

    // Create the table
    $html .= '<table class="mbh-generic-table">';

    // Create table headings from fields
    $html .= '<tr>';
    $html .= '<th>ID</th>';
    foreach ($fields as $field) {
      $html .= '<th>' . $field . '</th>';
    }
    $html .= '</tr>';

    // Display maximum number of records
    array_slice($listData, 0, $this->config['maxRowsDisplayInBackend'], true);

    foreach ($listData as $key => $record) {
      $html .= '<tr>';
      $html .= '<td data-key="' . $key . '">' . (++$rowId) . '</td>';
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
   * @param int $postId the post id
   */
  protected function addMetaboxForDynamicLists($postId, $key)
  {
    $helper = Metabox::get(self::LIST_TYPE_NAME);
    $boxId = 'dynamic-list-box';
    $helper->addMetabox($boxId, 'Dynamische Liste');

    // Show a message, if there is no key yet
    if (strlen($key) == 0) {
      $helper->addHtml('info', $boxId, '<p>Bitte geben Sie den Konfigurations-Schlüssel an.</p>');
      return;
    }

    // If we have a key, let actions from developers react to it
    do_action('Lbwp_LMS_Metabox_' . $key, $helper, $boxId, $postId);

    // Display the data information, if an import took place
    if (strlen($key) > 0) {
      $helper->addHtml('table', $boxId, $this->getStaticListData($postId));
    }
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
      'posts_per_page' => -1,
      'lang' => 'all'
    ));

    foreach ($lists as $list) {
      $data[$list->ID] = $list->post_title;
    }

    // Also allow developers to add dynamic segments
    $data = apply_filters('ComotiveNL_dynamic_target_get_list', $data);

    return $data;
  }

  /**
   * Do a newsletter unsubscription
   */
  public function handleUnsubscribe()
  {
    if (isset($_GET['lm_unsub'])) {
      list($recordId, $checkId, $listId) = explode('-', $_GET['lm_unsub']);
      $listId = intval($listId);
      $recordHash = md5($this->config['unsubscribeSalt'] . $recordId);
      // Unsubscribe, if check is valid and list is valid
      if ($listId > 0 && $recordHash == $checkId) {
        $this->unsubscribe($recordId, $listId);
      }
    }
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

  /**
   * Actually send a bunch of mails. If there are more mails to be sent, add another
   * job cron in a minute, if not, stop sending and remove the mailings from the list.
   */
  public function tryAndSendMails()
  {
    // The mailing id comes from the cron_data parameter
    $mailingId = $_GET['data'];
    // Check if the function is locked (= another cron is executing right now)
    if (TempLock::check('localmail_sending_' . $mailingId)) {
      return;
    }

    // Set a lock, before starting the process
    TempLock::set('localmail_sending_' . $mailingId, 55);

    $mailings = $this->getMailings();
    // Only proceed if the mailing still exists and is ready to send
    if (!isset($mailings[$mailingId]) || !Strings::startsWith($mailings[$mailingId], 'sending')) {
      TempLock::raise('localmail_sending_' . $mailingId);
      return;
    }

    // Create an instance of the sending service and configure it
    $class = $this->services[$this->config['mailServiceId']]['class'];
    /** @var MailService $service the service instance of Base */
    $service = new $class();
    $service->configure($this->config['mailServiceConfig']);
    $sendType = $mailings[$mailingId];

    // Load the mails of this mailing
    $mails = ArrayManipulation::forceArray(get_option('LocalMail_Mailing_' . $mailingId));

    // Log if there is a sending with no mails
    if (count($mails) == 0) {
      SystemLog::add('LocalMailService', 'critical', 'Tried to send mailing ' . $mailingId . ' with no mails!');
    }

    // Send maximum number of mails
    $mailsSent = 0;
    foreach ($mails as $id => $mail) {
      // Test if a mail like this has already been sent
      $securityKey = $mailingId . '-' . md5($mail['subject']) . '-' . md5($mail['recipient']);
      if ($sendType == 'sending' && wp_cache_get($securityKey, 'LocalMail') !== false) {
        // Log and send an email with critical state
        SystemLog::add('LocalMailService', 'critical', 'Preventing multi-send of localmail newsletter', array(
          'prevented-email' => $mail['subject'],
          'recipient' => $mail['recipient'],
          'total-mails' => count($mails)
        ));
        // Try saving the current mailings, if it doesn't fail on the first one
        $this->createMailObjects($mailingId, $mails);
        // Raise the lock anyway
        TempLock::raise('localmail_sending_' . $mailingId);
        return;
      }

      // Use the service to send the mail, tag it and reset after sending
      $service->setSubject($mail['subject']);
      $service->setBody($mail['html']);
      $service->setAltBody($this->generateAltBody($mail['html']));
      $service->setFrom($mail['senderEmail'], $mail['senderName']);
      $service->addReplyTo($mail['senderEmail']);
      $service->addAddress($mail['recipient']);
      $service->setTag($mailingId);
      $service->send();
      $service->reset();

      // Set a cache key to prevent multi-sending the same mail
      wp_cache_set($securityKey, 1, 'LocalMail', 600);

      // Log the sent email
      SystemLog::add('LocalMailService', 'debug', 'sending email to "' . $mail['recipient'] . '"', array(
        'subject' => $mail['subject'],
        'recipient' => $mail['recipient'],
        'mailingId' => $mailingId,
      ));

      // Unset from the array so it's not sent again
      unset($mails[$id]);

      // Check if we need to take a break
      if (++$mailsSent >= $this->config['maxMailsPerSendPeriod']) {
        break;
      }
    }

    // After sending the block, are there still mails left?
    if (count($mails) > 0) {
      // Save the mails back into the mailing (deleting the already sent ones)
      $this->createMailObjects($mailingId, $mails);
      // Schedule another cron
      $this->scheduleSendingCron($mailingId);
    } else {
      // Delete the mailing completely and don't reschedule
      $this->removeMailing($mailingId);
    }

    // Raise the cron lock
    TempLock::raise('localmail_sending_' . $mailingId);
  }

  /**
   * @param string $html
   * @return string text variant
   */
  protected function generateAltBody($html)
  {
    $worker = new Html2Text($html);
    return $worker->getText();
  }

  /**
   * @param array $data configuration data
   * @return array maybe replaced variables
   */
  public function replaceDefaultVariables($data)
  {
    $mcReplacers = array(
      '*|LNAME|*' => '{lastname}',
      '*|FNAME|*' => '{firstname}',
      '*|EMAIL|*' => '{email}',
      '*|UNSUB|*' => '{unsubscribe}',
      '*|FORWARD|*' => '',
      '*|ARCHIVE|*' => ''
    );

    foreach ($mcReplacers as $key => $value) {
      $data = ArrayManipulation::deepReplace($key, $value, $data);
    }

    return $data;
  }

  /**
   * @param array $data the data array for the content source
   * @param int $eventId the event id
   * @param \WP_Post $event the event object (post native, no meta info)
   * @return array $data array slightly changed
   */
  public function filterEventNewsletterItem($data, $eventId, $event)
  {
    // Only change something, if it is an event
    if ($event->post_type == EventType::EVENT_TYPE) {
      // Attach list and email id to the link in newsletter
      $data['link'] = Strings::attachParam('list', '_listId', $data['link']);
      $data['link'] = Strings::attachParam('ml', '_emailId', $data['link']);
      // Create empty event meta data array, if not already given
      add_post_meta($eventId, 'subscribeInfo', array(), true);
    }

    return $data;
  }

  /**
   * @param string $id the mailing status
   * @param string $status the mailing status
   */
  public function setMailing($id, $status)
  {
    $mailings = $this->getMailings();
    $mailings[$id] = $status;
    update_option('LocalMail_Mailings', $mailings);
  }

  /**
   * Removes a mailing from the list of local mail mailings
   * @param string $id
   */
  public function removeMailing($id)
  {
    $mailings = $this->getMailings();
    unset($mailings[$id]);
    update_option('LocalMail_Mailings', $mailings);
    delete_option('LocalMail_Mailing_' . $id);
  }

  /**
   * @return array all mailings or empty array if there are none
   */
  public function getMailings()
  {
    return ArrayManipulation::forceArray(get_option('LocalMail_Mailings'));
  }

  /**
   * Create an unsubscribe link
   * @param string $memberId the member id
   * @param int $listId the list id
   * @param string $language the language code
   * @return string the unsubscribe url
   */
  public function getUnsubscribeLink($memberId, $listId, $language)
  {
    $unsubscribeCode = $memberId . '-' . md5($this->config['unsubscribeSalt'] . $memberId) . '-' . $listId;
    return $this->config['unsubscribeUrl_' . $language] . '?lm_unsub=' . $unsubscribeCode;
  }

  /**
   * Sets the dynamic urls to our unsubscribe url scheme in config
   */
  protected function setDynamicUnsubscribeUrls()
  {
    $pageId = intval(get_theme_mod('unsubscribe_page_id'));

    // If multilang, maybe get a translation of that page, depending on current language
    if (Multilang::isActive()) {
      foreach (Multilang::getAllLanguages() as $language) {
        if ($language != Multilang::getPostLang($pageId)) {
          $pageId = Multilang::getPostIdInLang($pageId, $language);
        }
        $this->config['unsubscribeUrl_' . $language] = get_permalink($pageId);
      }
    } else {
      $this->config['unsubscribeUrl_de'] = get_permalink($pageId);
    }
  }

  /**
   * Schedule a sending cron in n-seconds
   * @param string $mailingId the mailing it to be sent
   * @param int $seconds to wait until the cron is called
   */
  public function scheduleSendingCron($mailingId, $seconds = 60)
  {
    Cronjob::register(array(
      (current_time('timestamp') + $seconds) => 'localmail_sending::' . $mailingId
    ));
  }

  /**
   * @param string $mailingId the mailing id to save to
   * @param array $mails the emails to be sent
   */
  public function createMailObjects($mailingId, $mails)
  {
    update_option('LocalMail_Mailing_' . $mailingId, $mails);
  }
}



