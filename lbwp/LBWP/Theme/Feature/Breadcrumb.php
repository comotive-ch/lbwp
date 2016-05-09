<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Multilang;
use LBWP\Util\String;

/**
 * Provides developers with the possibility to use breadcrumbs.
 * The module is highly configureable with options
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class Breadcrumb
{
  /**
   * @var array Since this is migrated, the config var is not used, but needed
   */
  protected $config = array();
  /**
   * @var array Contains all elements of the breadcrumb
   */
  protected $elements = array();
  /**
   * @var array Contains all options for the breadcrumb
   */
  protected $options = array();
  /**
   * @var Breadcrumb the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = $options;
  }

  /**
   * @param array $elements elements to display (automatic, if empty)
   */
  public static function show($elements = array())
  {
    self::$instance->fill($elements);
    self::$instance->display();
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    global $_wp_theme_features;
    $defaults = array(
      'classes' => array(
        'breadcrumb_class' => '',
        'entry_class' => 'entry',
        'separator_class' => 'separator',
        'last_separator_class' => 'separator',
        'current_class' => 'current'
      ),
      'archive_prefixes' => array(
        'category' => 'Kategorie: ',
        'tag' => 'Thema: ',
        'author' => 'Autor: '
      ),
      'archive_postfixes' => array(
        'category' => '',
        'tag' => '',
        'author' => ''
      ),
      'skip_categories' => array(),
      'shown_taxonomies' => array(),
      'show' => array(
        'home' => true,
        'paging' => false,
        'attachment' => true,
        'taxonomy_before_single' => '', //possible: any registered tax or empty for no element in between.
      ),
      'separator_html' => '&raquo;',
      'title' => array(
        'home' => get_bloginfo('name'),
        'max_length' => 0, //If >0, then the title will be shortend
        'strip_tags' => false, // run strip_Tags on the title (to remove filter added html)
        'error_404' => 'Leider nicht gefunden',
        'page_prefix' => 'Seite '
      ),
      'display_meta_tags' => false,
      'display_name_property' => false,
      'use_post_type_archive_link' => false,
      'link_last_element' => false, //defines if the last/current element has a link
      'classes_on_links' => false //adds the li classes to the a links also, for VERY special purposes
    );

    $settings = $_wp_theme_features['breadcrumb'][0];
    if (is_array($settings) == false) {
      $settings = array();
    }

    $settings = ArrayManipulation::deepMerge($defaults, $settings, $options);

    self::$instance = new Breadcrumb($settings);
  }

  /*
   * Creates the entries of the breadcrumb
   */
  public function fill($elements = array())
  {
    global $post, $wp_query;
    $queried_object = $wp_query->get_queried_object();
    $this->elements = $elements;

    //add Home element
    if ($this->options['show']['home']) {
      $this->elements[] = array(
        'link' => Multilang::isActive() ? Multilang::getHomeUrl() : get_home_url(),
        'title' => $this->options['title']['home'],
        'type' => 'home'
      );
    }

    if (!is_front_page()) {
      // If frontpage, then nothing to do.
      if (is_home()) {
        // Blog-Root but not front page
        $this->fillPagesToBlog();
      } else if (is_tax() || is_category() || is_tag()) {
        /** @var stdClass $tax figure out if this tax is posts, then add path to the blog */
        $tax = get_taxonomy($queried_object->taxonomy);
        if (in_array('post', $tax->object_type)) {
          $this->fillPagesToBlog();
        }
        // Add element for tax
        $this->fillTax($queried_object->taxonomy, $queried_object->term_id);
      } else if (is_date()) {
        // Display a date archive (any)
        $this->fillDate(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'));
      } else if (is_search()) {
        // Search results are displayed
        $this->addElement('search', get_query_var('s'), get_bloginfo('url') . '?s=' . urlencode(get_query_var('s')));
      } else if (is_author()) {
        $this->fillPagesToBlog();
        $this->addElement(
          'author',
          get_user_by('slug', apply_filters('breadcrumb_current_author_slug', get_query_var('author_name')))->display_name,
          get_author_posts_url(get_user_by('slug', apply_filters('breadcrumb_current_author_slug', get_query_var('author_name')))->ID)
        );
      } else if (is_404()) {
        $e404 = $this->options['title']['error_404'];
        if ($e404 == '') {
          $e404 = '404';
        }
        $this->addElement('404', $e404, '');
      } else if (is_attachment()) {
        // An attachment is displayed
        $this->fillBreadcrumbTill($post->post_parent);
        if ($this->options['show']['attachment']) {
          $this->addElement('attachment', $post->post_title, get_permalink($post->ID));
        }
      } else if (is_singular()) {
        // A page, post or a single is displayed
        $this->fillBreadcrumbTill($post->ID);
      } else if (is_archive()) {
        $this->addElement('post_type', $queried_object->label, get_post_type_archive_link($queried_object->name));
      }
    }

    // If this is a paged archive, add an element if feature is active
    if ($this->options['show']['paging']) {
      if (is_paged()) {
        $this->elements[] = array(
          'link' => $_SERVER['REQUEST_URI'],
          'title' => $this->options['title']['page_prefix'] . get_query_var('paged'),
          'type' => 'paging'
        );
      }
    }

    // Remove last element link, if wanted
    if ($this->options['link_last_element'] == false) {
      $this->elements[count($this->elements) - 1]['link'] = '';
    } else if (strlen($this->elements[count($this->elements) - 1]['link']) == 0) {
      // If it should be linked (link_last_element=true) and it's empty, still add a bogus link
      $this->elements[count($this->elements) - 1]['link'] = 'javascript:void(0);';
    }
  }

  /**
   * Create eintries for a date
   * @param int $year
   * @param int $month
   * @param int $day
   */
  protected function fillDate($year, $month = 0, $day = 0)
  {
    global $wp_locale;

    $this->addElement('year', $year, get_year_link($year));

    if ($month != 0) {
      $this->addElement('month', $wp_locale->get_month($month), get_month_link($year, $month));
    }
    if ($day != 0) {
      $this->addElement('day', $day, get_day_link($year, $month, $day));
    }
  }

  /**
   * Displays a hierachically tax
   * @param string $taxonomy
   * @param int $term_id
   */
  protected function fillTax($taxonomy, $term_id)
  {
    $term = get_term($term_id, $taxonomy);
    if ($term->parent != 0) {
      $this->fillTax($taxonomy, $term->parent);
    }

    // Show the taxonomy name, if configured
    if (isset($this->options['shown_taxonomies'][$taxonomy])) {
      $this->addElement('taxonomy_name', $this->options['shown_taxonomies'][$taxonomy], '');
      // just add the taxonomy name once
      unset($this->options['shown_taxonomies'][$taxonomy]);
    }

    $this->addElement($taxonomy, $term->name, get_term_link($term, $taxonomy));
  }

  /**
   * @param int $post_id
   */
  protected function fillBreadcrumbTill($post_id)
  {
    $post = get_post($post_id);
    if ($post->post_type == 'page') {
      // Is an page, so fill the breadcrumb with the parents of the page
      $this->fillPagesWithParents($post->ID);
    } else {
      if ($post->post_type == 'post') {
        // We are in the blog
        $this->fillPagesToBlog();
        switch ($this->options['show']['taxonomy_before_single']) {
          case '': // Do nothing
            break;
          case 'author':
            $this->addElement('author', get_user_by('id', $post->post_author)->display_name, get_author_posts_url($post->post_author));
            break;
          case 'day':
            $this->fillDate(get_the_time('Y', $post->ID), get_the_time('m', $post->ID), get_the_time('d', $post->ID));
            break;
          case 'month':
            $this->fillDate(get_the_time('Y', $post->ID), get_the_time('m', $post->ID), 0);
            break;
          case 'year':
            $this->fillDate(get_the_time('Y', $post->ID), 0, 0);
            break;
          default: // Any tax to add
            $terms = get_the_terms($post->ID, $this->options['show']['taxonomy_before_single']);
            if ($terms != false) {
              foreach ($terms as $term) {
                if (!in_array($term->slug, $this->options['skip_categories'])) {
                  $this->fillTax($this->options['show']['taxonomy_before_single'], $term->term_id);
                  break;
                }
              }
            }
            break;
        }
        $this->addElement('single', get_the_title($post->ID), get_permalink($post->ID));
      } else {
        // We are in an other post type
        $post_type = get_post_type_object($post->post_type);

        // Define the link as default or override with options
        $link = '';
        if ($this->options['use_post_type_archive_link']) {
          $link = get_post_type_archive_link($post->post_type);
        }
        if (isset($this->options['shown_posttypes'][$post_type->name])) {
          $link = $this->options['shown_posttypes'][$post_type->name];
        }

        // Set a default label or use config
        $label = $post_type->label;
        if (isset($this->options['shown_posttypes_label'][$post_type->name])) {
          $label = $this->options['shown_posttypes_label'][$post_type->name];
        }

        $this->addElement('post_type', $label, $link);
        $this->addElement('single', get_the_title($post->ID), get_permalink($post->ID));
      }

    }
  }

  /**
   * Fill pages to blog (?)
   */
  protected function fillPagesToBlog()
  {
    $post_id = get_option('page_for_posts');
    $show_on_front = get_option('show_on_front');
    if (($post_id != 0) && ($show_on_front == 'page')) {
      $this->fillPagesWithParents($post_id);
    }
  }

  /**
   * @param int $post_id
   */
  protected function fillPagesWithParents($post_id)
  {
    $post = get_post($post_id);
    if ($post->post_parent != 0) {
      $this->fillPagesWithParents($post->post_parent);
    }
    $this->addElement('page', get_the_title($post->ID), get_permalink($post_id));
  }

  /**
   * Adds an element to the breadcrumb
   * (should be used internally only)
   * @param string $type (home, page, single, cat, etc.)
   * @param string $title The title of the element
   * @param string $link The link for the element
   */
  public function addElement($type, $title, $link)
  {
    if ($this->options['title']['strip_tags']) {
      $title = strip_tags($title);
    }

    $this->elements[] = array(
      'type' => $type,
      'title' => $title,
      'link' => $link
    );
  }

  /**
   * Displays the breadcrumb
   */
  public function display()
  {
    $defaultClass = 'breadcrumb';

    if (isset($this->options['classes']['without-default-class']) && $this->options['classes']['without-default-class'] === true) {
      $defaultClass = '';
    }

    echo '<ul class="' . $defaultClass . ' ' . $this->options['classes']['breadcrumb_class'] . '" vocab="http://schema.org/" typeof="BreadcrumbList">';
    for ($i = 0; $i < count($this->elements); ++$i) {

      // output Separator
      if ($i > 0 && (!isset($this->options['without-separator']) || $this->options['without-separator'] !== true)) {
        $this->displaySeparator(($i == count($this->elements) - 1));
      }

      // output element
      $this->displayElement($this->elements[$i], ($i == count($this->elements) - 1), $i+1);
    }
    echo '</ul>';
  }

  /**
   * Outputs the html code of a separator element
   * @param boolean $last Adds the class for the last separator or not
   */
  public function displaySeparator($last = false)
  {
    if ($last == true) {
      echo '<li class="' . $this->options['classes']['separator_class'] . '">' . $this->options['separator_html'] . '</li>';
    } else {
      echo '<li class="' . $this->options['classes']['separator_class'] . ' ' . $this->options['classes']['last_separator_class'] . '">' . $this->options['separator_html'] . '</li>';
    }
  }

  /**
   * Outputs the html code for an element
   * @param array $element the data for the element
   * @param boolean $last adds the class for the last element or not
   * @param int $position of the element
   */
  public function displayElement($element, $last = false, $position = 0)
  {
    $class = ' class="' . $this->options['classes']['entry_class'] . ' element_' . $element['type'];
    if ($last == true) {
      $class .= ' ' . $this->options['classes']['current_class'];
    }
    $class .= '"';

    echo '<li' . $class . ' property="itemListElement" typeof="ListItem">';

    $this->displayPrepostfix('prefix', $element['type']);

    if ($element['link'] != '') {
      if ($this->options['classes_on_links'] == false) {
        echo '<a href="' . $element['link'] . '" property="item" typeof="WebPage">';
      } else {
        echo '<a' . $class . ' href="' . $element['link'] . '" property="item" typeof="WebPage">';
      }
    }

    if ($this->options['display_name_property']) echo '<span property="name">';
    $this->displayTitle($element['title']);
    if ($this->options['display_name_property']) echo '</span>';

    if ($element['link'] != '') {
      echo '</a>';
    }

    if ($this->options['display_meta_tags']) {
      echo '<meta property="position" content="' . $position . '">';
    }

    $this->displayPrepostfix('postfix', $element['type']);

    echo '</li>';
  }

  /**
   * Applies rules to the title for any element
   * @param string $title the original title
   */
  public function displayTitle($title)
  {
    if ($this->options['title']['max_length'] == 0) {
      echo $title;
      return;
    }

    $title = String::chopString($title, $this->options['title']['max_length'], true, '...');

    echo $title;
  }

  /**
   * Displays the pre- and postfix for an element type
   * @param <type> $fix
   * @param <type> $type
   */
  public function displayPrepostfix($fix, $type)
  {
    echo $this->options['archive_' . $fix . 'es'][$type];
  }
}
