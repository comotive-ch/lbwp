<?php

namespace LBWP\Module\Events;

use LBWP\Module\Events\Core;
use LBWP\Module\Forms\Component\Posttype;
use LBWP\Util\Date;

/**
 * The widget to display a list of events
 * @package LBWP\Module\Forms
 * @author Michael Sebel <michael@comotive.ch>
 */
class Widget extends \WP_Widget
{

  /**
   * Initialize the widget
   */
  public function __construct()
  {
    $widget_ops = array(
      'classname' => 'lbwp-event-widget',
      'description' => __('Erlaubt es die nächsten Events in der Sidebar darzustellen', 'lbwp')
    );

    parent::__construct('lbwp-event-widget', __('Events Widget', 'lbwp'), $widget_ops);
  }

  /**
   * Display da form, yo
   * @param array $args
   * @param array $instance
   */
  public function widget($args, $instance)
  {
    extract($args);
    $title = apply_filters('widget_title', empty($instance['title']) ? '' : $instance['title'], $instance, $this->id_base);
    $text = apply_filters('widget_text', empty($instance['text']) ? '' : $instance['text'], $instance);
    echo $before_widget;
    if (!empty($title)) {
      echo $before_title . $title . $after_title;
    }
    ?>
    <div class="textwidget eventwidget">
      <?php echo !empty($instance['filter']) ? wpautop($text) : $text; ?>
      <?php echo $this->getNextEventsHtml($instance); ?>
    </div>
    <?php
    echo $after_widget;
  }

  /**
   * Get a list of the next events
   * @param array $instance the widget config
   * @return string list containing infos about next events
   */
  protected function getNextEventsHtml($instance)
  {
    $html = '';

    // Set the config
    $shortcodeArgs = array(
      'max_events' => intval($instance['maxEvents'])
    );

    // Get the next events as configured
    $frontend = Core::getInstance()->getFrontendComponent();
    $config = Core::getInstance()->getShortcodeComponent()->getListConfiguration($shortcodeArgs);
    // Only query for future events as of right now and half a year into the future
    $config['from'] = current_time('timestamp');
    $config['to'] = $config['from'] + (86400 * 180);
    $events = $frontend->queryEvents($config);
    $frontend->populateEventData($events, $config, current_time('timestamp'));

    // Display the events, if available
    if (count($events) > 0) {
      $html .= '<ul class="list-events">';
      foreach ($events as $event) {
        $html .= '
          <li>' . Date::getTime(Date::EU_DATE, $event->startTime) . ': <a href="' . get_permalink($event->ID) . '">' . $event->post_title . '</a></li>
        ';
      }
      $html .= '</ul>';

      // If there is a page, link it
      if (intval($instance['pageId']) > 0) {
        $html .= '<p><a href="' . get_permalink($instance['pageId']) . '">' . __('Alle Events anzeigen', 'lbwp') . '</a></p>';
      }
    } else {
      $html .= wpautop($instance['noCurrentEvents']);
    }

    return $html;
  }

  /**
   * Save and filter contents
   * @param array $new_instance
   * @param array $old_instance
   * @return array
   */
  public function update($new_instance, $old_instance)
  {
    $instance = $old_instance;
    $instance['title'] = strip_tags($new_instance['title']);
    $instance['noCurrentEvents'] = strip_tags($new_instance['noCurrentEvents']);
    $instance['maxEvents'] = intval($new_instance['maxEvents']);
    $instance['pageId'] = intval($new_instance['pageId']);
    if (current_user_can('unfiltered_html'))
      $instance['text'] = $new_instance['text'];
    else
      $instance['text'] = stripslashes(wp_filter_post_kses(addslashes($new_instance['text']))); // wp_filter_post_kses() expects slashed
    $instance['filter'] = isset($new_instance['filter']);
    return $instance;
  }

  /**
   * Form to alter the widget
   */
  public function form($instance)
  {
    $instance = wp_parse_args((array)$instance, array('title' => '', 'text' => ''));
    $title = strip_tags($instance['title']);
    $text = esc_textarea($instance['text']);
    $noCurrentEvents = strip_tags($instance['noCurrentEvents']);
    $maxEvents = intval($instance['maxEvents']);
    $pageId = intval($instance['pageId']);

    // Set default for max events
    if ($maxEvents == 0) $maxEvents = 3;
    ?>
    <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label>
      <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
        name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>"/>
    </p>

    <p><label for="<?php echo $this->get_field_id('maxEvents'); ?>"><?php _e('Anzahl Einträge'); ?></label>
      <input class="widefat" id="<?php echo $this->get_field_id('maxEvents'); ?>"
        name="<?php echo $this->get_field_name('maxEvents'); ?>" type="text" value="<?php echo esc_attr($maxEvents); ?>"/>
    </p>

    <p><label for="<?php echo $this->get_field_id('noCurrentEvents'); ?>"><?php _e('Meldung, wenn es keine Events gibt'); ?></label>
      <input class="widefat" id="<?php echo $this->get_field_id('noCurrentEvents'); ?>"
        name="<?php echo $this->get_field_name('noCurrentEvents'); ?>" type="text" value="<?php echo esc_attr($noCurrentEvents); ?>"/>
    </p>

    <textarea class="widefat" rows="8" cols="20" id="<?php echo $this->get_field_id('text'); ?>"
      name="<?php echo $this->get_field_name('text'); ?>"><?php echo $text; ?></textarea>

    <p><input id="<?php echo $this->get_field_id('filter'); ?>" name="<?php echo $this->get_field_name('filter'); ?>"
         type="checkbox" <?php checked(isset($instance['filter']) ? $instance['filter'] : 0); ?> />&nbsp;<label
         for="<?php echo $this->get_field_id('filter'); ?>"><?php _e('Automatically add paragraphs'); ?></label></p>

    <p><label for="<?php echo $this->get_field_id('pageId'); ?>"><?php _e('Seite mit allen Events (optional)'); ?></label>
      <br />
      <?php
      wp_dropdown_pages(array(
        'option_none_value' => 0,
        'show_option_none' => __('Keinen Folgelink anzeigen', 'lbwp'),
        'selected' => $pageId,
        'name' => $this->get_field_name('pageId'),
        'id' => $this->get_field_name('pageId'),
      ));
      ?>
    </p>
    <?php
  }
}