<?php

namespace LBWP\Helper\Mail;
use LBWP\Util\External;

/**
 * Class for sending local bulk mail using phpMailer
 * @package LBWP\Helper\Mail
 * @author Michael Sebel <michael@comotive.ch>
 */
class Local extends Base
{
  /**
   * @var \PHPMailer instance of the actual mail sending object
   */
  protected $instance = NULL;

  /**
   * @param array $config a config array for the instance
   */
  public function configure($config = array())
  {
    // Just create an instance, no additional configuration
    $this->instance = External::PhpMailer();
  }

  /**
   * Should reset the instance for another use of send
   */
  public function reset()
  {
    $this->instance->clearAllRecipients();
    $this->instance->clearReplyTos();
    $this->instance->Subject = '';
    $this->instance->Body = '';
  }

  /**
   * @return bool true if the mail was sent or false if not
   */
  public function send()
  {
    $sent = $this->instance->send();

    // Log errors
    if (!$sent) {
      $this->log(
        'LocalMail_PHPMailer',
        $this->instance->getToAddresses()[0],
        $this->instance->Subject,
        'Bounce, reject or error: ' . $this->instance->ErrorInfo
      );
    }

    return $sent;
  }

  /**
   * @param string $subject the subject
   */
  public function setSubject($subject)
  {
    $this->instance->Subject = $subject;
  }

  /**
   * @param string $body the body
   */
  public function setBody($body)
  {
    $this->instance->Body = $body;
  }

  /**
   * @param string $body the alternative text body
   */
  public function setAltBody($body)
  {
    $this->instance->AltBody = $body;
  }

  /**
   * @param string $tag tagging for statistics
   */
  public function setTag($tag)
  {
    // No statistics or tagging support
  }

  /**
   * @param int $timestamp
   */
  public function setTime($timestamp)
  {
    // No timing support
  }

  /**
   * @param string $email actual mail from address
   * @param string $name name displayed in clients
   */
  public function setFrom($email, $name = '')
  {
    // Only set fromName, because we don't override system sender
    $this->instance->FromName = $name;
  }

  /**
   * @param string $email the reply email
   */
  public function addReplyTo($email)
  {
    $this->instance->addReplyTo($email);
  }

  /**
   * @param string $email the email to add
   * @param string $name the name to connect to the email
   */
  public function addAddress($email, $name = '')
  {
    $this->instance->addAddress($email, $name);
  }
}