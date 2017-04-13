<?php

namespace LBWP\Util;

/**
 * Helper for templating and html generation
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Templating
{
  /**
   * Simple html block replacing
   * @param string $html the html block to use
   * @param array $data the data to be replaced
   * @return string created html block
   */
  public static function getBlock($html, $data)
  {
    foreach ($data as $key => $value) {
      $html = str_replace("{$key}", $value, $html);
    }

    return $html;
  }

  /**
   * Put something into a container
   * @param string $container the container
   * @param string $html the content of the container
   * @param string $varName the variable name to replace
   * @return string full content in container
   */
  public static function getContainer($container, $html, $varName = '{content}')
  {
    if (strlen($html) == 0) {
      return '';
    }

    return str_replace($varName, $html, $container);
  }

  /**
   * @param array $items key value pair of html items to be listed
   * @param string $class an additional class to be set
   * @param string $type the type of list, defaults to unordered
   * @return string the built html block
   */
  public static function getListHtml($items, $class = '', $type = 'ul')
  {
    $html = '<' . $type . ' class="' . $class . '">';
    foreach ($items as $key => $value) {
      $html .= '<li class="item-' .esc_attr($key)  . '">' . $value . '</li>';
    }
    $html .= '</' . $type . '>';

    return $html;
  }

  /**
   * @param array $options key value pair of options
   * @param string $name name of the field
   * @param string $selectedValue selected value
   * @param string $first the first element, if needed
   * @param string|int $firstValue the value of the first option
   */
  public static function getSelectItem($options, $name, $selectedValue, $first = '', $firstValue = 0)
  {
    $html = '';

    // Initialize the select item
    $html .= '<select name="' . $name . '">';
    // First option, if needed
    if (strlen($first) > 0) {
      $selected = selected($firstValue, $selectedValue, false);
      $html .= '<option value="' . $firstValue . '" ' . $selected . '>' . $first . '</option>';
    }

    // Add all the options, preselect given
    foreach ($options as $key => $value) {
      $selected = selected($key, $selectedValue, false);
      $html .= '<option value="' . $key . '" ' . $selected . '>' . $value . '</option>';
    }

    $html .= '</select>';

    return $html;
  }
}