<?php

namespace LBWP\Theme\Widget;

use LBWP\Util\String;

/**
 * A widget that is cloned, hence uses data from another widget to display itself
 * @package Blogwerk\Module\Widget
 * @author Michael Sebel <michael@comotive.ch>
 */
class ClonedContent extends \WP_Widget
{
  /**
   * Register the actual widget information
   */
  public function __construct()
  {
    // We needed to keep the graveyard name in order to be backwards compatible
    parent::__construct(
      'ClonedContentWidget',
      'Klon-Widget',
      array(
        'classname' => 'clone-widget',
        'description' => 'Erlaubt es einen Klon eines bestehenden Widgets zu verwenden, der automatisch dem Original angepasst wird.'
      )
    );
  }

  /**
   * Display the widget
   * @param array $args
   * @param array $instance
   */
  function widget($args, $instance)
  {
    global $wp_registered_widgets;
    // See if its a self reference and exit
    $clone_id = $instance['cloned_widget_id'];
    if (strlen($clone_id) == 0) {
      $clone_id = esc_attr($instance['widget_id']);
    }
    // First check if the widget is found and available
    if (isset($wp_registered_widgets[$clone_id])) {
      // Does the widget have a valid callback?
      if (isset($wp_registered_widgets[$clone_id]['callback'])) {
        $callback = $wp_registered_widgets[$clone_id]['callback'];
        $widget_type = substr($clone_id, 0, strrpos($clone_id, '-'));
        $widget_type_id = substr($clone_id, strrpos($clone_id, '-') + 1);
        // Get the widget option so we can extract the widget by id
        $configurations = get_option('widget_' . $widget_type);
        $clone_instance = $configurations[$widget_type_id];
        // Check for an endles reference to not allow ram consumption
        if (isset($clone_instance['cloned_widget_id']) && $clone_instance['cloned_widget_id'] == $instance['cloned_widget_id']) {
          return '';
        }
        // add the original classname by searching the clone-widget class and replacing it
        $args['before_widget'] = str_replace(
          $this->widget_options['classname'],
          $callback[0]->widget_options['classname'] . ' ' . $this->widget_options['classname'],
          $args['before_widget']
        );
        // Display the original widget with local args and original instance
        $callback[0]->widget($args, $clone_instance);
      }
    }
  }

  /**
   * Callback on saving the widget
   * @param array $new_instance
   * @param array $old_instance
   * @return array
   */
  function update($new_instance, $old_instance)
  {
    return $new_instance;
  }

  /**
   * Display widget backend
   * @param array $instance
   * @return string|void
   */
  function form($instance)
  {
    global $wp_registered_sidebars;
    global $wp_registered_widgets;

    $current_widget_id = esc_attr($instance['cloned_widget_id']);
    if (strlen($current_widget_id) == 0) {
      $current_widget_id = esc_attr($instance['widget_id']);
    }
    $savedOnce = intval($instance['saved_once']);
    $select_options = '';
    $sidebars = get_option('sidebars_widgets');
    // This contains all widgets, just don't show "wp_inactive_widgets" and "array_version"
    $found = false;
    foreach ($sidebars as $key => $widgets) {
      if ($key == 'wp_inactive_widgets' || $key == 'array_version')
        continue;

      // an inactive sidebar will have the class 'inactive-sidebar', set in wp-admin/widgets.php:90
      if (in_array('inactive-sidebar', explode(' ', $wp_registered_sidebars[$key]['class']))) {
        continue;
      }

      // Close an open option group (the first unneeded close is removed after), and open a new one
      $select_options .= '<optgroup label="' . $wp_registered_sidebars[$key]['name'] . '">';
      // Show all their widgets, except for cloning widgets (type blogwerk_promotion_widgetcloning_widget)
      foreach ($widgets as $widget_id) {
        if (String::startsWith($widget_id, 'blogwerk_promotion_widgetcloning_widget')) {
          continue;
        }
        $widget_type = substr($widget_id, 0, strrpos($widget_id, '-'));
        $widget_type_id = substr($widget_id, strrpos($widget_id, '-') + 1);
        // Get the widget option so we can extract the widget by id
        $configurations = get_option('widget_' . $widget_type);
        $widget_title = $wp_registered_widgets[$widget_id]['name'];
        if (strlen($configurations[$widget_type_id]['title']) > 0) {
          $widget_title .= ': ' . $configurations[$widget_type_id]['title'];
        }

        $selected = '';
        if ($widget_id == $current_widget_id) {
          $selected = ' selected="selected"';
          $currentWidgetTitle = '';
          $currentWidgetTitleEnd = '';
          if (strlen($configurations[$widget_type_id]['title']) > 0) {
            // $currentWidgetTitle .= $configurations[$widget_type_id]['title'].' - ';
            $currentWidgetTitle .= $configurations[$widget_type_id]['title'] . ' (';
            $currentWidgetTitleEnd = ')';
          }
          $currentWidgetTitle .= $wp_registered_widgets[$widget_id]['name'] . $currentWidgetTitleEnd;
          $found = true;
        }

        $select_options .= '<option value="' . $widget_id . '" ' . $selected . '>' . $widget_title . '</option>';
      }
      $select_options .= '</optgroup>';
    }
    // Print error, if the configured widget doesn't exist anymore
    if (!$found && $savedOnce == 1) {
      echo '
        <div class="widget-error">
        Das konfigurierte Widget existiert nicht mehr, bitte wählen Sie ein neues oder löschen Sie den Klon.
        </div>
      ';
    }
    // Print the dropdown
    echo '
      <p>
        <input type="hidden" name="' . $this->get_field_name('title') . '" id="' . $this->get_field_id('title') . '" value="' . $currentWidgetTitle . '">
        <input type="hidden" name="' . $this->get_field_name('saved_once') . '" id="' . $this->get_field_id('saved_once') . '" value="1">
        <label for="' . $this->get_field_id('cloned_widget_id') . '">Original-Widget dieses Klons</label>
        <select style="width:225px;" id="' . $this->get_field_id('cloned_widget_id') . '" name="' . $this->get_field_name('cloned_widget_id') . '">
          <option value="0">Bitte wählen Sie ein Widget aus</option>
          ' . $select_options . '
        </select>
      </p>
    ';
  }
}