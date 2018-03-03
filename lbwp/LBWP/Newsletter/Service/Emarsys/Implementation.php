<?php

namespace LBWP\Newsletter\Service\Emarsys;

use LBWP\Newsletter\Service\Base;
use LBWP\Newsletter\Service\Definition;
use LBWP\Core as LbwpCore;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\ArrayManipulation;

/**
 * Implementation class for Emarsys service
 * @package LBWP\Newsletter\Service\Emarsys
 * @author Michael Sebel <michael@comotive.ch>
 */
class Implementation extends Base implements Definition
{
  /**
   * @var string the id of this service type
   */
  protected $serviceId = 'emarsys_1_0';
  /**
   * @var Helper the api helper object, only available if service is working
   */
  protected $api = NULL;
  /**
   * @var string query var name for double opt in
   */
  const QUERY_VAR_SUBSCRIPTION_USER_ID = 'subscriptionUserId';

  /**
   * @param \LBWP\Newsletter\Core $core
   */
  public function __construct($core)
  {
    parent::__construct($core);

    // Create an api instance, if the service is working
    if ($this->isWorking()) {
      // Instantiate and API helper
      $this->api = new Helper(
        $this->getSetting('userName'),
        $this->getSetting('secretKey'),
        $this->getSetting('apiUrl')
      );

      // Register hooks to do double opt ins
      add_action('generate_rewrite_rules', array($this, 'generateRewriteRules'));
      add_action('pre_get_posts', array($this, 'preGetPosts'), 20);
      add_filter('query_vars', array($this, 'filterQueryVars'));
    }
  }

  /**
   * Generates the needed rewrite rules
   * @param \WP_Rewrite $wpRewrite
   */
  public function generateRewriteRules(\WP_Rewrite $wpRewrite)
  {
    $rules = array(
      'confirm-subscription/uid/(.+)/?$' => 'index.php?' . self::QUERY_VAR_SUBSCRIPTION_USER_ID . '=' . $wpRewrite->preg_index(1)
    );

    $wpRewrite->rules = $rules + $wpRewrite->rules;

  }

  /**
   * Sets the double-opt in field, and redirects to the success or fail page.
   * @param \WP_Query $wpQuery
   */
  public function preGetPosts(\WP_Query $wpQuery)
  {
    if (isset($wpQuery->query[self::QUERY_VAR_SUBSCRIPTION_USER_ID])) {

      $wpQuery->query_vars['meta_query'] = array();
      $successOptIn = $this->api->updateOptIn($wpQuery->query[self::QUERY_VAR_SUBSCRIPTION_USER_ID]);
      $pageId = $this->getSetting('optinErrorPageId');

      if ($successOptIn) {
        $pageId = $this->getSetting('optinSuccessPageId');
      }

      // This works for multilang and non-mulitlang as is returns $pageId, if not langs are set
      $pageId = Multilang::getPostIdInLang($pageId, Multilang::getCurrentLang());
      header('Location: ' . get_permalink($pageId));
    }
  }

  /**
   * Filters the QueryVars, search for the user ID
   * @param array $queryVars
   * @return array the changed query vars
   */
  public function filterQueryVars($queryVars)
  {
    $queryVars[] = static::QUERY_VAR_SUBSCRIPTION_USER_ID;
    return $queryVars;
  }

