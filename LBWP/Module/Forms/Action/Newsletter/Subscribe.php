<?php

namespace LBWP\Module\Forms\Action\Newsletter;

use LBWP\Core;
use LBWP\Module\Forms\Action\Base;
use LBWP\Newsletter\Core as NewsletterCore;
use LBWP\Util\Strings;
use LBWP\Util\ArrayManipulation;

/**
 * This will suscribe an email adress to the current newsletter list
 * @package LBWP\Module\Forms\Action\Newsletter
 * @author Michael Sebel <michael@comotive.ch>
 */
class Subscribe extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Für Newsletter anmelden';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Newsletter anmelden',
    'help' => 'Anmeldung für eine Newsletter-Liste',
    'group' => 'Newsletter'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'email_feld' => array(
        'name' => 'Name des E-Mail-Feldes',
        'type' => 'textfield'
      ),
      'skip_errors' => array(
        'name' => 'Name des E-Mail-Feldes',
        'type' => 'dropdown',
        'values' => array(
          '0' => 'Fehler bei der Anmeldung werden angezeigt',
          '1' => 'Fehler bei der Anmeldung werden ignoriert (Erfolgsmeldung)'
        )
      ),
      'vorname_feld' => array(
        'name' => 'Name des Vorname-Feldes (Optional)',
        'type' => 'textfield'
      ),
      'nachname_feld' => array(
        'name' => 'Name des Nachname-Feldes (Optional)',
        'type' => 'textfield'
      ),
      'anrede_feld' => array(
        'name' => 'Name des Anrede-Feldes (Optional)',
        'type' => 'textfield'
      ),
      'custom_field_name_1' => array(
        'name' => 'Benutzerdefiniertes Feld 1 (Name)',
        'help' => 'Bei MailChimp bitte die komplette MergeVar z.B. *|COMPANY|* verwenden.',
        'type' => 'textfield'
      ),
      'custom_field_value_1' => array(
        'name' => 'Benutzerdefiniertes Feld 1 (Inhalt)',
        'type' => 'textfield'
      ),
      'custom_field_name_2' => array(
        'name' => 'Benutzerdefiniertes Feld 2 (Name)',
        'help' => 'Bei MailChimp bitte die komplette MergeVar z.B. *|COMPANY|* verwenden.',
        'type' => 'textfield'
      ),
      'custom_field_value_2' => array(
        'name' => 'Benutzerdefiniertes Feld 2 (Inhalt)',
        'type' => 'textfield'
      ),
      'custom_field_name_3' => array(
        'name' => 'Benutzerdefiniertes Feld 3 (Name)',
        'help' => 'Bei MailChimp bitte die komplette MergeVar z.B. *|COMPANY|* verwenden.',
        'type' => 'textfield'
      ),
      'custom_field_value_3' => array(
        'name' => 'Benutzerdefiniertes Feld 3 (Inhalt)',
        'type' => 'textfield'
      ),
      'custom_field_name_4' => array(
        'name' => 'Benutzerdefiniertes Feld 4 (Name)',
        'help' => 'Bei MailChimp bitte die komplette MergeVar z.B. *|COMPANY|* verwenden.',
        'type' => 'textfield'
      ),
      'custom_field_value_4' => array(
        'name' => 'Benutzerdefiniertes Feld 4 (Inhalt)',
        'type' => 'textfield'
      ),
      'custom_field_name_5' => array(
        'name' => 'Benutzerdefiniertes Feld 5 (Name)',
        'help' => 'Bei MailChimp bitte die komplette MergeVar z.B. *|COMPANY|* verwenden.',
        'type' => 'textfield'
      ),
      'custom_field_value_5' => array(
        'name' => 'Benutzerdefiniertes Feld 5 (Inhalt)',
        'type' => 'textfield'
      ),
      'list_id' => array(
        'name' => 'Listen- oder Segment ID',
        'help' => 'Diese ID bekommen Sie in der Regel von Ihrem Maildienstleister.',
        'type' => 'textfield'
      )
    ));
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['key'] = $key;
    // Set the defaults (will be overriden with current data on execute)
    $this->params['email_feld'] = 'Feldname der E-Mail-Adresse';
    $this->params['anrede_feld'] = 'Feldname für die Anrede (Optional)';
    $this->params['vorname_feld'] = 'Name des Feldes mit dem Vornamen (Optional)';
    $this->params['nachname_feld'] = 'Name des Feldes mit dem Nachnamen (Optional)';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    // Initialize an empty service data array
    $subscription = array(
      'email' => '',
      'firstname' => '',
      'lastname' => '',
      'salutation' => '',
    );

    // See if we can find the email field
    foreach ($data as $field) {
      // Skip fields with empty names to prevent strangeness
      if (strlen($field['name']) == 0) {
        continue;
      }
      switch ($field['name']) {
        case $this->params['email_feld']:
          $subscription['email'] = $field['value'];
          break;
        case $this->params['vorname_feld']:
          $subscription['firstname'] = $field['value'];
          break;
        case $this->params['nachname_feld']:
          $subscription['lastname'] = $field['value'];
          break;
        case $this->params['anrede_feld']:
          $subscription['salutation'] = $field['value'];
          break;
      }
    }

    // Set the matching fields
    $matches = array(
      'email' => 'email_feld',
      'firstname' => 'vorname_feld',
      'lastname' => 'nachname_feld',
      'salutation' => 'anrede_feld',
    );

    // For not yet found fields, try using new patterns
    foreach ($subscription as $key => $value) {
      if (strlen($value) == 0) {
        $subscription[$key] = $this->getFieldContent($data, $this->params[$matches[$key]]);
      }
    }

    // Decide if errors can be skipped
    $skipErrors = intval($this->params['skip_errors']) == 1;

    // Check if email is actually an email and return if not
    if (!Strings::checkEmail($subscription['email'])) {
      return $skipErrors;
    }

    // Add custom fields to the subscription (only implemented in mailchimp), if given
    for ($i = 1; $i <= 5; ++$i) {
      $fieldName = $this->params['custom_field_name_' . $i];
      if (strlen($fieldName) > 0) {
        $subscription[$fieldName] = $this->getFieldContent($data, $this->params['custom_field_value_' . $i]);
      }
    }

    // Provide empty or string or a given list id as param
    $listId = '';
    if (isset($this->params['list_id']) && strlen($this->params['list_id']) > 0) {
      $listId = $this->params['list_id'];
    }

    /** @var NewsletterCore $newsletter Call the definition method on the current NL sevice */
    $newsletter = Core::getModule('NewsletterBase');
    $service = $newsletter->getService();

    // Subscribe the user
    if ($service->isWorking()) {
      $status = $service->subscribe($subscription, $listId);
      return $skipErrors ? true : $status;
    }

    // If we reach this, subscription didn't happen
    return $skipErrors;
  }
} 