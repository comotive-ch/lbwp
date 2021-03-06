<?php

namespace LBWP\Newsletter\Service\Mailchimp;

use Exception;
use LBWP\Core as LbwpCore;
use LBWP\Util\Strings;
use LBWP\Util\Date;
use LBWP\Newsletter\Service\Base;
use LBWP\Newsletter\Service\Definition;
use LBWP\Util\ArrayManipulation;
use DrewM\MailChimp\MailChimp as MailChimpV3;

/**
 * Implementation class for Mailchimp service
 * @package LBWP\Newsletter\Service\Mailchimp
 * @author Michael Sebel <michael@comotive.ch>
 */
class Implementation extends Base implements Definition
{
  /**
   * @var string the id of this service type
   */
  protected $serviceId = 'mailchimp_2_0';

  /**
   * @param \LBWP\Newsletter\Core $core
   */
  public function __construct($core)
  {
    parent::__construct($core);
    // Include the mailchimp API
    require_once __DIR__ . '/api/MailchimpV3.php';
  }

  /**
   * This displays a form of settings for mailchimp
   */
  public function displaySettings()
  {
    $fields = '';
    $template = LbwpCore::getModule('LbwpConfig')->getTplDesc();
    $templateNoDesc = LbwpCore::getModule('LbwpConfig')->getTplNoDesc();

    // Create input and description
    $input = '<input type="text" class="cfg-field-text" name="apiKey" value="' . $this->getSetting('apiKey') . '">';
    $description = '
      <a href="https://us1.admin.mailchimp.com/account/api/" target="_blank">Erstellen Sie Ihren eigenen Key</a> bei mailchimp.com
    ';

    // Create the form field from template
    $fields .= str_replace('{title}', 'MailChimp API Key', $template);
    $fields = str_replace('{input}', $input, $fields);
    $fields = str_replace('{description}', $description, $fields);
    $fields = str_replace('{fieldId}', 'apiKey', $fields);

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
        <div class="cfg-field-check-text">Zu MailChimp senden und manuell verschicken</div>
      </label>
      <label for="sendType_Automatic" class="cfg-field-check">
        <input type="radio" name="sendType" value="automatic" id="sendType_Automatic"' . $selectedAuto . ' />
        <div class="cfg-field-check-text">Zu MailChimp senden und automatisch verschicken</div>
      </label>
    ';
    $description = '
      Sie können die Newsletter einplanen und zum geplanten Zeitpunkt zu MailChimp senden.<br />
      Dort besteht die Option, ob der Newsletter automatisch oder manuell ausgelöst wird.
    ';

    $fields .= str_replace('{title}', 'Versandart', $template);
    $fields = str_replace('{input}', $input, $fields);
    $fields = str_replace('{description}', $description, $fields);
    $fields = str_replace('{fieldId}', 'sendType_Manual', $fields);

    // Preselection (only disable, if it was disabled
    $selectedOpens = ' checked="checked"';
    $selectedLinks = ' checked="checked"';
    if ($this->getSetting('trackOpens') === 0) {
      $selectedOpens = '';
    }
    if ($this->getSetting('trackLinks') === 0) {
      $selectedLinks = '';
    }

    // Opening mails and link statistics option
    $input = '
      <label for="trackOpens" class="cfg-field-check">
        <input type="checkbox" name="trackOpens" value="1" id="trackOpens"' . $selectedOpens . ' />
        <div class="cfg-field-check-text">Öffnen der E-Mails aufzeichnen</div>
      </label>
      <label for="trackLinks" class="cfg-field-check">
        <input type="checkbox" name="trackLinks" value="1" id="trackLinks"' . $selectedLinks . ' />
        <div class="cfg-field-check-text">Klick auf Links aufzeichnen</div>
      </label>
    ';

    $fields .= str_replace('{title}', 'Statistik', $templateNoDesc);
    $fields = str_replace('{input}', $input, $fields);
    $fields = str_replace('{fieldId}', 'trackOpens', $fields);

    // If the API key is set, display the lists dropdown
    if (strlen($this->getSetting('apiKey')) > 0) {
      // Create input and description
      $input = '<select name="listId">' . $this->getListOptions('', 'listId', false) . '</select>';
      $description = 'Diese Liste wird für An-/Abmeldungen und den Versand verwendet.';

      // Create the form field from template
      $fields .= str_replace('{title}', 'Empfängerliste', $template);
      $fields = str_replace('{input}', $input, $fields);
      $fields = str_replace('{description}', $description, $fields);
      $fields = str_replace('{fieldId}', 'listId', $fields);

      // Create input and description
      $input = '<select name="testId">' . $this->getListOptions('', 'testId', true) . '</select>';
      $description = 'Diese Versandliste wird für den Testversand verwendet.';

      // Create the form field from template
      $fields .= str_replace('{title}', 'Testliste', $template);
      $fields = str_replace('{input}', $input, $fields);
      $fields = str_replace('{description}', $description, $fields);
      $fields = str_replace('{fieldId}', 'listId', $fields);
    }

    // Create the html
    $html = '
      <p>Bitte geben Sie Ihre Zugangsdaten an, um Newsletter via MailChimp zu versenden.</p>
      ' . $fields . '
    ';

    return $html;
  }

