<?php

namespace LBWP\Theme\Feature;
use LBWP\Module\General\AuthorHelper;

/**
 * Replaces the normal author selection box in the backend with a more sophisticated
 * one with the chosen JS to search for an author.
 * @author Michael Sebel <michael@comotive.ch>
 */
class ChosenAuthorBox
{
  /**
   * @var ChosenAuthorBox
   */
  protected static $instance = null;

  /**
   * Lock construction from outside
   */
  protected function __construct() {}

  /**
   * Initialize the object
   */
  public static function init()
  {
    if (self::$instance == null) {
      self::$instance = new ChosenAuthorBox();
      self::$instance->addAction();
    }
  }

  /**
   * Registers the needed actions
   */
  public function addAction()
  {
    // Be sure to do this late, so eventual post types can get registered before
    add_action('admin_init', array($this, 'initializeFeature'), 100);
    add_action('add_meta_boxes', array($this, 'addMetaboxes'), 200);
  }

  /**
   * initializes features only if backend is active
   */
  public function initializeFeature()
  {
    // Load the chosen library
    wp_enqueue_script('chosen-js');
    wp_enqueue_style('chosen-css');
  }

  /**
   * Adds the metaboxes, if in backend
   */
  public function addMetaboxes()
  {
    if (is_admin()) {
      // Add the metabox for all accessible post types
      $types = get_post_types(array('public' => true));
      foreach ($types as $typeKey => $type) {
        remove_meta_box('authordiv', $typeKey, 'normal');
        add_meta_box('chosen-authorbox','Autor', array($this, 'displayBackend'), $typeKey);
      }
    }
  }

  /**
   * @param stdClass $post the post whose metabox is displayed
   */
  public function displayBackend($post)
  {
    $authorId = $post->post_author;
    echo self::displayUserChosen('post_author_override', $authorId);
  }

  /**
   * Returns the html string to display a chosen user field
   *
   * @param string $fieldId HTML id for the select field
   * @param integer $selectedUserId
   * @return string
   */
  public static function displayUserChosen($fieldId, $selectedUserId)
  {
    $html = '<select name="' . $fieldId . '" id="' . $fieldId . '" style="max-width: 300px; width: 100%;">';

    // Add the options and select the current one
    $listEntries = array();
    /** @var \WP_User $author */
    foreach (get_users() as $author) {
      $listEntries[] = array(
        'id' => $author->ID,
        'label' => $author->data->display_name
      );
    }

    // Modify the list
    $listEntries = apply_filters('user_chosen_list_entries', $listEntries);

    foreach ($listEntries as $listEntry) {
      $selected = selected($selectedUserId, $listEntry['id'], false);
      $html .= '<option value="' . $listEntry['id'] . '"' . $selected . '>' . $listEntry['label'] . '</option>' . PHP_EOL;
    }

    $html .= '</select>';

    // Now add the javascript that makes it a chosen
    $html .= '
      <script type="text/javascript">
        jQuery(function($) {
          $("#' . $fieldId . '").chosen();
        });
      </script>
    ';

    return $html;
  }
}