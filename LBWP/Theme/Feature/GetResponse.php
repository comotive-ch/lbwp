<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provides an integration with getresponse for forms
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class GetResponse
{
  /**
   * Default Settings for the pagenavi
   * @var array
   */
  protected $settings = array(
    'apiKey' => ''
  );
  /**
   * @var \wpdb the wordpress db object
   */
  protected $wpdb = NULL;
  /**
   * @var TransientStickyPost the sticky post config object
   */
  protected static $instance = NULL;
  /**
   * @var string base string for api
   */
  const API_BASE = 'https://api.getresponse.com/v3';


  /**
   * Can only be instantiated by calling init method
   * @param array|null $settings overriding defaults
   */
  protected function __construct($settings = NULL)
  {
    if (is_array($settings)) {
      $this->settings = array_merge($this->settings, $settings);
    }

    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /**
   * Initialise while overriding settings defaults
   * @param array|null $settings overrides defaults as new default
   */
  public static function init($settings = NULL)
  {
    self::$instance = new GetResponse($settings);
    self::$instance->load();
  }

  /**
   * Loads/runs the needed actions and functions
   */
  protected function load()
  {
    add_filter('lbwpFormActions', array($this, 'addFormAction'));

    // Handle actual gr_ params in GET
    if (isset($_GET['gr_campaign']) && strlen($_GET['gr_campaign']) > 0) {
      $this->createNewContact($_GET['gr_campaign'], $_GET['gr_email'], $_GET['gr_name']);
    }

    // Have a cron call the api regularly, so the api key doesn't get inactive
    add_action('cron_weekday_6', array($this, 'triggerKeepAliveCall'));
  }

  protected function createNewContact($listId, $email, $name)
  {
    // Do some minor validation
    Strings::alphaNumLow($listId);
    $name = substr(trim(strip_tags($name)), 0, 255);
    $email = base64_decode($email);
    $name = (strlen($name) == 0) ? $email : $name;

    // If we have everything needed
    if (strlen($listId) > 0 && Strings::checkEmail($email)) {
      $this->call('/contacts', 'POST', array(
        'name' => $name,
        'email' => $email,
        'ipAddress' => isset($_SERVER['X_REAL_IP']) ? $_SERVER['X_REAL_IP'] : $_SERVER['REMOTE_ADDR'],
        'campaign' => array(
          'campaignId' => $listId
        )
      ));
    }
  }

  /**
   * Trigger a call to the api to keep the key alive
   */
  public function triggerKeepAliveCall()
  {
    $result = $this->call('/campaigns', 'GET', array());
    $success = is_array($result) && count($result) > 0;

    // Log critical (sent by mail) error, if no success
    if (!$success) {
      SystemLog::add('GetResponse', 'critical', 'apiError', $result);
    }

    // Send a response for testing purposes only
    WordPress::sendJsonResponse(array(
      'esult' => $result,
      'success' => $success
    ));
  }

  /**
   * @param $endpoint
   * @param $data
   * @param string $type
   * @return mixed
   */
  public function call($endpoint, $type, $data)
  {
    $string = json_encode($data);
    // The URL is set, try to get the contents with curl so we get HTTP Status too
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false, // do not verify ssl certificates (fails if they are self-signed)
      CURLOPT_HEADER => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.43 Safari/537.31 Comotive-Fetch-1.0',
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_COOKIEJAR => 'tempCookie',
      CURLOPT_POSTFIELDS => $data,
      CURLOPT_CUSTOMREQUEST => $type,
      CURLOPT_POSTFIELDS => $string,
      CURLOPT_HTTPHEADER => array(
        'X-Auth-Token: api-key ' . $this->settings['apiKey'],
        'Content-Type: application/json',
        'Content-Length: ' . strlen($string)
      )
    );

    $res = curl_init(self::API_BASE . $endpoint);
    curl_setopt_array($res, $options);
    $result = json_decode(curl_exec($res), true);
    curl_close($res);

    return $result;
  }

  /**
   * This will add the form to crm action
   * @param array $actions list of current actions
   * @return array altered $actions array with new actions
   */
  public function addFormAction($actions)
  {
    // Add the two actions and return
    $actions['get-response'] = '\LBWP\Module\Forms\Action\Crm\GetResponse';
    return $actions;
  }
}