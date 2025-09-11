<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;
use LBWP\Module\Forms\Component\FormHandler;

/**
 * This will cause a page break in the form
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class PageBreak extends Base
{
  static $pageNum = 1;
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Seitenumbruch',
    'group' => 'Spezial-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'titel' => array(
        'name' => 'Name der Seite',
        'type' => 'textfield'
      )
    ));

    // Don't let visiblity be configured
    unset($this->paramConfig['visible']);
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
    $this->params['description'] = 'Erstellt einen Seitenumbruch';
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
    // Create the full html block
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['titel'], $html);
    $html = str_replace('{class}', trim('text-field ' . $this->params['class']), $html);
    $html = str_replace('{field}', 'Seitenumbruch', $html);

    if (!FormHandler::$isBackendForm) {
      self::$pageNum++;
      $html = '</span><span class="lbwp-form-page page-' . self::$pageNum . '" data-page="' . self::$pageNum . '" data-page-name="' . $args['titel'] . '">';
    }

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