  /**
   * This displays a form of settings for mailchimp
   */
  public function displaySettings()
  {
    $html = '';
    $tplDesc = LbwpCore::getModule('LbwpConfig')->getTplDesc();
    $tplNoDesc = LbwpCore::getModule('LbwpConfig')->getTplNoDesc();

    // Set a subheader
    $html .= '
      <h3>Generelle Einstellungen / API Zugang</h3>
      <p style="clear:both">Nachfolgend müssen die Einstellungen für den Zugriff auf die Plattform von Emarsys eingestellt werden.</p>
    ';

    // Create the basic inputs for access from template
    $input = '<input type="text" class="cfg-field-text" name="userName" value="' . $this->getSetting('userName') . '">';
    $html .= str_replace('{title}', 'Emarsys Benutzer', $tplNoDesc);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{fieldId}', 'userName', $html);

    $input = '<input type="password" class="cfg-field-text" name="secretKey" value="' . $this->getSetting('secretKey') . '">';
    $html .= str_replace('{title}', 'Emarsys API Key', $tplNoDesc);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{fieldId}', 'secretKey', $html);

    $input = '<input type="text" class="cfg-field-text" name="apiUrl" value="' . $this->getSetting('apiUrl') . '">';
    $html .= str_replace('{title}', 'API Server', $tplNoDesc);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{fieldId}', 'apiUrl', $html);

    // Natural selection
    switch ($this->getSetting('sendType')) {
      case 'automatic':
        $selectedManual = '';
        $selectedAuto = ' checked="checked"';
        break;
      case 'manual':
      default:
        $selectedManual = ' checked="checked"';
        $selectedAuto = '';
        break;
    }

    // Sending options
    $input = '
      <label for="sendType_Manual" class="cfg-field-check">
        <input type="radio" name="sendType" value="manual" id="sendType_Manual"' . $selectedManual . ' />
        <div class="cfg-field-check-text">Zu Emarsys senden und manuell verschicken</div>
      </label>
      <label for="sendType_Automatic" class="cfg-field-check">
        <input type="radio" name="sendType" value="automatic" id="sendType_Automatic"' . $selectedAuto . ' />
        <div class="cfg-field-check-text">Zu Emarsys senden und automatisch verschicken</div>
      </label>
    ';
    $description = '
      Sie können die Newsletter einplanen und zum geplanten Zeitpunkt zu Emarsys senden.<br />
      Dort besteht die Option, ob der Newsletter automatisch oder manuell ausgelöst wird.
    ';

    $html .= str_replace('{title}', 'Versandart', $tplDesc);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{description}', $description, $html);
    $html = str_replace('{fieldId}', 'sendType_Manual', $html);

    // Add additional settings, when the service is working
    if ($this->isWorking()) {
      $html .= $this->getGeneralSettings($tplDesc, $tplNoDesc);
      $html .= $this->getFieldSettings($tplDesc, $tplNoDesc);
      $html .= $this->getSubscribeSettings($tplDesc, $tplNoDesc);
      // Add another lazy spacer
      $html .= '<p>&nbsp;<!--lazy-spacer--></p>';
    }

    return $html;
  }

  /**
   * @param string $tplDesc template with description field
   * @param string $tplNoDesc simple no desc template
   * @return string HTML code for additional general settings
   */
  protected function getGeneralSettings($tplDesc, $tplNoDesc)
  {
    $html = '';
    $options = '';

    // Create the selectable options
    foreach ($this->api->getCategories() as $id => $category) {
      $selected = selected($id, $this->getSetting('emailCategory'), false);
      $options .= '<option value="' . $id . '"' . $selected . '>' . $category . '</option>';
    }

    $input = '<select name="emailCategory">' . $options . '</select>';
    $description = 'Dieser Kategorie werden von der Webseite aus verschickte Newsletter zugeordnet.';

    $html .= str_replace('{title}', 'E-Mail Kategorie', $tplDesc);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{description}', $description, $html);
    $html = str_replace('{fieldId}', 'emailCategory', $html);

    return $html;
  }

  /**
   * @param string $tplDesc template with description field
   * @param string $tplNoDesc simple no desc template
   * @return string HTML code for field settings section
   */
  protected function getFieldSettings($tplDesc, $tplNoDesc)
  {
    $html = '
      <p>&nbsp;<!--lazy-spacer--></p>
      <h3>Einstellungen für Feld-Zuordnung</h3>
      <p style="clear:both">Zuordnung von Feldern, damit Neu-Anmeldungen und Versände in Emarsys korrekt erstellt werden.</p>
    ';

    // Add the normal fields like email, first, lastname, salutation
    $fields = $this->api->getAttributes();
    $config = array(
      'firstName' => 'Vorname',
      'lastName' => 'Nachname',
      'email' => 'E-Mail',
      'salutationCode' => 'Anrede'
    );

    // Add language Field, if multilang installation
    if (Multilang::isActive()) {
      $config['languageField'] = 'Sprachzuordnung';
    }

    // Generate the input fields
    foreach ($config as $fieldId => $fieldName) {
      $options = '';
      $fieldKey = 'fieldSetting_' . $fieldId;
      foreach ($fields as $id => $field) {
        $selected = selected($id, $this->getSetting($fieldKey), false);
        $options .= '<option value="' . $id . '"' . $selected . '>' . $field . '</option>';
      }

      $input = '<select name="' . $fieldKey . '" style="width:300px;">' . $options . '</select>';
      $html .= str_replace('{title}', $fieldName, $tplNoDesc);
      $html = str_replace('{input}', $input, $html);
      $html = str_replace('{fieldId}', $fieldKey, $html);
    }

    // Spacing and pre header, for language code configurations
    $languageField = $this->getSetting('fieldSetting_languageField');
    if (strlen($languageField) > 0) {
      $html .= '
        <p>&nbsp;<!--lazy-spacer--></p>
        <p style="clear:both">Zuordnung von Sprachen, damit Versände in Emarsys korrekt erstellt werden.</p>
      ';

      $languages = $this->api->getChoiceList($languageField);
      foreach (Multilang::getConfigureableFields() as $lang => $name) {
        $options = '';
        $fieldKey = 'languageSetting_' . $lang;
        foreach ($languages as $id => $language) {
          $selected = selected($id, $this->getSetting($fieldKey), false);
          $options .= '<option value="' . $id . '"' . $selected . '>' . $language . '</option>';
        }

        $input = '<select name="' . $fieldKey . '" style="width:300px;">' . $options . '</select>';
        $html .= str_replace('{title}', $name, $tplNoDesc);
        $html = str_replace('{input}', $input, $html);
        $html = str_replace('{fieldId}', $fieldKey, $html);
      }
    }

    return $html;
  }

