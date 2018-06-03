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
      'compact' => array(
        'name' => 'Kompakte Anordnung der Auswahlfelder',
        'type' => 'radio',
        'values' => array(
          'ja' => 'Ja',
          'nein' => 'Nein'
        )
      ),
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
    $this->addFormFieldConditions($args['conditions']);
    $attr = $this->getDefaultAttributes($args);
    $classes = $this->params['class'];
    $classes .= ($this->get('compact') == 'ja') ? ' field-compact' : '';

    // Make the field
    $field = '';
    $numberOfRadios = 0;
    $values = $this->prepareContentValues($content);

    foreach ($values as $key => $value) {
      $checked = '';
      if ($this->getValue($args) == strip_tags($key)) {
        $checked = ' checked="checked"';
      }
      $radioAttr = str_replace(
        'id="' . $this->get('id') . '"',
        'id="' . $this->get('id') . '_' . (++$numberOfRadios) . '"',
        $attr
      );
      $field .= '
        <label class="label-checkbox">
          <input type="radio" value="' . strip_tags($key) . '"' . $radioAttr . $checked . ' />
          <div class="beside-checkbox">' . $value . '</div>
        </label>
      ';
    }

    // Wrapper element for the field list
    $field = '<div class="field-list">' . $field . '</div>';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('radio-field ' . trim($classes)), $html);
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
    $content = parent::getContent();
    // Convert old comma value to new separator if none given
    if (stristr($content, self::MULTI_ITEM_VALUES_SEPARATOR) === false) {
      $content = str_replace(',', self::MULTI_ITEM_VALUES_SEPARATOR, $content);
    }

    return esc_attr($content);
  }
}