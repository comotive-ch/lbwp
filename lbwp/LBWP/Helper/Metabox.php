<?php

namespace LBWP\Helper;

// Basic framework dependencies
use wpdb;
use LBWP\Util\Strings;
use LBWP\Util\Date;
use LBWP\Helper\MetaItem\SimpleField;
use LBWP\Helper\MetaItem\Templates;
// Various sub items for complex helpers
use LBWP\Helper\MetaItem\CrossReference;
use LBWP\Helper\MetaItem\PostTypeDropdown;
use LBWP\Helper\MetaItem\AddressLocation;
use LBWP\Helper\MetaItem\ChosenDropdown;
use LBWP\Helper\MetaItem\NativeUpload;

/**
 * This helper can be instantiated to add one or more metaboxes to a posttype,
 * adding fields of different types to it, and it handels saving for these
 * field automatically. Displaying is handled globally by a CSS singleton.
 * @author Michael Sebel <michael@comotive.ch>
 * @author Tom Forrer <tom.forrer@gmail.com>
 */
class Metabox
{
  /**
   * @var wpdb the database connection if needed
   */
  protected $wpdb;
  /**
   * @var array the fields to work with
   */
  protected $fields = array();
  /**
   * @var array the metabox configs
   */
  protected $metaboxes = array();
  /**
   * @var array used while saving. Stores the error messages
   */
  protected $errors = array();
  /**
   * @var array those fields can be used directly for text fields
   */
  protected $knownPostFields = array('post_content', 'post_excerpt');
  /**
   * @var array types that can't use the metabox helper because they have their own UI
   */
  protected $forbiddenTypes = array('lbwp-form');
  /**
   * @var string the identifier, to get the metabox instance by post type
   */
  protected $posttype = '';
  /**
   * @var int used for the lines counter in addLine
   */
  protected $lineId = 0;
  /**
   * @var array an array of all metabox helper instances
   */
  protected static $instances = array();
  /**
   * @var int the version of this class
   */
  const VERSION = 17;
  /**
   * @var string merged fields metabox id
   */
  const MERGED_METABOX_ID = 'merged-fields';

  /**
   * Creates the helper object
   * @throws Exception if the id is already used
   */
  protected function __construct($posttype)
  {
    global $wpdb;
    $this->posttype = $posttype;
    $this->wpdb = $wpdb;

    Templates::setTemplates();

    // If this is the first instance, register js/css files for enqueuement
    if (count(self::$instances) == 0) {
      $this->enqueueAssets();
    }

    // Merge sections, then save to postmeta, to assure only the correct fields are saved
    add_action('save_post_' . $posttype, array($this, 'filterMetaboxes'), 50);
    add_action('save_post_' . $posttype, array($this, 'mergeSections'), 100);
    add_action('save_post_' . $posttype, array($this, 'saveMetabox'), 150);
    // Before displaying, merge eventually multiply registered fields into one visible field
    add_action('add_meta_boxes_' . $posttype, array($this, 'filterMetaboxes'), 50);
    add_action('add_meta_boxes_' . $posttype, array($this, 'mergeSections'), 100);
    add_action('add_meta_boxes_' . $posttype, array($this, 'addMetaboxes'), 150);
    // Generic ajax actions and listeners (just in case it is used)
    add_action('wp_ajax_newPostTypeItem', array('\LBWP\Helper\MetaItem\PostTypeDropdown', 'addNewPostTypeItem'));
    add_action('wp_ajax_trashAndRemoveItem', array('\LBWP\Helper\MetaItem\PostTypeDropdown', 'trashAndRemoveItem'));
  }

  /**
   * @param string $id the id of the metabox (must be unique within the helper)
   * @param string $title the title of the metabox
   * @param string $context the context (normal, advanced (default))
   * @param string $priority the priority (default, high, core)
   * @param bool $force creating of a new metabox, flushing an existing one
   */
  public function addMetabox($id, $title, $context = 'normal', $priority = 'default', $force = true)
  {
    // Create the metabox array item
    if (!isset($this->metaboxes[$id]) || $force) {
      $this->metaboxes[$id] = array(
        'title' => $title,
        'context' => $context,
        'priority' => $priority
      );
      // Prepare the field namespace
      $this->fields[$id] = array();
    }
  }

  /**
   * Remove a registered but not yet displayed metabox
   * @param string $id the id of the metabox
   */
  public function removeMetabox($id)
  {
    unset($this->metaboxes[$id]);
  }

  /**
   * Actually add the registered metaboxes
   */
  public function addMetaboxes()
  {
    foreach ($this->metaboxes as $id => $config) {
      add_meta_box(
        $this->posttype . '__' . $id,
        $config['title'],
        array($this, 'displayBox'),
        $this->posttype,
        $config['context'],
        $config['priority'],
        $id);
    }
  }

  /**
   * Callback used internally to display a metabox with a certain boxId
   * @param \WP_Post $post the post object on which the metabox is placed
   * @param string $id the boxId used at addMetabox
   */
  public function displayBox($post, $id)
  {
    $html = '';

    // Error messages?
    if (is_array($_SESSION['metabox_errors_' . $this->posttype][$id['args']])) {
      $html .= '<ul class="mbh-error-list">';
      foreach ($_SESSION['metabox_errors_' . $this->posttype][$id['args']] as $message) {
        $html .= '<li>' . $message . '</li>';
        unset($_SESSION['metabox_errors_' . $this->posttype][$id['args']]);
      }
      $html .= '</ul>';
    }

    // Display the fields
    if (is_array($this->fields[$id['args']]) && count($this->fields[$id['args']]) > 0) {
      foreach ($this->fields[$id['args']] as $field) {
        $field['args']['post'] = $post;
        $html .= call_user_func($field['display'], $field['args']);
      }
    }

    echo $html;
  }

  /**
   * Save all registered fields
   * @param int $postId the post it that's being saved
   */
  public function saveMetabox($postId)
  {
    // First, have a look if we're able to save
    if (
      // Don't save on auto save as there is not data sent
      defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
      // Also, don't save on bulk edit, no data as well
      isset($_REQUEST['bulk_edit']) ||
      (
        // Don't save on inline, trashing or untrashing, there's no meta data
        isset($_REQUEST['action']) && (
          $_REQUEST['action'] == 'inline-save' ||
          $_REQUEST['action'] == 'trash' ||
          $_REQUEST['action'] == 'untrash'
        )
      )
    ) {
      return;
    }

    // If we save a revision, change the revision post id to the original, to make the preview work
    if ($origPostId = wp_is_post_revision($postId)) {
      $postId = $origPostId;
    }

    foreach ($this->fields as $box => $fields) {
      foreach ($fields as $field) {
        callUserFunctionWithSafeArguments($field['save'], array($postId, $field, $box));
      }
    }

    if (count($this->errors) > 0) {
      $_SESSION['metabox_errors_' . $this->posttype] = $this->errors;
    }
  }

