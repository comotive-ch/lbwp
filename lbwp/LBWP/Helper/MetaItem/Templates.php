<?php

namespace LBWP\Helper\MetaItem;

/**
 * Helper object to provide templates
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class Templates
{
  /**
   * @var array the templates array
   */
  protected static $templates = array();

  /**
   * Set the default tempaltes
   */
  public static function setTemplates()
  {
    self::$templates['description'] = '
      <div class="mbh-item-normal">
        <div class="mbh-title"><label for="{fieldId}">{title}</label></div>
        <div class="mbh-field {fieldClass}">
          <div class="mbh-input">{input}</div>
          <div class="mbh-description"><label for="{fieldId}">{description}</label></div>
        </div>
      </div>
    ';

    self::$templates['description_full'] = '
      <div class="mbh-item-full">
        <div class="mbh-title"><label for="{fieldId}">{title}</label></div>
        <div class="mbh-field {fieldClass}">
          <div class="mbh-input">{input}</div>
          <div class="mbh-description"><label for="{fieldId}">{description}</label></div>
        </div>
      </div>
    ';

    self::$templates['short'] = '
      <div class="mbh-item-normal">
        <div class="mbh-title"><label for="{fieldId}">{title}</label></div>
        <div class="mbh-field {fieldClass}">
          <div class="mbh-input">{input}</div>
        </div>
      </div>
    ';

    self::$templates['short_full'] = '
      <div class="mbh-item-full">
        <div class="mbh-title"><label for="{fieldId}">{title}</label></div>
        <div class="mbh-field {fieldClass}">
          <div class="mbh-input">{input}</div>
        </div>
      </div>
    ';

    self::$templates['short_input_list'] = '
      <div class="mbh-item-normal list">
        <div class="mbh-title"><label for="{fieldId}">{title}</label></div>
        <div class="mbh-field {fieldClass}">
          <div class="mbh-input" style="{fieldStyle}">{input}</div>
        </div>
      </div>
    ';

    self::$templates['empty'] = '
      <div class="mbh-item-normal">{html}</div>
    ';

    self::$templates['emptyFull'] = '
      <div class="mbh-item-full">{html}</div>
    ';

    self::$templates['media'] = '
      <div class="mbh-item-normal">
        <div class="mbh-title"><label for="{fieldId}">{title}</label></div>
        <div class="mbh-field {fieldClass}">
          {media}
          <div class="mbh-description"><label for="{fieldId}">{description}</label></div>
        </div>
      </div>
    ';
  }

  /**
   * @param string $id of the template
   * @return string html code fo the requested template
   */
  public static function getById($id)
  {
    return self::$templates[$id];
  }

  /**
   * @param $args
   * @param $key
   * @param string $template
   * @return mixed
   */
  public static function get($args, $key, $template = '')
  {
    if (strlen($template) > 0 && isset(self::$templates[$template])) {
      $html = self::$templates[$template];
    } else {
      // Find the template by argument magic
      if (isset($args['full']) && $args['full']) {
        if (isset($args['description'])) {
          $html = self::$templates['description_full'];
          $html = str_replace('{description}', $args['description'], $html);
        } else {
          $html = self::$templates['short_full'];
        }
      } else {
        if (isset($args['description'])) {
          $html = self::$templates['description'];
          $html = str_replace('{description}', $args['description'], $html);
        } else {
          $html = self::$templates['short'];
        }
      }
    }

    // Put a required flag on the label
    if (isset($args['required']) && $args['required']) {
      $args['title'] .= ' <span class="required">*</span>';
    }
    // provide a css class defaulted to the key argument (non-unique)
    $args['class'] .= ' ' . $args['key'];


    // Put in the title
    $html = str_replace('{title}', $args['title'], $html);
    $html = str_replace('{fieldId}', $key, $html);
    $html = str_replace('{fieldClass}', $args['class'], $html);
    return $html;
  }
} 