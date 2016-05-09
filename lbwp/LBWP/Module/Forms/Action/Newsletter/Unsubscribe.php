<?php

namespace LBWP\Module\Forms\Action\Newsletter;

use LBWP\Core;
use LBWP\Util\String;
use LBWP\Module\Forms\Action\Base;
use LBWP\Newsletter\Core as NewsletterCore;
use LBWP\Util\ArrayManipulation;

/**
 * This will unsubscribe an email adress from the current newsletter list
 * @package LBWP\Module\Forms\Action\Newsletter
 * @author Michael Sebel <michael@comotive.ch>
 */
class Unsubscribe extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Von Newsletter abmelden';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Newsletter abmelden',
    'help' => 'Abmeldung von einer Newsletter-Liste',
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
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    // See if we can find the email field
    foreach ($data as $field) {
      // Check if the field names match
      if ($field['name'] != $this->params['email_feld']) {
        continue;
      }

      // Provide empty or string or a given list id as param
      $listId = '';
      if (isset($this->params['list_id']) && strlen($this->params['list_id']) > 0) {
        $listId = $this->params['list_id'];
      }

      /** @var NewsletterCore $newsletter Call the definition method on the current NL sevice */
      $newsletter = Core::getModule('NewsletterBase');
      $service = $newsletter->getService();

      // Unsubscribe if valid email and return
      if ($service->isWorking() && String::checkEmail($field['value'])) {
        return $service->unsubscribe($field['value'], $listId);
      }
    }

    // If we reach this, unsub didn't happen
    return false;
  }
} 