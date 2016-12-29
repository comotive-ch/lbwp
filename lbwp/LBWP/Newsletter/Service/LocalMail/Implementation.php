<?php

namespace LBWP\Newsletter\Service\LocalMail;

use LBWP\Helper\Cronjob;
use LBWP\Helper\MasterApi;
use LBWP\Newsletter\Service\Base;
use LBWP\Newsletter\Service\Definition;
use LBWP\Core as LbwpCore;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;

/**
 * Implementation class for local mail sending
 * @package LBWP\Newsletter\Service\Emarsys
 * @author Michael Sebel <michael@comotive.ch>
 */
class Implementation extends Base implements Definition
{
  /**
   * @var string the id of this service type
   */
  protected $serviceId = 'localmail_1_0';
  /**
   * @var LocalMailService the service api instance
   */
  protected $api = NULL;

  /**
   * @param \LBWP\Newsletter\Core $core
   */
  public function __construct($core)
  {
    parent::__construct($core);

    // Create an api instance, if the service is working and selected
    add_action('init', array($this, 'initializeApi'));
  }

  /**
   * Initialize the api
   */
  public function initializeApi()
  {
    if ($this->isWorking() && stristr($this->core->getSettings()->get('serviceClass'), 'LocalMail') !== false) {
      $this->api = LocalMailService::getInstance();
      $this->api->setVariables($this->getVariables());
    }
  }

  /**
   * @return bool true tells that the service is working
   */
  public function isWorking()
  {
    return LocalMailService::isWorking();
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
      <h3>Einstellungen für den lokalen Versand</h3>
      
    ';

    // Add settings only if the service is working (configured)
    if ($this->isWorking()) {
      $html .= '<p style="clear:both">Der lokale Mailversand kann genutzt werden. Klicken Sie auf "Speichern" um Ihn zu aktivieren.</p>';
    } else {
      $html .= '<p style="clear:both">Der lokale Mailversand ist für Ihre Installation nicht freigeschaltet.</p>';
    }

    return $html;
  }

  /**
   * This saves the settings from display Settings
   */
  public function saveSettings()
  {
    $message = '<div class="updated"><p>Der lokale Mailversand wurde aktiviert.</p></div>';
    $this->core->getSettings()->saveServiceClass($this);

    return $message;
  }

  /**
   * @param string $selectedKey
   * @param string $fieldKey
   * @return array list of options to use in a dropdown
   */
  public function getListOptions($selectedKey = '', $fieldKey = 'listId')
  {
    // Grab lists from API
    $html = '<option value="">Bitte wählen Sie eine Liste aus</option>';
    $lists = $this->api->getLists();
    $currentListId = $this->getSetting($fieldKey);

    // Display a list if possible
    if (is_array($lists) && count($lists) > 0) {
      foreach ($lists as $id => $list) {
        $listKey = $id . '$$' . $list;
        // Preselect with id or key
        if (strlen($selectedKey) == 0) {
          $selected = selected($currentListId, $id, false);
        } else {
          $selected = selected($selectedKey, $listKey, false);
        }
        // Display the entry
        $html .= '
          <option value="' . $listKey . '"' . $selected . '>
            ' . $list . '
          </option>
        ';
      }
    } else {
      $html = '<option value="">' . __('Es wurden noch keine Versandlisten erstellt.', 'lbwp') . '</option>';
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
    return self::DELIVERY_METHOD_SEND;
  }

  /** TODO
   * @param string $listId the list ID to use on the api
   * @param string $html the html code for the newsletter
   * @param string $text the text version of the newsletter
   * @param string $subject the subject
   * @param string $senderEmail the sender email address
   * @param string $senderName the sender name alias
   * @param string $originalTarget used to determine if list or segment is being sent
   * @param string $language internal language code to be mapped to emarsys
   * @return string|int the mailing id from the service
   */
  public function createMailing($listId, $html, $text, $subject, $senderEmail, $senderName, $originalTarget, $language)
  {
    // Create the mailing ID as a resilt of list and content
    $mailingId = md5($html . $subject . $listId) . '-' . $listId;

    // Create an unfinished mailing in our option array
    $this->api->setMailing($mailingId, 'creating');

    // Get the list and loop trough it to create the actual mailing object
    $mails = array();
    $list = get_post_meta($listId, 'list-data', true);
    if (is_array($list) && count($list)) {
      foreach ($list as $memberId => $recipient) {
        // First, add an unsubscribe object to the recipient
        $recipient['unsubscribe'] = $this->api->getUnsubscribeLink($memberId, $listId);

        // Personalize the mailing text with user data
        $personalizedHtml = $html;
        foreach ($recipient as $field => $value) {
          $personalizedHtml = str_replace('{' . $field . '}', $value, $personalizedHtml);
        }

        // Create a new mailing entry
        $mails[] = array(
          'html' => $personalizedHtml,
          'subject' => $subject,
          'recipient' => $recipient['email'],
          'senderEmail' => $senderEmail,
          'senderName' => $senderName
        );
      }
    }

    // Save the mails to be sent
    $this->api->createMailObjects($mailingId, $mails);

    // Create a cron that is checking for local mail sendings and actually starts sending
    $this->api->scheduleSendingCron($mailingId);
    $this->api->setMailing($mailingId, 'sending');

    return $mailingId;
  }

  /**
   * @return array the services current configuration
   */
  public function getConfigurationInfo()
  {
    $info = array(
      'Beachten Sie, dass der lokale Versand nur für ca. 1 - 300 Empfänger gedacht ist.',
      'Der Dienst kann zum Mail-Versand sowie für Blog-Abonnemente verwendet werden.'
    );

    return $info;
  }

  /**
   * variable mapping from lbwp to local mail
   * @return array see parent documentation for more info
   */
  public function getVariables()
  {
    return apply_filters('localMailVariables', array(
      'firstname' => 'firstname',
      'lastname' => 'lastname',
      'salutation' => 'salutation',
      'email' => 'email'
    ));
  }

  /**
   * @return array service instance information
   */
  public function getSignature()
  {
    return array(
      'id' => $this->serviceId,
      'name' => 'Lokaler Mailversand',
      'class' => __CLASS__,
      'description' => 'Lokaler Mail-Dienst, der für kleine Empfängerlisten gedacht ist.',
      'working' => LocalMailService::isWorking() ? true : false
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
    $listId = intval($listId);
    // Check if email and list id are given
    if (!Strings::checkEmail($data['email']) || $listId == 0) {
      return false;
    }

    // TODO add double optin variant here, once needed
    // Everything seems to have worked fine
    $recordId = md5($data['email']);
    return $this->api->subscribe($recordId, $listId, $data);
  }

  /**
   * Unsubscribes a specified email address from the current list
   * @param string $email the emai address
   * @param string $listId list id or name of a to be created list
   * @return bool true/false if the unsubscription worked
   */
  public function unsubscribe($email, $listId = '')
  {
    $listId = intval($listId);
    // Check if email and list id are given
    if (!Strings::checkEmail($email) || $listId == 0) {
      return false;
    }

    // Tell the API to remove that man or woman
    $recordId = md5($email);
    return $this->api->unsubscribe($recordId, $listId);
  }
} 