  /**
   * This saves the settings from display Settings
   */
  public function saveSettings()
  {
    $message = '<div class="updated"><p>Einstellungen gespeichert</p></div>';
    $apiKey = $_POST['apiKey'];
    if (strlen($apiKey) > 0) {
      $api = new MailchimpV3($apiKey);
      // Save the service settings
      $this->updateSetting('sendType', Strings::validateField($_POST['sendType']));
      $this->updateSetting('trackOpens', intval($_POST['trackOpens']));
      $this->updateSetting('trackLinks', intval($_POST['trackLinks']));

      // First, preset nothing for the test ids
      $this->updateSetting('listName', '');
      $this->updateSetting('testName', '');

      // List data
      if (strlen($_POST['listId']) > 0) {
        $listData = explode('$$', $_POST['listId']);
        $this->updateSetting('listId', $listData[0]);
        $this->updateSetting('listName', $listData[1]);
      }

      // Test list data
      if (strlen($_POST['testId']) > 0) {
        $listData = explode('$$', $_POST['testId']);
        $this->updateSetting('testId', $listData[0]);
        $this->updateSetting('testName', $listData[1]);
      }

      // Try to call the API and save the api key if it doesn't throw an error
      $response = $api->get('', array());

      if (!isset($response['status']) || $response['status'] == 200) {
        // If no exception happened, save the key and save the service id
        $this->updateSetting('apiKey', $apiKey);
        $this->core->getSettings()->saveServiceClass($this);
      } else {
        $message = '<div class="error"><p>MailChimp meldet folgendes Problem: ' . $response['detail'] . '</p></div>';
      }
    }

    return $message;
  }

