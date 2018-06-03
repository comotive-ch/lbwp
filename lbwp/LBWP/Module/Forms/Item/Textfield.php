<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;

/**
 * This will display a normal input text field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Textfield extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Einzeiliges Textfeld',
    'group' => 'Text-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'placeholder' => array(
        'name' => 'Platzhalter Text (optional)',
        'type' => 'textfield'
      ),
      'maxlength' => array(
        'name' => 'Maximale Anzahl Zeichen (optional)',
        'type' => 'textfield'
      ),
      'type' => array(
        'name' => 'Feld-Typ',
        'type' => 'dropdown',
        'values' => array(
          'text' => 'Normales Text-Feld',
          'url' => 'Link-Feld',
          'email' => 'E-Mail-Feld',
          'phone' => 'Telefon-Feld',
          'number' => 'Zahlen-Feld',
          'date' => 'Datums-Feld',
          'password' => 'Passwort-Feld'
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
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['description'] = 'Textfeld - Einzeiliges Feld mit Text';
    // Set type param, that can be overriden with email, number, phone etc.
    $this->params['type'] = 'text';
  }

  /**
   * @param string $type input param
   * @return string validated ouput
   */
  protected function validateType($type)
  {
    switch ($type) {
      case 'email':
      case 'url':
      case 'phone':
      case 'number':
      case 'date':
      case 'password':
        return $type;
      default:
        return 'text';
    }
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $this->addFormFieldConditions($args['conditions']);
    $type = $this->validateType($args['type']);
    $attr = $this->getDefaultAttributes($args);

    // Make the field
    $field = '<input type="' . $type . '" value="' . $this->getValue($args) . '"' . $attr . '/>';

    // Create the full html block
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('text-field ' . $this->params['class']), $html);
    $html = str_replace('{field}', $field, $html);

    return $html;
  }

  /**
   * @param array $args the shortcode params
   * @return string the user value, if the form has been sent
   */
  public function getValue($args = array())
  {
    // Get the value from post, if set
    if (isset($_POST[$this->get('id')])) {
      return $_POST[$this->get('id')];
    }

    // Or return a predefined value
    if (isset($args['vorgabewert'])) {
      return $args['vorgabewert'];
    }

    return '';
  }
} 