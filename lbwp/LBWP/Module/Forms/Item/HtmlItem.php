<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * This will display a normal input text field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class HtmlItem extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Text / HTML Element',
    'group' => 'Spezial-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'text' => array(
        'name' => 'Inhalt',
        'type' => 'textarea',
        'help' => 'Sie können den Inhalt im Editor bearbeiten und damit Formatierungen anwenden und Bilder in das Formular einfügen.',
        'editor' => true
      )
    ));

    unset($this->paramConfig['feldname']);
    unset($this->paramConfig['pflichtfeld']);
    unset($this->paramConfig['error_message']);
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['description'] = 'Text / HTML Element';
    $this->params['text'] = '';
    $this->params['default_text'] = '';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $this->addFormFieldConditions($args['conditions']);
    $text = $this->params['text'];
    // Replace the hacky shortcode thingies
    $text = str_replace(array('&lceil;', '⌈'), '[', $text);
    $text = str_replace(array('&rfloor;', '⌋'), ']', $text);
    // If it contains html encoded quotes, run entity decode on in
    if (Strings::contains($text, Strings::HTML_QUOTE)) {
      $text = html_entity_decode($text, ENT_COMPAT, 'UTF-8');
    }
    // Add an invisible data attribute to make to block invisible
    if ($args['visible'] == 'nein') {
      $text .= '<span data-init-invisible="1"></span>';
    }

    // Add a hidden field with name to be accessed by condition framework
    $text .= '<span data-name="' . $this->get('id') . '"></span>';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['label'], $html);
    $html = str_replace('{class}', trim('required-note ' . $this->params['class']), $html);
    $html = str_replace('{field}', do_shortcode($text), $html);

    return $html;
  }

  /**
   * @param array $args the shortcode params
   * @return string the user value, if the form has been sent
   */
  public function getValue($args = array())
  {
    return '';
  }
} 