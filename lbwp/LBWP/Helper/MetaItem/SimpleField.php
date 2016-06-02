<?php

namespace LBWP\Helper\MetaItem;

/**
 * Helper object to provide html for simple fields
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class SimpleField
{
  /**
   * @param array $args list of arguments
   * @param string $key the field key
   * @param string $html the html template
   * @param array $fields list of known post fields
   * @return string html to represent the field
   */
  public static function displayInputText($args, $key, $html, $fields = array())
  {
    // Get the current value
    $value = self::getTextFieldValue($args, $fields);
    if (strlen($value) == 0 && isset($args['default'])) {
      $value = $args['default'];
    }

    // Default width
    $width = self::getWidth($args);
    if ($width == 'none') {
      $width = '75%;';
    }

    $attr = ' style="width:' . $width . ';"';
    if (isset($args['required']) && $args['required']) {
      $attr .= ' required="required"';
    }

    // Replace in the input field
    $input = '
      <input type="text" id="' . $key . '" name="' . $key . '" value="' . esc_attr($value) . '"' . $attr . ' />
    ';
    $html = str_replace('{input}', $input, $html);
    return $html;
  }

  /**
   * @param array $args list of arguments
   * @param string $key the field key
   * @param string $html the html template
   * @param array $fields list of known post fields
   * @return string html to represent the field
   */
  public static function displayTextArea($args, $key, $html, $fields)
  {
    // Get the current value
    $value = self::getTextFieldValue($args, $fields);
    if (strlen($value) == 0 && isset($args['default'])) {
      $value = $args['default'];
    }

    // Default width
    $width = self::getWidth($args);
    if ($width == 'none') {
      $width = '75%;';
    }

    $attr = ' style="width:' . $width . ';height:' . $args['height'] . 'px"';
    if (isset($args['required']) && $args['required']) {
      $attr .= ' required="required"';
    }

    // Replace in the input field
    $input = '
      <textarea id="' . $key . '" name="' . $key . '"' . $attr . '>' . $value . '</textarea>
    ';
    $html = str_replace('{input}', $input, $html);
    return $html;
  }
  /**
   * @param array $args arguments coming from outside
   * @return string the width string in px or percent
   */
  public static function getWidth($args)
  {
    $width = 'none';
    if (isset($args['width'])) {
      $width = $args['width'];
      if (strstr($width, '%') === false && stristr($width, 'px') === false) {
        $width .= 'px';
      }
    }
    return $width;
  }

  /**
   * @param array $args list of arguments
   * @param array $fields list of known fields
   * @return string the text fields value
   */
  public static function getTextFieldValue($args, $fields)
  {
    if (in_array($args['key'], $fields)) {
      return $args['post']->{$args['key']};
    } else {
      return get_post_meta($args['post']->ID, $args['key'], true);
    }
  }
} 