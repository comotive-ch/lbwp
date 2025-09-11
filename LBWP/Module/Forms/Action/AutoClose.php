<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\Date;
use LBWP\Util\ArrayManipulation;

/**
 * Let's the user automagically close a form at e specified point in time
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class AutoClose extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'Formular zeitgesteuert schliessen';
  /**
   * @var int timestamp of loading time
   */
  protected $timestamp = 0;
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'Zeitgesteuert Schliessen',
    'group' => 'Häufig verwendet'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'message' => array(
        'name' => 'Text, sobald das Formular geschlossen wurde',
        'type' => 'textarea'
      ),
      'date' => array(
        'name' => 'Datum, an dem das Formular geschlossen wird',
        'type' => 'textfield'
      ),
      'time' => array(
        'name' => 'Zeit, zu der das Formular geschlossen wird (Optional)',
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
    $this->timestamp = current_time('timestamp');
    $this->params['key'] = $key;
    // Set the defaults (will be overriden with current data on execute)
    $this->params['date'] = Date::getTime(Date::EU_DATE, $this->timestamp);
    $this->params['time'] = Date::getTime(Date::EU_CLOCK, $this->timestamp);
    $this->params['message'] = 'Das Formular ist geschlossen.';
  }

  /**
   * There is nothing to do here, since this in only a load callback action
   * @param \WP_Post $form
   */
  public function onSave($form)
  {
    // Nothing to do
  }

  /**
   * If the maximum is reached, only displays an error
   * @param \WP_Post $form
   * @return string an error if needed
   */
  public function onDisplay($form)
  {
    if ($this->timeIsUp() && !is_admin()) {
      return $this->params['message'];
    }

    // If time is not up, we can't cache pages with such a form
    HTMLCache::avoidCache();
    return '';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool always true, as the action does nothing
   */
  public function execute($data)
  {
    // Nothing to do, assume everything is well
    return true;
  }

  /**
   * Checks, if the form needs to be closed
   * @return bool true, if the current time exeeds the configured limit
   */
  protected function timeIsUp()
  {
    $configTime = strtotime($this->params['date'] . ' ' . $this->params['time']);

    // If time is valid and already up
    if ($configTime !== false && $configTime <= $this->timestamp) {
      return true;
    }

    return false;
  }
} 