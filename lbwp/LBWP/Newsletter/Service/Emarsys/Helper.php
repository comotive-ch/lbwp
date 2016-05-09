<?php

namespace LBWP\Newsletter\Service\Emarsys;

use LBWP\Util\Date;

/**
 * API Helper Class for Emarsys
 * @package LBWP\Newsletter\Service\Emarsys
 * @author Michael Sebel <michael@comotive.ch>
 */
class Helper
{
  /**
   * @var string the API url
   */
  protected $apiUrl = '';
  /**
   * @var string the user name to call the api
   */
  protected $userName = '';
  /**
   * @var string the secure key to create the WSSE header
   */
  protected $secureKey = '';
  /**
   * The API url default used to predefine it in settings
   */
  const DEFAULT_API_URL = 'https://www.emarsys.net/api/v2/';
  /**
   * @var int the reply code if a contact exists
   */
  const CONTACT_EXISTS = 2009;
  /**
   * @var int number of seconds "get" api functions are cached
   */
  const CACHE_TIME = 900;
  /**
   * @var string the email field
   */
  const KEY_FIELD_ID = 3;

  /**
   * @param string $userName user name to call the api
   * @param string $secureKey secure key to creat WSSE headers
   * @param string $apiUrl the api url to call
   */
  public function __construct($userName, $secureKey, $apiUrl)
  {
    $this->userName = $userName;
    $this->secureKey = $secureKey;
    $this->cacheGroup = 'EmarsysApiCache_' . md5($userName . base64_encode($secureKey));
    $this->apiUrl = $apiUrl;
  }

  /**
   * @param $endpoint
   * @param array $data
   * @param string $type
   * @return array|mixed
   */
  protected function request($endpoint, $data = array(), $type = 'GET')
  {
    $ch = curl_init();
    $requestUri = $this->apiUrl . $endpoint;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, false);

    // Add parameters depending on request type
    switch ($type) {
      case 'GET':
        curl_setopt($ch, CURLOPT_HTTPGET, 1);
        // Do the usual get shenanigans
        if (is_array($data) && count($data) > 0) {
          $requestUri .= '?' . http_build_query($data);
        }
        break;
      case 'POST':
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        break;
      case 'PUT':
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        break;
    }

