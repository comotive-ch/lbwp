<?php

namespace LBWP\Module\Forms\Item;

/**
 * This will display a normal input text field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Hiddenfield extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Unsichtbares Feld',
    'group' => 'Spezial-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {

  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['description'] = 'Verstecktes / unsichtbares Feld';
    // Set type param, that can be overriden with email, number, phone etc.
    $this->params['type'] = 'hidden';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $attr = $this->getDefaultAttributes($args);
    // Make the field
    $field = '<input type="hidden" value="' . $this->getValue($args) . '"' . $attr . '/>';
    return $field;
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