  /**
   * Enqueue the assets if needed (only on edit dialogs)
   */
  protected function enqueueAssets()
  {
    // Only include in post.php and post-new.php
    if (Strings::startsWith($_SERVER['SCRIPT_NAME'], '/wp-admin/post')) {
      wp_enqueue_script(
        'metabox-helper-backend-js',
        '/wp-content/plugins/lbwp/resources/js/metabox-helper.js',
        array('jquery'),
        self::VERSION
      );
      wp_enqueue_script(
        'metabox-helper-image-uploader-js',
        '/wp-content/plugins/lbwp/resources/js/jquery-wpattachment.js',
        array('jquery', 'media-upload', 'media-models'),
        self::VERSION
      );
      wp_enqueue_style(
        'media-uploader-css',
        '/wp-content/plugins/lbwp/resources/css/media-uploader.css',
        array(),
        self::VERSION
      );
      wp_enqueue_style(
        'metabox-helper-backend-css',
        '/wp-content/plugins/lbwp/resources/css/metabox-helper.css',
        array(),
        self::VERSION
      );

      add_action('admin_enqueue_scripts', function () {
        global $post_type;
        if (!in_array($post_type, $this->forbiddenTypes)) {
          wp_enqueue_script('chosen-js');
          wp_enqueue_script('chosen-sortable-js');
          wp_enqueue_style('chosen-css');
          wp_enqueue_style('jquery-ui-theme-lbwp');
        }
      });

      // Allow to use modals
      add_action('admin_footer', array($this, 'allowCoreModalIframe'));
    }
  }

  /**
   * @param string $key the item to find
   * @param string $boxId the box id
   * @param array $field field configuration
   * @return bool true/false if it worked to replace the field
   */
  public function replaceField($key, $boxId, $field)
  {
    foreach ($this->fields[$boxId] as $index => $current) {
      if ($current['key'] == $key) {
        $this->fields[$boxId][$index] = $field;
        return true;
      }
    }

    return false;
  }

  /**
   * @param string $key the item to remove
   * @param string $boxId the box id
   * @return bool true, if removed
   */
  public function removeField($key, $boxId)
  {
    foreach ($this->fields[$boxId] as $index => $current) {
      if ($current['key'] == $key) {
        unset($this->fields[$boxId][$index]);
        return true;
      }
    }

    return false;
  }

  /**
   * Let developers filter the metaboxes before displaying and saving them
   */
  public function filterMetaboxes()
  {
    global $post;
    $this->metaboxes = apply_filters('filter_metabox_helper_' . $this->posttype, $this->metaboxes, $post, $this);
  }

