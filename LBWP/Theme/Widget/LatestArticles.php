<?php

namespace LBWP\Theme\Widget;

use \WP_Widget;
use LBWP\Util\WordPress;

/**
 * Widget to configure and use a more meaningful recent posts widget
 * @package LBWP\Theme\Widget
 * @author Michael Sebel <michael@comotive.ch>
 */
class LatestArticles extends WP_Widget
{
  /**
   * Create the widget
   */
  public function __construct()
  {
    parent::__construct('LatestArticles', 'Neuste Artikel', array(
      'classname' => 'latest-articles',
      'description' => __('Zeigt die neusten Artikel in der Sidebar', 'lbwp')
    ));
  }

  /**
   * Initialize and register the widget
   */
  public static function init()
  {
    add_action('widgets_init', function() {
      unregister_widget('WP_Widget_Recent_Posts');
      register_widget('\LBWP\Theme\Widget\LatestArticles');
    });
  }

  /**
   * Displays the widget
   * @param array $args
   * @param array $instance
   */
  public function widget($args, $instance)
  {
    echo $args['before_widget'] . $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'] . '<div class="latestarticles_content"><ul>';

    $argsPost = array(
      'posts_per_page'   => $instance['number'],
      'suppress_filters' => false
    );

    // include only a category
    if (($instance['cat'] != '') && ($instance['cat'] != '-1')) {
      $argsPost['category'] = $instance['cat'];
    }

    // exclude the current single shown post
    if ($instance['hide_current_single'] == 1 && is_single()) {
      global $post;
      $argsPost['exclude'] = $post->ID;
    }

    $posts = get_posts($argsPost);

    foreach ($posts as $p) {
      $post_html = '<li>';
      if (strlen($instance['date']) > 0) {
        $post_html .= '<div class="date">' . get_the_time($instance['date'], $p) . '</div>';
      }

      $post_html .= '<div class="title"><a href="' . get_permalink($p->ID) . '">' . $p->post_title . '</a></div>';

      if (($instance['thumbnail'] != '') && (has_post_thumbnail($p->ID))) {
        $post_html .= '<div class="thumbnail">' . get_the_post_thumbnail($p->ID, $instance['thumbnail']) . '</div>';
      }

      if ($instance['title_only'] == 0) {
        $post_html .= '
          <div class="content">' . WordPress::getConfigurableExcerpt($p->ID, 55, '', false) . '
            <span class="readmore"><a href="' . get_permalink($p->ID) . '">' . apply_filters('latestarticles_widget_more', __('&raquo; weiterlesen', 'lbwp')) . '</a></span>
          </div>
        ';
      }

      $post_html .= '</li>';

      echo apply_filters('latestarticles_widget_post_html', $post_html, $p, $instance);
    }

    echo '</ul></div>';
    // Maybe display a link
    if ($instance['has_link'] == 1) {
      $text = $instance['link_text'];
      if (strlen($text) == 0) {
        $text = __('mehr', 'lbwp');
      }
      echo '<p class="latest-article-link"><a href="' . get_category_link($instance['cat']) . '">' . $text . '</a></p>';
    }
    echo $args['after_widget'];
  }

  /**
   * @param array $new_instance
   * @param array $old_instance
   * @return array
   */
  public function update($new_instance, $old_instance)
  {
    if ($new_instance['cat'] == '0')
      $new_instance['cat'] = '';
    return $new_instance;
  }

