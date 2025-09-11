<?php

namespace LBWP\Theme\Widget\FormField;

use LBWP\Helper\Metabox;
use LBWP\Helper\MetaItem\ChosenDropdown;

/**
 * Class WidgetDropdownField
 *
 * @category Blogwerk
 * @package Blogwerk_Widget
 * @subpackage FormField
 * @author Tom Forrer <tom.forrer@blogwerk.com
 * @copyright Copyright (c) 2014 Blogwerk AG (http://blogwerk.com)
 */
class WidgetDropdownFormField extends AbstractFormField
{
  protected $label = '&nbsp;';

  /**
   * Make sure to have scripts enqueued from metabox helper
   */
  public function setup()
  {
    add_action('admin_enqueue_scripts', function () {
      wp_enqueue_script(
        'chosen-js',
        '/wp-content/plugins/lbwp/resources/js/chosen/chosen.jquery.min.js',
        array('jquery')
      );
      wp_enqueue_script(
        'chosen-sortable-js',
        '/wp-content/plugins/lbwp/resources/js/chosen/chosen.sortable.jquery.js',
        array('chosen-js')
      );
      wp_enqueue_style(
        'chosen-css',
        '/wp-content/plugins/lbwp/resources/js/chosen/chosen.min.css'
      );
    });
  }

  /**
   * Display the dropdown field
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
    $itemsCallback = $this->getOption('itemsCallback');
    $multiple = $this->getOption('multiple');
    if (is_null($multiple)) {
      $multiple = false;
    }
    if (!$multiple) {
      $value = array($value);
    }

    if (strpos($name, '__i__') === false) {
      $dropdownArguments = array(
        'name' => $name,
        'key' => str_replace(array('[', ']'), array('_', ''), $name),
        'multiple' => $multiple,
        'value' => $value,
        'itemsCallback' => $itemsCallback,
        'items' => $this->getOption('items'),
        'widgetInstance' => $instance,
      );

      $html = '
        <p>
          <label for="' . $this->getWidget()->get_field_id($this->getSlug()) . '">' . $this->getLabel() . '</label>
          ' . ChosenDropdown::displayDropdown($dropdownArguments) . '
        </p>
      ';
    }

    return $html;
  }

} 