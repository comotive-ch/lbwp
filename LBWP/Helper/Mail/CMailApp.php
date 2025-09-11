<?php

namespace LBWP\Helper\Mail;

use LBWP\Module\General\Cms\SystemLog;

/**
 * Very simple PHP class to utilize comotive mail
 * @package LBWP\Helper\Mail
 */
class CMailApp
{
  /**
   * @var string the host uri
   */
  const DEFAULT_HOST_URI = 'https://apps.comotive.ch';
  /**
   * @var string endpoint to send mail
   */
  const ENDPOINT_SEND = '/wp-json/c/mail/send';
  /**
   * @var string[] destination types
   */
  protected $destTypes = array('To', 'Cc', 'Bcc');
  /**
   * @var string
   */
  protected static $API_HOST = self::DEFAULT_HOST_URI;
  /**
   * @var bool
   */
  protected static $VERIFY_SSL = true;

  /**
   * @param $apiKey
   */
  public function __construct($apiKey)
  {
    $this->apiKey = $apiKey;
  }

  /**
   * @param $uri
   * @return void
   */
  public function setApiHostUri($uri, $local)
  {
    self::$API_HOST = $uri;
    self::$VERIFY_SSL = !$local;
  }

  /**
   * @param array $mail the full mail object, example:
   *  array(
        'From' => 'sender@example.ch',
        'FromName' =>  'Edwin Example',
        'Destination' => array(
          'To' => array('recipient@example.ch'),
          'Cc' => array('anotherone@example.ch'),
          'Bcc' => array()
        ),
        // Optional: Made with date('c'), only needed when send time is in the future
        'Delivery' => '2021-03-24T12:00:53+00:00',
        // Add attachments directly or previously uploaded file by ID
        'Attachments = array(
          array(
            // Should be a valid file name with ending and no special chars, to work in all clients
            'Name' => 'thefilename.pdf',
            // A binary file, base64 encoded as a string, use for small files
            'Data' => base64_encode($binary)
            // An ID of a previously uploaded file via /wp-json/c/mail/upload
            // 'ID' => 111746354 // NOT IMPLEMENTED YET
          )
        ),
        'Message' => array(
          'Subject' => 'An example Subject',
          'Body' => array(
            'Html' => 'This is <strong>THE HTML BODY</strong>.',
            'Text' => 'This is the text body.'
          )
        )
      )
   * @return array of status = 200 (or else) and message (if given)
   */
  public function sendEmail($mail)
  {
    // From has to be set
    if (!isset($mail['From']) || strlen($mail['From']) == 0) {
      throw new \Exception('From can not be empty');
    }

    if (!isset($mail['Destination']) || count($mail['Destination']) == 0) {
      throw new \Exception('Destination needs to be set and have content');
    }

    // Check for destinations
    $destCount = 0;
    foreach ($this->destTypes as $type) {
      if (isset($mail['Destination'][$type]) && is_array($mail['Destination'][$type])) {
        $destCount += count($mail['Destination'][$type]);
      }
    }

    if ($destCount === 0) {
      throw new \Exception('Destination list set but empty');
    }

    // Check futher fields for content
    if (!isset($mail['Message']['Subject']) || strlen($mail['Message']['Subject']) == 0) {
      throw new \Exception('Message.Subject can not be empty');
    }

    if (
      (!isset($mail['Message']['Body']['Html']) || strlen($mail['Message']['Body']['Html']) == 0) &&
      (!isset($mail['Message']['Body']['Text']) || strlen($mail['Message']['Body']['Text']) == 0)
    )
      {
      throw new \Exception('Message.Body.Text or .Html needs to be set');
    }

    if (isset($mail['Attachments']) && is_array($mail['Attachments'])) {
      foreach ($mail['Attachments'] as $attachment) {
        if (strlen($attachment['Name']) == 0 && (strlen($attachment['ID']) == 0 || strlen($attachment['Data']) == 0)) {
          throw new \Exception('Attachment Name and ID or Data must be given but not found');
        }
      }
    }

    // Always set delivery date "now" if not given
    if (!isset($mail['Delivery'])) {
      $mail['Delivery'] = date('c');
    }

    // If nothing's thrown, send to api
    $rq = curl_init(self::$API_HOST . self::ENDPOINT_SEND);
    curl_setopt_array($rq, array(
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => json_encode($mail),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => self::$VERIFY_SSL,
      CURLOPT_SSL_VERIFYHOST => self::$VERIFY_SSL,
      CURLOPT_HTTPHEADER => array(
        'content-type: application/json',
        'authorization: bearer ' . $this->apiKey
      )
    ));

    $response = curl_exec($rq);
    $message = curl_error($rq);
    $status = curl_errno($rq);

    if ($status == CURLE_OK) {
      $return = json_decode($response, true);
      if (is_array($return) && isset($return['status'])) {
        return $return;
      } else {
        SystemLog::add('CMailApp', 'error', 'cURL error: ' . $status . ' / ' . $response);
      }
    }

    curl_close($rq);
    return array(
      'status' => 500,
      'message' => $message
    );
  }
}