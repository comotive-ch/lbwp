<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;

/**
 * This will display a normal input text field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class RequiredNote extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Pflichtfeld-Hinweis',
    'group' => 'Spezial-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'text' => array(
        'name' => 'Hinweis-Text',
        'type' => 'textfield'
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
    $this->params['description'] = 'Pflichtfeld Hinweis';
    $this->params['text'] = '* Pflichtfelder';
    $this->params['default_text'] = '* Pflichtfelder';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['label'], $html);
    $html = str_replace('{class}', trim('required-note ' . $this->params['class']), $html);
    $html = str_replace('{field}', $this->params['text'], $html);

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