  /**
   * @param array $instance
   * @return string|void
   */
  public function form($instance)
  {
    $title = esc_attr($instance['title']);
    $link_text = esc_attr($instance['link_text']);
    $number = esc_attr($instance['number']);
    $thumbnail = $instance['thumbnail'];
    $date = esc_attr($instance['date']);
    $has_link = esc_attr($instance['has_link']);
    $title_only = esc_attr($instance['title_only']);
    $hide_current_single = esc_attr($instance['hide_current_single']);
    $cat = $instance['cat'];
    ?>
    <p>
      <label for="<?php echo $this->get_field_id('title'); ?>">
        Titel
        <input class="widefat"
               id="<?php echo $this->get_field_id('title'); ?>"
               name="<?php echo $this->get_field_name('title'); ?>"
               type="text" value="<?php echo $title; ?>"/>
      </label>
    </p>
    <p><label for="<?php echo $this->get_field_id('number'); ?>">
        Anzahl
        <input class="widefat"
               id="<?php echo $this->get_field_id('number'); ?>"
               name="<?php echo $this->get_field_name('number'); ?>"
               type="text"
               value="<?php echo $number; ?>"/>
      </label>
    </p>
    <p>
      <label for="<?php echo $this->get_field_id('thumbnail'); ?>">
        Bild<br/>
        <select id="<?php echo $this->get_field_id('thumbnail'); ?>"
                name="<?php echo $this->get_field_name('thumbnail'); ?>">
          <option value="" <?php selected('', $thumbnail) ?>>Kein Bild</option>
          <option value="thumbnail" <?php selected('thumbnail', $thumbnail) ?>>Miniaturbilder</option>
          <option value="medium" <?php selected('medium', $thumbnail) ?>>Mittlere Bildgröße</option>
          <option value="large" <?php selected('large', $thumbnail) ?>>Maximale Bildgröße</option>
          <?php
          global $_wp_additional_image_sizes;
          foreach ($_wp_additional_image_sizes as $name => $data) {
            echo '<option value="' . $name . '" ' . selected($name, $thumbnail, false) . '>' . $name . '</option>';
          }
          ?>
        </select>
      </label>
    </p>
    <p>
      <label for="<?php echo $this->get_field_id('date'); ?>">
        Datumsformat
        <input class="widefat"
               id="<?php echo $this->get_field_id('date'); ?>"
               name="<?php echo $this->get_field_name('date'); ?>"
               type="text"
               value="<?php echo $date; ?>"/>
      </label>
    </p>
    <p>
      <label for="<?php echo $this->get_field_id('cat'); ?>">Kategorie<br/>
        <?php wp_dropdown_categories(array(
          'name' => $this->get_field_name('cat'),
          'id' => $this->get_field_id('cat'),
          'show_option_none' => 'Alle Kategorien',
          'hide_empty' => false,
          'hierarchical' => true,
          'orderby' => 'name',
          'selected' => $cat
        ));
        ?>
      </label>
    </p>
    <p>
      <?php $checked = checked(1, $title_only, false); ?>
      <input type="checkbox" name="<?php echo $this->get_field_name('title_only'); ?>"
             id="<?php echo $this->get_field_id('title_only'); ?>" value="1" <?php echo $checked; ?> />
      <label for="<?php echo $this->get_field_id('title_only'); ?>">Nur Titel des Artikels anzeigen</label>
    </p>
    <p>
      <?php $checked = checked(1, $hide_current_single, false); ?>
      <input type="checkbox" name="<?php echo $this->get_field_name('hide_current_single'); ?>"
             id="<?php echo $this->get_field_id('hide_current_single'); ?>" value="1" <?php echo $checked; ?> />
      <label for="<?php echo $this->get_field_id('hide_current_single'); ?>">Aktuellen Artikel nicht anzeigen</label>
    </p>
    <p>
      <?php $checked = checked(1, $has_link, false); ?>
      <input type="checkbox" name="<?php echo $this->get_field_name('has_link'); ?>"
             id="<?php echo $this->get_field_id('has_link'); ?>" value="1" <?php echo $checked; ?> />
      <label for="<?php echo $this->get_field_id('has_link'); ?>">Link zur Kategorienseite anzeigen</label>
    </p>
    <p>
      <label for="<?php echo $this->get_field_id('link_text'); ?>">
        Link-Text zur Kategorienseite
        <input class="widefat"
               id="<?php echo $this->get_field_id('link_text'); ?>"
               name="<?php echo $this->get_field_name('link_text'); ?>"
               type="text"
               value="<?php echo $link_text; ?>"/></label>
    </p>
  <?php
  }
}