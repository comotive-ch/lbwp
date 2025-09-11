<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\WordPress;

/**
 * Checks for posts that aren't gutenberg yet
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class GutenCheck
{
  /**
   * Default Settings for the pagenavi
   * @var array
   */
  protected $settings = array(
    'postTypes' => array('post'),
  );
  /**
   * @var \wpdb the wordpress db object
   */
  protected $wpdb = NULL;
  /**
   * @var GutenCheck the sticky post config object
   */
  protected static $instance = NULL;


  /**
   * Can only be instantiated by calling init method
   * @param array|null $settings overriding defaults
   */
  protected function __construct($settings = NULL)
  {
    if (is_array($settings)) {
      $this->settings = array_merge($this->settings, $settings);
    }

    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /**
   * Initialise while overriding settings defaults
   * @param array|null $settings overrides defaults as new default
   */
  public static function init($settings = NULL)
  {
    self::$instance = new GutenCheck($settings);
    self::$instance->load();
  }

  /**
   * Loads/runs the needed actions and functions
   */
  protected function load()
  {
    add_action('admin_menu', array($this, 'addMenuPage'), 400);
  }

  /**
   * Adds the menu page
   */
  public function addMenuPage()
  {
    add_submenu_page(
      'options-general.php',
      'GutenCheck',
      'GutenCheck',
      'administrator',
      'gutencheck-posts',
      array($this, 'displayPosts')
    );
  }

  /**
   * Display posts that are not gutenberg
   */
  public function displayPosts()
  {
    $html = '';
    // <!-- wp:
    $db = WordPress::getDb();
    $results  = $db->get_results('
      SELECT ID, post_title, post_modified FROM ' . $db->posts . '
      WHERE LEFT(post_content, 8) != "<!-- wp:"
      AND post_type IN("' . implode('","', $this->settings['postTypes']) . '")
      AND post_status NOT IN("trash", "auto-draft", "inherit")
      ORDER BY post_modified DESC LIMIT 0,500
    ', ARRAY_A);

    $html .= '
      <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
          <tr>
            <td>ID</td>
            <td>Titel</td>
            <td>Letzte Änderung</td>
            <td>Link</td>
          </tr>
        </thead>
        <tbody>
    ';

    foreach ($results as $result) {
      $html .= '
        <tr>
          <td>' . $result['ID'] . '</td>
          <td>' . $result['post_title'] . '</td>
          <td>' . $result['post_modified'] . '</td>
          <td><a href="/wp-admin/post.php?post=' . $result['ID'] . '&action=edit">Bearbeiten</a></td>
        </tr>
      ';
    }

    $html .= '</tbody></table>';

    // Print the output
    echo '
      <div class="wrap">
        <div id="icon-edit-pages" class="icon32"><br /></div>
        <h2>Gutenberg Check</h2>
        <p>Zeigt alle Beiträge an, die noch nicht für Gutenberg konvertiert wurden.</p>
        ' . $html . '
      </div>
    ';
  }
}