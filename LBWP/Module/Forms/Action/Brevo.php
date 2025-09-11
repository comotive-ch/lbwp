<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Util\ArrayManipulation;
use LBWP\Module\General\Cms\SystemLog;

/**
 * This will send lbwp form data to zapier hook
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class Brevo extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Brevo E-Mail';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Brevo E-Mail',
    'help' => 'Synchronisiert Kontakt und sendet E-Mail',
    'group' => 'Entwickler Aktionen'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'template_id' => array(
        'name' => 'Template ID',
        'help' => 'Die Template ID aus Brevo, steht beim Template oben rechts z.B. "257"',
        'type' => 'textfield'
      ),
      'email' => array(
        'name' => 'E-Mail-Feld',
        'help' => 'Wert aus dem Formular welcher die Empfänger E-Mail-Adresse enthält',
        'type' => 'textfield'
      ),
      'firstname' => array(
        'name' => 'Vorname-Feld',
        'help' => 'Optionaler Wert aus dem Formular um den VORNAME in Brevo zu synchronisieren',
        'type' => 'textfield'
      ),
      'lastname' => array(
        'name' => 'Nachname-Feld',
        'help' => 'Optionaler Wert aus dem Formular um den NACHNAME in Brevo zu synchronisieren',
        'type' => 'textfield'
      ),
      'salutation' => array(
        'name' => 'Anrede-Feld',
        'help' => 'Optionaler Wert aus dem Formular um den SALUTATION in Brevo zu synchronisieren',
        'type' => 'textfield'
      ),
      'salutation_handling' => array(
        'name' => 'Verhalten Anrede',
        'type' => 'dropdown',
        'values' => array(
          'passtrough' => 'Feld-Wert 1:1 an Brevo übermitteln',
          'normalize' => 'Feld-Wert normalisieren'
        )
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
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    // Skip if no api key is available
    if (!defined('LBWP_FORMS_BREVO_API_KEY')) {
      return false;
    }

    $status = true;
    $postData = array();
    foreach ($data as $field) {
      // Check the value, only if given, it is saved to the session
      if (isset($field['value']) || strlen($field['value']) == 0) {
        $postData[$field['id']] = $field['value'];
      }
    }

    // Get basic data
    $email = $this->getFieldContent($data, $this->params['email'], true);
    $firstname = $this->getFieldContent($data, $this->params['firstname'], true);
    $lastname = $this->getFieldContent($data, $this->params['lastname'], true);
    $salutation = $this->getFieldContent($data, $this->params['salutation'], true);

    // Translate all possibilities of salutation text into normalized form
    if ($this->params['salutation_handling'] == 'normalize') {
      switch (strtolower($salutation)) {
        case 'frau':
        case 'sig.ra':
        case 'signora':
        case 'mrs.':
        case 'mme.':
          $salutation = 'female';
          break;
        case 'herr':
        case 'sig.':
        case 'signore':
        case 'mr.':
        case 'm.':
          $salutation = 'male';
          break;
        default:
          $salutation = 'unknown';
          break;
      }
    }

    // Create or sync contact in brevo
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/brevo/vendor/autoload.php';
    $config = \BrevoScoped\Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', LBWP_FORMS_BREVO_API_KEY);
    $apiInstance = new \BrevoScoped\Brevo\Client\Api\ContactsApi(new \BrevoScoped\GuzzleHttp\Client(), $config);
    $attributes = apply_filters('lbwp_brevo_action_sent_attributes', array(
      'SALUTATION' => $salutation,
      'VORNAME' => $firstname,
      'NACHNAME' => $lastname
    ));

    $createContact = new \BrevoScoped\Brevo\Client\Model\CreateContact([
      'email' => $email,
      'updateEnabled' => true,
      'attributes' => $attributes
    ]);

    try {
      SystemLog::mDebug('brevo lbwp action: creating contact ' . $email . ', named ' . $firstname . ' ' . $lastname);
      $apiInstance->createContact($createContact);
    } catch (\Exception $e) {
      $status = false;
      SystemLog::mDebug('brevo lbwp action: exception when creating contact: ', $e->getMessage());
    }

    // Create verification link (just url to be insertet in the template)
    $apiInstance = new \BrevoScoped\Brevo\Client\Api\TransactionalEmailsApi(new \BrevoScoped\GuzzleHttp\Client(), $config);

    // Send the activation email
    $sendSmtpEmail = new \BrevoScoped\Brevo\Client\Model\SendSmtpEmail([
      'to' => [['email' => $email]],
      'templateId' => intval($this->params['template_id']),
      'params' => [
        'form' => $postData,
      ]
    ]);

    try {
      $apiInstance->sendTransacEmail($sendSmtpEmail);
    } catch (\Exception $e) {
      $status = false;
      SystemLog::mDebug('brevo lbwp action: error sending email: ', $e->getMessage());
    }

    // Assume success on empty or string answer (this is a zapier specialty)
    return $status;
  }
} 