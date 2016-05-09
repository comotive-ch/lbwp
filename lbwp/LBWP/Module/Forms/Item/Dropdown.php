<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\ArrayManipulation;

/**
 * This will display a radio button list
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Dropdown extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Auswahl-Liste',
    'help' => 'Eine Option aus einer Liste auswählen',
    'group' => 'Auswahl-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'first' => array(
        'name' => 'Erste Auswahlmöglichkeit (Optional)',
        'type' => 'textfield',
      ),
      'content' => array(
        'name' => 'Auswahlmöglichkeiten',
        'type' => 'textfieldArray'
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
    $this->params['description'] = 'Auswahlliste mit einer Wahlmöglickeit';
    // Set this as a content item
    $this->shortCode = FormHandler::SHORTCODE_FORM_CONTENT_ITEM;
  }

  /**
   * @return string html code for multi select example
   */
  public function getExampleCode()
  {
    return $this->getMultiSelectExample();
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
    $field = '<select ' . $attr . '>';
    $values = $this->prepareContentValues($content);

    // Add first empty item to make a dropdown required
    if (isset($this->params['first']) && strlen($this->params['first']) > 0) {
      $field .= '<option value="">' . $this->params['first'] . '</option>';
    }

    foreach ($values as $value) {
      $selected = '';
      if ($this->getValue($args) == strip_tags($value)) {
        $selected = ' selected="selected"';
      }
      $field .= '
        <option value="' . strip_tags($value) . '"' . $selected . '>' . $value . '</option>
      ';
    }

    $field .= '</select>';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('dropdown-field ' . $this->params['class']), $html);
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

    return '';
  }

  /**
   * Escape attributes to allow HTML in options
   * @return string the content
   */
  public function getContent()
  {
    return esc_attr(parent::getContent());
  }
} 