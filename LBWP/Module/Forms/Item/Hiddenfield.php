<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;
use LBWP\Module\Forms\Component\FormHandler;

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
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'vorgabewert' => array(
        'name' => 'Feld-Inhalt',
        'type' => 'textfield'
      ),
      'divider1' => array('type' => 'line'),
      'automatic_calculation' => array(
        'name' => 'Feld-Inhalt berechnen',
        'type' => 'radio',
        'help' => '
          Wählen Sie diese Option, wenn das Feld automatisch Werte aus anderen Feldern berechnen soll.
          Ist die Option aktiv, kann der Benutzer das Feld nicht verändern, sieht aber den berechneten Wert.
        ',
        'values' => array(
          'ja' => 'Ja',
          'nein' => 'Nein'
        )
      ),
      'calculation_syntax' => array(
        'name' => 'Berechnungssyntax',
        'type' => 'textarea',
        'has_field_selection' => 1,
        'help' => '
          Verwenden Sie das Zahnrad Icon um Felder in die Berechnung aufzunehmen. Es können Klammern und aller arithmetischen Operatoren verwendet werden.
          Beispiel: (field:zahl1 * field:zahl2) + field:zahl3
        '
      )
    ));

    // Don't let visiblity be configured
    unset($this->paramConfig['visible']);
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
    $this->params['automatic_calculation'] = 'nein';
    $this->params['calculation_syntax'] = '';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    // Always set invisible
    $args['visible'] = 'nein';
    $attr = $this->getDefaultAttributes($args);
    // Make the field
    $html = '<input type="hidden" value="' . $this->getValue($args) . '"' . $attr . '/>';

    // Override in backend editor mode
    if (FormHandler::$isBackendForm) {
      // Make the field a text field and read only
      $field = '
        <input type="text" class="disabled" readonly="readonly" value="' . $this->getValue($args) . '"' . $attr . '/>
        <span class="description">Unsichbares Feld, wird dem Besucher nicht angezeigt.</span>
      ';

      // Create the full html block
      $html = Base::$template;
      $html = str_replace('{id}', $this->get('id'), $html);
      $html = str_replace('{label}', $args['feldname'], $html);
      $html = str_replace('{class}', trim('text-field ' . $this->params['class']), $html);
      $html = str_replace('{field}', $field, $html);
    }

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