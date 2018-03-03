<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Core as LbwpCore;
use LBWP\Module\Events\Core as EventCore;
use LBWP\Module\Forms\Core as FormCore;
use LBWP\Module\Forms\Item\Base as ItemBase;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Util\Date;
use LBWP\Util\ArrayManipulation;
use LBWP\Module\Forms\Component\ActionBackend\DataTable as DataTableBackend;
use LBWP\Util\External;
use LBWP\Util\Strings;


/**
 * This will gather form info in a table option
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class DataTable extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'In Tabelle speichern';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Formular-Daten speichern',
    'help' => 'Speichert Daten in einer Tabelle ab',
    'group' => 'Häufig verwendet'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'name' => array(
        'name' => 'Name der Tabelle',
        'type' => 'textfield'
      ),
      'max' => array(
        'name' => 'Maximale Anzahl Anmeldungen (0 = unendlich)',
        'type' => 'textfield',
        'help' => 'Nützlich für Event-Anmeldungen wo z.B. nur für 80 Personen Platz ist. Berechnung kann mit Zuweisung der Event-Felder unten optimiert werden.'
      ),
      'max_error' => array(
        'name' => 'Text, wenn die maximale Anzahl erreicht ist',
        'type' => 'textarea',
        'placeholder' => __('Es sind keine weiteren Anmeldungen möglich.', 'lbwp'),
        'help' => 'Anstelle des Formulars, wird dieser Text angezeigt, wenn die maximale Anzahl Datensätze erreicht ist.'
      ),
    ));

    // Allow the data table to show itself as a sending segment for newsletters
    if (LbwpCore::isModuleActive('NewsletterBase') && LocalMailService::isWorking()) {
      $this->paramConfig['use_segments'] = array(
        'name' => 'Versandsegmente für Newsletter',
        'type' => 'dropdown',
        'values' => array(
          0 => 'Keine Segmente zur Verfügung stellen',
          1 => 'Versandsegmente im Newsletter-Tool anzeigen'
        ),
        'help' => 'Dieser Datenspeicher wir beim Versand von Newslettern Versandsegmente zur Verfügung stellen. Wenn er mit einem Event verknüpft wird, sind zusätzliche Segmente möglich.'
      );
    }

    // Allow assignment of an event, if module is active
    if (LbwpCore::isModuleActive('Events')) {
      $this->paramConfig['event_id'] = array(
        'name' => 'Verknüpfung mit Event',
        'type' => 'dropdown',
        'values' => $this->getAssignableEvents(),
        'help' => 'Damit können Event-Informationen direkt im Datenspeicher angezeigt werden.'
      );

      $this->paramConfig['subscribe_field'] = array(
        'name' => 'Anmelde-Feld',
        'type' => 'textfield',
        'help' => 'Es kann jedes Auswahl- oder Text-Feld verknüpft werden. Bitte das Zahnrädchen benutzen.'
      );

      $this->paramConfig['subscribe_condition'] = array(
        'name' => 'Anmelde-Kondition',
        'type' => 'textfield',
        'help' => 'Wert, den das Anmelde-Feld haben muss, damit die Anmeldung gültig ist.'
      );

      $this->paramConfig['subscribers_field'] = array(
        'name' => 'Feld für Personenzahl',
        'type' => 'textfield',
        'help' => 'Bitte Zahnrädchen verwenden um auf ein Zahlenfeld zu verweisen. Wir hier nichts selektiert, gilt jeder Datensatz, dessen Anmelde-Kondition zutrifft als eine Person. Wird dieses Feld verwendet, wir die angemeldete Anzahl Personen bei der zugelassenen Anzahl Anmeldungen miteinbezogen.'
      );

      $this->paramConfig['emailid_field'] = array(
        'name' => 'Erkennungs-Feld',
        'type' => 'textfield',
        'help' => 'Wenn Sie via Newsletter Einladen kann hier ein unsichtbares Feld mit der Kennung der eingeladenen Person verwiesen werden. Damit kann der Datenspeicher ein Newsletter-Segment bilden, welches einen Versand an alle Personen enthält, welche die Einladung noch nicht beantwortet haben.'
      );
    }

    // Additional params to add edit link functionality
    $this->paramConfig['notify_mail_setting'] = array(
      'name' => 'Bestätigungs-E-Mail',
      'type' => 'dropdown',
      'values' => array(
        0 => 'Keine Bestätigungs-E-Mail senden',
        1 => 'Bestätigungs-E-Mail ohne Bearbeitungs-Link',
        2 => 'Bestätigungs-E-Mail mit Bearbeitungs-Link',
      ),
      'help' => 'Die Bestätigungsmail enthält den unten eingegebenen Text, welcher auch alle Formular-Felder darstellen kann. Falls gewünscht, kann die E-Mail einen Link beinhalten welcher es dem Besucher erlaubt die Daten später anzupassen.'
    );

    $this->paramConfig['notify_mail_subject'] = array(
      'name' => 'Betreffzeile Bestätigung',
      'type' => 'textfield'
    );

    $this->paramConfig['notify_mail_email'] = array(
      'name' => 'E-Mail-Feld (Empfänger)',
      'type' => 'textfield'
    );

    $this->paramConfig['notify_mail_replyto'] = array(
      'name' => 'Antwort-Adresse',
      'type' => 'textfield',
      'help' => 'An wen sollen Antworten auf diese E-Mail gesendet werden? Wird das Feld nicht ausgefüllt, wird die System-Adresse verwendet.'
    );

    $this->paramConfig['notify_mail_content'] = array(
      'name' => 'E-Mail Inhalt',
      'type' => 'textarea',
      'help' => 'Hier können Sie Ihren  E-Mail Inhalt definieren. Sie können Formularfelder verwenden indem Sie die Feld-ID z.B. wie folgt verwenden: {email_adresse}. Der Bearbeitungs-Link wird, sofern aktiviert, unter dem Text hinzugefügt. Die Tabelle mit allen Formular-Daten können Sie mit der Variable {lbwp:formContent} einfügen.'
    );
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['key'] = $key;
    // Set the defaults (will be overriden with current data on execute)
    $this->params['name'] = 'Name der Tabelle';
    $this->params['max'] = 0;
    $this->params['max_error'] = '';
  }

  /**
   * Create a list entry for the form and an empty data set if not yet available
   * @param \WP_Post $form
   */
  public function onSave($form)
  {
    if (intval($this->params['form_id']) == 0) {
      // Create the table in the list if not a referenced table (which also creates an empty data table)
      $backend = $this->core->getDataTableBackend();
      $backend->updateTableList($form->ID, $this->params['name']);
    }
  }

  /**
   * If the maximum is reached, only displays an error
   * @param \WP_Post $form
   * @return string an error if needed
   */
  public function onDisplay($form)
  {
    $backend = $this->core->getDataTableBackend();
    if ($backend->maximumReached($form->ID, $this)) {
      $this->core->getFormHandler()->showBackLink = false;
      return strlen($this->params['max_error']) > 0 ? $this->params['max_error'] : __('Es sind keine weiteren Anmeldungen möglich.', 'lbwp');
    }

    // If the maximum is not reached, see if we need to prefill the form from an existing row
    if (isset($_GET['tsid']) && strlen($_GET['tsid']) > 8) {
      $this->prefillPostVars(Strings::forceSlugString($_GET['tsid']));
    }

    return '';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    $formHandler = $this->core->getFormHandler();
    $formId = $formHandler->getCurrentForm()->ID;
    $backend = $this->core->getDataTableBackend();
    $state = true;

    // Override form id with another, if given
    if (isset($this->params['form_id']) && intval($this->params['form_id']) > 0) {
      $formId = intval($this->params['form_id']);
    }

    // Add source information
    $data = $this->addRowMetaData($data, $formId);

    // Add a table entry, if not working, send message
    if (!$backend->addTableEntry($formId, $data, $this)) {
      $formHandler->setCustomError($this->params['max_error']);
      // Flush HTML cache to make sure the error is displayed next time
      HTMLCache::invalidateCurrentPage();
      $state = false;
    }

    // Send a notification, if configured and adding worked
    if ($state && $this->params['notify_mail_setting'] > 0) {
      $this->sendNotification($data);
    }

    // All went well if the pointer comes here
    return $state;
  }

  /**
   * Add various additonal generic data to the row
   * @param array $data previous data array
   * @param int $formId the id of the executed form
   * @return array new data array with additional information
   */
  protected function addRowMetaData($data, $formId)
  {
    // Add a data item, that contains the form source
    $source = array(
      'name' => 'Ursprungsformular',
      'value' => $this->params['name']
    );

    // Override with specific source, if set
    if ($formId > 0) {
      $form = get_post($formId);
      if ($form instanceof \WP_Post) {
        $source['value'] = $form->post_title;
      }
    }

    $data[] = $source;

    // Add user IP
    $data[] = array(
      'name' => 'user-ip-adresse',
      'value' => $_SERVER['REMOTE_ADDR']
    );

    // Add time the form has been sent
    $data[] = array(
      'name' => 'zeitstempel',
      'value' => Date::getTime(Date::EU_DATETIME, current_time('timestamp'))
    );

    // Add time the form has been sent
    $data[] = array(
      'name' => 'tsid',
      'value' => 0
    );

    return $data;
  }

  /**
   * Send a notification to the user, that is data was added
   * @param array $data the data sent from form
   * @return bool true if the mail was successfully sent
   */
  protected function sendNotification($data)
  {
    $tsid = DataTableBackend::$lastTsid;
    $replyTo = 'info@' . str_replace('www.', '', getLbwpHost());
    $subject = $this->getFieldContent($data, $this->params['notify_mail_subject']);
    $recipient = $this->getFieldContent($data, $this->params['notify_mail_email']);
    $replyto = $this->getFieldContent($data, $this->params['notify_mail_replyto']);
    // Get the link of the form without id, should work fine at this point
    $url = get_permalink();

    // Create the content and replace variables
    $content = str_replace(PHP_EOL, '<br />', $this->params['notify_mail_content']);
    foreach ($data as $field) {
      $content = str_replace('{' . $field['id'] . '}', $field['value'], $content);
    }

    // Also input the form data if needed
    if (stristr($content, SendMail::FORM_CONTENT_VARIABLE) !== false) {
      $table = SendMail::getDataHtmlTable($data);
      $content = str_replace(SendMail::FORM_CONTENT_VARIABLE, $table, $content);
    }

    // Eventually add the edit link
    if ($this->params['notify_mail_setting'] == 2) {
      $url = Strings::attachParam('tsid', $tsid, $url);
      $content .= '<br/><br/>Sie können Ihre <a href="' . $url . '">Daten hier bearbeiten.</a>';
    }

    // Create the mail object
    $mail = External::PhpMailer();

    // Set reply to address, if given
    if (Strings::checkEmail($replyto)) {
      $mail->addReplyTo($replyto);
    }

    // Send the email to the recipient
    $mail->Subject = $subject;
    $mail->Body = $this->getMailStyleHeader() . $content;
    $mail->addAddress($recipient);
    $mail->addReplyTo($replyTo);
    return $mail->send();
  }

  /**
   * Forces some minimal styling to the mail
   * @return string
   */
  protected function getMailStyleHeader()
  {
    return '
      <style type="text/css">
        body, table, td, p { font-family: Arial, Helvetica, sans-serif !important; }
      </style>
    ';
  }

  /**
   * Creates JS that prefills the fields, if a tsid and form data is given
   * @param string $tsid the table storage row id
   */
  protected function prefillPostVars($tsid)
  {
    // Get the form and backend, then the table and the row by id
    $formHandler = $this->core->getFormHandler();
    $formId = $formHandler->getCurrentForm()->ID;
    $backend = FormCore::getInstance()->getDataTableBackend();
    $table = $backend->getTable($formId);
    $row = $backend->getRowById($table['data'], $tsid);

    // If there is data, try prefilling the form fields
    if (is_array($row) && count($row) > 0) {
      $script = '';
      foreach ($formHandler->getCurrentItems() as $item) {
        $cellKey = $this->getTableCellKey($item->get('feldname'));
        $class = strtolower(get_class($item));
        $class = substr($class, strripos($class, '\\') + 1);
        // Radio / Checkbox / Dropdown get a special treatment
        switch ($class) {
          case 'radio':
            $script .= 'jQuery("input[name=' . $item->get('id') . '][value=\'' . esc_js($row[$cellKey]) . '\'").attr("checked", "checked");' . PHP_EOL;
            break;
          case 'dropdown':
            $script .= 'jQuery("select[name=' . $item->get('id') . '] option[value=\'' . esc_js($row[$cellKey]) . '\'").attr("selected", "selected");' . PHP_EOL;
            break;
          // Checkboxes are a tad more complicated to solve
          case 'checkbox':
            $values = array_map('trim', explode(',', $row[$cellKey]));
            foreach ($values as $value) {
              $script .= '
                checkboxes = jQuery("input[name=\'' . $item->get('id') . '\[\]\']");
                checkboxes.each(function() {
                  var checkbox = jQuery(this);
                  if (checkbox.next().text() == "' . esc_js($value) . '") {
                    checkbox.attr("checked", "checked");
                  };
                });
              ';
            }
            break;
          default:
            $script .= 'jQuery("#' . $item->get('id') . '").val("' . esc_js($row[$cellKey]) . '");' . PHP_EOL;
        }
      }
    }

    // Add this content to prefill form and hidden field to bottom of form
    $formHandler->addBottomContent('
      <script type="text/javascript">
        jQuery(function() {
          ' . $script . '
        });
      </script>
      <input type="hidden" name="editingTsId" value="' . $tsid . '" />
    ');
  }

  /**
   * @param string $name actual field name with html
   * @return string table cell key from field name
   */
  protected function getTableCellKey($name)
  {
    return Strings::forceSlugString(str_replace(ItemBase::ASTERISK_HTML, '', $name));
  }

  /**
   * @return array list of selectable events
   */
  protected function getAssignableEvents()
  {
    $values = array(0 => 'Nicht zugewiesen');

    // Get the next events as configured
    $frontend = EventCore::getInstance()->getFrontendComponent();
    $config = EventCore::getInstance()->getShortcodeComponent()->getListConfiguration(array());
    // Only query for future events as of right now and two months into the future
    $config['from'] = current_time('timestamp');
    $config['to'] = $config['from'] + (86400 * 180);
    $events = $frontend->queryEvents($config);
    $events = array_merge($events, $frontend->getUntimedEvents());

    foreach ($events as $event) {
      $values[$event->ID] = $event->post_title;
    }

    return $values;
  }
} 