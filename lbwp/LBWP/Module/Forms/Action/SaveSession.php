<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Util\ArrayManipulation;

/**
 * This will save all form data into the session primitively
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class SaveSession extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'In Sitzung speichern';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'In Sitzung speichern',
    'help' => 'Speichert Daten zur späteren Verarbeitung.',
    'group' => 'Entwickler Aktionen'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'prefix' => array(
        'name' => 'Prefix (Optional)',
        'type' => 'textfield',
        'help' => 'Wird den Formular-Keys vorangestellt, wenn angegeben.'
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
    // Set the defaults (will be overridden with current data on execute)
    $this->params['prefix'] = '';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {

    // Loop trought the fields data
    foreach ($data as $field) {
      // Check the value, only if given, it is saved to the session
      if (isset($field['value']) || strlen($field['value']) == 0) {
        $_SESSION[$this->params['prefix'] . $field['id']] = $field['value'];
      }
    }

    return true;
  }
} 