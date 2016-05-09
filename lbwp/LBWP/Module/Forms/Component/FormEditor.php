<?php

namespace LBWP\Module\Forms\Component;

use LBWP\Core as LbwpCore;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\String;
use LBWP\Util\WordPress;

/**
 * The Form editor base component
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class FormEditor extends Base
{
  /**
   * @var string template for inline frames (form / action editor)
   */
  protected $inlineTemplate = '
    <div class="frame frame-left">{frameLeft}</div>
    <div class="frame frame-middle">{frameMiddle}</div>
    <div class="frame frame-right">{frameRight}</div>
  ';
  /**
   * @var string template for full width (settings)
   */
  protected $fullTemplate = '
    <div class="frame frame-full">{frameFull}</div>
  ';
  /**
   * @var string the template for actions/formfields
   */
  protected $draggableTemplate = '
    <div class="draggable-item {classes}" data-key="{key}" data-title="{title}">
      {draggableName}
    </div>
  ';

  /**
   * Called at init(50), for now only executed in local development
   */
  public function initialize()
  {
    // UI functions and assets are only needed on the actual edit page
    if (is_admin() && $this->isFormEditPage() && !isset($_GET['devmode'])) {
      $this->prepareInterface();
      $this->registerAssets();
    }

    // Save functions and ajax is needed in admin generally
    if (is_admin()) {
      add_action('wp_insert_post_data', array($this->core->getFormHandler(), 'saveEditorForm'));
      add_action('wp_ajax_getInterfaceHtml', array($this, 'getInterfaceHtml'));
      add_action('wp_ajax_updateFormHtml', array($this, 'updateFormHtml'));
    }
  }

  /**
   * Prepares the interface by removing / adding things from the view
   */
  protected function prepareInterface()
  {
    remove_post_type_support(Posttype::FORM_SLUG, 'editor');
    add_action('admin_footer', array($this, 'provideTranslations'));
  }

  /**
   * Add the needed css and js assets / libraries
   */
  protected function registerAssets()
  {
    // First, load all JS used for the form editor
    $baseUrl = File::getResourceUri() . '/js/form-editor';
    wp_enqueue_script('lbwp-form-editor-core', $baseUrl . '/LbwpFormEditor.Core.js', array('jquery'), LbwpCore::REVISION);
    wp_enqueue_script('lbwp-form-editor-interface', $baseUrl . '/LbwpFormEditor.Interface.js', array('lbwp-form-editor-core'), LbwpCore::REVISION);
    wp_enqueue_script('lbwp-form-editor-form', $baseUrl . '/LbwpFormEditor.Form.js', array('lbwp-form-editor-interface'), LbwpCore::REVISION);
    wp_enqueue_script('lbwp-form-editor-action', $baseUrl . '/LbwpFormEditor.Action.js', array('lbwp-form-editor-interface'), LbwpCore::REVISION);
    wp_enqueue_script('lbwp-form-editor-settings', $baseUrl . '/LbwpFormEditor.Settings.js', array('lbwp-form-editor-interface'), LbwpCore::REVISION);
    wp_enqueue_script('jquery-cookie');
    wp_enqueue_script('jquery-ui-draggable');
    wp_enqueue_script('jquery-ui-droppable');
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('jquery-ui-accordion');

    // Ditch on possible jquery ui css
    add_action('admin_enqueue_scripts', array($this, 'preventJqueryUiLoading'));

    // Also load the CSS machine
    $baseUrl = File::getResourceUri() . '/css/form-editor';
    wp_enqueue_style('lbwp-form-editor-css', $baseUrl . '/app.css', array(), LbwpCore::REVISION);

    // Special handling for frontend html
    add_action('admin_footer', array($this->core->getFormHandler(), 'addAssetReplaceable'));
    $this->core->getFormHandler()->addFormAssets();
  }

  /**
   * Prevent the loading of jquery ui themes
   */
  public function preventJqueryUiLoading()
  {
    wp_dequeue_style('jquery-ui-theme-lbwp');
    wp_dequeue_style('jquery-ui-style');
    wp_enqueue_style('jquery-ui-css');
    wp_enqueue_style('jquery-ui');
  }

  /**
   * @return bool if this is a form edit page
   */
  protected function isFormEditPage()
  {
    $post = WordPress::guessCurrentPost();
    return $_GET['action'] == 'edit' && $post->post_type == Posttype::FORM_SLUG || $_GET['post_type'] == Posttype::FORM_SLUG;
  }

  /**
   * Provides translations strings for the JS UI
   */
  public function provideTranslations()
  {
    echo '
      <script type="text/javascript">
        if (typeof(LbwpFormEditor) == "undefined") { var LbwpFormEditor = {}; }
        // Add text resources
        LbwpFormEditor.Text = {
          saveButton : "' . esc_js(__('Formular Speichern', 'lbwp')) . '",
          createNewForm : "' . esc_js(__('Neues Formular erstellen', 'lbwp')) . '",
          editorLoading : "' . esc_js(__('Einen Moment. Der Editor wird geladen.', 'lbwp')) . '",
          confirmDelete : "' . esc_js(__('Wollen sie dieses Element wirklich löschen?', 'lbwp')) . '",
          conditionText : "' . esc_js(__('Sie können diese Action unter definierten Umständen ausführen:', 'lbwp')) . '",
          conditionAndSelection : "' . esc_js(__('Alle Konditionen müssen zutreffen', 'lbwp')) . '",
          conditionOrSelection : "' . esc_js(__('Eine Kondition muss zutreffen', 'lbwp')) . '",
          conditionValue : "' . esc_js(__('Wert', 'lbwp')) . '",
          conditionField : "' . esc_js(__('Formularfeld', 'lbwp')) . '",
          conditionAdd : "' . esc_js(__('Kondition hinzufügen', 'lbwp')) . '",
          deletedAction : "<p>' . esc_js(__('Die Aktion wurde gelöscht.', 'lbwp')) . '</p>",
          deletedField : "<p>' . esc_js(__('Das Feld wurde gelöscht.', 'lbwp')) . '</p>",
          useFromFieldHeading : "' . esc_js(__('Formular-Feld verwenden', 'lbwp')) . '",
          useFromFieldText : "' . esc_js(__('Klicken Sie auf ein Formular-Feld um dessen Inhalt für das gewählte Aktionsfeld zu verwenden', 'lbwp')) . '",
          addOption : "' . esc_js(__('Option hinzufügen', 'lbwp')) . '"
        };
      </script>
    ';
  }

  /**
   * Returns new html form a form json object by converting to a shortcode, and executing it
   */
  public function updateFormHtml()
  {
    $handler = $this->core->getFormHandler();
    $shortcode = $handler->convertFormJsonToShortcode(trim($_REQUEST['formJson']));
    $data = $this->core->getFormHandler()->getFormEditData($shortcode);

    // Return as JSON response
    WordPress::sendJsonResponse(array(
      'formHtml' => $this->transformEditorForm($data['formHtml']),
      'formJsonObject' => $data['formJsonObject']
    ), JSON_PRETTY_PRINT);
  }

  /**
   * Ajax Callback to return the interface HTML block
   */
  public function getInterfaceHtml()
  {
    $formId = intval($_REQUEST['formId']);

    $html = '
      <div class="tabbed-navigation">
        <h2 class="nav-tab-wrapper" id="form-editor-tabs">
          <a class="nav-tab" id="form-tab" data-tab-id="#editor-form-tab">' . __('Formular', 'lbwp') . '</a>
          <a class="nav-tab" id="action-tab" data-tab-id="#editor-action-tab">' . __('Aktionen', 'lbwp') . '</a>
          <a class="nav-tab" id="settings-tab" data-tab-id="#editor-settings-tab">' . __('Einstellungen', 'lbwp') . '</a>
        </h2>
      </div>
      <div class="tab-container">
        <div class="form-editor-tab" id="editor-form-tab">
          ' . $this->getFormTabHtml() . '
        </div>
        <div class="form-editor-tab" id="editor-action-tab">
          ' . $this->getActionTabHtml() . '
        </div>
        <div class="form-editor-tab" id="editor-settings-tab">
          ' . $this->getSettingsTabHtml() . '
        </div>
      </div>
      <span class="data-containers">
        <textarea name="formJson" id="formJson"></textarea>
      </span>
    ';

    // Try getting the form object information from the forms current shortcode
    $shortcode = get_post($formId)->post_content;
    $data = $this->core->getFormHandler()->getFormEditData($shortcode);

    // Try using a template, if the form is empty for some reason
    if (!$data['hasFormItems']) {
      $shortcode = $this->integrateTemplate($shortcode);
      $data = $this->core->getFormHandler()->getFormEditData($shortcode);
    }

    // Return as JSON response
    WordPress::sendJsonResponse(array(
      'content' => $html,
      'shortcode' => $shortcode,
      'hasFormData' => $data['hasFormData'],
      'formHtml' => $this->transformEditorForm($data['formHtml']),
      'formJsonObject' => $data['formJsonObject']
    ), JSON_PRETTY_PRINT);
  }

  /**
   * @param string $shortcode without items
   * @return string the shortcode with an integrated form items template
   */
  protected function integrateTemplate($shortcode)
  {
    // If the shortcode is empty, create a new form element
    if (strlen(trim($shortcode)) == 0) {
      $shortcode = '
        [' . FormHandler::SHORTCODE_FORM . ' button="' . __('Absenden', 'lbwp') . '"]

        [/' . FormHandler::SHORTCODE_FORM . ']
      ';
    }

    // templateId is not yet handled, it's just a function to be completes with actual templates
    switch ($_REQUEST['templateId']) {
      default:
        $template = '
          [lbwp:formItem key="textfield" pflichtfeld="ja" feldname="Beispiel-Feld" type="text"]
        ';
    }

    // Integrate that template to the shortcode (at bottom)
    $endTag = '[/' . FormHandler::SHORTCODE_FORM . ']';
    return str_replace(
      $endTag,
      $template . PHP_EOL . $endTag,
      $shortcode
    );
  }

  /**
   * Transform the form html for editor output
   * @param string $html
   * @return string altered form $html
   */
  protected function transformEditorForm($html)
  {
    return str_replace(
      array('<form ', '</form>'),
      array('<div ', '</div>'),
      $html
    );
  }

  /**
   * @return string html code to represent a tab
   */
  protected function getFormTabHtml()
  {
    $html = $this->inlineTemplate;

    // Define an initial help message
    $middleMessage = '<div class="lbwp-form-preview"></div>';

    // Replate the left frame with form fields
    $html = str_replace('{frameLeft}', $this->getFormFieldBoxes(), $html);
    $html = str_replace('{frameMiddle}', $middleMessage, $html);
    $html = str_replace('{frameRight}', $this->getFormSettingsMessage(), $html);

    return $html;
  }

  /**
   * Form field boxes generated from the field configurations
   * @return string html for form field boxes
   */
  protected function getFormFieldBoxes()
  {
    // Get a associative array of all field groups
    $fieldGroups = array();
    $handler = $this->core->getFormHandler();
    foreach ($handler->getItems() as $key => $class) {
      $item = $handler->createItem($key);
      $config = $item->getFieldConfig();
      $fieldGroups[$config['group']][] = array(
        'key' => $key,
        'help' => $config['help'],
        'name' => $config['name']
      );
    }

    // Get the draggable items
    return $this->getDraggableItemsHtml($fieldGroups, 'field');
  }

  /**
   * Creates a post box list of draggables with a certain prefix
   * @param array $groups the groups with items
   * @param string $prefix the class prefix
   * @return string html code
   */
  protected function getDraggableItemsHtml($groups, $prefix)
  {
    $html = '';
    // Create html blocks
    foreach ($groups as $groupKey => $items) {
      $html .= '<div class="postbox">';
      $html .= '<h3 class="hndle"><span>' . $groupKey . '</span></h3>';
      $html .= '<div class="inside available-fields">';

      // Display the items
      foreach ($items as $item) {
        // Set the draggable name including help text
        $draggableName = '<strong>' . $item['name'] . '</strong>';
        if (strlen($item['help']) > 0) {
          $draggableName .= '<br />' . $item['help'];
        }
        // Replace the template values
        $itemHtml = str_replace('{draggableName}', $draggableName, $this->draggableTemplate);
        $itemHtml = str_replace('{classes}', $prefix . '-draggable ' . $prefix . '-' . $item['key'], $itemHtml);
        $itemHtml = str_replace('{key}', $item['key'], $itemHtml);
        $itemHtml = str_replace('{title}', __('Feld Titel', 'lbwp'), $itemHtml);
        $html .= $itemHtml;
      }

      // Close all opened blocks
      $html .= '</div></div>';
    }

    return $html;
  }

  /**
   * @return string html with help text until the first item is used
   */
  protected function getFormSettingsMessage()
  {
    return '
      <div class="postbox">
        <h3 class="hndle"><span>' . __('Feld bearbeiten', 'lbwp') . '</span></h3>
        <div class="inside field-settings">
          <p>' . __('So funktioniert\'s:', 'lbwp') . '</p>
          <ol>
            <li>' . __('Von der linken Spalte Felder ins Formular ziehen.', 'lbwp') . '</li>
            <li>' . __('Felder anklicken und hier die Einstellungen bearbeiten.', 'lbwp') . '</li>
          </ol>
			  </div>
	    </div>
    ';
  }

  /**
   * @return string html code to represent a tab
   */
  protected function getActionTabHtml()
  {
    $html = $this->inlineTemplate;

    // Define an initial help message
    $middleMessage = '
      <div class="lbwp-action-list">
        <p class="help-message">
          ' . __('Ziehen Sie die erste Aktion hierhin um die Formular-Daten zu verarbeiten.', 'lbwp') . '
        </p>
      </div>
    ';

    // Replate the left frame with form fields
    $html = str_replace('{frameLeft}', $this->getActionBoxes(), $html);
    $html = str_replace('{frameMiddle}', $middleMessage, $html);
    $html = str_replace('{frameRight}', $this->getActionSettingsMessage(), $html);

    return $html;
  }

  /**
   * Form action boxes generated from the action configurations
   * @return string html for action field boxes
   */
  protected function getActionBoxes()
  {
    // Get a associative array of all field groups
    $actionGroups = array();
    $handler = $this->core->getFormHandler();
    foreach ($handler->getActions() as $key => $class) {
      $item = $handler->createAction($key);
      $config = $item->getActionConfig();
      $actionGroups[$config['group']][] = array(
        'key' => $key,
        'help' => $config['help'],
        'name' => $config['name']
      );
    }

    // Get the draggable items
    return $this->getDraggableItemsHtml($actionGroups, 'action');
  }

  /**
   * @return string html with help text until the first item is used
   */
  protected function getActionSettingsMessage()
  {
    return '
      <div class="postbox">
        <h3 class="hndle"><span>' . __('Aktion bearbeiten', 'lbwp') . '</span></h3>
        <div class="inside field-settings">
          <p>' . __('So funktioniert\'s:', 'lbwp') . '</p>
          <ol>
            <li>' . __('Von der linken Spalte Aktionen in die Mitte ziehen.', 'lbwp') . '</li>
            <li>' . __('Aktion anklicken und hier die Einstellungen bearbeiten.', 'lbwp') . '</li>
          </ol>
			  </div>
			  <h3 class="hndle hndle-conditions"><span>' . __('Konditionen bearbeiten', 'lbwp') . '</span></h3>
			   <div class="inside field-conditions"></div>
	    </div>
    ';
  }

  /**
   * @return string html code to represent a tab
   */
  protected function getSettingsTabHtml()
  {
    $html = $this->fullTemplate;

    $settings = '
      <div class="settings">
        <p><strong>' . __('Nach erfolgreichem absenden', 'lbwp') . '</strong></p>
        <label>
          <input type="radio" name="sent" value="weiterleitung" id="redir" checked="checked">'. __('Weiterleiten nach', 'lbwp') . '
        </label>
        ' . $this->getPagesDropdown() . '<br>
        <label>
          <input type="radio" name="sent" value="meldung" id="msg">'.  __('Meldung anzeigen', 'lbwp'). '
        </label>
        <textarea rows="4" name="message" id="messageBox"></textarea>
        <p><strong>' . __('Formular-Einstellungen', 'lbwp') . '</strong></p>
        <input type="checkbox" name="after_submit" id="once">' . __('Benutzer können das Formular nur einmal ausfüllen.', 'lbwp') . '<br>
        <label>' . __('Nachricht, wenn Bereits ausgefüllt', 'lbwp') . '</label><textarea rows="4" name="onceMessage" id="onceMessage"></textarea><br><br>
        <label>' . __('Text des Absenden Buttons', 'lbwp') . '</label><input type="text" name="button" id="button" value="Absenden">
      </div>
    ';

    // Replace template and return
    $html = str_replace('{frameFull}', $settings, $html);
    return $html;
  }

  /**
   * @return string the pages dropdown in all languages
   */
  protected function getPagesDropdown()
  {
    // Start the select element
    $html = '<select name="page_id" id="page_id">';
    $args = array('echo' => false, 'value_field' => 'id');

    // Get pages (either normally, or multilang version
    if (Multilang::isActive()) {
      foreach (Multilang::getAllLanguages() as $language) {
        $args['lang'] = $language;
        $dropdownHtml = wp_dropdown_pages($args);
        $html .= '<optgroup label="' . Multilang::getLanguageName($language) . '">' . String::xpath($dropdownHtml, '//option', false) . '</optgroup>';
      }
    } else {
      $dropdownHtml = wp_dropdown_pages($args);
      $html .= String::xpath($dropdownHtml, '//option', false);
    }

    // End the select and return
    $html .= '</select>';
    return $html;
  }
} 