  /**
   * @param string $tplDesc template with description field
   * @param string $tplNoDesc simple no desc template
   * @return string HTML code for subscribe settings section
   */
  protected function getSubscribeSettings($tplDesc, $tplNoDesc)
  {
    $html = '
      <p>&nbsp;<!--lazy-spacer--></p>
      <h3>Einstellungen für Neu-Anmeldungen</h3>
      <p style="clear:both">Für den Double-Opt-In hat Emarsys keine eigenen Seiten. Die Landingpages für Anmeldungen können Sie hier definieren.</p>
    ';

    // Add the normal fields like email, first, lastname, salutation
    $pages = $this->getPages();
    $config = array(
      'optinSuccessPageId' => 'Opt-In Erfolgsseite',
      'optinErrorPageId' => 'Opt-In Fehlerseite'
    );

    foreach ($config as $fieldKey => $fieldName) {
      $options = '';
      foreach ($pages as $id => $page) {
        $selected = selected($id, $this->getSetting($fieldKey), false);
        $options .= '<option value="' . $id . '"' . $selected . '>' . $page . '</option>';
      }

      $input = '<select name="' . $fieldKey . '" style="width:300px;">' . $options . '</select>';
      $html .= str_replace('{title}', $fieldName, $tplNoDesc);
      $html = str_replace('{input}', $input, $html);
      $html = str_replace('{fieldId}', $fieldKey, $html);
    }

    // Events for double optin
    $html .= '
      <p>&nbsp;<!--lazy-spacer--></p>
      <p style="clear:both">Pro Sprachversion kann ein in Emarsys hinterlegter Event ausgelöst werden, wenn Sich ein Besucher für den Newsletter anmeldet.</p>
    ';

    $events = $this->api->getExternalEvents();
    foreach (Multilang::getConfigureableFields() as $lang => $name) {
      $options = '';
      $fieldKey = 'subscriptionEvent_' . $lang;
      foreach ($events as $id => $event) {
        $selected = selected($id, $this->getSetting($fieldKey), false);
        $options .= '<option value="' . $id . '"' . $selected . '>' . $event . '</option>';
      }

      $input = '<select name="' . $fieldKey . '" style="width:300px;">' . $options . '</select>';
      $html .= str_replace('{title}', $name, $tplNoDesc);
      $html = str_replace('{input}', $input, $html);
      $html = str_replace('{fieldId}', $fieldKey, $html);
    }

    return $html;
  }

  /**
   * This saves the settings from display Settings
   */
  public function saveSettings()
  {
    $message = '<div class="updated"><p>Einstellungen gespeichert</p></div>';
    $apiKey = $_POST['secretKey'];
    if (strlen($apiKey) > 0) {
      // Save the service settings
      $this->updateSetting('sendType', Strings::validateField($_POST['sendType']));
      $this->updateSetting('userName', $_POST['userName']);
      $this->updateSetting('secretKey', $_POST['secretKey']);
      $this->updateSetting('apiUrl', $_POST['apiUrl']);
      $this->updateSetting('emailCategory', $_POST['emailCategory']);
      $this->updateSetting('optinSuccessPageId', $_POST['optinSuccessPageId']);
      $this->updateSetting('optinErrorPageId', $_POST['optinErrorPageId']);
      // Field settings (some blindly saved even if they not exist
      foreach (array('firstName', 'lastName', 'email', 'salutationCode', 'languageField') as $field) {
        $this->updateSetting('fieldSetting_' . $field, $_POST['fieldSetting_' . $field]);
      }

      // Save fields that are maybe multilanguage capable
      if (Multilang::isActive()) {
        foreach (Multilang::getConfigureableFields() as $lang => $name) {
          $this->updateSetting('languageSetting_' . $lang, $_POST['languageSetting_' . $lang]);
          $this->updateSetting('subscriptionEvent_' . $lang, $_POST['subscriptionEvent_' . $lang]);
        }
      }

      // Create a new helper, with the given data, and see if that works
      $api = new Helper($_POST['userName'], $_POST['secretKey'], $_POST['apiUrl']);

      // If it worked fine, permanently save the settings
      if ($api->checkAccess()) {
        $this->core->getSettings()->saveServiceClass($this);
      } else {
        $message = '<div class="error"><p>Die eingegebenen Emarsys Zugangsdaten scheinen nicht korrekt zu sein.</p></div>';
      }
    }

    return $message;
  }

