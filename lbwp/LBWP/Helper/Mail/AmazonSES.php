<?php

namespace LBWP\Helper\Mail;

use Aws\Ses\SesClient;
use LBWP\Util\AwsFactoryV3;

/**
 * Class for sending bulk mail trough AmazonSES
 * @package LBWP\Helper\Mail
 * @author Michael Sebel <michael@comotive.ch>
 */
class AmazonSES extends Base
{
  /**
   * @var SesClient instance of the actual mail sending object
   */
  protected $instance = NULL;
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
    $this->instance = AwsFactoryV3::getSesService(
      $config['accessKey'],
      $config['secretKey'],
      'eu-west-1'
    );
  }

  /**
   * Just reset the data array
   */
  public function reset()
  {
    $this->data = array(
      'recipients' => array()
    );
  }

  /**
   * @return bool true if the mail was sent or false if not
   */
  public function send()
  {
    // On local, assume it was sent, but don't send anything
    /*if (defined('LOCAL_DEVELOPMENT')) {
      return true;
    }*/

    $response = $this->instance->sendEmail(array(
      'Source' => $this->data['from']['name'] . '<' . $this->data['from']['email'] . '>',
      'Destination' => array(
        'ToAddresses' => $this->data['recipients']
      ),
      'Message' => array(
        'Subject' => array(
          'Data' => $this->data['subject'],
          'Charset' => 'UTF-8'
        ),
        'Body' => array(
          'Html' => array(
            'Data' => $this->data['body'],
            'Charset' => 'UTF-8'
          ),
          'Text' => array(
            'Data' => $this->data['altBody'],
            'Charset' => 'UTF-8'
          ),
        )
      )
    ));

    if ($response->get('@metadata')['statusCode'] != 200) {
      $this->log(
        'AmazonSES',
        $this->data['recipients'][0],
        $this->data['subject'],
        'Bounce, reject or error'
      );
    }

    return $response->status == 200;
  }

  /**
   * @param string $subject the subject
   */
  public function setSubject($subject)
  {
    $this->data['subject'] = $subject;
  }

  /**
   * @param string $body the body
   */
  public function setBody($body)
  {
    $this->data['body'] = $body;
  }

  /**
   * @param string $body the alternative text body
   */
  public function setAltBody($body)
  {
    $this->data['altBody'] = $body;
  }

  /**
   * @param string $tag tagging for statistics
   */
  public function setTag($tag)
  {
    // No statistics or tagging support
  }

  /**
   * @param string $email actual mail from address
   * @param string $name name displayed in clients
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
   */
  public function addReplyTo($email)
  {
    $this->data['replyTo'] = $email;
  }

  /**
   * @param string $email the email to add
   * @param string $name the name to connect to the email
   */
  public function addAddress($email, $name = '')
  {
    $this->data['recipients'][] = $email;
  }
}