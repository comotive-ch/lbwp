<?php

namespace LBWP\Theme\Feature;

use LBWP\Helper\Converter;
use LBWP\Helper\Cronjob;
use LBWP\Helper\Html2Text;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Metabox;
use LBWP\Helper\Mail\Base as MailService;
use LBWP\Module\Events\Component\EventType;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\TempLock;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Util\Multilang;
use LBWP\Util\LbwpData;
use LBWP\Newsletter\Core as NLCore;
use LBWP\Newsletter\Service\Base as ServiceImplementation;
use LBWP\Newsletter\Service\LocalMail\Implementation as LocalMailImplementation;
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
   * Sending 75 mails should take about 20s (of 60s)
   * @var array configuration defaults
   */
  protected $config = array(
    'mailServiceId' => 'localmail',
    'mailServiceConfig' => array(),
    'maxMailsPerSendPeriod' => 100,
    'unsubscribeSalt' => '9t2hoeg24tgrhg'
  );

  /**
   * @var array list of useable mail services
   */
  protected $services = array(
    'localmail' => array(
      'class' => '\LBWP\Helper\Mail\Local'
    ),
    'development' => array(
      'class' => '\LBWP\Helper\Mail\Development'
    ),
    'amazon-ses' => array(
      'class' => '\LBWP\Helper\Mail\AmazonSES'
    ),
    'comotive-mail' => array(
      'class' => '\LBWP\Helper\Mail\ComotiveMail'
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
      self::$instance->setDynamicDoubleOptInUrls();
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
    $service = $newsletter->getService();
    add_action('wp', array($this, 'handleUnsubscribe'));
    add_action('wp_ajax_deleteStaticListEntry', array($this, 'deleteStaticListEntry'));
    add_action('lbwp_incoming_mail_sendto-static-list', array($this, 'sendIncomingMailToList'));
    // Only register these, if needed (in fact, whenever a newsletter is possible, as local sending is always an option now)
    if ($service instanceof ServiceImplementation) {
      add_filter('lbwpFormActions', array($newsletter, 'addFormActions'));
      add_action('cron_job_localmail_sending', array($this, 'tryAndSendMails'));
      add_action('cron_job_check_orphaned_mails', array($this, 'checkOrphanedMails'));
      add_action('cron_hourly_daytime_all', array($this, 'checkOrphanedMails'));
      add_action('init', array($this, 'registerType'));
      add_action('admin_init', array($this, 'addMetaboxes'));
      add_action('save_post_' . self::LIST_TYPE_NAME, array($this, 'addMetaboxes'));
      add_filter('cs_wordpress_filter_post_data', array($this, 'filterEventNewsletterItem'), 10, 3);
      add_action('wp', array($this, 'checkDoubleOptInSubscription'));
    }

    // Instantiate to get variables, if the service is not of LocalMail
    if (!($service instanceof LocalMailImplementation)) {
      $instance = new LocalMailImplementation($newsletter, true);
      $this->setVariables($instance->getVariables());
    } else {
      // Only do this with the NL theme if it is full local mail
      add_filter('standard-theme-modify-data', array($this, 'replaceDefaultVariables'));
    }

    // If configured, provide a UI to display unsubscribe url settings, and filter them into our config
    if ($this->config['useDynamicUnsubscribeUrls']) {
      add_action('customize_register', array($this, 'addUnsubscribeCustomizerSettings'));
      $this->setDynamicUnsubscribeUrls();
    }

    // If configured, provide a UI to display double optin url settings, and filter them into our config
    if ($this->config['useDoubleOptinPages']) {
      add_action('customize_register', array($this, 'addDoubleOptInCustomizerSettings'));
    }

    // Display Email content in a new tab
    if(isset($_GET['email']) && is_user_logged_in() && current_user_can('manage_options')){
      echo '<div style="width: 90%; max-width: 560px; margin: 6rem auto;">' . get_post_meta($_GET['post'], 'lbwp-mail-distributor-history', true)[$_GET['email']]['content'] . '</div>';
      exit;
    }
  }

  /**
   * Checks if there are mails in the queue that should have been sent, but are still in queue
   * @return void
   */
  public function checkOrphanedMails()
  {
    $db = WordPress::getDb();
    // This gets every unsent mail that should have been sent 30 min ago or longer
    $sql = '
      SELECT COUNT(pid) FROM ' . $db->prefix . 'lbwp_data 
      WHERE row_key LIKE "localmail_stats_%"
      AND user_id > 0
      AND DATE_ADD(row_modified, INTERVAL 30 MINUTE) < NOW()
      AND row_data LIKE \'%"sent":0,%\'
    ';
    $unsentMailCount = intval($db->get_var($sql));

    // Also count grand total of the last 24 hours
    $sql = '
      SELECT COUNT(pid) FROM ' . $db->prefix . 'lbwp_data 
      WHERE row_key LIKE "localmail_stats_%"
      AND user_id > 0
      AND DATE_ADD(row_modified, INTERVAL 24 HOUR) > NOW()
    ';
    $mailTotal = intval($db->get_var($sql));

    // How many percent are unset?
    $unsentPercentage = 0;
    if ($unsentMailCount > 0 && $mailTotal > 0) {
      $unsentPercentage = round($unsentMailCount / $mailTotal * 100, 2);
    }

    // Send info mail if more than 100 mails or over 90% unsent
    if ($unsentMailCount > 100 || $unsentPercentage > 90) {
      $mail = External::PhpMailer();
      $cronlist = Cronjob::list();
      $url = get_bloginfo('url');
      $host = parse_url($url, PHP_URL_HOST);
      $mail->Subject = '[' . $host . '] Possibly unsent mails in local mail queue';
      $mail->addAddress('it+monitoring@comotive.ch');
      $mail->Body = '
        Mails total within 24h: ' . $mailTotal . '<br>
        Mails unsent for 30min or longer: ' . $unsentMailCount . '<br>
        Percentage of unsent mails: ' . $unsentPercentage . '%<br>
        <br>
        <a href="' . $url . '/wp-admin/admin.php?page=comotive-newsletter/admin/dispatcher.php">Check the mail queue</a><br>
        <br>
        Current Crons: <br>' . print_r($cronlist, true) . '
      ';
    }
  }

  /**
   * @param $data
   * @return void
   */
  public function sendIncomingMailToList($data)
  {
    $listId = intval(array_shift($data['additional']));
    $sendConfimation = get_post_meta($listId, 'lbwp-mail-distributor-send-confirmation', true) === 'on';

    // Only send mail if it has been activated in the backend
    if(get_post_meta($listId, 'lbwp-mail-distributor-active', true) !== 'on'){
      return;
    }

    // Security checks
    $mailingWhitelist = [];

    // Only allow wp users to send mails
    if(get_post_meta($listId, 'lbwp-mail-distributor-allow-wp-users', true) === 'on'){
      $userQuery = array(
        'fields' => 'user_email'
      );

      $allowedRoles = get_post_meta($listId, 'lbwp-mail-distributor-roles');
      if(is_array($allowedRoles) && !empty($allowedRoles)){
        $userQuery['role__in'] = $allowedRoles;
      }

      $users = get_users($userQuery);
      $mailingWhitelist = $users;
    }

    // Or only allow mails on the whitelist
    $whitelistField = get_post_meta($listId, 'lbwp-mail-distributor-whitelist', true);
    if($whitelistField !== ''){
      $mailingWhitelist = array_merge($mailingWhitelist, array_map('trim', explode(',', $whitelistField)));
    }

    // Check if the sender is in the whitelist before sending the email
    if(!empty($mailingWhitelist)){
      if(!in_array($data['from'], $mailingWhitelist)){
        if(wp_cache_get('lbwp_sendIncomingMailToList_error_' . $data['from']) !== true){
          SystemLog::add('LocalMailService::sendIncomingMailToList', 'debug', 'Disallowed sender, aborting mail redirection', 'The email: ' . $data['from'] . ' tried to send an email to the list #' . $listId);

          $mail = External::PhpMailer();
          $mail->addAddress($data['from']);
          $mail->Subject = get_bloginfo('name') . ' - Email konnte nicht an Versandliste gesendet werden';
          $mail->Body = 'Leider konnte dein Email nicht an die gewünschte Versandliste gesendet werden. Du bist nicht berechtigt an dieser Liste Emails zu senden.';
          $mail->send();

          wp_cache_set('lbwp_sendIncomingMailToList_error_' . $data['from'], true, '', 3600);
        }

        return;
      }
    }

    $list = $this->getListData($listId);

    // Create the mail
    $mail = External::PhpMailer();
    $mail->Subject = $data['subject'];
    $mail->Body = $data['html'];
    $mail->AltBody = $data['text'];

    // Just add everyone as bcc, so technically send just one mail
    foreach ($list as $entry) {
      $mail->addBCC($entry['email']);
    }

    // Support for attachments
    $attachmentNames = array();
    if (isset($data['attachments']) && is_array($data['attachments']) && count($data['attachments'])) {
      $folder = File::getNewUploadFolder();
      foreach ($data['attachments'] as $filename => $base64) {
        $attachmentNames[] = $filename;
        $filePath = $folder . $filename;
        file_put_contents($filePath, base64_decode($base64));
        $mail->addAttachment($filePath, $filename);
      }
    }

    // If there are recipients, send
    if (count($mail->getBccAddresses()) > 0) {
      $mail->send();
      SystemLog::add('LocalMailService::sendIncomingMailToList', 'debug', 'Email sent to list ' . $listId, array($data['from'], imap_utf8($data['subject'])));

      $history = ArrayManipulation::forceArray(get_post_meta($listId, 'lbwp-mail-distributor-history', true));
      $history[time()] = array(
        'sender' => $data['from'],
        'subject' => $data['subject'],
        'content' => $data['html'],
        'attachments' => $attachmentNames,
      );

      update_post_meta($listId, 'lbwp-mail-distributor-history', $history);
    }

    if($sendConfimation){
      $confEmail = External::PhpMailer();
      $confEmail->addAddress($data['from']);
      $confEmail->Subject = get_bloginfo('name') . ' | Deine Email wurdet gesendet';
      $confEmail->Body = 'Hallo<br>Deine Email «' . imap_utf8($data['subject']) . '» wurde an die Liste ' . get_the_title($listId) . ' gesendet';
      $confEmail->send();
    }
  }

  /**
   * Add various configurations to theme
   * @param \WP_Customize_Manager $customizer the customizer
   */
  public function addUnsubscribeCustomizerSettings($customizer)
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
   * Add various configurations to theme
   * @param \WP_Customize_Manager $customizer the customizer
   */
  public function addDoubleOptInCustomizerSettings($customizer)
  {
    // Options
    $customizer->add_section('section_localmail', array(
      'title' => 'Lokaler Mailversand'
    ));
    $customizer->add_setting('double_optin_page_id', array(
      'default' => 0,
      'sanitize_callback' => array($this, 'sanitizePageId'),
    ));
    $customizer->add_control('double_optin_page_id', array(
      'type' => 'dropdown-pages',
      'section' => 'section_localmail',
      'label' => 'Double-Opt-In Bestätigungsseite',
      'description' => 'Seite auf der jemand landet, der sich über die Double-Opt-In E-Mail definitiv anmeldet',
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
      'supports' => array('title', 'editor'),
      'rewrite' => false
    ), '');
  }

  /**
   * Add metabox functionality
   */
  public function addMetaboxes()
  {
    $postId = $this->getCurrentPostId();

    $this->handleAddRow();

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
        'crm' => 'Liste aus CRM-Daten generieren',
        'combi' => 'Kombination aus bestehenden Listen',
        'dynamic' => 'Automatische Liste aus dynamischer Quelle'
      )
    ));

    // Resetter for cached lists, if feature is active
    if (defined('LBWP_LMS_ENABLE_CACHE_CRM_LISTS')) {
      if (isset($_GET['flush-crm-cache'])) {
        // Flush the cache, list is reloaded within this functions button, also display a message
        wp_large_cache_delete('lbwp_crm_cached_list_' . $postId, 'LMS');
        echo '<div id="message" class="notice notice-success is-dismissible updated"><p>Die Liste wurde neu geladen.</p></div>';
      }
      $helper->addMetabox('lms-cache-reset-crm', 'Cache zurücksetzen', 'side', 'low');
      $helper->addHtml('cache-reset-info', 'lms-cache-reset-crm', '
        <p>
          Diese Liste ist für schnellere Ladezeiten zwischengespeichert.
          <a href="/wp-admin/post.php?post=' . $postId . '&action=edit&flush-crm-cache">Jetzt neu laden</a>
        </p>  
      ');
    }

    if ($type == 'static') {
      // Add metabox for the lbwp auto mail
      $lbwpMailBox = 'lbwp-mail-distributor';
      $helper->addMetabox($lbwpMailBox, 'Direktes Mailing', 'side');

      $helper->addCheckbox('lbwp-mail-distributor-active', $lbwpMailBox, '', array('description' => 'Funktion aktivieren'));

      $autoEmailAddress = LBWP_HOST . '_sendto-static-list_' . $postId . '@commotive.ch';
      $helper->addHtml('lbwp-mail-distributor-html', $lbwpMailBox, '
        <code id="lbwp-mail-distributor-field" style="font-size: 10px; margin-bottom: .5rem; display: block;" overflow:auto;>' . $autoEmailAddress . '</code>
        <p>Mit dieser E-Mail Adresse kannst du direkt an die Liste E-Mails senden & weiterleiten.</p>
        <script>
          const node = document.getElementById("lbwp-mail-distributor-field");
          
          node.addEventListener("click", () => {
            if (document.body.createTextRange) {
              const range = document.body.createTextRange();
              range.moveToElementText(node);
              range.select();
            } else if (window.getSelection) {
              const selection = window.getSelection();
              const range = document.createRange();
              range.selectNodeContents(node);
              selection.removeAllRanges();
              selection.addRange(range);
            }
          });
        </script>');

      $helper->addHeading($lbwpMailBox, 'Senden Einschränken');
      $helper->addCheckbox('lbwp-mail-distributor-allow-wp-users', $lbwpMailBox, '', array('description' => 'Nur WordPress Benutzer erlauben'));
      $items = array();
      foreach(wp_roles()->roles as $roleName => $role){
        $items[$roleName] = $role['name'];
      }
      $helper->addDropdown('lbwp-mail-distributor-roles', $lbwpMailBox, 'Einschränken auf bestimmte Rollen:', array(
        'multiple' => true,
        'items' => $items
      ));
      $helper->addTextarea('lbwp-mail-distributor-whitelist', $lbwpMailBox, 'Weitere Absender E-Mail-Adressen', 65, array(
        'description' => 'Mehrere E-Mail-Adressen mit Komma getrennt eingeben.'
      ));

      $helper->addDropdown('optin-type', $boxId, 'Opt-In-Typ', array(
        'items' => array(
          'default' => 'Direkte Anmeldung ohne Bestätigung',
          'none' => 'Keine Anmeldung (von aussen) möglich',
          'double' => 'Anmeldung erst bei Bestätigung der E-Mail-Adresse'
        )
      ));
      $helper->addInputText('field-config', $boxId, 'Feld-IDs', array(
        'description' => '
          Wir bei Upload automatisch ausgefüllt. Kommagetrennte Liste der Feld-IDs in der gleichen Reihenfolge wie sie in geuploadeten CSV Dateien vorkommen.
          Die Felder sollten nur Kleinbuchstaben und keine Sonderzeichen beinhalten. Beispiel: email,vorname,nachname,anrede,strasse,ort.
        '
      ));

      $helper->addHeading($lbwpMailBox, 'Einstellungen');
      $helper->addCheckbox('lbwp-mail-distributor-send-confirmation', $lbwpMailBox, '', array('description' => 'Bestätigung an Absender schicken'));


      if(get_post_meta($postId, 'lbwp-mail-distributor-active', true) === 'on'){
        $lbwpMailBoxHistory = 'lbwp-mail-distributor-hisotry';
        $helper->addMetabox($lbwpMailBoxHistory, 'Direktes Mailing Verlauf', 'normal', 'low');

        $history = get_post_meta($postId, 'lbwp-mail-distributor-history', true);

        if(empty($history)){
          $helper->addHtml('lbwp-mail-distributor-history-content', $lbwpMailBoxHistory, '<p>Es wurden noch keine Emails direkt an diese Liste geschickt.</p>');
        }else{
          $historyHtml = '<table class="mbh-generic-table">
            <thead>
              <tr>
                <th>Zeit</th>
                <th>Absender</th>
                <th>Betreff</th>
                <th>Inhalt</th>
                <th>Anhänge</th>
              </tr>
            </thead>
            <tbody>';

          krsort($history);
          foreach ($history as $time => $entry){
            $attachments = '';
            if(!empty($entry['attachments'])){
              foreach($entry['attachments'] as $attachment){
                $attachments .= '<p>' . $attachment . '</p>';
              }
            }

            $historyHtml .= '<tr>
              <td>' . date('H:i d.m.Y', $time) . '</td>
              <td>' . $entry['sender'] . '</td>
              <td>' . $entry['subject'] . '</td>
              <td><a href="/wp-admin/post.php?post=' . $postId . '&email=' . $time . '" target="_blank">Inhalt ansehen</a></td>
              <td>' . $attachments . '</td>
            </tr>';
          }

          $historyHtml .= '
            </tbody>
          </table>';


          $helper->addHtml('lbwp-mail-distributor-history-content', $lbwpMailBoxHistory, $historyHtml);
        }
      }

    } else if ($type == 'dynamic') {
      $helper->addInputText('config-key', $boxId, 'Konfigurations-Schlüssel', array(
        'description' => 'Vom Entwickler genannter Konfigurations-Schlüssel für die automatische Liste'
      ));
      do_action('Lbwp_LMS_Metabox_top_dynamic', $helper, $boxId, $postId);
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
      if ($type == 'static') {
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

      if (is_array($this->variables) && count($this->variables) > 0) {
        // Switch on the type to display the data/upload box
        $type = get_post_meta($postId, 'list-type', true);

        switch ($type) {
          case 'static':
            $this->addMetaboxForStaticLists($fields);
            break;
          case 'dynamic':
            $key = get_post_meta($postId, 'config-key', true);
            $this->addMetaboxForDynamicLists($postId, $key);
            break;
          case 'crm':
            $this->addMetaboxForDynamicLists($postId, 'crm-segment');
            break;
          case 'combi':
            $this->addMetaboxForCombinations($postId, $helper);
            break;

        }
      }
    }
  }

  /**
   * @param int $postId
   * @param Metabox $helper
   * @return void
   */
  public function addMetaboxForCombinations($postId, $helper)
  {
    $helper->addMetabox('combi-lists', 'Kombination von Listen definieren');
    $helper->addDropdown('combined-list-ids', 'combi-lists', 'Listen', array(
      'multiple' => true,
      'sortable' => true,
      'description' => 'Es wird die Schnittmenge aller gleichen Datensätze der gewählten Listen selektiert.',
      'items' => $this->getCombinableLists($postId)
    ));

    $helper->addDropdown('filtering-list-ids', 'combi-lists', 'Ausschluss', array(
      'multiple' => true,
      'sortable' => true,
      'description' => 'Datensätze aus diesen Listen werden aus der Zusammenführung entfernt.',
      'items' => $this->getCombinableLists($postId)
    ));

    // Display the data information, if an import took place
    $helper->addHtml('table', 'combi-lists', $this->getStaticListData($postId));
  }

  /**
   * @param $postId
   * @return array
   */
  public function getCombinableLists($postId)
  {
    $lists = get_posts(array(
      'post_type' => self::LIST_TYPE_NAME,
      'post__not_in' => array($postId),
      'posts_per_page' => -1
    ));

    $items = array();
    foreach ($lists as $list) {
      $items[$list->ID] = $list->post_title;
    }

    return $items;
  }

  /**
   * @param string $html
   * @param array $data
   * @return string
   */
  public function replaceOneclickSubscribeVars($html, $data)
  {
    // First get every starting position of {oneclick to parse
    $tag = '{oneclick';
    $posStart = 0;

    while (($posStart = strpos($html, $tag, $posStart))!== false) {
      // Get immediate end position from $posStart
      $posEnd = stripos($html, '}', $posStart);
      $ocTag = substr($html, $posStart, ($posEnd - $posStart)+1);
      list($type, $listId, $text) = explode(':', substr($ocTag,1, -1));
      // Add data needed for the double optin URL
      $data['listId'] = $listId;
      $data['recordId'] = md5($data['email']);
      $data = apply_filters('Lbwp_LMS_oneclick_subscribe_vars', $data);
      // Put this as a link back into the html
      $link = '<a href="' . $this->getDoubleOptinUrl($data) . '">' . $text . '</a>';
      $html = str_replace($ocTag, $link, $html);
    }

    return $html;
  }

  public function handleAddRow(){
    if(isset($_POST['list-add-row'])){
      $postId = $this->getCurrentPostId();
      $emailFieldKey = get_post_meta($postId, 'email', true);
      $emailField = strtolower($_POST['list-add_' . $emailFieldKey]);

      if(Strings::checkEmail($emailField)){
        $entry = array();

        foreach($_POST as $key => $postData){
          if(!Strings::startsWith($key, 'list-add_')){
            continue;
          }

          $field = str_replace('list-add_', '', $key);

          $entry[$field] = $postData;
        }

        $fields = get_post_meta($postId, 'list-data', true);
        $fields = !is_array($fields) ? array() : $fields;
        $fields[md5($emailField)] = $entry;

        update_post_meta($postId, 'list-data', $fields);
      }

      unset($_POST['list-add-row']);
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
      $reload = false;
      $delimiter = Csv::guessDelimiter($fileData, true);
      $utf8 = (mb_detect_encoding($fileData) == 'UTF-8');
      $data = Csv::getArrayFromString($fileData, $delimiter);
      // Now do the actual list data import, if possible
      if (is_array($data) && count($data) > 0 && count($data[0]) >= count($fields)) {
        // Convert if xlsx
        if (Strings::endsWith($fileUrl, '.xlsx') || Strings::endsWith($fileUrl, '.xls')) {
          file_put_contents($tempFile, file_get_contents($fileUrl));
          $data = Converter::excelToArray($tempFile);
        }
        $info = $this->importStaticListData($postId, $data, $fields, $fileName, $reload, $utf8);
      } else {
        $info = 'Datei ' . $fileName . ' konnte nicht importiert werden (Leer oder ungültiges Format) und wurde gelöscht.';
      }

      // Set the current list as last imported, also add an info that the import worked
      $lastImportedList = $selectedList;
      update_post_meta($postId, 'last-imported-list', $selectedList);
      update_post_meta($postId, 'last-import-info', $info);
      // After import, delete the whole attachment no matter if the import worked
      wp_delete_attachment($selectedList);
      // Do reload if needed
      if ($reload) {
        wp_redirect(get_bloginfo('url') . '/wp-admin/post.php?post=' . $postId . '&action=edit');
        exit;
      }
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
    }
    // Show the table
    $helper->addHtml('table', $boxId, $this->getStaticListData($postId, $fields));
  }

  /**
   * @param int $listId
   * @return array list data
   */
  public function getListData($listId)
  {
    // Maybe get list from cache if enabled
    $allowCaching = defined('LBWP_LMS_ENABLE_CACHE_CRM_LISTS');
    $cacheKey = 'lbwp_crm_cached_list_' . $listId;
    if ($allowCaching) {
      $data = wp_large_cache_get($cacheKey, 'LMS');
      if (is_array($data)) {
        return $data;
      }
    }

    switch (get_post_meta($listId, 'list-type', true))
    {
      case 'dynamic':
        $args = array();
        $key = get_post_meta($listId, 'config-key', true);
        if (Strings::contains($key, ':')) {
          list ($key, $args) = explode(':', $key);
        }
        $data = apply_filters('Lbwp_LMS_Data_' . $key, array(), $listId, $args);
        $cacheKey = ''; // do not cache this
        break;
      case 'combi':
        $data = apply_filters('LBWP_LMS_Data_combi-segment', $this->getCombiSegment($listId), $listId);
        break;
      case 'crm':
        $data = apply_filters('Lbwp_LMS_Data_crm-segment', array(), $listId);
        break;
      case 'static':
      default:
        $data = ArrayManipulation::forceArray(get_post_meta($listId, 'list-data', true));
        $cacheKey = ''; // do not cache this
        break;
    }

    $allowDuplicates = get_post_meta($listId, 'allow-duplicate-email', true) == 'on';
    $allowDuplicates = apply_filters('Lbwp_LMS_allow_duplicate_email', $allowDuplicates, $key, $listId);
    // Make sure to not use integer ids, but email hashes for best compat to other features
    if (!$allowDuplicates) {
      foreach ($data as $key => $record) {
        if (is_numeric($key) && isset($record['email']) && strlen($record['email']) > 0) {
          $data[md5($record['email'])] = $record;
          unset($data[$key]);
        }
      }
    }

    // Remove unsubs (mostly done for dynamic lists (but makes it sure for static ones as well))
    $unsubs = ArrayManipulation::forceArray(get_post_meta($listId, 'unsubscribe-data', true));
    foreach ($data as $key => $record) {
      if (isseT($unsubs[$key])) {
        unseT($data[$key]);
      }
    }

    // Maybe allow caching the whole list
    if ($allowCaching && strlen($cacheKey > 0)) {
      wp_large_cache_set($cacheKey, $data, 'LMS', 86400*2);
    }

    return $data;
  }

  /**
   * Get a combination of lists
   * @param $listId
   * @return array
   */
  public function getCombiSegment($listId)
  {
    $listIds = get_post_meta($listId, 'combined-list-ids');
    // If not, get all list data first
    $lists = array();
    foreach ($listIds as $id) {
      $lists[] = $this->getListData($id);
    }

    // Now create a new list where only the common entries are in
    $combi = array();
    $blacklist = array();
    $first = array_shift($lists);
    $neededMatches = count($lists);

    // Build a blacklist from all userids of filter lists
    $filterIds = get_post_meta($listId, 'filtering-list-ids');
    if (count($filterIds) > 0) {
      foreach ($filterIds as $id) {
        $filterList = $this->getListData($id);
        foreach ($filterList as $record) {
          $blacklist[$record['userid']] = true;
        }
      }
    }

    // Create a hash map for each list
    $listMaps = array_map(function($list) {
      $map = [];
      foreach ($list as $record) {
        $map[$record['userid']] = true;
      }
      return $map;
    }, $lists);

    foreach ($first as $record) {
      $id = $record['userid'];
      // Skip if the user is in blacklist
      if (isset($blacklist[$id])) {
        continue;
      }
      // Do the matching if not
      $matches = 0;
      foreach ($listMaps as $map) {
        if (isset($map[$id])) {
          $matches++;
        }
      }

      if ($matches == $neededMatches) {
        $combi[] = $record;
      }
    }

    return $combi;
  }

  /**
   * @param $listId
   * @return array
   */
  public function getUnsubscribeData($listId)
  {
    return array_filter(ArrayManipulation::forceArray(get_post_meta($listId, 'unsubscribe-data', true)));
  }

  /**
   * @param int $listId the post id of the list
   * @param array $fields the field names
   * @return string html table
   */
  protected function getStaticListData($listId, $fields = array())
  {
    global $crmCoreSettings;
    $html = '';
    $rowId = 0;
    $isSearch = false;
    $countFields = count($fields);
    $humanreadableOptin = apply_filters('Lbwp_LMS_human_readable_optin', false);

    // First, display how many items are in the table
    $listData = $this->getListData($listId);
    $unsubscribes = $this->getUnsubscribeData($listId);
    $rowCount = count($listData);
    $actionUrl = get_bloginfo('url') . '/wp-admin/post.php?post=' . $listId . '&action=edit';

    // Eventually run a search within the segment
    if (isset($_GET['segmentsearch'])) {
      $_GET['segmentsearch'] = esc_attr($_GET['segmentsearch']);
      $search = strtolower($_GET['segmentsearch']);
      foreach ($listData as $key => $row) {
        $candidate = strtolower(implode(' ', $row));
        if (!Strings::contains($candidate, $search)) {
          unset($listData[$key]);
        }
      }

      // Set search state and recount with resulting dataset
      $isSearch = true;
      $rowCount = count($listData);
    }

    if (!$isSearch) {
      $html .= 'Es sind aktuell ' . $rowCount . ' Datensätze vorhanden (Download als <a href="' . $actionUrl . '&downloadCsv' . '">CSV</a> oder <a href="' . $actionUrl . '&downloadExcel' . '">Excel</a>).';
    } else {
      $html .= 'Ihre Suche ergab ' . $rowCount . ' Datensätze (<a href="' . $actionUrl . '">Alles anzeigen</a>).';
    }

    // Add Search form
    $html .= '
      <div class="crm-search-container">
        <input type="text" id="segmentsearch_term" placeholder="In Versandliste suchen" value="' . $_GET['segmentsearch'] . '" />
        <a href="#" id="segmentsearch" class="button">Suchen</a>
      </div>
      <script>
        jQuery(function() {
          jQuery("#segmentsearch_term").on("keyup", function(e) {
            if (e.keyCode === 13) {
              jQuery("#segmentsearch").trigger("click");
              return false;
            }
          });
          jQuery("#segmentsearch").on("click", function() {
            var base = "'.$actionUrl.'&segmentsearch=";
            var searchUrl = base + jQuery("#segmentsearch_term").val();
            document.location.href = searchUrl;
          });
        });
      </script>
    ';

    // If there are no fields, it might just be a dynamic list
    if ($countFields == 0 && $rowCount > 0) {
      reset($listData);
      $firstKey = key($listData);
      foreach ($listData[$firstKey] as $key => $value) {
        $fields[] = $key;
        $countFields++;
      }
    }

    // If no optin fields has been added, ad it
    if (!in_array('optin', $fields)) {
      $countFields++;
      $fields[] = 'optin';
    }

    // Translate optin for view and download to human readble if needed
    if ($humanreadableOptin) {
      foreach ($listData as $key => $record) {
        if (isset($record['optin']) && $record['optin'] > 0) {
          $listData[$key]['optin'] = date('d.m.Y H:i:s', $record['optin']);
        }
      }
    }

    // Download the das as csv
    if(isset($_GET['downloadCsv'])){
      array_unshift($listData, $fields);
      CSV::downloadFile($listData, 'liste-' . $listId, ';', '"', false);
    }
    if(isset($_GET['downloadExcel'])){
      array_unshift($listData, $fields);
      CSV::downloadExcel($listData, 'liste-' . $listId);
    }

    // Maybe limit the data displayed
    if (!$isSearch && is_array($crmCoreSettings) && isset($crmCoreSettings['misc']['limitSegmentPreview'])) {
      $html .= ' Es werden die ersten ' . $crmCoreSettings['misc']['limitSegmentPreview'] . ' Datensätze angezeigt.';
      $listData = array_slice($listData, 0, $crmCoreSettings['misc']['limitSegmentPreview']);
    }

    // Create the table
    $html .= '<input id="fullscreen-checkbox" type="checkbox">
      <label class="close-fullscreen-bg" for="fullscreen-checkbox"></label>
      <label class="open-fullscreen" for="fullscreen-checkbox">Tabelle im Vollbild anzeigen</label>
      <div class="fullscreen-table-container">
        <label class="dashicons-before dashicons-fullscreen-exit-alt close-fullscreen" for="fullscreen-checkbox"></label>
        <table class="mbh-generic-table">';

    // Create table headings from fields
    $html .= '<tr><th>&nbsp;</th>';

    // Create fields for manual adding when static list
    $addRowHtml = '';
    if(get_post_meta($listId, 'list-type', true) === 'static'){
      $addRowHtml = '<tr><td>&nbsp;</td>';
      foreach ($fields as $key => $field) {
        $addRowHtml .= '<td><input type="text" name="list-add_' . $field . '" placeholder="' . $field . '"></td>';
      }
      $addRowHtml .= '<td><input type="submit" name="list-add-row" class="button button-primary button-small" value="+"></td></tr>';
    }

    // Create table headings
    foreach ($fields as $field) {
      $html .= '<th>' . $field . '</th>';
    }

    $html .= '<th>&nbsp;</th></tr>';

    foreach ($listData as $key => $record) {
      $userLink = ++$rowId;
      $deletable = true;
      if (isset($record['userid']) && $record['userid'] > 0) {
        $userLink = '<a href="/wp-admin/user-edit.php?user_id=' . $record['userid'] . '"><span class="dashicons dashicons-edit"></span></a>';
        $deletable = false;
      }
      $html .= '<tr>';
      $html .= '<td data-key="' . $key . '">' . $userLink . '</td>';
      $record['optin'] = (isset($record['optin'])) ? $record['optin'] : 0;
      $countCells = 0;
      foreach ($fields as $field) {
        if ($countFields >= ++$countCells) {
          $html .= '<td>' . $record[$field] . '</td>';
        }
      }
      if ($deletable) {
        $html .= '
          <td data-id="' . $key . '" data-list="' . $listId . '">
            <a href="javascript:void(0)" class="remove-static-list-entry dashicons dashicons-trash"></a>
          </td>
        ';
      } else {
        $html .= '<td>&nbsp;</td>';
      }
      $html .= '</tr>';
    }

    $html .= $addRowHtml . '</table></div>';

    if (count($unsubscribes) > 0) {
      // Create the table
      $html .= '
        <br><hr>
        <p>Es sind aktuell ' . count($unsubscribes) . ' Abmeldungen eingegangen.</p>
        <table class="mbh-generic-table">
      ';

      // Create table headings from fields
      $html .= '<tr>';
      $html .= '<th>ID</th>';
      $html .= '<th>Abmeldung per</th>';
      foreach ($fields as $field) {
        $html .= '<th>' . $field . '</th>';
      }
      $html .= '</tr>';

      foreach ($unsubscribes as $key => $record) {
        $html .= '<tr>';
        $html .= '<td data-key="' . $key . '">' . (++$rowId) . '</td>';
        $html .= '<td>' . Date::getTime(Date::EU_DATETIME, $record['timestamp']) . '</td>';
        $countCells = 0;
        foreach ($record as $field) {
          if ($countFields >= ++$countCells) {
            $html .= '<td>' . $field . '</td>';
          }
        }
        $html .= '</tr>';
      }

      $html .= '</table>';
    }

    // And the script to delete the actual lines
    $html .= '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".remove-static-list-entry").on("click", function() {
            if (confirm("Eintrag wirklich löschen?")) {
              var row = jQuery(this).parent();
              var data = {
                action : "deleteStaticListEntry",
                listId : row.data("list"),
                entryKey : row.data("id")
              }
              
              jQuery.post(ajaxurl, data);
              row.parent().remove();
            }
          });
        });
      </script>
    ';
    return $html;
  }

  /**
   * Delete a static list entry from the backend
   */
  public function deleteStaticListEntry()
  {
    $found = false;
    $key = Strings::forceSlugString($_POST['entryKey']);
    $listId = intval($_POST['listId']);

    $listData = $this->getListData($listId);
    // Remove the entry if found
    if (isset($listData[$key])) {
      unset($listData[$key]);
      $found = true;
    }

    // Save the list back
    update_post_meta($listId, 'list-data', $listData);

    // Response with success always
    WordPress::sendJsonResponse(array(
      'success' => $found,
      'list' => $listId,
      'key' => $key
    ));
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
  protected function importStaticListData($postId, $data, $fields, $file, &$reload, $utf8 = false)
  {
    // Translate integrated fields into their respective config
    $translations = $this->getTranslations($postId);

    // Validate fields and translations array
    $fields = array_filter($fields);
    $translations = array_filter($translations);

    // Set fields if empty and save them into meta for later
    if (count($fields) === 0) {
      $fields = array();
      foreach ($data[0] as $field) {
        $field = Strings::forceSlugString($field);
        // use minimal logic to directly map the fields correctly
        if (str_starts_with($field, 'email') || str_starts_with($field, 'e-mail'))
          $field = 'email';
        if (str_contains($field, 'anrede') || str_contains($field, 'salutation'))
          $field = 'salutation';
        if (str_contains($field, 'vorname'))
          $field = 'firstname';
        if (str_contains($field, 'nachname') || str_ends_with($field, 'name'))
          $field = 'lastname';

        $fields[] = $field;
      }
      update_post_meta($postId, 'field-config', implode(',', $fields));
      $reload = true;
    }

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

    // Get unsubscribes of that list
    $unsubCheck = array();
    $unsubscribes = self::getUnsubscribeData($postId);
    foreach ($unsubscribes as $unsubscribe) {
      $unsubCheck[md5($unsubscribe['email'])] = true;
    }

    // Import (and possibly override) the new data
    foreach ($data as $record) {
      // Make sure to have only lowercase emails
      $record[$emailIndex] = strtolower($record[$emailIndex]);
      // Validate the record by checking email syntax
      if (Strings::checkEmail($record[$emailIndex])) {
        $recordId = md5($record[$emailIndex]);
        // Skip import if already unsubscribed
        if (isset($unsubCheck[$recordId]))
          continue;
        // Skip import, if type is preserve (not override) and records is available already
        if ($importType == 'preserve' && isset($listData[$recordId]))
          continue;

        // Import a new record if we come here
        $dataset = array();
        foreach ($record as $index => $value) {
          $dataset[$fields[$index]] = ($utf8) ? $value : utf8_encode($value);
        }
        $listData[$recordId] = $dataset;
      }
    }

    // Save the new list data and return with success
    update_post_meta($postId, 'list-data', $listData);
    return 'Datei ' . $file . ' importiert am ' . Date::getTime(Date::EU_DATETIME) . ' Uhr. Datei wurde nach dem Import gelöscht.';
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
    if (defined('LBWP_CRM_LARGE_DATABASE')) {
      set_time_limit(300);
      ini_set('memory_limit', '1024M');
    }

    $data = array();
    $lists = get_posts(array(
      'post_type' => self::LIST_TYPE_NAME,
      'orderby' => apply_filters('Lbwp_LMS_getLists_orderby', 'title'),
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
      $preValidListId = $listId;
      $listId = intval($listId);
      $recordHash = md5($this->config['unsubscribeSalt'] . $recordId);
      // Unsubscribe, if check is valid and list is valid
      if ($recordHash == $checkId) {
        if ($preValidListId === 'auto') {
          $this->unsubscribeAutomationsByUrlParams($recordId);
        } else if ($listId > 0) {
          if (apply_filters('Lbwp_LMS_handle_list_unsubscribe', true, $recordId, $listId)) {
            $this->unsubscribe($recordId, $listId);
          }
        }
      }
    }
  }

  /**
   * @param $recordId
   * @return void
   */
  public function unsubscribeAutomationsByUrlParams($recordId)
  {
    // Evaulate the ID of the user
    $userId = intval($_GET['id']);
    if ($userId == 0)
      $userId = intval($_GET['userid']);
    if ($userId == 0)
      $userId = intval($_GET['crmid']);

    $user = get_user_by('id', $userId);
    // Check if actually a user
    if ($user instanceof \WP_User) {
      if (apply_filters('Lbwp_LMS_handle_automation_unsubscribe', true, $recordId, $user)) {
        update_user_meta($userId, 'lbwp-automation-optout', 1);
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

    // Add time of subscription
    $record['optin'] = current_time('timestamp');
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
    $listType = get_post_meta($listId, 'list-type', true);

    // Actually remove from list when static
    if ($listType == 'static') {
      $listData = ArrayManipulation::forceArray(get_post_meta($listId, 'list-data', true));
      $removedEntry = $listData[$recordId];
      $removedEntry['timestamp'] = current_time('timestamp');
      unset($listData[$recordId]);
      update_post_meta($listId, 'list-data', $listData);
    } else {
      // Load the segment and correctly add $removedEntry to be added below
      // The actual removal of an entry is done with below meta at getSegment method
      $listData = self::getListData($listId);
      if (isset($listData[$recordId])) {
        $removedEntry = $listData[$recordId];
        $removedEntry['timestamp'] = current_time('timestamp');
      }
    }

    // Also write an unsubscribe option
    if (is_array($removedEntry)) {
      $unsubs = ArrayManipulation::forceArray(get_post_meta($listId, 'unsubscribe-data', true));
      $unsubs[$recordId] = $removedEntry;
      update_post_meta($listId, 'unsubscribe-data', $unsubs);
    }

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
   * @return MailService
   */
  public function getSendingService()
  {
    $class = $this->services[$this->config['mailServiceId']]['class'];
    /** @var MailService $service the service instance of Base */
    $service = new $class();
    $service->configure($this->config['mailServiceConfig']);
    return $service;
  }

  /**
   * Actually send a bunch of mails. If there are more mails to be sent, add another
   * job cron in a minute, if not, stop sending and remove the mailings from the list.
   */
  public function tryAndSendMails()
  {
    $time = microtime(true);
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
    $service = $this->getSendingService();
    $sendType = $mailings[$mailingId];

    // Load the mails of this mailing
    $mails = $this->getSendableMailingObjects($mailingId);
    $mailCount = count($mails);

    // Data table helper for statistics
    $newsletterId = $this->getNewsletterIdByMailingId($mailingId);
    if ($newsletterId > 0) {
      $key = 'localmail_stats_' . $newsletterId;
      $stats = new LbwpData($key);
      update_post_meta($newsletterId, 'statistics_key', $key);
    }

    // Log if there is a sending with no mails
    if ($mailCount == 0) {
      SystemLog::add('LocalMailService', 'critical', 'Tried to send mailing ' . $mailingId . ' with no mails!');
    }

    // Send maximum number of mails
    foreach ($mails as $id => $mail) {
      // Check if email valid and gracefully skip record if not
      if (!isset($mail['data']['recipient']) || strlen($mail['data']['recipient']) == 0 || !Strings::checkEmail($mail['data']['recipient'])) {
        if ($newsletterId > 0) {
          // Remove the mail object and continue with the rest
          $this->removeMailObject($mailingId, $id);
          SystemLog::add('LocalMailService', 'debug', 'skipped invalid mail "' . $mail['recipient'] . '"', array(
            'subject' => $mail['subject'],
            'newsletterId' => $newsletterId,
          ));
        }
        continue;
      }

      if (!isset($mail['data']) || !isset($mail['data']['recipient'])) {
        // Skip, not enought data, shoud never happen actually
        continue;
      } else {
        $mail = $mail['data'];
      }
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
        $this->removeMailObjects($mailingId);
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
      $lastRecipient = $mail['recipient'];
      if ($service->send()) {
        // Set a cache key to prevent multi-sending the same mail
        wp_cache_set($securityKey, 1, 'LocalMail', 600);
        // Update the statistics row, as the mail is sent now
        if ($newsletterId > 0) {
          $stats->mergeRow(md5($mail['recipient']), array('sent' => 1));
        }

        // And remove from database, cause its sent
        $this->removeMailObject($mailingId, $id);
      }

      // Reset mailer for next loop cycle
      $service->reset();
      // Unset from the array so it's not sent again
      unset($mails[$id]);
    }

    // After sending the block, are there still mails left?
    if (count($this->getSendableMailingObjects($mailingId)) > 0) {
      // Schedule another cron
      $this->scheduleSendingCron($mailingId);
    } else {
      // Delete the mailing completely and don't reschedule
      $this->removeMailing($mailingId);
    }

    // Raise the cron lock
    TempLock::raise('localmail_sending_' . $mailingId);

    /*
    // Track the time used to send the mails to the API
    $elapsed = microtime(true) - $time;
    SystemLog::add('LocalMail', 'debug', 'mail sending time track', array(
      'mailCount' => $mailCount,
      'elapsedTime' => $elapsed,
      'mailingId' => $mailingId,
      'newsletterId' => $newsletterId,
      'lastRecipient' => $lastRecipient
    ));
    */
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
      '*|ARCHIVE|*' => get_bloginfo('url') . '/cms/plugins/comotive-newsletter/preview/personalized.php?nl='.$data['newsletterId'].'&user={preview-id}&type=html'
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
   * @param string $mailingId
   * @return int the internal newsletter id
   */
  protected function getNewsletterIdByMailingId($mailingId)
  {
    $db = WordPress::getDb();
    $sql = 'SELECT post_id FROM {sql:postMeta} WHERE meta_key = {metaKey} AND meta_value = {metaValue}';
    return intval($db->get_var(Strings::prepareSql($sql, array(
      'postMeta' => $db->postmeta,
      'metaKey' => 'serviceMailingId',
      'metaValue' => $mailingId
    ))));
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
    $this->removeMailObjects($id);
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
      // Have a fallback as well if $language in getUnsubscribeLink is missing a language code
      $this->config['unsubscribeUrl_de'] = get_permalink($pageId);
      $this->config['unsubscribeUrl_'] = $this->config['unsubscribeUrl_de'];
    }
  }

  /**
   * Sets the dynamic urls to our unsubscribe url scheme in config
   */
  protected function setDynamicDoubleOptInUrls()
  {
    $pageId = intval(get_theme_mod('double_optin_page_id'));

    // If multilang, maybe get a translation of that page, depending on current language
    if (Multilang::isActive()) {
      foreach (Multilang::getAllLanguages() as $language) {
        if ($language != Multilang::getPostLang($pageId)) {
          $pageId = Multilang::getPostIdInLang($pageId, $language);
        }
        $this->config['doubleOptInUrl_' . $language] = get_permalink($pageId);
      }
    } else {
      $this->config['doubleOptInUrl_de'] = get_permalink($pageId);
    }
  }

  /**
   * @param int $listId the list to check
   * @return bool true if double opt in is required
   */
  public function needsDoubleOptin($listId)
  {
    return get_post_meta($listId, 'optin-type', true) === 'double';
  }

  /**
   * @param $data
   * @return string
   */
  public function getDoubleOptinUrl($data)
  {
    $flag = Multilang::isActive() ? Multilang::getCurrentLang() : 'de';
    $url = $this->config['doubleOptInUrl_' . $flag];
    $url = Strings::attachParam('lms-subscribe', base64_encode(json_encode($data)), $url);
    // If the url doesn't start with http, add it
    if (!Strings::startsWith($url, 'http')) {
      $url = get_bloginfo('url') . '/' . $url;
    }

    return $url;
  }

  /**
   * @param string $email the email to optin
   * @param array $data the data to be used
   * @return bool true if the mail is sent
   */
  public function sendDoubleOptInMail($email, $data)
  {
    // Create the optin url with data object
    $url = $this->getDoubleOptinUrl($data);

    // Create the mailing
    $mail = External::PhpMailer();
    $mail->addAddress($email);
    $mail->Subject = LBWP_HOST . ' - ' . __('Bestätigung Ihrer Anmeldung', 'lbwp');
    $mail->Body = '
      ' . __('Vielen Dank für Ihre Anmeldung zum Newsletter.', 'lbwp') . '<br>
      <br>
      ' . __('Bitte bestätigen Sie diese mit einem Klick auf den nachfolgenden Link:', 'lbwp') . '<br>
      <br>
      <a href="' . $url . '" target="_blank">' . $url . '</a>
    ';
    // Let developers filter the mail before sending
    $mail = apply_filters('lbwp_localmail_doubleoptin_mail', $mail, $data, $url, $email);
    $mail->AltBody = Strings::getAltMailBody($mail->Body);
    return $mail->send();
  }

  /**
   * Actually run the subscription on the second optin
   */
  public function checkDoubleOptInSubscription()
  {
    if (isset($_GET['lms-subscribe'])) {
      $data = json_decode(base64_decode($_GET['lms-subscribe']), true);

      // Check for minimum data to be given for a loose optin in a csv list
      if (intval($data['listId']) > 0 && strlen($data['recordId']) > 0 && Strings::checkEmail($data['email'])) {
        $recordId = $data['recordId']; unset($data['recordId']);
        $listId = $data['listId']; unset($data['listId']);
        // Now just subscribe that user with given data
        $this->subscribe($recordId, $listId, $data);
      }

      // Check for an optin for an existing crm user to set a checkbox field active
      if (isset($data['type'])) {
        if ($data['type'] == 'crm' && $data['user'] > 0 && $data['field'] > 0) {
          $userId = intval($data['user']);
          $metaField = 'crmcf-' . intval($data['field']);
          $time = current_time('timestamp');
          // Set checkbox active and proformally also set a change timestamp (even if not activated for the field)
          update_user_meta($userId, $metaField, 1);
          update_user_meta($userId, $metaField . '-changed', $time);
          update_user_meta($userId, $metaField . '-optin', $time);
          update_user_meta($userId, 'users-last-optin', $time);
        }
      }
    }
  }

  /**
   * Schedule a sending cron in n-seconds
   * @param string $mailingId the mailing it to be sent
   * @param int $seconds to wait until the cron is called
   */
  public function scheduleSendingCron($mailingId, $seconds = 30)
  {
    Cronjob::register(array(
      (current_time('timestamp') + $seconds) => 'localmail_sending::' . $mailingId
    ));
  }

  /**
   * Gets the next batch of emails ot be sent if there are
   * @param string $mailingId the mailing id to save to
   */
  public function getSendableMailingObjects($mailingId)
  {
    $lbwpData = new LbwpData($mailingId);
    return $lbwpData->getRows('pid', 'ASC', $this->config['maxMailsPerSendPeriod']);
  }

  /**
   * @param $mailingId
   * @param $mail
   */
  public function addMailObject($mailingId, $mailId, $mail)
  {
    $lbwpData = new LbwpData($mailingId);
    $lbwpData->updateRow($mailId, $mail);
  }

  /**
   * @param $mailingId
   */
  public function removeMailObjects($mailingId)
  {
    $lbwpData = new LbwpData($mailingId);
    $lbwpData->flush();
  }

  /**
   * @param $mailingId
   * @param $mailId
   */
  public function removeMailObject($mailingId, $mailId)
  {
    $lbwpData = new LbwpData($mailingId);
    $lbwpData->deleteRow($mailId);
  }
}



