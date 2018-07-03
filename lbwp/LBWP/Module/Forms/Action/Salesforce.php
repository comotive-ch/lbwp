<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Module\Forms\Item\Base as BaseItem;
use LBWP\Module\Forms\Item\Hiddenfield;
use LBWP\Module\Forms\Item\Textfield;
use LBWP\Util\External;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Util\ArrayManipulation;

/**
 * This will send a lead to salesforce
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class Salesforce extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Salesforce Web2Lead';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Salesforce Web2Lead',
    'help' => 'Erstellt einen Lead in Salesforce',
    'group' => 'Häufig verwendet'
  );
  /**
   * @var array configuration modes
   */
  protected $modes = array(
    'prod' => 'https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8',
    'dev' => 'https://test.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8'
  );

  protected $fields = array(
    'oid',
    'lead_source',
    'first_name',
    'last_name',
    'email',
    'company',
    'salutation'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'oid' => array(
        'name' => 'OID',
        'help' => 'Die Organisations-ID in welche die Daten geschrieben werden sollen.',
        'type' => 'textfield'
      ),
      'lead_source' => array(
        'name' => 'Lead-Quelle',
        'help' => 'Dieses Feld darf nur von Salesforce vorgebene Werte beinhalten.',
        'type' => 'textfield'
      ),
      'first_name' => array(
        'name' => 'Vorname',
        'type' => 'textfield'
      ),
      'last_name' => array(
        'name' => 'Nachname',
        'type' => 'textfield'
      ),
      'email' => array(
        'name' => 'E-Mail-Adresse',
        'type' => 'textfield'
      ),
      'company' => array(
        'name' => 'Firma',
        'type' => 'textfield'
      ),
      'salutation' => array(
        'name' => 'Anrede',
        'help' => 'Dieses Feld darf nur von Salesforce vorgebene Werte beinhalten.',
        'type' => 'textfield'
      ),
      'mode' => array(
        'name' => 'Speichern in',
        'type' => 'radio',
        'help' => '
          Sofern Sie eine Salesforce Test-Instanz besitzen können Sie die Aktion testen, indem Sie hier "Testumgebung" wählen
        ',
        'values' => array(
          'prod' => 'Produktionsumgebung',
          'dev' => 'Testumgebung'
        )
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
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    // Prepare the post data to be send to salesforce
    $postData = array(
      'retURL' => get_bloginfo('url'),
      'submit' => 'send'
    );

    // Add the custom fields
    foreach ($this->fields as $fieldId) {
      $postData[$fieldId] = $this->getFieldContent($data, $this->params[$fieldId]);
    }

    // Send data to salesforce
    $url = $this->modes[$this->params['mode']];
    $options = array(
      CURLOPT_HEADER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_POST => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_POSTFIELDS => $postData
    );

    $res = curl_init($url);
    curl_setopt_array($res, $options);
    $headers = curl_exec($res);
    return Strings::contains($headers, 'HTTP/1.1 200 OK');
  }
} 