<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Util\Strings;
use wpdb;
use LBWP\Module\Forms\Core;
use LBWP\Module\Forms\Component\FormHandler;

/**
 * Base class for form items
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
{
  /**
   * @var wpdb wordpress db object
   */
  protected $wpdb = NULL;
  /**
   * @var Core forms core object
   */
  protected $core = NULL;
  /**
   * @var string the action name for displaying
   */
  protected $name = 'override $this->name';
  /**
   * @var array default params for every action, can be extendes by the item
   */
  protected $params = array(
    'key' => '',  // Internal field, not overridable
  );
  /**
   * Needs to be extended by the action
   * @var array param configurations for the form editor
   */
  protected $paramConfig = array(
    'conditions' => array(
      'name' => 'Konditionsliste',
      'type' => 'conditions',
      'value' => '',
    ),
    'condition_type' => array(
      'name' => 'Konditionsverknüpfung',
      'type' => 'conditions',
      'value' => 'AND'
    ),
  );
  /**
   * @var array needs to be overridden with for both fields
   */
  protected $actionConfig = array(
    'name' => 'Base Action',
    'group' => 'Aktionen'
  );
  /**
   * @var string the content between action tags
   */
  protected $content = '';

  /**
   * @param Core $core the forms core object
   */
  public function __construct($core)
  {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->core = $core;
  }

  /**
   * @return string an example shortcode, can be overridden if needed for complex shortcodes
   */
  public function getExampleCode()
  {
    $code = '[' . FormHandler::SHORTCODE_ACTION;
    // Parameters
    foreach ($this->params as $key => $value) {
      $code .= ' ' . $key . '="' . $value . '"';
    }

    // close and return
    return $code . ']';
  }

  /**
   * @param array $fields all form fields including content
   * @param string $value given parameter value
   * @return string the value or the value from referenced field
   */
  public function getFieldContent($fields, $value)
  {
    // Return directly, if there is no parseable value
    if (Strings::startsWith($value, 'field:')) {
      list($tag, $fieldName) = explode(':', $value);
      foreach ($fields as $field) {
        if ($field['name'] == $fieldName || $field['id'] == $fieldName) {
          return $field['value'];
        }
      }
    }

    // If not found or no reference, return value directly
    return $value;
  }

  /**
   * This is called on actual form and action execution automatically.
   * Basic function to test if an action is executeable. It only tests for
   * a possible "conditions" param to match to a certain field.
   * Can be overridden to check for executability.
   * @param array $data the form data
   * @return bool true, if the action is executeable
   */
  public function isExecuteable($data)
  {
    // Only test something more than usual, if there are conditions
    if (isset($this->params['conditions']) && strlen($this->params['conditions']) > 0) {
      $conditions = explode(';', $this->params['conditions']);
      $conditionsTotal = count($conditions);
      $conditionsMet = 0;
      foreach ($conditions as $condition) {
        list($fieldName, $checkValue) = explode('=', $condition);
        if ($this->getFieldContent($data, 'field:' . $fieldName) == $checkValue) {
          ++$conditionsMet;
        }
      }

      // Wheter we do a AND or OR conditioning
      switch (strtoupper($this->params['condition_type'])) {
        case 'OR':
          return $conditionsMet > 0;
        case 'AND':
        default:
          return $conditionsMet == $conditionsTotal;
      }
    }

    // If checks are passed, let the action execute
    return true;
  }

  /**
   * This adds params, even though they're already given
   * @param array $params the shortcode params
   */
  public function setParams($params)
  {
    $this->params = array_merge($this->params, $params);
  }

  /**
   * This overrides previous params
   * @param array $params the shortcode params
   */
  public function overrideParams($params)
  {
    $this->params = $params;
  }

  /**
   * @param string $content the content
   */
  public function setContent($content)
  {
    $this->content = $content;
  }

  /**
   * @return string the content
   */
  public function getContent()
  {
    return $this->content;
  }

  /**
   * @param string $param the param you want
   * @return mixed the param value or NULL of not set
   */
  public function get($param)
  {
    return $this->params[$param];
  }

  /**
   * @return string the action name
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * @param string $key the key of the item
   */
  public function loadAction($key)
  {
    $this->load($key);
    $this->setParamConfig();
  }

  /**
   * @return array the action config
   */
  public function getActionConfig()
  {
    return $this->actionConfig;
  }

  /**
   * @return array the param config
   */
  public function getParamConfig()
  {
    return $this->paramConfig;
  }

  /**
   * Called while saving the form. Can be overridden if needed.
   * @param \WP_Post $form the form post object
   */
  public function onSave($form) { }

  /**
   * Called before displaying the form. Can be overridden if needed.
   * @param \WP_Post $form the form post object
   * @return string empty by default
   */
  public function onDisplay($form) { }

  /**
   * Called after construction for defining files and an id
   * @param string $key the key of the item
   */
  abstract function load($key);

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  abstract function execute($data);

  /**
   * Lets the developer configure the own field parameters
   */
  abstract protected function setParamConfig();
} 