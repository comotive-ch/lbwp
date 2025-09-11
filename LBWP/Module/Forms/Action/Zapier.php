<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Util\ArrayManipulation;

/**
 * This will send lbwp form data to zapier hook
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class Zapier extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Zapier Trigger';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Zapier Trigger',
    'help' => 'Startet einen Zap mit Formular-Daten',
    'group' => 'Entwickler Aktionen'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'hook_url' => array(
        'name' => 'Hook-URL',
        'help' => 'Die URL zum Triggern des Zapier Webhook.',
        'type' => 'textfield'
      ),
      'data_id' => array(
        'name' => 'Name / ID',
        'help' => 'Optionaler fixer Wert, der an den Hook als "data_id" übertragen wird.',
        'type' => 'textfield'
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
    $postData = array();
    foreach ($data as $field) {
      // Check the value, only if given, it is saved to the session
      if (isset($field['value']) || strlen($field['value']) == 0) {
        $postData[$field['id']] = $field['value'];
      }
    }
    if (strlen($this->params['data_id']) > 0) {
      $postData['data_id'] = $this->params['data_id'];
    }

    // Post this to the Zap
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->params['hook_url'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => $postData,
    ));

    $response = json_decode(curl_exec($curl), true);
    curl_close($curl);
    if (is_array($response) && isset($response['status'])) {
      return $response['status'] === 'success';
    }
    // Assume success on empty or string answer (this is a zapier specialty)
    return true;
  }
} 