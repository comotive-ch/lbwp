<?php

namespace LBWP\Module\Config;

use LBWP\Core;
use LBWP\Util\File;
use LBWP\Util\String;
use LBWP\Util\WordPress;

/**
 * Allows to add specific configurations for modules
 * @author Michael Sebel <michael@comotive.ch>
 */
class Settings extends \LBWP\Module\Base
{

  /**
   * @var array Loaded if needed from includes/Module_LbwpConfig_configData
   */
  protected $configData = array();
  /**
   * @var array array of saving errors
   */
  protected $errors = array();
  /**
   * @var string template with description
   */
  protected $tplDesc = '
    <div class="cfg-item">
      <div class="cfg-title"><label for="{fieldId}">{title}</label></div>
      <div class="cfg-field">
        <div class="cfg-input">{input}</div>
        <div class="cfg-description">{description}</div>
      </div>
    </div>
  ';
  /**
   * @var string template without description
   */
  protected $tplNodesc = '
    <div class="cfg-item">
      <div class="cfg-title"><label for="{fieldId}">{title}</label></div>
      <div class="cfg-field">
        <div class="cfg-input">{input}</div>
      </div>
    </div>
  ';
  /**
   * @var string template with description
   */
  protected $tplDescCheckbox = '
    <div class="cfg-item">
      <div class="cfg-title">&nbsp;</div>
      <div class="cfg-field">
        <div class="cfg-input">
          {input} <label for="{fieldId}">{title}</label>
        </div>
        <div class="cfg-description">{description}</div>
      </div>
    </div>
  ';
  /**
   * @var string template without description
   */
  protected $tplNodescCheckbox = '
    <div class="cfg-item">
      <div class="cfg-title">&nbsp;</div>
      <div class="cfg-field">
        <div class="cfg-input">
          {input} <label for="{fieldId}">{title}</label>
        </div>
      </div>
    </div>
  ';

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters and removes some.
   */
  public function initialize()
  {
    // Add the menu to "Settings"
    add_action('admin_menu', array($this, 'addMenu'));
  }

  /**
   * Adds the settings menu and its callback
   */
  public function addMenu()
  {
    add_submenu_page(
      'options-general.php',
      'LBWP Einstellungen',
      'LBWP Einstellungen',
      'administrator',
      'lbwp-settings',
      array($this, 'configForm')
    );
  }

  /**
   * The actual form that displays the configurations
   */
  public function configForm()
  {
    $html = '';
    wp_enqueue_style('lbwp-config', '/wp-content/plugins/lbwp/resources/css/config.css', array(), '1.1');
    wp_enqueue_media();
    $url = File::getResourceUri() . '/js/generic-media-upload.js';
    wp_enqueue_script('generic-media-upload', $url, array('jquery'), Core::REVISION, 'all');
    // Load textual configurations from subfile
    require ABSPATH . PLUGINDIR . '/lbwp/views/includes/LbwpConfig_configData.php';
    // Controller, to save the features
    $message = '';
    if (isset($_POST['saveLbwpConfig'])) {
      $message = $this->saveConfig();
    }

    // Create the html output from the configs
    foreach ($this->configData as $groupkey => $group) {
      // Is the requirement given?
      $show = false;
      if (isset($group['requires']) && count($group['items']) > 0) {
        $r = $group['requires'];
        if ($this->features[$r[0]][$r[1]] == $r[2]) {
          $show = true;
        }
      }

      // See if there is a callback that decides visibility
      if (isset($group['requireCallback']) && is_callable($group['requireCallback'])) {
        $show = call_user_func($group['requireCallback']);
      }

      // Show always visible settings
      if (isset($group['visible']) && $group['visible']) {
        $show = true;
      }

      // if not shown, skip
      if (!$show) {
        continue;
      }
      // If we pass, first display the header info
      $html .= '
        <div class="cfg-head">
          <h3>' . $group['title'] . '</h3>
          <p class="entry">' . $group['description'] . '<p>
        </div>
      ';
      // Afterwards show all features to configure
      foreach ($group['items'] as $key => $item) {
        // decide on which template to use
        $tpl = $this->getTemplate($item);
        // Replace the title
        $tpl = str_replace('{title}', $item['title'], $tpl);
        // Replace the input by using a callback
        $tpl = call_user_func(
          array($this, 'displayField' . ucfirst($item['type'])),
          $item['typeConfig'],
          $this->config[$groupkey . ':' . $key],
          $tpl,
          $groupkey . $key
        );
        // At last, replace html id,s for labels
        $html .= str_replace('{fieldId}', $groupkey . $key, $tpl);
      }
    }

    // Create the form around it and the submit buttin
    $html = '
      <div class="wrap lbwp-config">
        <h2>LBWP Einstellungen</h2>
        ' . $message . '
        <form action="" method="post">
          ' . $html . '
          <p><input type="submit" class="button-primary" name="saveLbwpConfig" value="Änderungen übernehmen"></p>
        </form>
      </div>
    ';
    echo $html;
  }

