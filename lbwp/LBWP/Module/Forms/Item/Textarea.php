<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;

/**
 * This will display a textarea field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Textarea extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Grosses Textfeld',
    'help' => 'Textfeld mit mehreren Zeilen',
    'group' => 'Text-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'placeholder' => array(
        'name' => 'Platzhalter Text',
        'type' => 'textfield'
      ),
      'rows' => array(
        'name' => 'Anzahl Zeilen',
        'type' => 'textfield'
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
    $this->params['description'] = 'Textfeld - Mehrzeiliges Feld mit Text';
    // Number of rows for the textfield
    $this->params['rows'] = 6;
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    // get the attributes
    $attr = $this->getDefaultAttributes($args);

    // Make the field
    $field = '<textarea' . $attr . '>' . $this->getValue($args) . '</textarea>';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('text-field textarea-field ' . $this->params['class']), $html);
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