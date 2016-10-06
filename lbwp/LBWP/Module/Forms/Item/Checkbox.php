<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\ArrayManipulation;

/**
 * This will display a checkbox field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Checkbox extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Mehrfach Auswahl',
    'help' => 'Eine oder mehrere Optionen auswählen',
    'group' => 'Auswahl-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'content' => array(
        'name' => 'Auswahlmöglichkeiten',
        'type' => 'textfieldArray',
        'help' => 'Bitte geben Sie eine oder mehrere Möglichkeiten an.',
        'separator' => Base::MULTI_ITEM_VALUES_SEPARATOR
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
    $this->params['description'] = 'Auswahlfelder mit mehreren Wahlmöglickeiten';
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
    // This makes the name attribute an array for multiple values
    $args['nameArray'] = true;
    $attr = $this->getDefaultAttributes($args);

    // Make the field
    $field = '';
    $values = $this->prepareContentValues($content);

    foreach ($values as $key => $value) {
      $checked = '';
      if (stristr($this->getValue($args), strip_tags($key))) {
        $checked = ' checked="checked"';
      }
      $field .= '
        <label class="label-checkbox">
          <input type="checkbox" value="' . strip_tags($key) . '"' . $attr . $checked . ' />
          <div class="beside-checkbox">' . $value . '</div>
        </label>
      ';
    }

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('checkbox-field ' . $this->params['class']), $html);
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
      $value = $_POST[$this->get('id')];
      if (is_array($value)) {
        return implode(', ', $value);
      } else {
        return $value;
      }
    }

    return '';
  }

  /**
   * Escape attributes to allow HTML in options
   * @return string the content
   */
  public function getContent()
  {
    $content = parent::getContent();
    // Convert old comma value to new separator if none given
    if (stristr($content, self::MULTI_ITEM_VALUES_SEPARATOR) === false) {
      $content = str_replace(',', self::MULTI_ITEM_VALUES_SEPARATOR, $content);
    }

    return esc_attr($content);
  }
} 