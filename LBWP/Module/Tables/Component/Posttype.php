<?php

namespace LBWP\Module\Tables\Component;

use LBWP\Util\WordPress;
use LBWP\Helper\Metabox;
use LBWP\Util\File;

/**
 * This class registers the posttype and provides methods for working with it
 * @package LBWP\Module\Tables\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Posttype extends Base
{
  /**
   * @var string the post type slug
   */
  const TABLE_SLUG = 'lbwp-table';
  /**
   * Called after component construction
   */
  public function load()
  {
    if (is_admin()) {
      add_action('media_buttons', array($this, 'addFormButton'), 20);
      add_filter('post_updated_messages', array($this, 'alterSavedMessage'));
      add_action('admin_init', array($this, 'addCellEditorHelper'));
    }
  }

  /**
   * Sets all possible save messages to a "was saved" text, since there's no specific save actions
   */
  public function alterSavedMessage($messages)
  {
    $messages[self::TABLE_SLUG] = array_fill( 1, 10, __('Tabelle wurde gespeichert.', 'lbwp'));
    return $messages;
  }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    WordPress::registerType(self::TABLE_SLUG, 'Tabelle', 'Tabellen', array(
      'show_in_menu' => 'tables',
      'publicly_queryable' => true,
      'exclude_from_search' => true
    ), '');
  }

  /**
   * Displays (but hidden by frontend JS) the editor that is used for handling table cell content
   */
  public function addCellEditorHelper()
  {
    $helper = Metabox::get(self::TABLE_SLUG);
    $helper->addMetabox('table-helper', 'Editor');
    $helper->addEditor('cell-editor', 'table-helper', 'Zelleneditor', 10);
  }

  /**
   * Adds the link to add a form to the page
   */
  public function addFormButton()
  {
    global $current_screen;

    if (
      ($current_screen->base != 'post' && $current_screen->base != 'widgets') ||
      (wp_count_posts(self::TABLE_SLUG)->publish == 0)
    ) {
      return;
    }

    $config = '&name=Tabelle&plural=Tabellen&posttype=lbwp-table&code=lbwp:table';
    $link = File::getViewsUri() . '/module/general/add.php?body_id=media-upload' . $config . '&TB_iframe=1';

    echo '
      <a href="' . $link . '" id="add-form" title="Tabelle einfügen"
        class="thickbox button dashicons-before dashicon-big dashicons-media-spreadsheet"></a>
    ';
  }
} 