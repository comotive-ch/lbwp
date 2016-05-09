<?php

namespace LBWP\Theme\Base;

use \LBWP\Theme\Widget\FormField;
use \WP_Widget;

/**
 * Class AbstractWidget
 *
 * Base class for widget class
 *
 * @category    Blogwerk
 * @package     Blogwerk_Widget
 * @author      Tom Forrer <tom.forrer@blogwerk.com>
 * @copyright   Copyright (c) 2014 Blogwerk AG (http://blogwerk.com)
 */
abstract class Widget extends WP_Widget
{
  protected $version = 1.0;
  protected $baseId = 'AbstractWidget';
  protected $widgetName = 'Abstract Widget';
  protected $textDomain = 'default';
  protected $options = array();
  protected $title;
  protected $description = '';
  /**
   * @var FormField\AbstractFormField[]
   */
  protected $fields = array();

  /**
   * Register all the things
   */
  public function __construct()
  {
    if (!isset($this->options['description'])) {
      $this->options['description'] = $this->getDescription();
    } elseif (!$this->getDescription()) {
      $this->description = $this->options['description'];
    }
    $this->options['classname'] = $this->getBaseId();

    parent::__construct($this->getBaseId(), $this->getWidgetName(), $this->getOptions());

    // before the WP_Widget_Factory uses the properties (at widgets_init(100))
    add_action('widgets_init', array($this, 'applyProperties'), 90);
    // add a setup callback (i.e. for the textdomain)
    add_action('widgets_init', array($this, 'internalSetup'), 110);
    // allow custom initialization
    add_action('widgets_init', array($this, 'init'), 120);
  }

  /**
   * @return void
   */
  abstract public function init();

  /**
   * setup the text domain, if necessary
   */
  public function internalSetup()
  {
    // set the text domain according to the current theme text domain, but only if it is not yet set specifically
    if ($this->textDomain == 'default') {
      $wordpressTheme = wp_get_theme();
      $textDomain = $wordpressTheme->get('TextDomain');

      // make sure we have something usable
      if (strlen($textDomain) > 0) {
        $this->textDomain = $textDomain;
      }
    }
  }

  /**
   * Apply properties to widget
   */
  public function applyProperties()
  {
    $options = $this->getOptions();
    if (!isset($options['classname'])) {
      $options['classname'] = $this->getBaseId();
      $this->options = $options;
    }
    $this->widget_options = $this->getOptions();
    $this->name = $this->getWidgetName();
  }

  /**
   * @param array $args
   * @param array $instance
   */
  public function widget($args, $instance)
  {
    $innerHtml = $this->html($args, $instance);

    // Print if possible
    if (strlen($innerHtml) > 0) {
      echo $this->beforeWidget($args, $instance);
      echo $innerHtml;
      echo $this->afterWidget($args, $instance);
    }
  }

  /**
   * @param array $args
   * @param array $instance
   * @return mixed
   */
  abstract protected function html($args, $instance);

  /**
   * @param FormField\AbstractFormField $formField
   */
  protected function registerFormField($formField)
  {
    $this->fields = array_merge($this->fields, array($formField->getSlug() => $formField));
  }

  /**
   * @param $field
   * @param array $options
   */
  protected function registerTextFormField($field, $options = array())
  {
    $formField = new FormField\WidgetTextFormField($field, $this, $options);
    $this->registerFormField($formField);
  }

  /**
   * @param $field
   * @param array $options
   */
  protected function registerTextAreaFormField($field, $options = array())
  {
    $formField = new FormField\WidgetTextareaFormField($field, $this, $options);
    $this->registerFormField($formField);
  }

  /**
   * @param $field
   * @param array $options
   */
  protected function registerDropdownFormField($field, $options = array())
  {
    $formField = new FormField\WidgetDropdownFormField($field, $this, $options);
    $this->registerFormField($formField);
  }

  /**
   * @param $field
   * @param array $options
   */
  protected function registerCheckboxFormField($field, $options = array())
  {
    $formField = new FormField\WidgetCheckboxFormField($field, $this, $options);
    $this->registerFormField($formField);
  }

  /**
   * @param $field
   * @param array $options
   */
  protected function registerAttachmentFormField($field, $options = array())
  {
    $formField = new FormField\WidgetAttachmentFormField($field, $this, $options);
    $this->registerFormField($formField);
  }

  /**
   * @param array $instance
   * @return string|void
   */
  public function form($instance)
  {
    $html = '';
    foreach ($this->fields as $field => $formField) {
      $fieldHtml = $formField->display($instance[$field], $instance);
      if ($fieldHtml) {
        $html .= $fieldHtml;
      }
    }
    echo $html;
  }

  /**
   * @param array $newInstance
   * @param array $oldInstance
   * @return array
   */
  public function update($newInstance, $oldInstance)
  {
    foreach ($this->fields as $field => $formField) {
      /**
       * @var FormField\AbstractFormField $formField ;
       */
      if (isset($newInstance[$field])) {
        $newInstance[$field] = $formField->sanitize($newInstance[$field], $oldInstance[$field]);
      } elseif (isset($newInstance['nonce']) && isset($newInstance[$field . '_' . $newInstance['nonce']])) {
        $newInstance[$field] = $formField->sanitize($newInstance[$field . '_' . $newInstance['nonce']], $oldInstance[$field]);
      } else {
        $newInstance[$field] = $formField->getDefaultValue();
      }
    }
    return $newInstance;
  }

  /**
   * Helper function to be optionally overridden
   *
   * @param array $args
   * @param array $instance
   * @return string
   */
  protected function beforeWidget($args, $instance)
  {
    return $args['before_widget'];
  }

  /**
   * Helper function to be optionally overridden
   *
   * @param array $args
   * @param array $instance
   * @return string
   */
  protected function afterWidget($args, $instance)
  {
    return $args['after_widget'];
  }

  /**
   * @return string
   */
  public function getBaseId()
  {
    return $this->baseId;
  }

  /**
   * @return float
   */
  public function getVersion()
  {
    return $this->version;
  }

  /**
   * @return string
   */
  public function getWidgetName()
  {
    return $this->widgetName;
  }

  /**
   * @deprecated use getTextDomain()
   * @return string
   */
  public function getDomain()
  {
    return $this->textDomain;
  }

  /**
   * @return string
   */
  public function getTextDomain()
  {
    return $this->textDomain;
  }

  /**
   * @return array
   */
  public function getOptions()
  {
    return $this->options;
  }

  /**
   * @return int
   */
  public function getDescription()
  {
    return $this->description;
  }
}