<?php

namespace LBWP\Helper\Mail;

use LBWP\Helper\Mail\CMailApp;

/**
 * Class for sending bulk mail through comotive mail
 * @package LBWP\Helper\Mail
 * @author Michael Sebel <michael@comotive.ch>
 */
class ComotiveMail extends Base
{
  /**
   * @var CMailApp instance of the actual mail sending object
   */
  protected $instance = NULL;
  /**
   * @var string delivery time string, generated on construct
   */
  protected $time = '';
  /**
   * @var array the data array to temporarly store info before sending
   */
  protected $data = array(
    'recipients' => array()
  );

  /**
   * @param array $config a config array for the instance
   */
  public function configure($config = array())
  {
    $this->instance = new CMailApp($config['apiKey']);
    $this->time = date('c', current_time('timestamp'));
  }

  /**
   * Just reset the data array
   */
  public function reset()
  {
    $this->data = array(
      'recipients' => array(),
      'cc' => array(),
      'bcc' => array(),
    );
  }

  /**
   * @return bool true if the mail was sent or false if not
   */
  public function send()
  {
    // On local, assume it was sent, but don't send anything
    if (defined('LOCAL_DEVELOPMENT')) {
      return true;
    }

    try {
      $response = $this->instance->sendEmail(array(
        'From' =>  $this->data['from']['email'],
        'FromName' =>  $this->data['from']['name'],
        'Delivery' => $this->time,
        'Destination' => array(
          'To' => $this->data['recipients'],
          'Cc' => $this->data['cc'],
          'Bcc' => $this->data['bcc']
        ),
        'Message' => array(
          'Subject' => $this->data['subject'],
          'Body' => array(
            'Html' => $this->data['body'],
            'Text' => $this->data['altBody']
          )
        )
      ));

      if ($response['status'] != 200) {
        $this->log(
          'ComotiveMail/Status',
          $this->data['recipients'][0],
          $this->data['subject'],
          $response['status'] . ': ' . $response['message']
        );
      }

      return $response['status'] == 200;
    } catch (\Exception $e) {
      $this->log(
        'ComotiveMail/Exception',
        $this->data['recipients'][0],
        $this->data['subject'],
        $e->getMessage()
      );
    }

    return false;
  }

  /**
   * @param string $subject the subject
   * @return void
   */
  public function setSubject($subject)
  {
    $this->data['subject'] = $subject;
  }

  /**
   * @param string $body the body
   * @return void
   */
  public function setBody($body)
  {
    $this->data['body'] = $body;
  }

  /**
   * @param string $body the alternative text body
   * @return void
   */
  public function setAltBody($body)
  {
    $this->data['altBody'] = $body;
  }

  /**
   * @param string $tag tagging for statistics
   * @return void
   */
  public function setTag($tag)
  {
    // No statistics or tagging support
  }

  /**
   * @param int $timestamp
   * @return void
   */
  public function setTime($timestamp)
  {
    if ($timestamp > 0) {
      $this->time = date('Y-m-d\TH:i:s.vO', $timestamp);;
    }
  }

  /**
   * @param string $email actual mail from address
   * @param string $name name displayed in clients
   * @return void
   */
  public function setFrom($email, $name = '')
  {
    $this->data['from'] = array(
      'email' => $email,
      'name' => $name
    );
  }

  /**
   * @param string $email the reply email
   * @return void
   */
  public function addReplyTo($email)
  {
    $this->data['replyTo'] = $email;
  }

  /**
   * @param string $email the email to add
   * @param string $name the name to connect to the email
   * @return void
   */
  public function addAddress($email, $name = '')
  {
    $this->data['recipients'][] = $email;
  }

  /**
   * @param string $email the email to add
   * @return void
   */
  public function addBcc($email)
  {
    $this->data['bcc'][] = $email;
  }

  /**
   * @param string $email the email to add
   * @return void
   */
  public function addCc($email)
  {
    $this->data['cc'][] = $email;
  }
}