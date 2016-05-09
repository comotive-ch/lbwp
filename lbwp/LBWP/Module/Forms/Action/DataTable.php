<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\Date;
use LBWP\Util\ArrayManipulation;


/**
 * This will gather form info in a table option
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class DataTable extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'In Tabelle speichern';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Formular-Daten speichern',
    'help' => 'Speichert Daten in einer Tabelle ab',
    'group' => 'Häufig verwendet'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'name' => array(
        'name' => 'Name der Tabelle',
        'type' => 'textfield'
      ),
      'max' => array(
        'name' => 'Maximale Anzahl Datensätze (0 = unendlich)',
        'type' => 'textfield',
        'help' => 'Nützlich für Event-Anmeldungen wo z.B. nur für 80 Personen Platz ist.'
      ),
      'max_error' => array(
        'name' => 'Text, wenn die maximale Anzahl erreicht ist',
        'type' => 'textarea',
        'help' => 'Anstelle des Formulars, wird dieser Text angezeigt, wenn die maximale Anzahl Datensätze erreicht ist.'
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
    // Set the defaults (will be overriden with current data on execute)
    $this->params['name'] = 'Name der Tabelle';
    $this->params['max'] = 0;
    $this->params['max_error'] = '';
  }

  /**
   * Create a list entry for the form and an empty data set if not yet available
   * @param \WP_Post $form
   */
  public function onSave($form)
  {
    if (intval($this->params['form_id']) == 0) {
      // Create the table in the list if not a referenced table (which also creates an empty data table)
      $backend = $this->core->getDataTableBackend();
      $backend->updateTableList($form->ID, $this->params['name']);
    }
  }

  /**
   * If the maximum is reached, only displays an error
   * @param \WP_Post $form
   * @return string an error if needed
   */
  public function onDisplay($form)
  {
    $backend = $this->core->getDataTableBackend();
    if ($backend->maximumReached($form->ID, $this->params['max'])) {
      return $this->params['max_error'];
    }

    return '';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    $formHandler = $this->core->getFormHandler();
    $formId = $formHandler->getCurrentForm()->ID;
    $backend = $this->core->getDataTableBackend();

    // Override form id with another, if given
    if (isset($this->params['form_id']) && intval($this->params['form_id']) > 0) {
      $formId = intval($this->params['form_id']);
    }

    // Add source information
    $data = $this->addRowMetaData($data);

    // Add a table entry, if not working, send message
    if (!$backend->addTableEntry($formId, $data, intval($this->params['max']))) {
      $formHandler->setCustomError($this->params['max_error']);
      // Flush HTML cache to make sure the error is displayed next time
      HTMLCache::invalidateCurrentPage();
      return false;
    }

    // All went well if the pointer comes here
    return true;
  }

  /**
   * Add various additonal generic data to the row
   * @param array $data previous data array
   * @return array new data array with additional information
   */
  protected function addRowMetaData($data)
  {
    // Add a data item, that contains the form source
    $source = array(
      'name' => 'Ursprungsformular',
      'value' => $this->params['name']
    );

    // Override with specific source, if set
    if (isset($this->params['ursprung']) && strlen($this->params['ursprung']) > 0) {
      $source['value'] = $this->params['ursprung'];
    }

    $data[] = $source;

    // Add user IP
    $data[] = array(
      'name' => 'user-ip-adresse',
      'value' => $_SERVER['REMOTE_ADDR']
    );

    // Add time the form has been sent
    $data[] = array(
      'name' => 'zeitstempel',
      'value' => Date::getTime(Date::EU_DATETIME, current_time('timestamp'))
    );

    return $data;
  }
} 