  /**
   * @param array $item the item being displayed
   * @return string the html template
   */
  protected function getTemplate($item)
  {
    $tpl = '';

    if (isset($item['description'])) {
      if (isset($item['checkbox'])) {
        $tpl = $this->tplDescCheckbox;
      } else {
        $tpl = $this->tplDesc;
      }
      $tpl = str_replace('{description}', $item['description'], $tpl);
    } else {
      if (isset($item['checkbox'])) {
        $tpl = $this->tplNodescCheckbox;
      } else {
        $tpl = $this->tplNodesc;
      }
    }

    return $tpl;
  }

  /**
   * Saves all the configuration depending on their type
   * @return string html code to display error/success messages
   */
  protected function saveConfig()
  {
    foreach ($this->configData as $groupkey => $group) {
      foreach ($group['items'] as $key => $item) {
        // Have a callable do the work
        if ($this->isSaveableItem($item, $groupkey, $key)) {
          $hook = strtolower('lbwp_settings_' . $groupkey . '_' . $key);
          $value = apply_filters($hook, $_POST[$groupkey . $key], $item);
          call_user_func(
            array($this, 'saveField' . ucfirst($item['type'])),
            $item,
            $value,
            $groupkey . ':' . $key
          );
        }
      }
    }
    // Save the config array (non optimal values are changed or not added)
    update_option('LbwpConfig', $this->config);
    // Build the message
    if (count($this->errors) > 0) {
      $message = '';
      foreach ($this->errors as $error) {
        $message .= '<div class="error"><p>' . $error . '</p></div>';
      }
    } else {
      $message = '<div class="updated"><p>Einstellungen wurden gespeichert.</p></div>';
    }
    // returnt the message
    return $message;
  }

  /**
   * @param array $item the item
   * @param string $group the group key
   * @param string $key the item key
   * @return bool true, if item is saveable
   */
  protected function isSaveableItem($item, $group, $key)
  {
    // Checkboxes are always saved
    if ($item['type'] == 'checkbox') {
      return true;
    }

    // If anything else, saveable if there is a value
    if (isset($_POST[$group . $key])) {
      return true;
    }

    return false;
  }

  /**
   * Builds a selfclosing html tag from attributes
   * @param string $tag the tag to build (input,textarea)
   * @param array $attributes array of attributes
   * @return string html input field
   */
  protected function buildClosingTag($tag, $attributes)
  {
    $str = '';
    foreach ($attributes as $attribute => $value) {
      $str .= $attribute . '="' . esc_attr($value) . '" ';
    }
    return '<' . $tag . ' ' . $str . '/>';
  }

