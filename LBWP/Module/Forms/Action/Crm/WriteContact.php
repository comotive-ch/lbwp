<?php

namespace LBWP\Module\Forms\Action\Crm;

use LBWP\Module\Forms\Action\Base;
use LBWP\Theme\Component\Crm\Core;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This will put data from the form to crm fields
 * @package LBWP\Module\Forms\Action\Newsletter
 * @author Michael Sebel <michael@comotive.ch>
 */
class WriteContact extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Daten in CRM Kontakt';
  /**
   * @var \LBWP\Theme\Component\Crm\Core the crm core component
   */
  protected static $crm = NULL;
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Kontakt ins CRM schreiben',
    'help' => 'Daten von Formularfeldern in den Kontakt eines CRM Nutzers schreiben',
    'group' => 'CRM'
  );
  /**
   * @var array allowed types to be used for input
   */
  protected $allowedTypes = array(
    'textfield',
    'textarea'
  );
  /**
   * @var int
   */
  const MAX_FIELDS = 10;

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    // Get all CRM Fields to build the dropdown segment
    $dropdown = array();
    $fields = self::$crm->getCustomFields(false);
    foreach ($fields as $field) {
      if (in_array($field['type'], $this->allowedTypes)) {
        $dropdown[$field['id']] = $field['title'];
      }
    }

    // Add internal title field
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'title' => array(
        'name' => 'Interne Bezeichnung (Optional)',
        'type' => 'textfield',
        'help' => 'Bezeichnet, in welche Kontaktart geschrieben wird.'
      ),
      'id_field' => array(
        'name' => 'Feld zur Verknüpfung der Datensatz-ID',
        'type' => 'textfield',
        'help' => 'Dieses Feld muss die eindeutige Datensatz / CRM-Benutzer-ID enthalten (userID).'
      ),
      'email_field' => array(
        'name' => 'Feld zur Verknüpfung via Kontakt-E-Mail',
        'type' => 'textfield',
        'help' => 'Wenn gesetzt, wird die userID anhand der E-Mail in bestehenden Kontakten gesucht und dieser Kontakt geupdated.'
      ),
      'contact_field' => array(
        'name' => 'Kontaktart-ID',
        'type' => 'textfield',
        'help' => 'ID der Kontaktart die beschrieben werden soll.'
      ),
      'category_field' => array(
        'name' => 'Profilkategorie-ID',
        'type' => 'textfield',
        'help' => 'ID der zuzweisenden Profilkategorie bei neuen Kontakten.'
      ),
      'role_name' => array(
        'name' => 'Rolle Neu-Kontakt',
        'type' => 'textfield',
        'help' => 'Rolle bei neuen Kontakten.'
      ),
      'line_0' => array('type' => 'line'),
      'contact_salutation' => array(
        'name' => 'Anrede-Feld',
        'type' => 'textfield',
        'help' => 'Als Werte für den CRM Kontakt müssen die Keys "male" oder "female" verwendet werden.'
      ),
      'contact_firstname' => array(
        'name' => 'Vorname-Feld',
        'type' => 'textfield'
      ),
      'contact_lastname' => array(
        'name' => 'Nachname-Feld',
        'type' => 'textfield'
      ),
      'contact_email' => array(
        'name' => 'E-Mail-Feld',
        'type' => 'textfield'
      ),
      'line_1' => array('type' => 'line'),
      'checkbox_optin' => array(
        'name' => 'Opt-In E-Mail schicken, danach CRM Checkbox-Feld setzen',
        'type' => 'textfield',
        'help' => 'Löst eine Mail mit Link aus. wird dieser geklickt, wird die Checkbox mit der hier hinterlegten ID aktiviert'
      ),
    ));
  }


  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['key'] = $key;
  }

  /**
   * @param \LBWP\Theme\Component\Crm\Core $component
   */
  public static function setCrmComponent($component)
  {
    self::$crm = $component;
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    $db = WordPress::getDb();
    $userId = intval($this->getFieldContent($data, $this->params['id_field']));
    $mergeEmail = strtolower($this->getFieldContent($data, $this->params['email_field']));
    $contactTypeId = intval($this->params['contact_field']);
    $profileCategoryId = intval($this->params['category_field']);
    $optinCrmFieldId = intval($this->params['checkbox_optin']);

    // If we have no ID but a merge email, try getting the ID from email
    if ($userId == 0 && strlen($mergeEmail) > 0 && Strings::checkEmail($mergeEmail)) {
      $candidates = $db->get_results('
        SELECT user_id, meta_value FROM ' . $db->usermeta . '
        WHERE meta_key = "crm-contacts-' . $contactTypeId . '" AND meta_value LIKE "%' . $mergeEmail . '%"
      ');
      // Loop trough candidates and see if we have an exact match
      foreach ($candidates as $candidate) {
        if (maybe_unserialize($candidate->meta_value)[0]['email'] == $mergeEmail) {
          $userId = intval($candidate->user_id);
          break;
        }
      }
    }

    // Build the contact object
    $contact = array(
      'salutation' => $this->getFieldContent($data, $this->params['contact_salutation']),
      'firstname' => $this->getFieldContent($data, $this->params['contact_firstname']),
      'lastname' => $this->getFieldContent($data, $this->params['contact_lastname']),
      'email' => strtolower($this->getFieldContent($data, $this->params['contact_email']))
    );

    // Maybe fix male/female if not given correctly
    if (strlen($contact['salutation']) > 0 && $contact['salutation'] != 'male' && $contact['salutation'] != 'female') {
      if (Strings::contains($contact['salutation'], 'Herr')) {
        $contact['salutation'] = 'male';
      } else if (Strings::contains($contact['salutation'], 'Frau')) {
        $contact['salutation'] = 'female';
      }
    }

    // If we don't have a user, create an empty new contact with given profile-category
    if ($userId == 0) {
      $userId = wp_insert_user(array(
        'user_login' => $contact['email'],
        'user_pass' => sha1(md5($contact['email'])),
        'user_nicename' => Strings::forceSlugString($contact['email']),
        'user_email' => $contact['email'],
        'role' => $this->params['role_name'],
      ));
      // Set the profile category
      update_user_meta($userId, 'profile-categories', array($profileCategoryId));
    }

    // Get the current contact group
    $contacts = get_user_meta($userId, 'crm-contacts-' . $contactTypeId, true);
    // Build new array if not given yet
    if ($contacts == false) {
      $contacts = array($contact);
    } else {
      // Only merge in fields that are not empty with the first contact found in the list
      foreach ($contact as $key => $value) {
        if (strlen($value) > 0) {
          $contacts[0][$key] = $value;
        }
      }
    }

    // And save back to user meta
    update_user_meta($userId,'crm-contacts-' . $contactTypeId, $contacts);

    // See if there are contact maps - if a main contact is saved, syncs need to be run
    $doSyncs = false;
    $config = self::$crm->getConfiguration();
    if (isset($config['mainContactMap'])) {
      $user = get_user_by('id', $userId);
      foreach ($config['mainContactMap'] as $role => $id) {
        if ($user->has_cap($role) && $id == $contactTypeId) {
          $doSyncs = true;
        }
      }
    }

    // If we do syncs, see what exactly we're doing
    if ($doSyncs) {
      $fields = array('user_email' => $contact['email']);
      if (isset($config['syncUserCoreEmail']) && $config['syncUserCoreEmail']) {
        $fields['user_login'] = $contact['email'];
      }
      // Need to update with DB, as we would create an endless loop with update_user functions
      $db = WordPress::getDb();
      $db->update(
        $db->users,
        $fields,
        array('ID' => $userId)
      );
      clean_user_cache($userId);
    }

    // Send Optin-E-Mail if needed for user to optin to a given checkbox (most likely a newsletter subscription)
    if ($optinCrmFieldId > 0 && $userId > 0) {
      LocalMailService::getInstance()->sendDoubleOptInMail($contact['email'], array(
        'type' => 'crm',
        'user' => $userId,
        'field' => $optinCrmFieldId
      ));
    }

    // Make sure to flush eventually changed segmentation data
    Core::flushContactCache();

    return true;
  }
} 