    // Now set the URL
    curl_setopt($ch, CURLOPT_URL, $requestUri);
    // And get the headers to authenticate
    curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getAuthHeader());

    // Call the API and hope the best
    $response = curl_exec($ch);
    // Also, we need to (eventually) convert the string
    if (mb_detect_encoding($response) == 'ISO-8859-1') {
      $response = mb_convert_encoding($response, 'UTF-8', 'ISO-8859-1');
    }

    curl_close($ch);

    return json_decode($response, true);
  }

  /**
   * We add X-WSSE header for authentication.
   * Always use random 'nonce' for increased security.
   * timestamp: the current date/time in UTC format encoded as
   * an ISO 8601 date string like '2010-12-31T15:30:59+00:00' or '2010-12-31T15:30:59Z'
   * passwordDigest looks sg like 'MDBhOTMwZGE0OTMxMjJlODAyNmE1ZWJhNTdmOTkxOWU4YzNjNWZkMw=='
   */
  protected function getAuthHeader()
  {
    $nonce = uniqid('blw') . time();
    $timestamp = gmdate("c");
    $passwordDigest = base64_encode(sha1($nonce . $timestamp . $this->secureKey, false));

    // Generate the header as of documentation
    return array(
      'X-WSSE: UsernameToken ' .
      'Username="' . $this->userName . '", ' .
      'PasswordDigest="' . $passwordDigest . '", ' .
      'Nonce="' . $nonce . '", ' .
      'Created="' . $timestamp . '", ',
      'Content-Type: application/json'
    );
  }

  /**
   * Checks wheter the given credentials actually work
   * @return bool true, if access to the API is given
   */
  public function checkAccess()
  {
    $response = $this->request('language');
    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      return true;
    }

    return false;
  }

  /**
   * @return array a list of lists
   */
  public function getLists()
  {
    $result = wp_cache_get('getLists', $this->cacheGroup);

    // See if already cached
    if (!is_array($result)) {
      // Get from API
      $result = array();
      $response = $this->request('contactlist');
      if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $list) {
          $result[$list['id']] = $list['name'];
        }
      }

      // Reverse the array because oldest are first, and cache
      $result = array_reverse($result, true);
      wp_cache_set('getLists', $result, $this->cacheGroup, self::CACHE_TIME);
    }

    return $result;
  }

  /**
   * @return array a list of external events
   */
  public function getExternalEvents()
  {
    $result = wp_cache_get('getExternalEvents', $this->cacheGroup);

    // See if already cached
    if (!is_array($result)) {
      // Get from API
      $result = array();
      $response = $this->request('event');
      if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $event) {
          $result[$event['id']] = $event['name'];
        }
      }

      // Reverse the array because oldest are first, and cache
      $result = array_reverse($result, true);
      wp_cache_set('getExternalEvents', $result, $this->cacheGroup, self::CACHE_TIME);
    }

    return $result;
  }

  /**
   * @param int $eventId the event id
   * @param string $email a contact email
   * @param array $payload optional payload data
   * @return bool true, if the triggering was successful
   */
  public function triggerEvent($eventId, $email, $payload = array())
  {
    $endpoint = sprintf('event/%d/trigger', $eventId);
    $data = array(
      'key_id' => self::KEY_FIELD_ID,
      'external_id' => $email,
      'data' => array(
        'global' => $payload
      )
    );

    $response = $this->request($endpoint, $data, 'POST');
    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      return true;
    }

    return false;
  }

  /**
   * @param int $fieldId
   * @return array
   */
  public function getChoiceList($fieldId)
  {
    $result = wp_cache_get('getChoiceList-' . $fieldId, $this->cacheGroup);

    // See if already cached
    if (!is_array($result)) {
      // Get from API
      $result = array();
      $endpoint = sprintf('field/%d/choice', $fieldId);
      $response = $this->request($endpoint);
      if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $choice) {
          if (strlen($choice['choice']) > 0) {
            $result[$choice['id']] = $choice['choice'];
          }
        }
      }

      // Save to cache
      wp_cache_set('getChoiceList-' . $fieldId, $result, $this->cacheGroup, self::CACHE_TIME);
    }

    return $result;
  }

  /**
   * @return array a list of categories
   */
  public function getCategories()
  {
    $result = wp_cache_get('getCategories', $this->cacheGroup);

    // See if already cached
    if (!is_array($result)) {
      // Get from API
      $result = array('0' => 'Keine Kategorie');
      $response = $this->request('emailcategory');
      if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $category) {
          if (strlen($category['category']) > 0) {
            $result[$category['id']] = $category['category'];
          }
        }
      }

      // Save to cache
      wp_cache_set('getCategories', $result, $this->cacheGroup, self::CACHE_TIME);
    }

    return $result;
  }

  /**
   * Fields used by the customer
   * @return array the fields
   */
  public function getAttributes()
  {
    $result = wp_cache_get('getAttributes', $this->cacheGroup);

    // See if already cached
    if (!is_array($result)) {
      // Get from API
      $result = array();
      $response = $this->request('field');
      if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $attribute) {
          $result[$attribute['id']] = $attribute['name'];
        }
      }

      // Save the results to cache
      wp_cache_set('getAttributes', $result, $this->cacheGroup, self::CACHE_TIME);
    }

    return $result;
  }

  /**
   * @return array a list of segments
   */
  public function getSegments()
  {
    $result = wp_cache_get('getSegments', $this->cacheGroup);

    // See if already cached
    if (!is_array($result)) {
      // Get from API
      $result = array();
      $response = $this->request('filter');
      if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $segment) {
          $result[$segment['id']] = $segment['name'];
        }
      }

      // Save to cache
      wp_cache_set('getSegments', $result, $this->cacheGroup, self::CACHE_TIME);
    }

    return $result;
  }

  /**
   * This adds or updates a contact
   * @param array $data the data to post to the api
   * @return int the contact id
   */
  public function updateContact($data)
  {
    $data['key_field'] = self::KEY_FIELD_ID;
    $insert = $this->request('contact', $data, 'POST');
    if (isset($insert['data']['id'])) {
      return intval($insert['data']['id']);
    }

    // The subscriber wasn't inserted, but already exists, update him
    if (isset($insert['replyCode']) && $insert['replyCode'] == self::CONTACT_EXISTS) {
      $update = $this->request('contact', $data, 'PUT');
      if (isset($update['data']['id'])) {
        return intval($update['data']['id']);
      }
    }

    return 0;
  }

  /**
   * Sets the opt-in field for the user with the given uid to true
   *
   * @param $uid
   * @return bool
   */
  public function updateOptIn($uid)
  {

    $data = array(
      'key_id' => 'uid',
      'uid' => $uid,
      '31' => '1' //Set Opt-in Field to true
    );

    $update = $this->request('contact', $data, 'PUT');
    if (isset($update['data']['id'])) {
      return true;
    }

    return false;
  }

  /**
   * @param string $email the contact to add
   * @param int $listId the list to add him
   * @return bool true/false if worked or not
   */
  public function addContactToList($email, $listId)
  {
    $endpoint = sprintf('contactlist/%d/add', $listId);
    $data = array(
      'key_id' => self::KEY_FIELD_ID,
      'external_ids' => array($email)
    );

    $response = $this->request($endpoint, $data, 'POST');
    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      return true;
    }

    return false;
  }

  /**
   * @param string $email the contact to be removed
   * @param int $listId the list to remove him from
   * @return bool true/false if worked or not
   */
  public function removeContactFromList($email, $listId)
  {
    $endpoint = sprintf('contactlist/%d/delete', $listId);
    $data = array(
      'key_id' => self::KEY_FIELD_ID,
      'external_ids' => array($email)
    );

    $response = $this->request($endpoint, $data, 'POST');
    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      return true;
    }

    return false;
  }

  /**
   * @param string $listName name of the list
   * @return bool|int
   */
  public function createContactList($listName)
  {
    $data = array('name' => $listName);
    $response = $this->request('contactlist', $data, 'POST');

    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      wp_cache_delete('getLists', $this->cacheGroup);
      return $response['id'];
    }

    return 0;
  }

  /**
   * @param string $lang language tag (SMK notation, must be convertet possibly
   * @param string $title internal mailing title
   * @param string $fromemail from email header
   * @param string $fromname from name header
   * @param string $subject the subject
   * @param int $category the emailcategory to set
   * @param int $segment the segment to send to (if given, list must be 0)
   * @param int $list the list to send to (if given, segment must be 0)
   * @param string $html full html mail
   * @param string $text text version
   * @return int email id to further schedule or 0 if an error occured
   */
  public function createMailing($lang, $title, $fromemail, $fromname, $subject, $category, $segment, $list, $html, $text)
  {
    $data = array(
      'name' => $title . '_' . uniqid('lbwp'),
      'language' => strtolower($lang),
      'fromemail' => $fromemail,
      'fromname' => $fromname,
      'subject' => $subject,
      'email_category' => $category,
      'filter' => $segment,
      'contactlist' => $list,
      'html_source' => $html,
      'text_source' => $text,
    );

    $response = $this->request('email', $data, 'POST');

    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      return intval($response['data']['id']);
    }

    return 0;
  }

  /**
   * @param int $mailingId the mailing to be scheduled
   * @param int $scheduleTime the schedule time
   * @return bool if success or not
   */
  public function scheduleMailing($mailingId, $scheduleTime)
  {
    $endpoint = sprintf('email/%d/launch', $mailingId);

    // Defined timezone with fallback
    $timezone = get_option('timezone_string');
    if (strlen(trim($timezone)) == 0) {
      $timezone = 'Europe/Zurich';
    }

    // Provide schedule date and timezone
    $data = array(
      'schedule' => Date::get_time('Y-m-d H:i', $scheduleTime),
      'timezone' => $timezone
    );

    $response = $this->request($endpoint, $data, 'POST');
    if (isset($response['replyCode']) && $response['replyCode'] == 0) {
      return true;
    }

    return false;
  }
} 