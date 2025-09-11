<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Util\ArrayManipulation;

/**
 * This as a filterable action to do custom stuff
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class SaveDynamic extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Dynamische Verarbeitung';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Dynamische Verarbeitung',
    'help' => 'Daten werden gemäss Filter verarbeitet.',
    'group' => 'Entwickler Aktionen'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'filter' => array(
        'name' => 'Filter-ID',
        'type' => 'textfield',
        'help' => 'Diese wird vom Entwickler eingetragen um die Daten spezifisch zu verarbeiten.'
      ),
      'params' => array(
        'name' => 'Parameter',
        'type' => 'textfield',
        'help' => 'Dieser Wert wird ggf. vom Entwickler eingetragen um die Daten spezifisch zu verarbeiten.'
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
    $this->params['filter'] = '';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    return apply_filters('lbwp_form_save_dynamic_' . $this->params['filter'], true, $data, $this->params['params']);
  }
} 