  /**
   * Builds an input field from attributes
   * @param string $tag the tag to build (input,textarea)
   * @param array $attributes array of attributes
   * @param string $content the content of the html tag
   * @return string html input field
   */
  protected function buildContainerTag($tag, $attributes, $content)
  {
    $str = '';
    foreach ($attributes as $attribute => $value) {
      $str .= $attribute . '="' . esc_attr($value) . '" ';
    }
    return '<' . $tag . ' ' . $str . '>' . $content . '</' . $tag . '>';
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldNumber($config, $value, $html)
  {
    $attr = array(
      'type' => 'number',
      'class' => 'cfg-field-number',
      'id' => '{fieldId}',
      'name' => '{fieldId}',
      'value' => $value
    );

    // Set min and max if given
    if (isset($config['rangeFrom']) && intval($config['rangeFrom']) > 0) {
      $attr['min'] = $config['rangeFrom'];
    }
    if (isset($config['rangeTo']) && intval($config['rangeTo']) > 0) {
      $attr['max'] = $config['rangeTo'];
    }

    // Create the field
    $field = $this->buildClosingTag('input', $attr);
    // If html follows, do so
    if (isset($config['afterHtml'])) {
      $field .= ' ' . $config['afterHtml'];
    }
    // Display a number field
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * Validates and saves a number field, adds errors to $this->errors
   * @param array $item the item to be saved
   * @param mixed $value the new value to be saved
   * @param string $key the key where to save it, if no error occured
   */
  public function saveFieldNumber($item, $value, $key)
  {
    $oldvalue = $value;
    $value = intval($value);
    // Raise the value to minimum if it's too low
    if ($item['typeConfig']['rangeFrom'] > 0 && $item['typeConfig']['rangeFrom'] > $value) {
      $value = $item['typeConfig']['rangeFrom'];
      $this->errors[] = 'Der Wert ' . $oldvalue . ' für "' . $item['title'] . '" ist zu niedrig und wurde auf ' . $value . ' korrigiert.';
    }
    // Lower the value to maximum if it's too high
    if ($item['typeConfig']['rangeTo'] > 0 && $item['typeConfig']['rangeTo'] < $value) {
      $value = $item['typeConfig']['rangeTo'];
      $this->errors[] = 'Der Wert ' . $oldvalue . ' für "' . $item['title'] . '" ist zu hoch und wurde auf ' . $value . ' korrigiert.';
    }
    // The value can be saved now
    $this->config[$key] = $value;
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldFile($config, $value, $html)
  {
    // For sure, add the link to
    $field = '<p>';
    $field.= '<a href="javascript:void(0);" class="generic-media-upload" data-save-to="{fieldId}">Datei auswählen</a>';
    // And make a hidden field of the value
    $field.= '<input type="hidden" name="{fieldId}" value="' . esc_attr($value) . '">';

    // Get the url of the image
    if ($config['valueType'] == 'id' && intval($value) > 0) {
      $value = WordPress::getImageUrl($value, 'original');
    }

    if (String::isURL($value)) {
      $field .= ' | <a href="javascript:void(0);" class="remove-generic-media-upload" data-remove-image="{fieldId}">Datei entfernen</a><br>';
    }
    // Close the paragraph we started earlier
    $field.= '</p>';

    // Display the image, if given
    $class = (strlen($value) == 0 || $value === 0) ? 'class="hidden"' : '';
    $field .= '
      <div id="{fieldId}" ' . $class . '>
        <img src="' . $value . '" width="' . $config['width'] . '" />
      </div>
    ';

    // Display the output in input placement
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * Validates and saves a number field, adds errors to $this->errors
   * @param array $item the item to be saved
   * @param mixed $value the new value to be saved
   * @param string $key the key where to save it, if no error occured
   * @return bool always true
   */
  public function saveFieldFile($item, $value, $key)
  {
    // Do not change, if the value is an url
    if (String::isURL($value)) {
      return true;
    }

    // Make sure it is an integer (or zero if no value is delivered
    $value = intval($value);
    $type = $item['typeConfig']['valueType'];

    // Convert to a url, if needed
    if ($type == 'url' && $value > 0) {
      $value = WordPress::getImageUrl($value, 'original');
    } else if ($type == 'url' && $value == 0) {
      $value = '';
    }

    $this->config[$key] = $value;
    return true;
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldCheckbox($config, $value, $html)
  {
    $attr = array(
      'type' => 'checkbox',
      'class' => 'cfg-field-checkbox',
      'id' => '{fieldId}',
      'name' => '{fieldId}',
      'value' => 1,
    );

    if ($value == 1) {
      $attr['checked'] = 'checked';
    }
    // Create the field
    $field = $this->buildClosingTag('input', $attr);

    // Display a number field
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * Validates and saves a number field, adds errors to $this->errors
   * @param array $item the item to be saved
   * @param mixed $value the new value to be saved
   * @param string $key the key where to save it, if no error occured
   */
  public function saveFieldCheckbox($item, $value, $key)
  {
    // Validate the value, selected or not
    $value = intval($value);
    if ($value !== 1) {
      $value = 0;
    }

    // The value can be saved now
    $this->config[$key] = $value;
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldText($config, $value, $html)
  {
    $attr = array(
      'type' => 'text',
      'class' => 'cfg-field-text',
      'id' => '{fieldId}',
      'name' => '{fieldId}',
      'value' => $value
    );
    // If it's not optional, add required attribute
    if (isset($config['optional']) && !$config['optional']) {
      $attr['required'] = 'required';
    }
    // Create the field
    $field = $this->buildClosingTag('input', $attr);
    // If html follows, do so
    if (isset($config['afterHtml'])) {
      $field .= ' ' . $config['afterHtml'];
    }
    // Display a number field
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * Validates and saves a text field, adds errors to $this->errors
   * @param array $item the item to be saved
   * @param mixed $value the new value to be saved
   * @param string $key the key where to save it, if no error occured
   */
  public function saveFieldText($item, $value, $key)
  {
    // Check if this field is optional, don't save if it's empty / mandatory
    if (strlen($value) == 0 && isset($item['typeConfig']['optional']) && !$item['typeConfig']['optional']) {
      $this->errors[] = 'Das Feld "' . $item['title'] . '" muss einen Wert beinhalten. Der leere Inhalt wurde nicht gespeichert.';
    } else {
      // The value can be saved
      $this->config[$key] = $value;
    }
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldTextarea($config, $value, $html)
  {
    $attr = array(
      'class' => 'cfg-field-textarea',
      'id' => '{fieldId}',
      'name' => '{fieldId}',
    );
    // If it's not optional, add required attribute
    if (isset($config['optional']) && !$config['optional']) {
      $attr['required'] = 'required';
    }
    // IF there's a height given, overwrite css with a style
    if (isset($config['height']) && intval($config['height']) > 100) {
      $attr['style'] = 'height:' . $config['height'] . 'px;';
    }

    // Create the field
    $field = $this->buildContainerTag('textarea', $attr, $value);
    // If html follows, do so
    if (isset($config['afterHtml'])) {
      $field .= ' ' . $config['afterHtml'];
    }
    // Display a number field
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @param string $fieldId the actual id of the field (needed for editor)
   * @return string the resulting html code
   */
  public function displayFieldEditor($config, $value, $html, $fieldId)
  {
    // Create the field
    $field = String::getWpEditor($value, $fieldId, array(
      'textarea_rows' => isset($config['rows'])? $config['rows'] : 10
    ));
    // If html follows, do so
    if (isset($config['afterHtml'])) {
      $field .= ' ' . $config['afterHtml'];
    }
    // Display a number field
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * Validates and saves a textarea field, adds errors to $this->errors
   * @param array $item the item to be saved
   * @param mixed $value the new value to be saved
   * @param string $key the key where to save it, if no error occured
   */
  public function saveFieldTextarea($item, $value, $key)
  {
    // This can be done the same is text
    $this->saveFieldText($item, $value, $key);
  }

  /**
   * Validates and saves a textarea field, adds errors to $this->errors
   * @param array $item the item to be saved
   * @param mixed $value the new value to be saved
   * @param string $key the key where to save it, if no error occured
   */
  public function saveFieldEditor($item, $value, $key)
  {
    // This can be done the same is text
    $this->saveFieldText($item, $value, $key);
  }

  /**
   * @return string template with description
   */
  public function getTplDesc()
  {
    return $this->tplDesc;
  }

  /**
   * @return string template without description
   */
  public function getTplNodesc()
  {
    return $this->tplNodesc;
  }
}