  /**
   * This function parses all fields in boxes. If two or more metaboxes register for the same field,
   * only one needs to be shown and saved to the post actually. This will create a new metabox on
   * top of all other metaboxes with the merged fields and an additional info field.
   */
  public function mergeSections()
  {
    $removedMetaboxes = array();
    $allFields = array();
    $indistinctFields = array();

    // First find all names that need to bee merged, and continue only if there are
    foreach ($this->metaboxes as $id => $metabox) {
      foreach ($this->fields[$id] as $fieldId => $field) {
        // Only add, if it isn't a sole informational field
        if (!isset($field['args']['html']) && !in_array($field['key'], array('info'))) {
          if (!isset($allFields[$field['key']])) {
            $allFields[$field['key']] = true;
          } else {
            // Remove the field from the current array
            unset($this->fields[$id][$fieldId]);
            // Add it to the new indistinct fiales
            $removedMetaboxes[$id] = $this->metaboxes[$id]['title'];
            $indistinctFields[$field['key']] = $field;
          }
        }
      }
    }

    // Iterate again to sort out all first matches of indistinct fields
    foreach ($this->metaboxes as $id => $metabox) {
      foreach ($this->fields[$id] as $fieldId => $field) {
        if (isset($indistinctFields[$field['key']])) {
          $removedMetaboxes[$id] = $this->metaboxes[$id]['title'];
          unset($this->fields[$id][$fieldId]);
        }
      }
    }

    // Remove metaboxes that are now empty
    foreach ($this->metaboxes as $id => $metabox) {
      // Make a copy of the fields
      $fields = $this->fields[$id];
      foreach ($fields as $key => $field) {
        switch ($field['save'][1]) {
          case 'saveVoid':
          case 'void':
            unset($fields[$key]);
        }
      }

      // If there are no fields anymore, remove metabox
      if (count($fields) == 0) {
        unset($this->metaboxes[$id]);
      }
    }

    // Create new metabox from merged fields, if available
    if (count($indistinctFields) > 0) {
      // Create a new metabox with the indistinct fields
      $this->addMetabox(self::MERGED_METABOX_ID, __('Zusammengeführte Felder', 'lbwp'), 'normal');
      $this->addHtml('info', self::MERGED_METABOX_ID, '
        <p>' . __('Es wurden gleichnamige Felder aus folgenden Dialogen zusammengeführt: ', 'lbwp') . '</p>
        <p><em>' . implode(', ', $removedMetaboxes) . '</em></p>
      ');
      foreach ($indistinctFields as $field) {
        $this->addFieldObject($field, self::MERGED_METABOX_ID);
      }
    }
  }

  /**
   * Search for a field in a box an return it
   * @param string $key the item to find
   * @param string $boxId the box id
   * @return bool|array field configuration
   */
  public function getField($key, $boxId)
  {
    foreach ($this->fields[$boxId] as $field) {
      if ($field['key'] == $key) {
        return $field;
      }
    }

    return false;
  }

  /**
   * @param string $boxId the box id
   * @return array full list of registered fields of a metabox
   */
  public function getBoxFields($boxId)
  {
    return $this->fields[$boxId];
  }

  /**
   * @param string $boxId the box id
   * @return bool true, if there are fields in this box
   */
  public function hasMetaboxFields($boxId)
  {
    return count($this->getBoxFields($boxId)) > 0;
  }

  /**
   * @return array of all fields / metabox combos
   */
  public function getFields()
  {
    return $this->fields;
  }

  /**
   * @return array of all metaboxes
   */
  public function getMetaboxes()
  {
    return $this->metaboxes;
  }

  /**
   * @param string $postType the id of the metabox helper to get
   * @return Metabox the instance of the helper
   */
  public static function get($postType)
  {
    if (!isset(self::$instances[$postType])) {
      self::$instances[$postType] = new self($postType);
    }
    return self::$instances[$postType];
  }

  /**
   * @return string html code to hide editor but keep upload intact
   */
  protected static function getHideEditorCss()
  {
    return '
      <style type="text/css">
        #postdivrich { display:none; }
      </style>
    ';
  }

  /**
   * @param array $args field arguments
   * @return string the value, if given
   */
  protected function getTextFieldValue($args)
  {
    return SimpleField::getTextFieldValue($args, $this->knownPostFields);
  }

  /**
   * Returns the width for the field using (and fixing) the width parameter
   * @param string $args the arguments of the displayed fields
   * @return string the width string in percent or px
   */
  public function getWidth($args)
  {
    return SimpleField::getWidth($args);
  }

  /**
   * Gets the template based on argument configuration
   * @param array $args the arguments given the display function
   * @param string $key the key to identify the post
   * @param string $template a template if a specific is wanted
   * @return string HTML code
   */
  public function getTemplate($args, $key, $template = '')
  {
    return Templates::get($args, $key, $template);
  }

  /**
   * @param string $boxId the box id in which to store the error
   * @param string $text the error message
   */
  public function addError($boxId, $text)
  {
    if (!isset($this->errors[$boxId])) {
      $this->errors[$boxId] = array();
    }
    $this->errors[$boxId][] = $text;
  }

  /**
   * @param string $key the key to use as meta key in the postmeta table
   * @param string $boxId the $id given in addMetabox
   * @param array $args the arguments to provide to the callbacks
   * @param callable $displayCb is called on displaying the metabox
   * @param callable $saveCb is called on saving the metabox information
   */
  public function addField($key, $boxId, array $args, $displayCb, $saveCb)
  {
    // Put the key into the args and save the field for later display/save
    $args['key'] = $key;
    $this->fields[$boxId][] = array(
      'key' => $key,
      'args' => $args,
      'display' => $displayCb,
      'save' => $saveCb
    );
  }

  /**
   * Add a previously selected field to a new or existing box
   * @param array $field field configuration
   * @param string $boxId the box to add the field to
   */
  public function addFieldObject($field, $boxId)
  {
    $this->fields[$boxId][] = $field;
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $boxId the metabox to display the field
   * @param string $text the heading text
   * @param array $args additional arguments: description, width
   */
  public function addHeading($boxId, $text, $args = array())
  {
    $html = '<h4>' . $text . '</h4>';
    $key = md5(microtime());
    $this->addHtml($key, $boxId, $html, $args);
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $boxId the metabox to display the field
   * @param string $text the heading text
   * @param array $args additional arguments: description, width
   */
  public function addParagraph($boxId, $text, $args = array())
  {
    $html = '<p>' . $text . '</p>';
    $key = md5(microtime());
    $this->addHtml($key, $boxId, $html, $args);
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $html HTML code to display
   * @param array $args additional arguments: description, width
   */
  public function addHtml($key, $boxId, $html, $args = array())
  {
    $args['html'] = $html;
    $this->addField($key, $boxId, $args, array($this, 'displayHtml'), array($this, 'saveVoidNonMergeable'));
  }

  /**
   * Adds a simple line as element
   * @param $boxId
   */
  public function addLine($boxId)
  {
    $this->addHtml('simple-line-' . (++$this->lineId), $boxId, '<hr class="simple-line">');
  }

  /**
   * Adds a simple line as element
   * @param $boxId
   */
  public function addTitleLine($boxId, $text)
  {
    $this->addHtml('text-line-' . (++$this->lineId), $boxId, '
      <div class="mbh-title-line">
        <h3><span>' . $text . '</span></h3>
      </div>
    ');
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param array $args additional arguments: description, width
   */
  public function addInputText($key, $boxId, $title, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayInputText'),
      array($this, 'saveTextField')
    );
  }

  /**
   * Helper for adding an upload field that is native
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param array $args additional arguments: description, width
   */
  public function addNativeUploadField($key, $boxId, $title, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayNativeUpload'),
      array('\LBWP\Helper\MetaItem\NativeUpload', 'saveNativeUpload')
    );
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param array $args additional arguments: description, width
   */
  public function addAddressLocation($key, $boxId, $args = array())
  {
    // Add the title as an argument
    $args['title'] = '{title}';
    $args['template'] = $this->getTemplate($args, '{fieldId}');
    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array('LBWP\Helper\MetaItem\AddressLocation', 'displayFormFields'),
      array('LBWP\Helper\MetaItem\AddressLocation', 'saveFormFields')
    );
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param array $postTypes the title of the field besides the input
   * @param array $args additional arguments: description, width
   */
  public function addAssignPostsField($key, $boxId, $postTypes = array('post'), $args = array())
  {
    $args['types'] = $postTypes;

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayAssignPostsField'),
      array($this, 'saveAssignPostsField')
    );

    // Ajax callback for auto completion
    add_action('wp_ajax_mbhAssignPostsData', function ($args) use ($args) {
      self::ajaxAssignPostsData($args['types']);
    });
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param string $format the format in which the field should be saved (Date::*_DATE*)
   * @param array $args additional arguments: description, showTime = false
   */
  public function addDate($key, $boxId, $title, $format = Date::EU_DATE, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;
    $args['format'] = $format;

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayDateField'),
      array($this, 'saveDateField')
    );
  }

  /**
   * Helper for adding an input text field (one liner) for date and time
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param string $format the format in which the field should be saved (Date::*_DATE*)
   * @param array $args additional arguments: description, showTime = false
   */
  public function addDateTime($key, $boxId, $title, $format = Date::EU_DATE, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;
    $args['format'] = $format;

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayDateTimeField'),
      array($this, 'saveDateTimeField')
    );
  }

  /**
   * Helper for adding an input text field (one liner)
   * @param string $taxonomy the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param array $args additional arguments: description, display (radio|checkbox), numberOfVisibleTerms
   */
  public function addTaxonomy($taxonomy, $boxId, $title, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;
    $key = 'taxonomy_' . $taxonomy;
    $args['taxonomy'] = $taxonomy;

    $args = wp_parse_args($args, array(
      'display' => 'checkbox',
      'numberOfVisibleTerms' => 5,
      'sortable' => false, // chosen option
    ));

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayTaxonomy'),
      array($this, 'saveTaxonomy')
    );
  }

  /**
   * Helper for adding a checkbox input field (one liner)
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param array $args additional arguments: description, width
   */
  public function addCheckbox($key, $boxId, $title, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;
    $args['template'] = 'short';

    // Set the callback defaults, override if given
    $displayCallback = array($this, 'displayCheckbox');
    $saveCallback = array($this, 'saveCheckboxField');
    if (isset($args['displayCallback']) && is_callable($args['displayCallback'])) {
      $displayCallback = $args['displayCallback'];
    }
    if (isset($args['saveCallback']) && is_callable($args['saveCallback'])) {
      $saveCallback = $args['saveCallback'];
    }

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      $displayCallback,
      $saveCallback
    );
  }

  /**
   * Helper for adding a media button upload
   *
   * additional arguments:
   *  - uploaderButtonText
   *  - removeMediaText
   *  - mediaContainerCallback
   *
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param array $args additional arguments: description, width
   */
  public function addMediaUploadField($key, $boxId, $title, $args = array())
  {
    $args['title'] = $title;
    if (!isset($args['uploaderButtonText'])) {
      $args['uploaderButtonText'] = __('Datei wählen');
    }
    if (!isset($args['removeMediaText'])) {
      $args['removeMediaText'] = __('Datei entfernen');
    }
    if (!isset($args['mediaContainerCallback']) || !is_callable($args['mediaContainerCallback'])) {
      $args['mediaContainerCallback'] = array($this, 'mediaContainerCallback');
    }
    if (isset($args['multiple'])) {
      $args['uploader']['multiple'] = $args['multiple'];
    }
    if (isset($args['uploader']['multiple'])) {
      $args['multiple'] = $args['uploader']['multiple'];
    }

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayMediaUploadField'),
      array('\LBWP\Helper\MetaItem\ChosenDropdown', 'saveDropdown')
    );
  }

