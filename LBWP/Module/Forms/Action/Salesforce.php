<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Module\Forms\Item\Base as BaseItem;
use LBWP\Module\Forms\Item\Hiddenfield;
use LBWP\Module\Forms\Item\Textfield;
use LBWP\Module\General\Cms\SystemLog;
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
    'group' => 'Entwickler Aktionen'
  );
  /**
   * @var array configuration modes
   */
  protected $modes = array(
    'prod' => 'https://{instance}.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8',
    'dev' => 'https://{instance}.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8'
  );
  /**
   * @var array configuration mode defaults
   */
  protected $defaults = array(
    'prod' => 'webto',
    'dev' => 'test'
  );
  /**
   * @var array the fields that are sent to salesforce
   */
  protected $fields = array(
    'oid' => 'oid',
    'lead_source' => 'lead_source',
    'first_name' => 'first_name',
    'last_name' => 'last_name',
    'email' => 'email',
    'company' => 'company',
    'salutation' => 'salutation',
    'campaign_id' => 'Campaign_ID'
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
        'name' => 'Firma (optional)',
        'type' => 'textfield'
      ),
      'salutation' => array(
        'name' => 'Anrede',
        'help' => 'Dieses Feld darf nur von Salesforce vorgebene Werte beinhalten.',
        'type' => 'textfield'
      ),
      'campaign_id' => array(
        'name' => 'Kampagnen-ID (optional)',
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
      ),
      'instance_dev' => array(
        'name' => 'Test-Instanz ID',
        'type' => 'textfield',
        'help' => '
          Wenn Sie in der Test-Instanz eingeloggt sind, ist dies die Zeichenkette vor .salesforce.com im Browserfenster z.b. "cs71"
        '
      ),
      'instance_prod' => array(
        'name' => 'Produktiv-Instanz ID',
        'type' => 'textfield',
        'help' => '
          Sofern die Daten nicht in der Produktionsumgebung ankommen, muss wahrscheinlich eine Instanz-ID angegeben werden.
          Üblicherweise lautet diese in der Regel "firmenname.my" oder nur "firmenname" in Kleinbuchstaben.
        '
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
    $returnUrl = get_bloginfo('url');
    $postData = array(
      'retURL' => $returnUrl,
      'submit' => 'send'
    );

    // Add the custom fields
    foreach ($this->fields as $formField => $sfField) {
      $content = $this->getFieldContent($data, $this->params[$formField]);
      if (strlen($content) > 0) {
        $postData[$sfField] = $content;
      }
    }

    // Set instance name or the default for each type of stage
    $mode = $this->params['mode'];
    $instance = (strlen($this->params['instance_' . $mode]) > 0) ? $this->params['instance_' . $mode] : $this->defaults[$mode];
    $url = str_replace('{instance}', $instance, $this->modes[$mode]);

    // Send data to salesforce
    $options = array(
      CURLOPT_HEADER => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSLVERSION => 6,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36',
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_POSTFIELDS => $postData
    );

    $res = curl_init($url);
    curl_setopt_array($res, $options);
    $content = curl_exec($res);
    return Strings::contains($content, $returnUrl);
  }
} 