  /**
   * @return array list of pages (id=>name pair)
   */
  protected function getPages()
  {
    $pages = array();
    foreach (get_pages() as $p) {
      $pages[$p->ID] = $p->post_title;
    }

    return $pages;
  }

  /**
   * @param array $selectedKeys
   * @return array list of options to use in a dropdown
   */
  public function getListOptions($selectedKeys = array(), $fieldKey = 'listId')
  {
    // Grab lists from API
    $html = '';
    $currentListId = $this->getSetting($fieldKey);
    $selectedKeys = ArrayManipulation::forceArrayAndInclude($selectedKeys);

    $optionGroups = array(
      'segment' => array(
        'name' => 'Segmente',
        'items' => $this->api->getSegments()
      ),
      'list' => array(
        'name' => 'Kontaktlisten',
        'items' => $this->api->getLists()
      ),
    );

    // Display a list if possible
    foreach ($optionGroups as $groupKey => $groupConfig) {
      $html .= '<optgroup label="' . $groupConfig['name'] . '">';
      foreach ($groupConfig['items'] as $id => $item) {
        $listKey = $id . '$$' . $groupKey . '$$' . $item;

        // Preselect with id or key
        $selected = '';
        foreach ($selectedKeys as $selectedKey) {
          if (strlen($selectedKey) == 0) {
            $selected = selected($currentListId, $id, false);
          } else {
            $selected = selected($selectedKey, $listKey, false);
          }
          if (strlen($selected) > 0) {
            break;
          }
        }

        // Display the entry
        $html .= '
          <option value="' . $listKey . '"' . $selected . '>
            ' . $item . '
          </option>
        ';
      }

      $html .= '</optgroup>';
    }

    return $html;
  }

  /**
   * @param string $listName to search
   * @return int the list id, if found or 0
   */
  public function getListIdByName($listName)
  {
    foreach ($this->api->getLists() as $id => $name) {
      if ($listName == $name) {
        return $id;
      }
    }

    return 0;
  }

  /**
   * @return string delivery method depending on settings
   */
  public function getDeliveryMethod()
  {
    $method = self::DELIVERY_METHOD_SEND;
    if ($this->settings['sendType'] == 'manual') {
      $method = self::DELIVERY_METHOD_TRANSFER;
    }

    return $method;
  }

  /**
   * @param array $targets the list IDs to use on the api
   * @param string $html the html code for the newsletter
   * @param string $text the text version of the newsletter
   * @param string $subject the subject
   * @param string $senderEmail the sender email address
   * @param string $senderName the sender name alias
   * @param string $originalTarget used to determine if list or segment is being sent
   * @param string $language internal language code to be mapped to emarsys
   * @param \ComotiveNL\Newsletter\Newsletter\Newsletter $newsletter the actual object
   * @return string|int the mailing id from the service
   */
  public function createMailing($targets, $html, $text, $subject, $senderEmail, $senderName, $originalTarget, $language, $newsletter)
  {
    foreach ($targets as $index => $listId) {
      // Define if it is a segment or list, initalize both as 0
      // $sendItemId is not used, because it is the same as the validated $listId
      $contactListId = $segmentId = 0;
      list($sendItemId, $typeId) = explode('$$', $originalTarget[$index]);
      switch ($typeId) {
        case 'segment':
          $segmentId = $listId;
          break;
        case 'list':
          $contactListId = $listId;
          break;
      }

      // Submit the mailing to emarsys
      $mailingId = $this->api->createMailing(
        $this->getSetting('languageSetting_' . $language),
        $subject,
        $senderEmail,
        $senderName,
        $subject,
        $this->getSetting('emailCategory'),
        $segmentId,
        $contactListId,
        $html,
        $text
      );

      // Schedule if configured
      if ($this->getSetting('sendType') == 'automatic') {
        // Schedule the campaign
        if (!$this->api->scheduleMailing($mailingId, current_time('timestamp'))) {
          return 0;
        }
      }
    }

    // Return the mailing id
    return $mailingId;
  }

