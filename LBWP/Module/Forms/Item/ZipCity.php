<?php

namespace LBWP\Module\Forms\Item;

/**
 * This will display a zip/city field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class ZipCity extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'PLZ / Ort Feld',
    'group' => 'Text-Felder'
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
    $this->params['description'] = 'Feld zur Eingabe von Postleitzahl und Ort';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $this->addFormFieldConditions($args['conditions']);
    // get the attributes
    $attr = $this->getRequiredAttributes($args);
    $value = $this->getArrayValue($args);
    $hide = '';

    if ($args['visible'] == 'nein') {
      $hide = ' data-init-invisible="1"';
    }

    // Add error message or default if given
    if (isset($args['error_message']) && strlen($args['error_message']) > 0) {
      $attr .= ' data-errormsg="' . esc_attr(sprintf($args['error_message'], $this->get('feldname'))) . '"';
    } else {
      $attr .= ' data-errormsg="' . esc_attr(sprintf($this->defaultErrorMessage, $this->get('feldname'))) . '"';
    }

    // Make the field
    $field = '
      <input type="text"
        class="zip-field-part"' . $attr . $hide . '
        name="' . $this->get('id') . '[zip]"
        id="' . $this->get('id') . '-zip"
        value="' . $value['zip'] . '"
        autocomplete="postal-code"
        data-field="' . $this->get('id') . '"
      />
      <input type="text"
        class="city-field-part"' . $attr . '
        name="' . $this->get('id') . '[city]"
        id="' . $this->get('id') . '-city"
        value="' . $value['city'] . '"
        autocomplete="address-level2"
        data-field="' . $this->get('id') . '"
      />
    ';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id') . '-zip', $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('zipcity-field ' . $this->params['class']), $html);
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
      return $_POST[$this->get('id')]['zip'] . ' ' . $_POST[$this->get('id')]['city'];
    }

    return false;
  }

  /**
   * @param array $args the shortcode params
   * @return array the separated part values
   */
  public function getArrayValue($args = array())
  {
    // Get the value from post, if set
    if (isset($_POST[$this->get('id')])) {
      return $_POST[$this->get('id')];
    }

    return array();
  }
} 