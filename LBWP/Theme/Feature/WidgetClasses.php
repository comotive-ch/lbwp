<?php

namespace LBWP\Theme\Feature;

/**
 * Allow to add widget class configurations
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class WidgetClasses
{
  /**
   * This defines an example, that is always completely overridden on init
   * @var array Contains all configurations
   */
  protected $options = array(
    'classes' => array(
      'css-class-name' => 'Standard'
    )
  );
  /**
   * @var ResponsiveWidgets the instance
   */
  protected static $instance = NULL;
  /**
   * @var array helper opbject
   */
  protected $currentWidget;
  /**
   * @var string the setting name
   */
  const SETTING_NAME = 'widgetClass';

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = $options;

    // Developer message, if there are no settings defined
    if (!is_array($this->options['classes']) || count($this->options['classes']) == 0) {
      echo 'Warning: WidgetClasses without settings is not working. Plz disable or configure.';
      return;
    }

    // Register all needed actions for this to work
    if (is_admin()) {
      add_action('in_widget_form', array($this, 'addDropdown'), 10, 3);
      add_filter('widget_update_callback', array($this, 'saveCurrentWidget'), 10, 4);
    } else {
      add_filter('dynamic_sidebar_params', array($this, 'filterSidebarParameters'));
    }
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new WidgetClasses($options);
  }

  /**
   * @param $widget
   * @param $return
   * @param $instance
   */
  public function addDropdown($widget, $return, $instance )
  {
    if (count($this->options['classes']) > 0) {
      echo '
        <div>' . __('Darstellung:', 'lbwp') . '
        <select name="' . $widget->id . '_' . self::SETTING_NAME . '">
      ';

      // Display all possible mappings
      foreach ($this->options['classes'] as $class => $title) {
        $selected = selected($class, $instance[self::SETTING_NAME], false);
        echo '<option value="' . $class . '"' . $selected . '>' . $title . '</option>';
      }

      echo '</select></div>';
    }
  }

  /**
   * @param $instance
   * @param $new
   * @param $old
   * @param $widget
   * @return mixed
   */
  public function saveCurrentWidget($instance, $new, $old, $widget)
  {
    $key = $widget->id . '_' . self::SETTING_NAME;
    if (!empty($_POST[$key]) && isset($this->options['classes'][$_POST[$key]])) {
      $instance[self::SETTING_NAME] = $_POST[$key];
    } else {
      unset($instance[self::SETTING_NAME]);
    }

    return $instance;
  }

  /**
   * Add the hide classes, if configured for the widget
   * @param array $parameters sidebar params
   * @return array the returned array
   */
  public function filterSidebarParameters($parameters)
  {
    global $wp_registered_widgets;

    // Hell, try to get that option array of the desired widget
    $settings = get_option($wp_registered_widgets[$parameters[0]['widget_id']]['callback'][0]->option_name);
    $widgetSettings = $settings[$parameters[1]['number']];

    // Add the widget class, if given
    if (isset($widgetSettings[self::SETTING_NAME])) {
      $parameters[0]['before_widget'] = str_replace('class="', 'class="' . $widgetSettings[self::SETTING_NAME] . ' ', $parameters[0]['before_widget']);
    }

    return $parameters;
  }
}
