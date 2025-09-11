<?php

namespace LBWP\Module\Forms\Action\Crm;

use LBWP\Module\Forms\Action\Base;
use LBWP\Theme\Component\Crm\Core;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This will put data from the form to crm fields
 * @package LBWP\Module\Forms\Action\Newsletter
 * @author Michael Sebel <michael@comotive.ch>
 */
class WriteData extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Daten in CRM Nutzer';
  /**
   * @var \LBWP\Theme\Component\Crm\Core the crm core component
   */
  protected static $crm = NULL;
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Daten ins CRM schreiben',
    'help' => 'Daten von Formularfeldern in einen bestimmten CRM Nutzer schreiben',
    'group' => 'CRM'
  );
  /**
   * @var array allowed types to be used for input
   */
  protected $allowedTypes = array(
    'textfield',
    'textarea',
    'file'
  );
  /**
   * @var int
   */
  const MAX_FIELDS = 10;
  /**
   * @var string
   */
  const UPLOAD_SEARCH = '/wp-file-proxy.php?key=';

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
        'help' => 'Es können ' . self::MAX_FIELDS . ' Zuweisungen pro Aktion gemacht werden. Sollten sie mehrere Aktionen benötigen um mehr Zuweisungen zu machen, kann es hilfreich sein, einen internen Titel pro Aktion zu definieren.'
      ),
      'id_field' => array(
        'name' => 'Feld zur Verknüpfung der Datensatz-ID',
        'type' => 'textfield',
        'help' => 'Dieses Feld muss die eindeutige Datensatz / CRM-Benutzer-ID enthalten.'
      ),
      'line_0' => array('type' => 'line')
    ));



    // Create the fields, sad but true
    for ($i = 1; $i <= self::MAX_FIELDS; ++$i) {
      $this->paramConfig['source_' . $i] = array(
        'name' => $i . '. Wenn Daten in folgendem Formularfeld sind:',
        'type' => 'textfield'
      );
      $this->paramConfig['destination_' . $i] = array(
        'name' => $i . '. Dann schreibe die Daten in dieses CRM-Feld:',
        'type' => 'dropdown',
        'values' => $dropdown
      );
      $this->paramConfig['line_' . $i] = array('type' => 'line');
    }
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
    $userId = intval($this->getFieldContent($data, $this->params['id_field']));
    // Get field IDs that have change tracking active
    $db = WordPress::getDb();
    $trackedFieldIds = $db->get_col('SELECT post_id FROM ' . $db->prefix . 'postmeta WHERE meta_key = "track-changes" AND meta_value = "on"');

    // Loop trough all the fields and update them, if needed
    for ($i = 1; $i <= self::MAX_FIELDS; ++$i) {
      if ($userId > 0 && strlen($this->params['source_' . $i]) > 0 && intval($this->params['destination_' . $i]) > 0) {
        $fieldId = intval($this->params['destination_' . $i]);
        $metaKey = 'crmcf-' . $fieldId;
        $newMetaValue = $this->getFieldContent($data, $this->params['source_' . $i]);

        // To support uploads, shorten proxy urls to key/file pair
        if (Strings::contains($newMetaValue, self::UPLOAD_SEARCH)) {
          $newMetaValue = urldecode(substr($newMetaValue, stripos($newMetaValue, self::UPLOAD_SEARCH) + strlen(self::UPLOAD_SEARCH)));
        }

        // Track the change if allowed and the field has changed
        if (in_array($fieldId, $trackedFieldIds)) {
          // Get the current value to compare
          $current = get_user_meta($userId, $metaKey, true);
          // Track the change, if the field value will change
          if ($current != $newMetaValue) {
            update_user_meta($userId, $metaKey . '-changed', current_time('timestamp'));
          }
        }

        // Update the corresponding field
        update_user_meta($userId, $metaKey, $newMetaValue);
      }
    }

    // Make sure to flush eventually changed segmentation data
    Core::flushContactCache();

    return true;
  }
} 