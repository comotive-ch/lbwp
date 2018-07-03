<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Module\Forms\Component\FormEditor;
use LBWP\Util\ArrayManipulation;
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
   * @var FormHandler the item handler
   */
  protected $formHandler = NULL;
  /**
   * @var string the shortcode key
   */
  protected $shortCode = FormHandler::SHORTCODE_FORM_ITEM;
  /**
   * @var array default params for every item, can be extendes by the item
   */
  protected $params = array(
    'id' => '',                   // Internal field, not overridable
    'key' => '',                  // Internal field, not overridable
    'description' => '',          // Internal field, not overridable
    'pflichtfeld' => 'nein',      // Marks the field not required by default
    'visible' => 'ja',            // Marks the field visible by default
    'vorgabewert' => '',          // Predefined value
    'class' => '',                // An additional, optional class
    'feldname' => 'Formularfeld', // Name / Title of the element
    'conditions' => ''
  );
  /**
   * Helper array to configure reversing of conditions
   * @var array the reversal configuration
   */
  protected $conditionReversal = array(
    'operator' => array(
      'is' => 'not',
      'not' => 'is',
      'contains' => 'absent',
      'absent' => 'contains',
      'morethan' => 'lessthan',
      'lessthan' => 'morethan'
    ),
    'action' => array(
      'show' => 'hide',
      'hide' => 'show'
    ),
    // Need a callback to change the value
    'value' => array(
      'morethan' => '__return_minus_one', // the lessthan equiv needs the same value minus one
      'lessthan' => '__return_plus_one'   // the morethan equiv needs the same value plus one
    )
  );
  /**
   * This is the base configuration that can be merged / overriden in setParamConfig
   * @var array param configurations for the form editor
   */
  protected $paramConfig = array(
    'feldname' => array(
      'name' => 'Feld-Beschriftung',
      'type' => 'textfield',
    ),
    'pflichtfeld' => array(
      'name' => 'Pflichtfeld?',
      'type' => 'radio',
      'values' => array(
        'ja' => 'Ja',
        'nein' => 'Nein'
      )
    ),
    'visible' => array(
      'name' => 'Sichtbar?',
      'type' => 'radio',
      'help' => 'Formular-Felder können initial als unsichtbar markiert werden. Mit Konditionen können diese je nach Nutzerinteraktionen angezeigt werden.',
      'values' => array(
        'ja' => 'Ja',
        'nein' => 'Nein'
      )
    ),
    'error_message' => array(
      'name' => 'Fehlermeldung (Optional)',
      'type' => 'textfield',
      'help' => 'Diese Meldung wird angezeigt, wenn das Formularfeld nicht ausgefüllt ist. Die Meldung wird nur bei einem Pflichtfeld angezeigt.'
    ),
    /* TODO do we need this really?
    'warning_message' => array(
      'name' => 'Warnungss',
      'type' => 'textfield'
    ),
    */
    'class' => array(
      'name' => 'Zusätzliche CSS Klasse(n)',
      'type' => 'textfield'
    ),
    'id' => array(
      'name' => 'Feld-ID',
      'type' => 'textfield',
      'availability' => 'readonly',
      'optin' => 1, // Make user able to remove readonly "on his own danger"
      'help' => 'Bitte ändern Sie die Feld-ID nur, wenn die Daten an externe Systeme gesendet werden. Ansonsten kann es passieren, dass die Referenz zu einer Aktion verloren geht und diese nicht mehr funktioniert.'
    ),
    'conditions' => array(
      'name' => 'Konditionsliste',
      'type' => 'conditions',
      'value' => '',
    ),
  );
  /**
   * @var array needs to be overridden with for both fields
   */
  protected $fieldConfig = array(
    'name' => 'Base Field',
    'group' => 'Text-Felder'
  );
  /**
   * @var array this defines the globally hidden but working params for pro users
   */
  protected $hiddenParams = array(
    'id', 'class', 'vorgabewert', 'description'
  );
  /**
   * @var string HTML template a field should use for displaying itself
   */
  public static $template = '
    <div class="forms-item {class}">
      <label for="{id}" class="default-label">{label}</label>
      <div class="default-container">
        {field}
      </div>
    </div>
  ';
  /**
   * @var string HTML template a field should use for displaying itself if it hasn't got a label
   */
  public static $noLabelTemplate = '
    <div class="forms-item {class}">
      <div class="default-container">
        {field}
      </div>
    </div>
  ';
  /**
   * @var string HTML template a field should use for displaying itself if it hasn't got a label
   */
  public static $sendButtonTemplate = '
    <div class="forms-item {class}">
      <div></div>
      <div class="default-container">
        {field}
      </div>
    </div>
  ';
  /**
   * @var string default validation warning
   */
  protected $defaultWarningMessage = '';
  /**
   * @var string default validation error
   */
  protected $defaultErrorMessage = '';
  /**
   * @var string the content between item tags
   */
  protected $content = '';
  /**
   * @var string the asterisk html
   */
  const ASTERISK_HTML = ' <span class="required">*</span>';
  /**
   * @var string the multi key value separator
   */
  const MULTI_KEY_VALUE_SEPARATOR = '==';
  /**
   * @var string the values separator in multi items
   */
  const MULTI_ITEM_VALUES_SEPARATOR = '$$';

  /**
   * @param Core $core the forms core object
   */
  public function __construct($core)
  {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->core = $core;
    $this->formHandler = $core->getFormHandler();
    // Set messages (translated)
    $this->defaultWarningMessage = __('Bitte überprüfen Sie den Feld-Inhalt.', 'lbwp');
    $this->defaultErrorMessage = __('Bitte füllen Sie das Feld "%s" aus.', 'lbwp');
  }

  /**
   * @param array $args the full array of field params
   * @return string required attributes if needed
   */
  protected function getRequiredAttributes(&$args)
  {
    // See if a param is set, afterwards check various valid user inputs
    if (isset($args['pflichtfeld'])) {
      if ($args['pflichtfeld'] == 'ja' || $args['pflichtfeld'] == 'yes') {
        // It is set, return the attributes
        $args['feldname'] .= self::ASTERISK_HTML;
        return ' required="required" aria-required="true"';
      }
    }

    // Nothing configured
    return '';
  }

  /**
   * Decode hacky json attribute value
   * @param string $string the potiential json string
   * @return array the actual object of encoded json
   */
  protected function decodeObjectString($string)
  {
    $string = str_replace(
      array('((', '))', Strings::HTML_QUOTE),
      array('[', ']', '"'),
      $string
    );

    return ArrayManipulation::forceArray(
      json_decode($string, true)
    );
  }

  /**
   * @param array $args the full array of field params
   * @param string $class a class name to set statically
   * @param string $styles eventuall css styles
   * @param bool $requiredAttributes true
   * @return string attributes, if set
   */
  protected function getDefaultAttributes(&$args, $class = '', $styles = '', $requiredAttributes = true)
  {
    $attr = '';

    $name = $this->get('id');
    if ($args['nameArray']) {
      $name .= '[]';
    }

    // First, set the id and name
    $attr .= ' name="' . $name . '" id="' . $this->get('id') . '"';
    $attr .= ' data-field="' . $this->get('id') . '"';

    // Is a class set?
    if (strlen($class) > 0) {
      $attr .= ' class="' . $class . '"';
    }

    // Set default warning and error messages
    if (isset($args['warning_message']) && strlen($args['warning_message']) > 0) {
      $attr .= ' data-warningMsg="' . esc_attr(sprintf($args['warning_message'], $this->get('feldname'))) . '"';
    } else {
      $attr .= ' data-warningMsg="' . esc_attr(sprintf($this->defaultWarningMessage, $this->get('feldname'))) . '"';
    }
    if (isset($args['error_message']) && strlen($args['error_message']) > 0) {
      $attr .= ' data-errorMsg="' . esc_attr(sprintf($args['error_message'], $this->get('feldname'))) . '"';
    } else {
      $attr .= ' data-errorMsg="' . esc_attr(sprintf($this->defaultErrorMessage, $this->get('feldname'))) . '"';
    }

    // Is a width set?
    if (isset($args['width']) && strlen($args['width']) > 0) {
      // If neither percentage or pixel is set, define the width as pixel
      $width = $args['width'];
      if (stristr($width, '%') === false && stristr($width, 'px') === false) {
        $width .= 'px';
      }
      $attr .= ' style="width:' . $width . ';' . $styles . '"';
    }

    if ($args['visible'] == 'nein') {
      $attr .= ' data-init-invisible="1"';
    }

    // Placeholder text
    if (isset($args['placeholder'])) {
      if (
        strlen($args['placeholder']) > 0 &&
        ($args['pflichtfeld'] == 'ja' || $args['pflichtfeld'] == 'yes')
      ) {
        $args['placeholder'] .= ' *';
      }
      $attr .= ' placeholder="' . esc_attr($args['placeholder']) . '"';
    }

    // Maximum length
    if (isset($args['maxlength']) && intval($args['maxlength']) > 0) {
      $attr .= ' maxlength="' . intval($args['maxlength']) . '"';
    }

    // does it have rows or cols attributes?
    if (isset($args['rows']) && intval($args['rows']) > 0) {
      $attr .= ' rows="' . $args['rows'] . '"';
    }
    if (isset($args['cols']) && intval($args['cols']) > 0) {
      $attr .= ' cols="' . $args['cols'] . '"';
    }

    // Add the possible required attributes
    if ($requiredAttributes) {
      $attr .= $this->getRequiredAttributes($args);
    }

    return $attr;
  }

  /**
   * @return string an example shortcode, can be overridden if needed for complex shortcodes
   */
  public function getExampleCode()
  {
    return $this->getSimpleExample();
  }

  /**
   * Used to generate the first part of a simple example code
   */
  protected function getSimpleExample()
  {
    $code = '  [' . $this->shortCode;
    // Parameters
    foreach ($this->params as $key => $value) {
      if (!in_array($key, $this->hiddenParams)) {
        $code .= ' ' . $key . '="' . $value . '"';
      }
    }

    // close and return
    return $code . ']';
  }

  /**
   * Can be used to override getExampleCode in multi select fields
   * @param bool $values default values (must be an array)
   * @return string an example code
   */
  protected function getMultiSelectExample($values = false)
  {
    // Set defaults if none is given
    if (!is_array($values)) {
      $values = array(
        'Auswahl 1',
        'Auswahl 2',
        'Auswahl 3'
      );
    }

    // Get the first part
    $code = $this->getSimpleExample() . PHP_EOL;

    // Add the values
    foreach ($values as $value) {
      $code .= '    ' . $value . ',' . PHP_EOL;
    }

    // Close code and return
    return $code . '  [/' . $this->shortCode . ']';
  }

  /**
   * @param string $content shortcode content with a comma separated list
   * @return array of prepared values ready for combat
   */
  public function prepareContentValues($content)
  {
    $preparedValues = array();
    $content = trim($content);

    // Convert old comma value to new separator if none given
    if (stristr($content, self::MULTI_ITEM_VALUES_SEPARATOR) === false) {
      $content = str_replace(',', self::MULTI_ITEM_VALUES_SEPARATOR, $content);
    }

    // Explode the values by the (maybe converted) separator
    $values = explode(self::MULTI_ITEM_VALUES_SEPARATOR, $content);

    foreach ($values as $value) {
      $value = trim($value);
      if (strlen($value) > 0) {
        if (stristr($value, self::MULTI_KEY_VALUE_SEPARATOR) !== false) {
          list($key, $value) = explode(self::MULTI_KEY_VALUE_SEPARATOR, $value);
          $preparedValues[$key] = $value;
        } else {
          $preparedValues[$value] = $value;
        }
      }
    }

    return $preparedValues;
  }

  /**
   * @param string $conditions maybe json or empty string
   */
  protected function addFormFieldConditions($conditions)
  {
    $conditions = $this->decodeObjectString($conditions);
    foreach ($conditions as $key => $condition) {
      $conditions[$key]['target'] = $this->params['id'];
    }

    // Add a reverse condition for every condition
    if (count($conditions) > 0) {
      foreach ($conditions as $key => $condition) {
        $reverse = $this->getReverseCondition($condition);
        if (is_array($reverse)) {
          $conditions[] = $reverse;
        }
      }
    }

    // Add this to the core conditions
    $this->formHandler->addConditions($conditions);
  }

  /**
   * Generates a reverse condition to make the framework do the exact
   * opposite of the condition, if the condition isn't met anymore
   * @param array $condition the original condition
   * @return array the new, opposite condition
   */
  protected function getReverseCondition($condition)
  {
    $reverse = false;
    // Only reverse if there is something to reverse
    if (
      isset($this->conditionReversal['operator'][$condition['operator']]) &&
      isset($this->conditionReversal['action'][$condition['action']])
    ) {
      // Create the new, reversed condition
      $reverse = array(
        'field' => $condition['field'],
        'operator' => $this->conditionReversal['operator'][$condition['operator']],
        // Value will be added a callback if needed
        'value' => isset($this->conditionReversal['value'][$condition['operator']])
          ? call_user_func($this->conditionReversal['value'][$condition['operator']], $condition['value'])
          : $condition['value'],
        'action' => $this->conditionReversal['action'][$condition['action']],
        'target' => $condition['target']
      );
    }

    return $reverse;
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
   * @param array $params the shortcode params
   */
  public function setParams($params)
  {
    $this->params = array_merge($this->params, $params);
  }

  /**
   * Destroys the value of the item, so displaying it again will be impossible
   */
  public function removeValue()
  {
    unset($_POST[$this->get('id')]);
  }

  /**
   * @param string $key the key of the item
   */
  public function loadItem($key)
  {
    $this->load($key);
    $this->setParamConfig();
  }

  /**
   * @return array the field config
   */
  public function getFieldConfig()
  {
    return $this->fieldConfig;
  }

  /**
   * @return array the param config
   */
  public function getParamConfig()
  {
    return $this->paramConfig;
  }

  /**
   * @return array the params
   */
  public function getAllParams()
  {
    return $this->params;
  }

  /**
   * Called after construction for defining files and an id
   * @param string $key the key of the item
   */
  abstract function load($key);

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to represent the element in frontend
   */
  abstract function getElement($args, $content);

  /**
   * @param array $args the shortcode params
   * @return string the user value that has been given in
   */
  abstract function getValue($args = array());

  /**
   * Lets the developer configure the own field parameters
   */
  abstract protected function setParamConfig();
} 