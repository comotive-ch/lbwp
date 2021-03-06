<?php

namespace LBWP\Newsletter\Service;

/**
 * This defines the features a mail service has to implement
 * @package LBWP\Newsletter\Service
 */
interface Definition
{
  /**
   * Delivery method constants
   */
  const DELIVERY_METHOD_SEND = 'transfer_and_send';
  const DELIVERY_METHOD_TRANSFER = 'transfer_only';
  /**
   * This function should display the settings page for the service
   */
  public function displaySettings();

  /**
   * This function should save the settings posted in eventual forms of displaySettings
   */
  public function saveSettings();

  /**
   * @return array list of strings represeting the services current configuration
   */
  public function getConfigurationInfo();

  /**
   * The service needs to map a few global variables for all service to provide
   * template support over all service. These variables are:
   * {lbwp:firstname}, {lbwp:lastname}, {lbwp:salutation}, {lbwp:email}, {lbwp:unsubscribe}
   * Hence, the return value should be like this
   *
   * array(
   *   'firstname' => '*|FNAME|*',
   *   'lastname' => '*|LNAME|*',
   *   'salutation' => '*|SALUTATION|*',
   *   'email' => '*|EMAIL|*',
   *   'unsubscribe' => '<a href="*|UNSUB|*">Abmelden</a>',
   * );
   *
   * @return array see documentation
   */
  public function getVariables();

  /**
   * @param array $selectedKeys the selected list item
   * @return array of selectable lists (with id, value)
   */
  public function getListOptions($selectedKeys = array());

  /**
   * @return string one of self::DELIVERY_METHOD_*
   */
  public function getDeliveryMethod();

  /**
   * Subscribes a user to the current list
   * @param array $data must contain email. firstname, lastname optional, everything else defined by service
   * @param mixed $listId an optional override list ID
   * @return bool true/false if the subscription worked
   */
  public function subscribe($data, $listId = '');

  /**
   * Unsubscribes a specified email address from the current list
   * @param string $email the emai address
   * @param mixed $listId an optional override list ID
   * @return bool true/false if the unsubscription worked
   */
  public function unsubscribe($email, $listId = '');

  /**
   * @return bool true/false if there are dynamic targets supported by filtering
   */
  public function hasDynamicTargets();

  /**
   * @return bool true/false if dynamic email adresses instead of targets are possible to use
   */
  public function hasDynamicAddressing();

  /**
   * @return bool true/false if there are statistics with this service
   */
  public function hasStatistics();

  /**
   * @param int $newsletterId the internal newsletter id
   * @return string the url of a statistics page (internal or external) to display stats for a newsletter
   */
  public function getStatisticsUrl($newsletterId);

  /**
   * @param array $targets the list IDs to use
   * @param string $html the html code for the newsletter
   * @param string $text the text version of the newsletter
   * @param string $subject the subject
   * @param string $senderEmail the sender email address
   * @param string $senderName the sender name alias
   * @param string $originalTarget full target name (which may contain additional send info)
   * @param string $language language of the newsletter
   * @param \ComotiveNL\Newsletter\Newsletter\Newsletter $newsletter the actual object
   * @return string|int the mailing id from the service
   */
  public function createMailing($targets, $html, $text, $subject, $senderEmail, $senderName, $originalTarget, $language, $newsletter);

  /**
   * Should contain:
   * - id: the id name of the service (only small letters)
   * - name: the displayable name of the service
   * - class: the full class name of the implementation
   * - description: meta information: what is this service, who provides it, etc.
   * - working: bool, must be true if the service is considered as useable
   * @return array this should return an array of informations about the service
   */
  public function getSignature();
}