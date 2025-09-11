<?php

namespace LBWP\Theme\Widget\FormField;

use LBWP\Helper\Metabox;

/**
 * Class WidgetCheckboxField
 *
 * @category Blogwerk
 * @package Blogwerk_Widget
 * @subpackage FormField
 * @author Tom Forrer <tom.forrer@blogwerk.com
 * @copyright Copyright (c) 2014 Blogwerk AG (http://blogwerk.com)
 */
class WidgetCheckboxFormField extends AbstractFormField
{
  protected $label = '&nbsp;';

  /**
   * Nothing to do here
   */
  public function setup()
  {

  }

  /**
   * Display the checkbox field
   * @param mixed $value
   * @param array $instance
   * @return string
   */
  public function display($value, $instance)
  {
    $html = '';
    if (!isset($value)) {
      $value = $this->getDefaultValue();
    }
    $name = $this->getWidget()->get_field_name($this->getSlug());

    if (strpos($name, '__i__') === false) {
      $mbh = Metabox::get('post');
      $checkboxArguments = array(
        'name' => $name,
        'key' => str_replace(array('[', ']'), array('_', ''), $name),
        'value' => $value,
        'widgetInstance' => $instance,
        'description' => $this->getLabel()
      );
      // Generate HTML item
      $html = '<p>' . $mbh->displayCheckbox($checkboxArguments) . '</p>';
    }

    return $html;
  }
} 