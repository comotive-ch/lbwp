<?php

namespace LBWP\Helper\Mail;


/**
 * Abstract base class for an email sending servicee
 * @package LBWP\Helper\Mail
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
{
  /**
   * @var \stdClass instance of the actual mail sending object
   */
  protected $instance = NULL;
  /**
   * @var bool override with true, if there are statistics
   */
  protected $hasStatistics = false;

  /**
   * Used to create instance and configure the instance
   * @param array $config a config array for the instance
   */
  abstract public function configure($config);

  /**
   * TODO not yet implemented anything on any service :-)
   * @return bool determines if the service offers stats
   */
  public function hasStatistics()
  {
    return $this->hasStatistics;
  }

  /**
   * Should reset the instance for another use of send
   */
  abstract public function reset();

  /**
   * @return bool true if the mail was sent or false if not
   */
  abstract public function send();

  /**
   * @param string $subject the subject
   */
  abstract public function setSubject($subject);

  /**
   * @param string $body the body
   */
  abstract public function setBody($body);

  /**
   * @param string $tag tagging for statistics
   */
  abstract public function setTag($tag);

  /**
   * @param string $email actual mail from address
   * @param string $name name displayed in clients
   */
  abstract public function setFrom($email, $name = '');

  /**
   * @param string $email the reply email
   */
  abstract public function addReplyTo($email);

  /**
   * @param string $email the email to add
   * @param string $name the name to connect to the email
   */
  abstract public function addAddress($email, $name = '');
}