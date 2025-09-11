<?php

namespace LBWP\Module\Forms;

use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Module\Forms\Component\Posttype;

/**
 * The widget to display forms
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
      'classname' => 'lbwp-form-widget',
      'description' => __('Erlaubt es ein zuvor erstelltes Formular in der Sidebar darzustellen', 'lbwp')
    );

    parent::__construct('lbwp-form-widget', __('Formular Widget', 'lbwp'), $widget_ops);
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
    <div class="textwidget formwidget">
      <?php echo !empty($instance['filter']) ? wpautop($text) : $text; ?>
      <?php echo do_shortcode('[' . FormHandler::SHORTCODE_DISPLAY_FORM . ' id="' . $instance['formId'] . '"]'); ?>
    </div>
    <?php
    echo $after_widget;
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
    $instance['formId'] = intval($new_instance['formId']);
    $instance['title'] = strip_tags($new_instance['title']);
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
    $formId = intval($instance['formId']);
    ?>
    <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label>
      <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
        name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>"/>
    </p>

    <textarea class="widefat" rows="8" cols="20" id="<?php echo $this->get_field_id('text'); ?>"
      name="<?php echo $this->get_field_name('text'); ?>"><?php echo $text; ?></textarea>

    <?php
    $forms = get_posts(array(
      'post_type' => Posttype::FORM_SLUG,
      'orderby' => 'title',
      'order' => 'ASC',
      'posts_per_page' => -1
    ));

    echo '<p><select name="' . $this->get_field_name('formId') . '">';
    foreach ($forms as $form) {
      $selected = selected($formId, $form->ID, false);
      echo '<option value="' . $form->ID . '"' . $selected . '>' . $form->post_title . '</option>';
    }
    echo '</select></p>';
    ?>

    <p><input id="<?php echo $this->get_field_id('filter'); ?>" name="<?php echo $this->get_field_name('filter'); ?>"
         type="checkbox" <?php checked(isset($instance['filter']) ? $instance['filter'] : 0); ?> />&nbsp;<label
         for="<?php echo $this->get_field_id('filter'); ?>"><?php _e('Automatically add paragraphs'); ?></label></p>
    <?php
  }
}