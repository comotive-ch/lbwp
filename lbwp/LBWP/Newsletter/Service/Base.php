<?php

namespace LBWP\Newsletter\Service;

use LBWP\Newsletter\Core;

/**
 * This is a base class for service implementations
 * @package LBWP\Newsletter\Service
 */
abstract class Base
{
  /**
   * @var Core the core newsletter instance
   */
  protected $core = NULL;
  /**
   * @var array the service signature
   */
  protected $signature = array();
  /**
   * @var array the settings
   */
  protected $settings = array();
  /**
   * @var string the service id
   */
  protected $serviceId = 'must_be_overridden';

  /**
   * @param Core $core the newsletter core
   */
  public function __construct(Core $core)
  {
    $this->core = $core;
    $this->settings = get_option('serviceSettings_' . $this->serviceId);
    $this->signature = $this->getSignature();

    // If there are no settings, at least have an empty array
    if (!is_array($this->settings)) {
      $this->settings = array();
    }
  }

  /**
   * @return bool true tells that the service is working
   */
  public function isWorking()
  {
    return $this->signature['working'];
  }

  /**
   * @param \Exception $exception an exception
   */
  protected function sendReport($exception)
  {
    $subject = LBWP_HOST . ' Exception: ' . $exception->getMessage();
    $body = $exception->getTraceAsString();
    mail(SERVER_EMAIL, $subject, $body, 'From: info@sdd1.ch');
  }

  /**
   * @param string $key the key
   * @return mixed the value
   */
  public function getSetting($key)
  {
    return $this->settings[$key];
  }

  /**
   * @param string $key the key
   * @param mixed $value the value
   */
  public function updateSetting($key, $value)
  {
    $this->settings[$key] = $value;
    update_option('serviceSettings_' . $this->signature['id'], $this->settings);
  }
}