<?php

namespace LBWP\Module\Forms\Component;

use LBWP\Util\WordPress;
use LBWP\Helper\Metabox;
use LBWP\Util\File;

/**
 * This class registers the posttype and provides methods for working with it
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Posttype extends Base
{
  /**
   * @var string the post type slug
   */
  const FORM_SLUG = 'lbwp-form';
  /**
   * Called after component construction
   */
  public function load()
  {
    if (is_admin()) {
      add_action('admin_init', array($this, 'addMetaboxes'));
      add_action('admin_head', array($this, 'addResources'));
      add_action('media_buttons', array($this, 'addFormButton'), 20);
      add_filter('post_updated_messages', array($this, 'alterSavedMessage'));
      add_action('manage_' . self::FORM_SLUG . '_posts_custom_column', array($this, 'showModifiedDate'),	10,	2);
      add_filter('manage_' . self::FORM_SLUG . '_posts_columns', array($this, 'addModifiedDateField'));
      add_filter('manage_edit-' . self::FORM_SLUG . '_sortable_columns', array($this, 'addModifiedSortable'));
    }
  }

  /**
   * Sets all possible save messages to a "was saved" text, since there's no specific save actions
   */
  public function alterSavedMessage($messages)
  {
    $messages[self::FORM_SLUG] = array_fill( 1, 10, __('Formular wurde gespeichert.', 'lbwp'));
    return $messages;
  }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    WordPress::registerType(self::FORM_SLUG, 'Formular', 'Formulare', array(
      'show_in_menu' => 'forms',
      'publicly_queryable' => false,
      'exclude_from_search' => true
    ));
  }

  /**
   * Adds the link to add a form to the page
   */
  public function addFormButton()
  {
    global $current_screen;

    if (
      ($current_screen->base != 'post' && $current_screen->base != 'widgets') ||
      (wp_count_posts(self::FORM_SLUG)->publish == 0)
    ) {
      return;
    }

    $config = '&name=Formular&plural=Formulare&posttype=lbwp-form&code=lbwp:formular';
    $link = File::getViewsUri() . '/module/general/add.php?body_id=media-upload' . $config . '&TB_iframe=1';

    echo '
      <a href="' . $link . '" id="add-form" title="Formular einfügen"
        class="thickbox button dashicons-before dashicon-big dashicons-welcome-widgets-menus"></a>
    ';
  }

  /**
   * @param array $columns the columns array
   * @return array $columns with one more field
   */
  public function addModifiedDateField($columns)
  {
    //$columns['modified'] = '<a href="/wp-admin/edit.php?post_type=' . self::FORM_SLUG . '&orderby=modified&order=desc">' . __('Letzte Änderung', 'lbwp') . '</a>';
    $columns['modified'] = __('Letzte Änderung', 'lbwp');
    return $columns;
  }

  /**
   * @param array $columns the columns array
   * @return array $columns with one more field
   */
  public function addModifiedSortable($columns)
  {
    $columns['modified'] = 'post_modified';
    return $columns;
  }

  /**
   * @param string $column the col name
   * @param int $postId the working post
   */
  public function showModifiedDate($column, $postId)
  {
    switch ($column) {
      case 'modified':
        $mOrig = get_post_field('post_modified', $postId, 'raw');
        echo '
          <p class="mod-date">
            ' . date('d.m.Y, H:i', strtotime($mOrig)) . '
          </p>
        ';
    }
  }

  /**
   * Add the metaboxes
   */
  public function addMetaboxes()
  {
    $helper = Metabox::get(self::FORM_SLUG);
    // Add the first step metabox
    $helper->addMetabox('first-step', '1. Schritt - Formular einstellen');
    $helper->addField('info', 'first-step', array(), array($this, 'displayFirstStep'), array($this, 'saveVoidNonMergeable'));
    // Add the second step metabox
    $helper->addMetabox('second-step', '2. Schritt - Felder hinzufügen');
    $helper->addField('info', 'second-step', array(), array($this, 'displaySecondStep'), array($this, 'saveVoidNonMergeable'));
    // Add the second step metabox
    $helper->addMetabox('third-step', '3. Schritt - Aktionen hinzufügen');
    $helper->addField('info', 'third-step', array(), array($this, 'displayThirdStep'), array($this, 'saveVoidNonMergeable'));
    // Add the helper metabody
    $helper->addMetabox('form-shortcode', 'Einbinden', 'side');
    $helper->addHtml('info', 'form-shortcode', '
      <p>Kopiere diesen Shortcode um das Formular einzubinden:</p>
      <input type="text" style="width:99%;" value="' . esc_attr('[' . FormHandler::SHORTCODE_DISPLAY_FORM . ' id="' . $_GET['post'] . '"]') . '" />
    ');
  }

  /**
   * Adds resources only for the post edit backen
   */
  public function addResources()
  {
    global $current_screen;

    // Add functionality only to the post edit page
    if ($current_screen->id == self::FORM_SLUG) {
      $src = File::getResourceUri() . '/js/lbwp-form.js';
      wp_enqueue_script(self::FORM_SLUG, $src, array('jquery'), '1.0');
    }
  }

  /**
   * The first step, creating the form
   */
  public function displayFirstStep()
  {
    echo '
      <p>
        Kopieren Sie den folgenden Code und passen Sie die Parameter nach Wunsch an.
        Danach können sie im 2. Schritt die gewünschten Felder in die leeren Zeilen herein kopieren.
      </p>
      <textarea style="width:100%;height:100px;">' . $this->core->getFormHandler()->getFormExample() . '</textarea>
    ';
  }

  /**
   * The second step, field selection
   */
  public function displaySecondStep()
  {
    $html = '<p>Wählen Sie im Dropdown das gewünschte Feld und kopieren sie den Code. Anschliessend können sie die Parameter anpassen</p>';

    $handler = $this->core->getFormHandler();
    $items = $handler->getItems();

    // Select and first item that does nothing
    $html .= '
      <p>
        <select id="formItemSelect">
          <option value="">Bitte Formular-Feld auswählen</option>
    ';

    foreach ($items as $key => $class) {
      $item = $handler->getItem($key);
      $code = $item->getExampleCode();
      $html .= '
        <option value="' . esc_attr($code) . '">' . $item->get('description') . '</option>
      ';
    }

    $html .= '</select></p>';
    // Textarea for the copiable code
    $html .= '
      <textarea style="width:100%;height:110px;" id="formItemCode"></textarea>
    ';

    echo $html;
  }

  /**
   * The third step, action selection
   */
  public function displayThirdStep()
  {
    $html = '<p>Wählen Sie im Dropdown die gewünschte Aktion und kopieren sie den Code. Anschliessend können sie die Parameter anpassen</p>';

    $handler = $this->core->getFormHandler();
    $actions = $handler->getActions();

    // Select and first item that does nothing
    $html .= '
      <p>
        <select id="formActionSelect">
          <option value="">Bitte Aktion auswählen</option>
    ';

    foreach ($actions as $key => $class) {
      $action = $handler->getAction($key);
      $code = $action->getExampleCode();
      $html .= '
        <option value="' . esc_attr($code) . '">' . $action->getName() . '</option>
      ';
    }

    $html .= '</select></p>';
    // Textarea for the copiable code
    $html .= '
      <textarea style="width:100%;height:110px;" id="formActionCode"></textarea>
    ';

    echo $html;
  }

  /**
   * This is called on save for the metaboxes, but does nothing actually
   */
  public function saveVoidNonMergeable()
  {
    return;
  }
} 