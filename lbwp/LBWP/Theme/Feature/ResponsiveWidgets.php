<?php

namespace LBWP\Theme\Feature;

/**
 * Provides possibilities and filters to configure Widgets to certain visibility options
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class ResponsiveWidgets
{
  /**
   * This defines an example, that is always completely overridden on init
   * @var array Contains all configurations
   */
  protected $options = array(
    'settings' => array(
      'lbwp-hide-desktop' => 'Ausblenden auf Desktops',
      'lbwp-hide-tablet' => 'Ausblenden auf Tablet (Hoch)',
      'lbwp-hide-mobile' => 'Ausblenden auf Mobile'
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
   * @var string prefix for options
   */
  const PREFIX = 'LbwpResponsiveWidgets_';

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = $options;

    // Developer message, if there are no settings defined
    if (!is_array($this->options['settings']) || count($this->options['settings']) == 0) {
      echo 'Warning: ResponsiveWidgets without settings is not working. Plz disable or configure.';
      return;
    }

    // Register all needed actions for this to work
    if (is_admin()) {
      add_action('in_widget_form', array($this, 'controlAction'));
      add_action('wp_ajax_save-widget', array($this, 'saveWidget'), 0);
      add_filter('widget_form_callback', array($this, 'saveCurrentWidget'), 10, 2);
    } else {
      add_filter('dynamic_sidebar_params', array($this, 'filterSidebarParameters'));
    }
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new ResponsiveWidgets($options);
  }

  /**
   * Display the options for the user depending on mapping
   */
  public function controlAction()
  {
    if (count($this->options['settings']) > 0) {
      $widgetId = $this->currentWidget->id;
      echo '<div>' . __('Responsive Einstellungen:', 'lbwp') . '<ul>';

      // Display all possible mappings
      foreach ($this->options['settings'] as $class => $title) {
        $widgets = get_option(self::PREFIX . $class, array());
        $input = '<input type="checkbox" name="' . $class . '" ' . checked(true, in_array($widgetId, $widgets), false) . ' />';
        echo '<li><label>' . $input . $title . '</label></li>';
      }

      echo '</ul></div>';
    }
  }

  /**
   * @param array $instance object
   * @param \stdClass $widget object
   * @return array $instance
   */
  public function saveCurrentWidget($instance, $widget)
  {
    $this->currentWidget = $widget;
    return $instance;
  }

  /**
   * Save the widget configuration
   */
  public function saveWidget()
  {
    if (isset($_POST['widget-id'])) {
      $widgetId = $_POST['widget-id'];

      foreach ($this->options['settings'] as $class => $title) {
        // Get current class config
        $widgets = get_option(self::PREFIX . $class, array());
        // Save or unset the widget ID
        if (isset($_POST[$class]) && $_POST[$class] == 'on') {
          if (!in_array($widgetId, $widgets)) {
            $widgets[] = $widgetId;
          }
        } else {
          $index = array_search($widgetId, $widgets);
          if ($index !== false) {
            unset($widgets[$index]);
          }
        }
        // Save back to option
        update_option(self::PREFIX . $class, array_values($widgets));
      }
    }
  }

  /**
   * Add the hide classes, if configured for the widget
   * @param array $parameters sidebar params
   * @return array the returned array
   */
  public function filterSidebarParameters($parameters)
  {
    foreach ($this->options['settings'] as $class => $title) {
      $widgets = get_option(self::PREFIX . $class, array());
      $widgetId = $parameters[0]['widget_id'];
      if (in_array($widgetId, $widgets)) {
        $parameters[0]['before_widget'] = str_replace('class="', 'class="' . $class . ' ', $parameters[0]['before_widget']);
      }
    }

    return $parameters;
  }
}