  /**
   * @param array $selectedKeys
   * @param string $fieldKey
   * @param bool $includeNoSelection
   * @return array list of options to use in a dropdown
   */
  public function getListOptions($selectedKeys = array(), $fieldKey = 'listId', $includeNoSelection = false)
  {
    // Grab lists from API
    $html = '';
    $lists = $this->getLists();
    $currentListId = $this->getSetting($fieldKey);
    $selectedKeys = ArrayManipulation::forceArrayAndInclude($selectedKeys);

    // If no selection is allowed
    if ($includeNoSelection) {
      $html .= '<option value="">-- Keine Vorauswahl treffen</option>';
    }

    // Display a list if possible
    if (is_array($lists['lists']) && count($lists['lists']) > 0) {
      foreach ($lists['lists'] as $list) {
        $listKey = $list['id'] . '$$' . $list['name'];

        // Preselect with id or key
        $selected = '';
        foreach ($selectedKeys as $selectedKey) {
          if (strlen($selectedKey) == 0) {
            $selected = selected($currentListId, $list['id'], false);
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
            ' . $list['name'] . ' (' . $list['stats']['member_count'] . ' Empfänger)
          </option>
        ';
      }
    } else {
      $html = '<option value="">' . __('Konnte keine Listen laden. Bitte erstellen sie eine Liste in MailChimp.', 'lbwp') . '</option>';
    }

    return $html;
  }

  /**
   * @param int $maxLists the maximum number of lists to return
   * @return array all lists
   * @throws Exception if the apiKey isn't valid
   */
  protected function getLists($maxLists = 20)
  {
    if (strlen($this->getSetting('apiKey')) == 0) {
      throw new Exception('Please define an API key before querying lists');
    }

    $key = 'serviceLists_' . $this->signature['id'] . '_' . $maxLists;
    $lists = wp_cache_get($key, 'LbwpNewsletter');

    if (!is_array($lists['lists'])) {
      $lists = $this->getApi()->get('lists', array(
        'start' => 0,
        'limit' => $maxLists,
        'sort_field' => 'date_created',
        'sort_order' => 'ASC'
      ));

      wp_cache_set($key, $lists, 'LbwpNewsletter', 900);
    }

    return $lists;
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
   * @param string $originalTarget not used
   * @param string $language not used
   * @param \ComotiveNL\Newsletter\Newsletter\Newsletter $newsletter the actual object
   * @return string|int the mailing id from the service
   */
  public function createMailing($targets, $html, $text, $subject, $senderEmail, $senderName, $originalTarget, $language, $newsletter)
  {
    foreach ($targets as $listId) {
      // This would be returned if anything bad happens
      $mailingId = 0;

      // Create the campaign
      $result = $this->getApi()->post('campaigns', array(
        'type' => 'regular',
        'recipients' => array(
          'list_id' => $listId,
        ),
        'settings' => array(
          'subject_line' => $subject,
          'reply_to' => $senderEmail,
          'from_name' => $senderName
        ),
        'tracking' => array(
          'opens' => ($this->getSetting('trackOpens') == 1) ? true : false,
          'html_clicks' => ($this->getSetting('trackLinks') == 1) ? true : false
        )
      ));

      // Try finding the id in results
      if (isset($result['id']) && strlen($result['id']) > 0) {
        $mailingId = $result['id'];
      }

      // Set the content of the campaign
      $this->getApi()->put('campaigns/' . $mailingId . '/content', array(
        'html' => $html,
        'plain_text' => $text
      ));

      // Schedule if configured
      if ($this->getSetting('sendType') == 'automatic') {
        // Schedule the campaign for "now"
        if (!$this->getApi()->post('campaigns/' . $mailingId . '/actions/send')) {
          return 0;
        }
      }
    }

    // Return the campaing id as mailing id
    return $mailingId;
  }

  /**
   * @return array the services current configuration
   */
  public function getConfigurationInfo()
  {
    $info = array();

    // Sending info
    switch($this->getSetting('sendType')) {
      case 'manual':
        $info[] = 'Versand muss in MailChimp manuell ausgeführt werden.';
        break;
      case 'automatic':
        $info[] = 'Versand wird in MailChimp automatisch ausgeführt.';
        break;
    }

    // Open tracking
    if ($this->getSetting('trackOpens') == 1) {
      $info[] = 'Öffnungsrate der E-Mails wird aufgezeichnet.';
    }

    // Link tracking
    if ($this->getSetting('trackLinks') == 1) {
      $info[] = 'Angeklickte Links in E-Mails werden aufgezeichnet.';
    }

    // List name
    if (strlen($this->getSetting('listName')) > 0) {
      $info[] = 'Die Liste "' . $this->getSetting('listName') . '" wird verwendet.';
    }

    return $info;
  }

  /**
   * variable mapping from lbwp to mailchimp
   * @return array see parent documentation for more info
   */
  public function getVariables()
  {
    return apply_filters('mailchimpVariables', array(
      'firstname' => '*|FNAME|*',
      'lastname' => '*|LNAME|*',
      'salutation' => '*|SALUTATION|*',
      'email' => '*|EMAIL|*',
      'unsubscribe' => '<a href="*|UNSUB|*" target="_blank">Abmelden</a>',
    ));
  }

  /**
   * @return array service instance information
   */
  public function getSignature()
  {
    $working = (
      strlen($this->getSetting('apiKey')) > 0 &&
      strlen($this->getSetting('listId')) > 0
    );

    return array(
      'id' => $this->serviceId,
      'name' => 'MailChimp',
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
    // Get the according mailchimp variables to send
    $mergeVars = array();
    $variables = $this->getVariables();

    // Get the default list id of not given
    if (strlen($listId) == 0) {
      $listId = $this->getSetting('listId');
    }

    // Save the email, but unset from data array, because it's not a merge var
    $email = $data['email'];
    unset($data['email']);

    // Map the data keys to local mailchimp vars
    foreach ($data as $key => $value) {
      $translatedKey = $variables[$key];
      // Remove the mailchimp tags, because the API docs say so
      $translatedKey = str_replace(array('*|', '|*'), '', $translatedKey);
      if (strlen($data[$key]) > 0 && strlen($translatedKey) > 0) {
        $mergeVars[$translatedKey] = $data[$key];
      } else if (Strings::startsWith($key, '*|') && Strings::endsWith($key, '|*')) {
        $translatedKey = str_replace(array('*|', '|*'), '', $key);
        $mergeVars[$translatedKey] = $data[$key];
      }
    }

    // Send the subscription, while catching no more errors since v3
    $result = $this->getApi()->post('lists/' . $listId . '/members', array(
      'email_address' => $email,
      'merge_fields' => $mergeVars,
      'status' => 'pending'
    ));

    // If member already exists, try a patch command
    if ($result['status'] == 400 && $result['title'] == MailChimpV3::ERROR_MEMBER_EXISTS) {
      $emailHash = md5(strtolower($email));
      $result = $this->getApi()->patch('lists/' . $listId . '/members/' . $emailHash, array(
        'email_address' => $email,
        'status' => 'pending',
        'merge_fields' => $mergeVars
      ));
    }

    // Everything seems to have worked fine
    return true;
  }

  /**
   * Unsubscribes a specified email address from the current list
   * @param string $email the emai address
   * @param mixed $listId an optional override list ID
   * @return bool true/false if the unsubscription worked
   */
  public function unsubscribe($email, $listId = '')
  {
    // Get the default list id of not given
    if (strlen($listId) == 0) {
      $listId = $this->getSetting('listId');
    }

    // Remove the address from the current list, catch all errors
    $emailHash = md5(strtolower($email));
    $this->getApi()->delete('lists/' . $listId . '/members/' . $emailHash);

    // Everything seems to have worked fine
    return true;
  }

  /**
   * @param int $newsletterId
   * @return string url to the stats page or empty string
   */
  public function getStatisticsUrl($newsletterId)
  {
    $serviceNewsletterId = $this->getServiceMailingId($newsletterId);
    // If there is an id, link it
    if (strlen($serviceNewsletterId)) {
      return 'https://admin.mailchimp.com/reports/summary?id=' . $serviceNewsletterId;
    }

    // If there is no mailing id from mailchimp, don't provide the summary link
    return '';
  }

  /**
   * Helper to make api calls where it's secure to use the apiKey blindly
   * @return MailchimpV3
   */
  protected function getApi()
  {
    return new MailchimpV3($this->getSetting('apiKey'));
  }

  /**
   * @return bool false: no dynamic targets
   */
  public function hasDynamicTargets()
  {
    return false;
  }

  /**
   * @return bool false: no dynamic addressing
   */
  public function hasDynamicAddressing()
  {
    return false;
  }

  /**
   * @return bool true: statistics available
   */
  public function hasStatistics()
  {
    return true;
  }
} 