  /**
   * Add a chosen.js Dropdown. Any options in http://harvesthq.github.io/chosen/options.html can
   * be passed directly in the $args parameter.
   * The 'multiple' = (true|false) option will also add the [] to the select name and
   * save the meta_values as an array (with preserved order).
   *
   * You can provide items with
   *  - 'items' => array($value => array('title' => $title, 'data' => array('url' => $editUrl )))
   * or with
   *  - a callable 'itemsCallback' returning the same structure. This becomes necessary,
   * if the data is not yet available during instantiation, because it has to be before the
   * 'save_post' hook.
   *
   * @param $key
   * @param $boxId
   * @param $title
   * @param array $args
   */
  public function addDropdown($key, $boxId, $title, $args = array())
  {
    $args['title'] = $title;
    if (!isset($args['sortable']) && isset($args['multiple']) && $args['multiple'] == true) {
      $args['sortable'] = true;
    }

    $saveCallback = array('\LBWP\Helper\MetaItem\ChosenDropdown', 'saveDropdown');
    if (isset($args['saveCallback']) && is_callable($args['saveCallback'])) {
      $saveCallback = $args['saveCallback'];
    }

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array('\LBWP\Helper\MetaItem\ChosenDropdown', 'displayDropdown'),
      $saveCallback
    );
  }

  /**
   * Displays a pages dropdown with hierarchy
   * @param string $key
   * @param string $boxId
   * @param string $title
   * @param array $args
   */
  public function addPagesDropdown($key, $boxId, $title, $args = array())
  {
    $items = array(0 => __('Bitte Seite auswählen', 'lbwp'));
    $pages = get_pages();

    foreach ($pages as $page) {
      $items[$page->ID] = $page->post_title;
    }

    $args['items'] = $items;
    $this->addDropdown($key, $boxId, $title, $args);
  }

  /**
   * Displays a user dropdown
   * @param string $key
   * @param string $boxId
   * @param string $title
   * @param array $args
   */
  public function addUserDropdown($key, $boxId, $title, $args = array())
  {
    $items = array();
    $users = get_users();

    foreach ($users as $user) {
      $items[$user->ID] = array(
        'title' => $user->display_name,
        'data' => array(
          'html' => esc_attr(ChosenDropdown::getUserHtmlCallback($user)),
          'is-modal' => 1
        )
      );
    }

    $args['items'] = $items;
    $this->addDropdown($key, $boxId, $title, $args);
  }

  /**
   * @param $key
   * @param $boxId
   * @param $title
   * @param $taxonomy
   * @param array $args
   */
  public function addTaxonomyDropdown($key, $boxId, $title, $taxonomy, $args = array())
  {
    $args['taxonomy'] = $taxonomy;

    $args['items'] = array();
    // Add a first default item, if set
    if (isset($args['default'])) {
      $args['items'][$args['default']['key']] = $args['default']['value'];
    }

    // Query and add terms
    $terms = get_terms($args);
    foreach ($terms as $term) {
      $args['items'][$term->slug] = $term->name;
    }

    $this->addDropdown($key, $boxId, $title, $args);
  }

  /**
   * Add a simple connector dropdown to another post type
   * @param $key
   * @param $boxId
   * @param $title
   * @param $postType
   * @param array $args
   */
  public function addPostTypeDropdown($key, $boxId, $title, $postType, $args = array())
  {
    $args = PostTypeDropdown::createDropdownArguments($postType, $args);
    $this->addDropdown($key, $boxId, $title, $args);
  }

  /**
   * @param string $boxId the box id
   * @param string $title the title
   * @param string $localType the cross reference requesting type
   * @param string $referencedType the type that needs to be referenced
   * @param array $args
   */
  public function addPostCrossReference($boxId, $title, $localType, $referencedType, $args = array())
  {
    $key = CrossReference::getKey($localType, $referencedType);
    $dropdown = CrossReference::createDropdownArguments($localType, $referencedType, $args);
    $dropdown = array_merge($dropdown, $args);

    $this->addDropdown($key, $boxId, $title, $dropdown);
  }

  /**
   * Helper for adding an input text area
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param int $height the area height in pixel (without px)
   * @param array $args additional arguments: description, width (Default 100%)
   */
  public function addTextarea($key, $boxId, $title, $height, $args = array())
  {
    // Add the title as an argument
    $args['title'] = $title;
    $args['height'] = $height;

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayTextarea'),
      array($this, 'saveTextField')
    );
  }

  /**
   * add a table. table content (rows: array of array) supplied by rows or by rowsCallback argument
   * @param string $boxId the metabox to display the field
   * @param array $rows
   * @param array $headers
   * @param array $args
   */
  public function addTable($boxId, $rows = array(), $headers = array(), $args = array())
  {
    $args = wp_parse_args($args, array(
      'rows' => $rows,
      'headers' => $headers,
      'emptyNotice' => 'Keine Daten vorhanden.',
      'rowsCallback' => null
    ));
    $this->addField(microtime(), $boxId, $args, array($this, 'displayTable'), array($this, 'saveVoid'));
  }

  /**
   * Helper for adding a wysiwyg editor
   * @param string $key the key to store the metadata in
   * @param string $boxId the metabox to display the field
   * @param string $title the title of the field besides the input
   * @param int $rows number of rows, at least 5, recommended 10
   * @param array $args additional arguments: description, width (Default 100%)
   */
  public function addEditor($key, $boxId, $title, $rows, $args = array())
  {
    // Validate number of rows
    if ($rows < 5) {
      $rows = 5;
    }

    // Add the title as an argument
    $args['title'] = $title;
    $args['rows'] = $rows;

    // add a identifying (non-unique) css class
    $args['class'] .= ' editor ';

    // Add the field
    $this->addField(
      $key, $boxId, $args,
      array($this, 'displayEditor'),
      array($this, 'saveTextField')
    );
  }

  /**
   * Display table callback: display the rows from the arguments or from the output from rowsCallback
   *
   * @param array $args
   * @return mixed|string
   */
  public function displayTable($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $html = $this->getTemplate($args, $key, 'empty');

    $rows = $args['rows'];
    if (is_callable($args['rowsCallback'])) {
      $rows = callUserFunctionWithSafeArguments($args['rowsCallback'], array($args));
    }

    $headers = $args['headers'];
    $tableHeader = '';
    if (count($headers) > 0) {
      $tableHeader = '<thead><tr class="row"><th>' . implode('</th><th>', $headers) . '</th></tr></thead>';
    }

    $tableBody = '';
    foreach ($rows as $row) {
      if (count($row) > 0) {
        $tableBody .= '<tr class="row"><td>' . implode('</td><td>', $row) . '</td></tr>';
      }
    }

    $table = $args['emptyNotice'];
    if ($tableBody) {
      $table = '<table class="customer-projects widefat fixed ">
        ' . $tableHeader . '
        <tbody>
      ' . $tableBody . '
        </tbody>
      </table>';
    }

    $html = str_replace('{html}', $table, $html);

    return $html;
  }

  /**
   * Inline callback to display a native upload field
   * @param array $args the arguments to display the input field
   * @return string HTML code to display the field
   */
  public function displayNativeUpload($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $template = $this->getTemplate($args, $key);
    return NativeUpload::displayNativeUpload($args, $key, $template, $this->knownPostFields);
  }

  /**
   * Inline callback to display a normal textfield
   * @param array $args the arguments to display the input textfield
   * @return string HTML code to display the field
   */
  public function displayInputText($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $template = $this->getTemplate($args, $key);
    return SimpleField::displayInputText($args, $key, $template, $this->knownPostFields);
  }

  /**
   * Inline callback to display a date textfield with jquery date selector
   * @param array $args the arguments to display the input textfield
   * @param bool $addTimeField true, adds a time field
   * @param bool $useIntegerConversion true uses integer conversion instead of strings
   * @return string HTML code to display the field
   */
  public function displayDateField($args, $addTimeField = false, $useIntegerConversion = false)
  {
    $datepickerConfig = array();
    $key = $args['post']->ID . '_' . $args['key'];
    $html = $this->getTemplate($args, $key);
    wp_enqueue_script('jquery-ui-datepicker');

    // Get the current value
    $time = $value = '';
    $value = get_post_meta($args['post']->ID, $args['key'], true);
    if (strlen($value) == 0 && isset($args['default'])) {
      $value = $args['default'];
    }

    $attr = '';
    if (isset($args['required']) && $args['required']) {
      $attr .= ' required="required"';
    }

    // Convert the configured format to the display format EU_DATE
    if (!$useIntegerConversion && strlen($value) > 0) {
      $time = Date::convertDate($args['format'], Date::EU_CLOCK, $value);
      $value = Date::convertDate($args['format'], Date::EU_DATE, $value);
    } else if ($useIntegerConversion) {
      if (intval($value) > 0) {
        $time = Date::getTime(Date::EU_CLOCK, $value);
        $value = Date::getTime(Date::EU_DATE, $value);
      }
    }

    // If there is a maximum of days to be selectable, add this as an option
    if (intval($args['max_days_in_future']) > 0) {
      $datepickerConfig['maxDate'] = '+' . $args['max_days_in_future'] . 'd';
    }

    // Need of a time field?
    $timeField = '';
    if ($addTimeField) {
      $timeField = '<input type="text" id="' . $key . '-time" name="' . $key . '-time" class="mbh-timefield" placeholder="00:00" value="' . esc_attr($time) . '" />';
    }

    // Replace in the input field and add the js to use a picker
    $input = '
      <input type="text" id="' . $key . '" name="' . $key . '" class="mbh-datefield" value="' . esc_attr($value) . '"' . $attr . ' />
      ' . $timeField . '
      <script type="text/javascript">
        jQuery(function() {
          jQuery("#' . $key . '").datepicker(' . Date::getDatePickerJson('de', $datepickerConfig) . ');
        });
      </script>
    ';

    $html = str_replace('{input}', $input, $html);
    return $html;
  }

  /**
   * Inline callback to display a date textfield with jquery date selector
   * @param array $args the arguments to display the input textfield
   * @return string HTML code to display the field
   */
  public function displayDateTimeField($args)
  {
    return $this->displayDateField($args, true, true);
  }

  /**
   * Displays a "field" (or rather an admin) to assign posts via ajax autocomplete and lets the user sort them by
   * drag and drop. This will register a few needed javascript libraries.
   * Warning: Only once useable per post type, doesn't work with multiple use.
   * @param array $args the arguments
   * @return string html code to display the fields
   */
  public function displayAssignPostsField($args)
  {
    $html = '';

    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_script('jquery-ui-sortable');

    // Add the field to add new posts via ajax
    $attr = ' style="width:' . $this->getWidth($args) . ';"';
    $html .= '
      <div class="mbh-title">
        Artikel hinzufügen:<br />
        <br />
        <br />
        Bereits verknüpfte Artikel:
      </div>
      <div class="mbh-field">
        <div class="mbh-input">
          <input type="text" value="" id="newAssignedItem" ' . $attr . '/>
        </div>
        <div class="mbh-description">
          Geben sie den Titel oder die ID des gewünschten Artikels ein und bestätigen Sie die Auswahl mit Enter.
        </div>
      </div>
    ';

    // Get the current items and print json (metabox-helper.js will display them)
    $posts = $value = get_post_meta($args['post']->ID, $args['key'], true);
    $items = array();

    // Generate JS items, if there are assigned posts
    if (is_array($posts) && count($posts)) {

      foreach ($posts as $postId) {
        $data = get_post(intval($postId));
        if (strlen($data->post_title) > 0) {
          $items[] = array(
            'id' => $data->ID,
            'value' => $data->post_title
          );
        }
      }
    }

    // Always print the JS variable, even if empty
    $html .= '
      <script type="text/javascript">
        var mbhAssignedPostsData = ' . json_encode($items) . ';
      </script>
      <div id="mbh-assign-posts-container"></div>
    ';

    // Display everything in an empty full item
    return str_replace('{html}', $html, Templates::getById('empty'));
  }

  /**
   * Inline callback to display a normal checkbox
   * @param array $args the arguments to display the input checkbox
   * @return string HTML code to display the field
   */
  public function displayCheckbox($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $template = '';
    $classes = array();
    if (isset($args['template'])) {
      $template = $args['template'];
    }


    $html = $this->getTemplate($args, $key, $template);

    if (isset($args['value'])) {
      $value = $args['value'];
    } else {
      // Get the current value
      $value = get_post_meta($args['post']->ID, $args['key'], true);
    }

    // override meta value with selected argument
    if (isset($args['selected'])) {
      $selected = checked($args['selected'], true, false);
    } else {
      $selected = checked($value, 'on', false);
    }

    // If on the post new screen and always selected isset and true, preselect the checkbox
    if (isset($args['always_selected']) && $args['always_selected'] && stristr($_SERVER['REQUEST_URI'], 'post-new') !== false) {
      $selected  = checked(true, true, false);
    }

    if (empty($value)) {
      $value = 'on';
    }

    if (isset($args['name'])) {
      $name = $args['name'];
    } else {
      // Get the current value
      $name = $key;
    }

    $attr = ' style="width:' . $this->getWidth($args) . ';"';
    if (isset($args['required']) && $args['required']) {
      $attr .= ' required="required"';
    }
    if (isset($args['autosave_on_change']) && $args['autosave_on_change']) {
      $classes[] = 'mbh-autosave-on-change';
    }
    if (count($classes) > 0) {
      $attr .= ' class="' . implode(' ', $classes) . '"';
    }
    $description = '';
    if (isset($args['description'])) {
      $description = $args['description'];
    }

    // Replace in the input field
    $input = '
      <input type="checkbox" id="' . $key . '" name="' . $name . '" value="' . $value . '" ' . $selected . $attr . ' />
      <label for="' . $key . '">' . $description . '</label>
    ';
    if ($template) {
      $html = str_replace('{input}', $input, $html);
    } else {
      $html = $input;
    }

    return $html;
  }

  /**
   * Inline callback to display a normal radio button
   * @param array $args the arguments to display the input checkbox
   * @return string HTML code to display the field
   */
  public function displayRadioButton($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $description = '';

    if (isset($args['value'])) {
      $value = $args['value'];
    } else {
      // Get the current value
      $value = get_post_meta($args['post']->ID, $args['key'], true);
    }
    if (isset($args['name'])) {
      $name = $args['name'];
    } else {
      // Get the current value
      $name = $key;
    }
    if (isset($args['description'])) {
      $description = $args['description'];
    }


    $attr = ' style="width:' . $this->getWidth($args) . ';"';
    if (isset($args['required']) && $args['required']) {
      $attr .= ' required="required"';
    }

    // Replace in the input field
    $html = '
      <input type="radio" id="' . $key . '" name="' . $name . '" value="' . $value . '" ' . checked($args['selected'], true, false) . $attr . ' />
      <label for="' . $key . '">' . $description . '</label>
    ';

    return $html;
  }


  /**
   * Display callback for displaying a taxonomy as a list of radio buttons or checkboxes
   * if $args['display'] is set to 'radio', the terms of the taxonomy will be
   * displayed as radio buttons.
   * The display callback automatically  sets a inline style height, depending on the
   * $args['numberOfVisibleTerms'] argument.
   * @param array $args
   * @return string
   */
  public function displayTaxonomy($args)
  {

    $key = $args['post']->ID . '_' . $args['key'];
    $template = $this->getTemplate($args, $key, 'short_input_list');
    $inputs = '';
    $optionValues = array();
    $optionItems = array();
    $taxonomy = $args['taxonomy'];
    $terms = get_terms($taxonomy, array('hide_empty' => false));
    foreach ($terms as $term) {
      $inputArguments = array_merge($args, array(
        'key' => $args['key'] . '_' . $term->term_id,
        'name' => 'taxonomies[' . $term->taxonomy . '][]',
        'value' => $term->term_id,
        'selected' => has_term(intval($term->term_id), $term->taxonomy, $args['post']),
        'description' => $term->name,
        'width' => 'none'
      ));
      if ($args['display'] == 'chosen') {
        $optionItems[$term->term_id] = array(
          'title' => $term->name,
          'data' => array('url' => admin_url('edit-tags.php?action=edit&taxonomy=' . $taxonomy . '&tag_ID=' . $term->term_id))
        );
        if (has_term(intval($term->term_id), $term->taxonomy, $args['post'])) {
          $optionValues[] = $term->term_id;
        }
      } else {
        if ($inputArguments['display'] == 'radio') {
          $inputs .= $this->displayRadioButton($inputArguments);
        } elseif ($inputArguments['display'] == 'checkbox') {
          $inputs .= $this->displayCheckbox($inputArguments);
        }
      }


    }
    if ($args['display'] == 'chosen') {
      $html = ChosenDropdown::displayDropdown(array_merge($args, array(
        'items' => $optionItems,
        'value' => $optionValues,
        'name' => 'taxonomies[' . $taxonomy . ']'
      )));
    } else {
      $fieldStyle = 'height:' . 22 * $args['numberOfVisibleTerms'] . 'px;';

      $html = str_replace('{input}', $inputs, $template);
      $html = str_replace('{fieldStyle}', $fieldStyle, $html);
    }
    return $html;
  }

  /**
   * Inline callback to display a media upload button
   * @param array $args the arguments to display the input checkbox
   * @return string HTML code to display the field
   */
  public function displayMediaUploadField($args)
  {
    $postId = intval($args['post']->ID);
    $key = $args['post']->ID . '_' . $args['key'];
    $html = $this->getTemplate($args, $key, 'media');

    // filter out empty values
    $attachmentIds = array_filter(get_post_meta($postId, $args['key'], false));
    $attachments = array_map(function ($attachmentId) {
      return get_post(intval($attachmentId));
    }, $attachmentIds);

    $description = '';
    if (isset($args['description'])) {
      $description = $args['description'];
    }
    $fieldName = $key;
    $sortableCommand = '';
    if ($args['multiple']) {
      $fieldName = $key . '[]';
      $sortableCommand = '$("#media-uploader-' . $key . ' .media-uploader-attachments").sortable();';
    }

    $attachmentsHtml = array_reduce($attachments, function ($html, $attachment) use ($args, $fieldName, $postId) {
      $mediaContainer = callUserFunctionWithSafeArguments($args['mediaContainerCallback'], array($attachment->ID, $postId, $attachment, $args));
      return $html . sprintf('
      <li class="media-uploader-attachment">
        <a href="#" class="remove-attachment"></a>
        %s
        <input type="hidden" class="field-attachment-id" name="%s" value="%d" />
      </li>
      ', $mediaContainer, $fieldName, $attachment->ID);
    }, '');

    // Make senseless concat, to not have a fatal error in code editor at percents
    $input = sprintf('' . '
      <div class="media-uploader" id="media-uploader-%s">
        <ul class="media-uploader-attachments clearfix">
          %s
        </ul>
        <input type="button" class="button" name="%d-upload" value="%s"  />
      </div>

      <script>
        (function ($) {
          $(document).ready(function(){
            $("#media-uploader-%s").wordpressAttachment(%s);
            %s
          });
        }(jQuery));
      </script>
    ', $key, $attachmentsHtml, $postId, $args['uploaderButtonText'], $key, json_encode($args), $sortableCommand);

    $html = str_replace('{description}', $description, $html);
    $html = str_replace('{media}', $input, $html);
    return $html;
  }

  /**
   * Inline callback to display a textarea
   * @param array $args the arguments to display the input textfield
   * @return string HTML code to display the field
   */
  public function displayTextarea($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $template = $this->getTemplate($args, $key);
    return SimpleField::displayTextArea($args, $key, $template, $this->knownPostFields);
  }

  /**
   * Inline callback to display an editor
   * @param array $args the arguments to display the input textfield
   * @return string HTML code to display the field
   */
  public function displayEditor($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $html = $this->getTemplate($args, $key);

    // Get the current value
    $value = $this->getTextFieldValue($args);
    if (strlen($value) == 0 && isset($args['default'])) {
      $value = $args['default'];
    }

    // Replace in the input field
    $input = Strings::getWpEditor($value, $key, array(
      'textarea_rows' => $args['rows']
    ));
    $html = str_replace('{input}', $input, $html);
    return $html;
  }

  /**
   * @param array $args the arguments given
   * @return string HTML Code that should be displayed
   */
  public function displayHtml($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    $html = $this->getTemplate($args, $key, 'empty');
    $html = str_replace('{html}', $args['html'], $html);

    return $html;
  }

  /**
   * Inline callback to save a normal textfield
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public function saveTextField($postId, $field, $boxId)
  {
    // Validate and save the field
    $value = $_POST[$postId . '_' . $field['key']];
    $value = stripslashes(trim($value));

    // Check if the field is required
    if (isset($field['args']['required']) && $field['args']['required'] && strlen($value) == 0) {
      $this->addError($boxId, 'Bitte füllen Sie das Feld "' . $field['args']['title'] . '" aus.');
      return;
    }

    // Save the meta data to the database (Directly in post for known fields
    if (in_array($field['key'], $this->knownPostFields)) {
      global $wpdb;
      $this->wpdb->update(
        $this->wpdb->posts,
        array($field['key'] => $value),
        array('ID' => $postId,)
      );
    } else {
      $this->updateStringOption($postId, $field['key'], $value);
    }
  }

  /**
   * save the taxonomy terms (or delete them)
   * @param int $postId
   * @param array $field
   * @param string $boxId
   */
  public function saveTaxonomy($postId, $field, $boxId)
  {
    $taxonomy = $field['args']['taxonomy'];
    if (isset($_POST['taxonomies'][$taxonomy]) && is_array($_POST['taxonomies'][$taxonomy])) {
      $terms = $_POST['taxonomies'][$taxonomy];
      $terms = array_map('intval', $terms);
      wp_set_object_terms($postId, $terms, $taxonomy);
    } elseif (isset($_POST['taxonomies'][$taxonomy]) && is_numeric($_POST['taxonomies'][$taxonomy])) {
      $termId = intval($_POST['taxonomies'][$taxonomy]);
      $terms = array();
      if ($termId > 0) {
        $terms = array($termId);
      }
      wp_set_object_terms($postId, $terms, $taxonomy);
    } else {
      // delete the terms, if no $_POST data was found (but the metabox taxonomy field was configured)
      wp_set_object_terms($postId, array(), $taxonomy);
    }
  }

  /**
   * Inline callback to save a normal textfield
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public function saveDateField($postId, $field, $boxId)
  {
    // Validate and save the field
    $value = $_POST[$postId . '_' . $field['key']];
    $value = stripslashes(trim($value));

    // Validate the input with EU_FORMAT_DATE
    if (!Strings::checkDate($value, Date::EU_FORMAT_DATE)) {
      $value = ''; // Make the error pop up
    }

    // Check if the field is required
    if (isset($field['args']['required']) && $field['args']['required'] && strlen($value) == 0) {
      $this->addError($boxId, 'Bitte füllen Sie das Feld "' . $field['args']['title'] . '" aus.');
    } else {
      // If everything OK, convert to the desired format
      if (strlen($value) > 0) {
        $value = Date::convertDate(
          Date::EU_DATE,
          $field['args']['format'],
          $value
        );
      }
    }

    // Save the meta data to the database
    update_post_meta($postId, $field['key'], $value);
  }

  /**
   * Inline callback to save a normal textfield
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   * @return bool true/false if saved or not
   */
  public function saveDateTimeField($postId, $field, $boxId)
  {
    // Validate and save the field
    $timestamp = 0;
    $date = $_POST[$postId . '_' . $field['key']];
    $time = $_POST[$postId . '_' . $field['key'] . '-time'];

    // If time is empty, assume 00:00 for validation
    if (strlen($time) == 0) {
      $time = '00:00';
    }

    // Syntactically, first char needs to be 0,1,2, if not, set 0
    if (!in_array($time[0], array('0', '1', '2'))) {
      $time = '0' . $time;
    }

    // Validate the input with EU_FORMAT_DATE
    if (
      !Strings::checkDate($date, Date::EU_FORMAT_DATE) ||
      (!Strings::checkDate($time, DATE::EU_FORMAT_CLOCK) && !Strings::checkDate($time, Date::EU_FORMAT_TIME))
    ) {
      // No saving, since not valid
      return false;
    }

    // Set a timestamp from the time
    $timestamp = strtotime($date . ' ' . $time);

    // Check if the field is required
    if (isset($field['args']['required']) && $field['args']['required'] && $timestamp == 0) {
      $this->addError($boxId, 'Bitte füllen Sie das Feld "' . $field['args']['title'] . '" aus.');
    } else {
      update_post_meta($postId, $field['key'], $timestamp);
    }
  }

  /**
   * Inline callback to save a  checkbox value
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public function saveCheckboxField($postId, $field, $boxId)
  {
    // Validate and save the field
    $key = $postId . '_' . $field['key'];
    $value = isset($_POST[$key]) && $_POST[$key] == 'on' ? 'on' : false;

    if (isset($field['args']['globally_distinct']) && $value == 'on' && get_post_meta($postId, $field['key'], true) == false) {
      delete_post_meta_by_key($field['key']);
    }

    // Check if the field is required
    if (isset($field['args']['required']) && $field['args']['required'] && strlen($value) == 0) {
      $this->addError($boxId, 'Bitte füllen Sie das Feld "' . $field['args']['title'] . '" aus.');
      return;
    }

    // Save the meta data to the database
    $this->updateStringOption($postId, $field['key'], $value);
  }

  /**
   * Saves the assigned posts
   * @param int $postId the post that is saved
   * @param array $field the field data
   * @param string $boxId the metabox id
   */
  public function saveAssignPostsField($postId, $field, $boxId)
  {
    $posts = $_POST['assignedPostsId'];
    if (!is_array($posts)) {
      $posts = array();
    }

    update_post_meta($postId, $field['key'], $posts);
  }

  /**
   * Inline callback to save nothing
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public function saveVoid($postId, $field, $boxId) { }

  /**
   * Inline callback to save nothing
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public function saveVoidNonMergeable($postId, $field, $boxId) { }

  /**
   * Hides the editor. Needed if the editor isn't used, but media upload is
   * @param string $boxId needed to print html somewhere
   */
  public function hideEditor($boxId)
  {
    $this->addHtml('remove-editor', $boxId, Metabox::getHideEditorCss());
  }

  /**
   * Inserts a backdrop for the metabox helper scripts to use
   */
  public function allowCoreModalIframe()
  {
    echo '
      <div class="media-modal-backdrop-mbh" style="display:none;"></div>
      <div id="metaboxHelperContainer">
        <iframe id="metaboxHelper_frame" src="" width="100%" height="800" frameborder="0"></iframe>
        <a class="dashicons dashicons-no-alt button mbh-close-modal"></a>
      </div>
    ';
  }

  /**
   * Ajax callback for assign posts auto complete
   */
  public static function ajaxAssignPostsData($types)
  {
    $results = array();

    if (strlen($_GET['term']) > 0) {
      // Go directly to the database
      $sql = '
        SELECT ID, post_title FROM {sql:postTable}
        WHERE (ID = {postId} OR post_title LIKE {postTitle})
        AND post_type IN({sql:postTypes})
      ';

      global $wpdb;
      $posts = $wpdb->get_results(Strings::prepareSql($sql, array(
        'postTable' => $wpdb->posts,
        'postId' => intval($_GET['term']),
        'postTitle' => '%' . $_GET['term'] . '%',
        'postTypes' => '"' . implode('","', $types) . '"'
      )));

      foreach ($posts as $post) {
        $results[] = array(
          'id' => $post->ID,
          'value' => $post->post_title,
          'label' => $post->post_title,
        );
      }
    }

    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
  }

  /**
   * Provide the HTML content of the media uploader field.
   * it is overridable with the 'mediaContainerCallback' argument. for the removeMedia to work it has to have
   * @param int $attachmentId
   * @param int $postId
   * @param \WP_Post|null $attachment
   * @param array $args
   * @return string
   */
  public function mediaContainerCallback($attachmentId, $postId, $attachment, $args)
  {
    if (is_a($attachment, 'WP_Post') && Strings::startsWith($attachment->post_mime_type, 'image/')) {
      list($url, $width, $height, $crop) = wp_get_attachment_image_src($attachmentId, 'thumbnail');
      $container = sprintf('
        <div class="image-media-container media-container" style="padding: %s%% 0 0 0; width: %spx; ">
          <img src="%s" />
        </div>',
        number_format(100 * ($height / $width), 2, '.', ''),
        $width,
        $url
      );
    } else {
      // "Just a file"
      $fileUrl = wp_get_attachment_url($attachmentId);
      $container = sprintf('
        <div class="application-media-container media-container">
          <img src="' . get_bloginfo('url') . '/wp-includes/images/media/document.png">
          <p><strong><a href="%s" target="_blank">%s</a></strong></p>
        </div>',
        $fileUrl,
        $attachment->post_title
      );
    }
    return $container;
  }

  /**
   * Cleanly save or delete an empty string value
   * @param int $id the post id
   * @param string $key the meta key
   * @param string $value the string value
   */
  protected function updateStringOption($id, $key, $value)
  {
    if (empty($value) || strlen($value) == 0 || $value === false) {
      delete_post_meta($id, $key);
    } else {
      update_post_meta($id, $key, $value);
    }
  }
}