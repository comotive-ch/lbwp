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
          'tel' => 'Telefon-Feld',
          'phone' => 'Telefon-Feld (veraltet)',
          'number' => 'Zahlen-Feld',
          'date' => 'Datums-Feld',
          'password' => 'Passwort-Feld'
        )
      ),
      'dns_validation' => array(
        'name' => 'E-Mail-Adresse per DNS validieren (experimentell)',
        'type' => 'radio',
        'help' => 'Wir prüfen per DNS Abfrage, ob die E-Mail-Adresse gültig sein kann. Dies ist experimentell und kann dazu führen, dass die E-Mail-Adresse nicht korrekt validiert wird.',
        'values' => array(
          '1' => 'Aktivieren',
          '0' => 'Inaktiv'
        )
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
    $this->params['automatic_calculation'] = 'nein';
    $this->params['dns_validation'] = '0';
    $this->params['calculation_syntax'] = '';
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
      case 'tel':
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

    // Add data attribut for DNS validation (always add, it's 0 if not set or disabled)
    $attr .= ' data-dns-validation="' . intval($args['dns_validation']) . '"';

    // Make the field
    $field = '<input type="' . $type . '" value="' . $this->getValue($args) . '"' . $attr . '/>';
    $class = 'text-field ';
    if ($type != 'text') {
      $class .= $type . '-field ';
    }

    // Create the full html block
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim($class . $this->params['class']), $html);
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
      if ($this->params['type'] == 'date') {
        $value = date_i18n(get_option('date_format'), strtotime($value));
      }
      return $value;
    }

    // Or return a predefined value
    if (isset($args['vorgabewert'])) {
      return $args['vorgabewert'];
    }

    return '';
  }
} 