  /**
   * @return array the services current configuration
   */
  public function getConfigurationInfo()
  {
    $info = array();

    // Sending info
    switch ($this->getSetting('sendType')) {
      case 'manual':
        $info[] = 'Versand muss in Emarsys manuell ausgeführt werden.';
        break;
      case 'automatic':
        $info[] = 'Versand wird in Emarsys automatisch ausgeführt.';
        break;
    }

    return $info;
  }

  /**
   * variable mapping from lbwp to mailchimp
   * @return array see parent documentation for more info
   */
  public function getVariables()
  {
    return apply_filters('emarsysVariables', array(
      'firstname' => $this->getSetting('fieldSetting_firstName'),
      'lastname' => $this->getSetting('fieldSetting_lastName'),
      'salutation' => $this->getSetting('fieldSetting_salutationCode'),
      'email' => $this->getSetting('fieldSetting_email'),
      'unsubscribe' => '',
    ));
  }

  /**
   * @return array service instance information
   */
  public function getSignature()
  {
    $working = (
      strlen($this->getSetting('userName')) > 0 &&
      strlen($this->getSetting('secretKey')) > 0 &&
      strlen($this->getSetting('apiUrl')) > 0
    );

    return array(
      'id' => $this->serviceId,
      'name' => 'Emarsys',
      'class' => __CLASS__,
      'description' => 'Mail-Dienst, der den reibungslosen Versand des Newsletters durchführt.',
      'working' => $working ? true : false
    );
  }

  /**
   * Subscribes a user to the current list
   * @param array $data must contain email. firstname, lastname optional
   * @param mixed $listId an optional override list ID
   * @return bool true/false if the subscription worked
   */
  public function subscribe($data, $listId = '')
  {
    // Create new list, or get existing list, if a name is given
    if (is_string($listId) && strlen($listId) > 0) {
      $listId = $this->createListOrReturnExistingId($listId);
    }

    $emarsysData = array();
    $variables = $this->getVariables();

    // Save the email, but unset from data array, because it's not a emarsys field
    $email = $data['email'];
    unset($data['email']);

    // Map the data keys to local mailchimp vars
    foreach ($data as $key => $value) {
      $translatedKey = $variables[$key];
      // Remove the mailchimp tags, because the API docs say so
      if (strlen($data[$key]) > 0) {
        $emarsysData[$translatedKey] = $data[$key];
      }
    }

    // Add the multilang field, if given
    if (Multilang::isActive()) {
      $languageCode = $this->getSetting('languageSetting_' . Multilang::getCurrentLang());
      $emarsysData[$this->getSetting('fieldSetting_languageField')] = $languageCode;
    }

    // Finally add/update contact and put the cntact on the
    $eventId = $this->getSetting('subscriptionEvent_' . Multilang::getConfureableFieldLang());
    $this->api->updateContact($data);
    $this->api->addContactToList($email, $listId);
    $this->api->triggerEvent($eventId, $email);

    // Everything seems to have worked fine
    return true;
  }

  /**
   * Unsubscribes a specified email address from the current list
   * @param string $email the emai address
   * @param string $listId list id or name of a to be created list
   * @return bool true/false if the unsubscription worked
   */
  public function unsubscribe($email, $listId = '')
  {
    // Get existing list, if a name is given
    if (is_string($listId) && strlen($listId) > 0) {
      $listId = $this->createListOrReturnExistingId($listId);
    }

    // Tell the API to remove that man or woman
    return $this->api->removeContactFromList($email, $listId);
  }

  /**
   * @param int $listName the contact list name
   * @return int list id
   */
  protected function createListOrReturnExistingId($listName)
  {
    // Try getting the list id by name
    $listId = $this->getListIdByName($listName);

    // Add the creation function and use $data if needed
    if ($listId == 0) {
      $listId = $this->api->createContactList($listName);
    }

    return $listId;
  }

  /**
   * @param int $newsletterId
   * @return string url to the stats page or empty string
   */
  public function getStatisticsUrl($newsletterId)
  {
    return '';
  }

  /**
   * @return bool false: no dynamic targets
   */
  public function hasDynamicTargets()
  {
    return false;
  }

  /**
   * @return bool false: no statistics available
   */
  public function hasStatistics()
  {
    return false;
  }
} 