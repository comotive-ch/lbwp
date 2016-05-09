<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\ArrayManipulation;

/**
 * This will display a radio button list
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Radio extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Auswahl-Knopf',
    'help' => 'Eine Option per Knopfdruck wählen',
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
        'help' => 'Bitte geben Sie eine oder mehrere Möglichkeiten an.'
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
    $this->params['description'] = 'Auswahlfelder mit einer Wahlmöglickeit';
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
    $field = '';
    $numberOfRadios = 0;
    $values = $this->prepareContentValues($content);

    foreach ($values as $value) {
      $checked = '';
      if ($this->getValue($args) == strip_tags($value)) {
        $checked = ' checked="checked"';
      }
      $radioAttr = str_replace(
        'id="' . $this->get('id') . '"',
        'id="' . $this->get('id') . '_' . (++$numberOfRadios) . '"',
        $attr
      );
      $field .= '
        <label class="label-checkbox">
          <input type="radio" value="' . strip_tags($value) . '"' . $radioAttr . $checked . ' />
          <div class="beside-checkbox">' . $value . '</div>
        </label>
      ';
    }

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('radio-field ' . $this->params['class']), $html);
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