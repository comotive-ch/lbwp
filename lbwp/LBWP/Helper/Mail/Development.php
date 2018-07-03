<?php

namespace LBWP\Helper\Mail;
use LBWP\Util\External;

/**
 * Class for sending local bulk mail in dev mode, only with whitelist, rest is simulated
 * @package LBWP\Helper\Mail
 * @author Michael Sebel <michael@comotive.ch>
 */
class Development extends Base
{
  /**
   * @var \PHPMailer instance of the actual mail sending object
   */
  protected $instance = NULL;
  /**
   * @var array whitelist of sendable emails
   */
  protected $whitelist = array();
  /**
   * @var bool sets a bool if the mail is sendable
   */
  protected $sendable = false;

  /**
   * @param array $config a config array for the instance
   */
  public function configure($config = array())
  {
    $this->whitelist = $config['whitelist'];
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
    $sent = false;
    if ($this->sendable) {
      $sent = $this->instance->send();
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
    $this->sendable = false;
    $this->instance->addAddress($email, $name);
    if (in_array($email, $this->whitelist)) {
      $this->sendable = true;
    }

  }
}