<?php

namespace LBWP\Helper;

use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;
use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Class PageSettingsBackend
 * @package LBWP\Helper
 */
class PageSettingsBackend
{

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
        <div class="cfg-input"><label for="{fieldId}">{input}</label></div>
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
        <div class="cfg-input"><label for="{fieldId}">{input}</label></div>
      </div>
    </div>
  ';

  /**
   * Get the template to provide individual fields from external classes
   * @param bool $noDesc determines if you want the no desc variant of the template
   * @return string html template code
   */
  public function getTemplateHtml($noDesc = false)
  {
    if ($noDesc) {
      return $this->tplNodesc;
    } else {
      return $this->tplDesc;
    }
  }

  /**
   * Load the settings pages before admin menu display to make sure everybody
   * has enough time to register its settings (e.g. at plugins_loaded or init hook)
   */
  public function load()
  {
    add_action('admin_menu', array($this, 'addSettingsPages'), 15);
  }

  /**
   * Gets the configuration from PageSettings class and creates the
   * backend pages to change page settings
   */
  public function addSettingsPages()
  {
    $config = PageSettings::getConfiguration();
    foreach ($config as $pageSlug => $pageConfig) {
      call_user_func(array($this, 'addSettingsPage'), $pageSlug, $pageConfig);
    }
  }

  /**
   * @param string $slug the slug of the settings page
   * @param array $config the configuration data
   */
  public function addSettingsPage($slug, $config)
  {
    add_submenu_page(
      $config['parent'],
      $config['name'],
      $config['name'],
      $config['capability'],
      $slug,
      array($this, 'displayPage')
    );
  }

  /**
   * Displays a single settings page
   */
  public function displayPage()
  {
    $config = PageSettings::getConfiguration();
    $config = $config[$_GET['page']];
    $sections = $config['sections'];

    // Display the sections and items
    $html = '';
    // Controller, to save the features
    $message = '';
    if (isset($_POST['savePageSettings'])) {
      $message = $this->saveConfig();
    }

    // Create the html output from the configs
    foreach ($sections as $sectionSlug => $section) {

      // If we pass, first display the header info
      $html .= '
        <div class="cfg-head">
          <h3>' . $section['title'] . '</h3>
          <p class="entry">' . $section['description'] . '<p>
        </div>
      ';
      // Afterwards show all features to configure
      foreach ($section['items'] as $key => $item) {
        // decide on which template to use
        if (isset($item['description']) || isset($item['config']['infoCallback'])) {
          $tpl = $this->tplDesc;
          if (isset($item['config']['infoCallback'])) {
            $tpl = str_replace('{description}', call_user_func($item['config']['infoCallback'], $item), $tpl);
          } else {
            $tpl = str_replace('{description}', $item['description'], $tpl);
          }
        } else {
          $tpl = $this->tplNodesc;
        }
        // Replace the title
        $tpl = str_replace('{title}', $item['title'], $tpl);
        // Replace the input by using a callback
        $value = get_option($item['id']);
        if ($value === false) {
          $value = $item['config']['default'];
        }
        $item['config']['fieldId'] = $item['id'];

        // Determine the callable
        $displayCallable = array($this, 'displayField' . ucfirst($item['type']));
        if (isset($item['config']['displayCallback']) && is_callable($item['config']['displayCallback'])) {
          $displayCallable = $item['config']['displayCallback'];
        }

        $tpl = call_user_func($displayCallable, $item['config'], $value, $tpl);
        // At last, replace html id,s for labels
        $html .= str_replace('{fieldId}', $item['id'], $tpl);
      }
    }

    // Create the form around it and the submit buttin
    $html = '
      <div class="wrap lbwp-settings-page">
        <h2>' . $config['name'] . '</h2>
        ' . $message . '
        <form action="" method="post" enctype="multipart/form-data">
          ' . $html . '
          <p style="clear:both">
            <input type="submit" class="button-primary" name="savePageSettings" value="Speichern">
          </p>
        </form>
      </div>
    ';
    echo $html;
  }

  /**
   * Saves all the configuration depending on their type
   * @return string html code to display error/success messages
   */
  protected function saveConfig()
  {
    $config = PageSettings::getConfiguration();
    $config = $config[$_GET['page']];
    $sections = $config['sections'];

    foreach ($sections as $sectionSlug => $section) {
      foreach ($section['items'] as $item) {
        // if there's a new value, try to save it
        if (isset($_POST[$item['id']]) || isset($item['config']['saveAlways'])) {
          // Determine the callable
          $saveCallable = array($this, 'saveField' . ucfirst($item['type']));
          if (isset($item['config']['saveCallback']) && is_callable($item['config']['saveCallback'])) {
            $saveCallable = $item['config']['saveCallback'];
          }
          call_user_func($saveCallable, $item);
        }
      }
    }

    // Build the message
    if (count($this->errors) > 0) {
      $message = '';
      foreach ($this->errors as $error) {
        $message .= '<div class="error"><p>' . $error . '</p></div>';
      }
    } else {
      $message = '<div class="updated"><p>Einstellungen wurden gespeichert.</p></div>';
    }

    // return the message
    return $message;
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
      'value' => $value,
      'min' => $config['rangeFrom'],
      'max' => $config['rangeTo']
    );
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
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldUpload($config, $value, $html)
  {
    $attr = array(
      'type' => 'file',
      'class' => 'cfg-field-file',
      'id' => '{fieldId}',
      'name' => '{fieldId}'
    );

    // Create the field
    $field = $this->buildClosingTag('input', $attr);
    // If html follows, do so
    if (isset($config['afterHtml'])) {
      $field .= ' ' . $config['afterHtml'];
    }

    // See if there is already something uploaded
    $url = get_option($config['fieldId']);
    if (Strings::checkURL($url)) {
      $field .= '<p><a href="' . $url . '" target="_blank">Download ' . File::getFileOnly($url) . '</a></p>';
    }

    // Display a upload field
    $html = str_replace('{input}', $field, $html);

    return $html;
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldEditor($config, $value, $html)
  {
    // Get the editor as html to return and not echo
    ob_start();
    $rows = 10;
    if (isset($config['rows'])) {
      $rows = intval($config['rows']);
    }
    wp_editor($value, $config['fieldId'], array('textarea_rows' => $rows));
    $field = ob_get_contents();
    ob_end_clean();

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
   * @return string the resulting html code
   */
  public function displayFieldCheckbox($config, $value, $html)
  {
    $attr = array(
      'id' => '{fieldId}',
      'name' => '{fieldId}',
      'class' => 'cfg-checkbox',
      'value' => 1,
      'type' => 'checkbox'
    );

    // Preselection, if the option is active
    if ($value == 1) {
      $attr['checked'] = 'checked';
    }

    // Create the field
    $field = $this->buildClosingTag('input', $attr);

    // Add text besides the checkbox
    if (isset($config['rightHtml'])) {
      $field .= ' <div class="cfg-text-rightof-input">' . $config['rightHtml'] . '</div>';
    }
    // Display a number field
    $html = str_replace('{input}', $field, $html);
    return $html;
  }

  /**
   * @param array $config configuration for this field
   * @param mixed $value the current configuration
   * @param string $html the html template in where to replace {input}
   * @return string the resulting html code
   */
  public function displayFieldDropdown($config, $value, $html)
  {
    //  und [values]
    // Create the dropdown field
    $field = '<select id="' . $config['fieldId'] . '" name="' . $config['fieldId'] . '">';
    // Add the options
    foreach ($config['values'] as $key => $text) {
      $field .= '<option value="' . $key . '" ' . selected($key, $value, false) . '>' . $text . '</option>' . PHP_EOL;
    }
    $field .= '</select>';

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
   * @return string the resulting html code
   */
  public function displayFieldGroupdropdown($config, $value, $html)
  {
    // Create the dropdown field
    $field = '<select id="' . $config['fieldId'] . '" name="' . $config['fieldId'] . '">';

    // Add the options
    foreach ($config['values'] as $groupKey => $group) {
      $field .= '<optgroup label="' . $group['name'] . '">' . PHP_EOL;

      foreach ($group['values'] as $key => $text) {
        $field .= '<option value="' . $key . '" ' . selected($key, $value, false) . '>' . $text . '</option>' . PHP_EOL;
      }

      $field .= '</optgroup>';
    }

    $field .= '</select>';

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
   * @return string the resulting html code
   */
  public function displayFieldCustom($config, $value, $html)
  {
    $result = '';
    if (isset($config['displayCallback']) && is_callable($config['displayCallback'])) {
      $result = call_user_func($config['displayCallback'], $config, $value, $html);
    }
    return $result;
  }

  /**
   * Validates and saves a checkbox field
   * @param array $item the item to be saved
   */
  public function saveFieldCheckbox($item)
  {
    $value = intval($_POST[$item['id']]);
    if ($value !== 1) {
      $value = 0;
    }
    // The value can be saved now
    update_option($item['id'], $value);
  }

  /**
   * Validates and saves a dropdown field
   * @param array $item the item to be saved
   */
  public function saveFieldDropdown($item)
  {
    $value = $_POST[$item['id']];

    // See if the value exists in the possible dropdown values
    if (isset($item['config']['values'][$value])) {
      update_option($item['id'], $value);
    } else {
      $this->errors[] = 'Die Auswahl von ' . $item['title'] . ' ist ungültig und wurde nicht gespeichert.';
    }
  }

  /**
   * Validates and saves a dropdown field
   * @param array $item the item to be saved
   */
  public function saveFieldUpload($item)
  {
    if (isset($_FILES[$item['id']]) && $_FILES[$item['id']]['error'] == 0) {
      /** @var S3Upload $upload the uploader to the configured cdn */
      $upload = LbwpCore::getInstance()->getModule(('S3Upload'));
      $url = $upload->uploadLocalFile($_FILES[$item['id']]);
      update_option($item['id'], $url);
    }
  }

  /**
   * Validates and saves a group dropdown field
   *
   * @param array $item the item to be saved
   */
  public function saveFieldGroupdropdown($item)
  {
    $value = $_POST[$item['id']];

    $found = false;

    foreach ($item['config']['values'] as $groupKey => $group) {
      if (isset($group['values'][$value])) {
        $found = true;
        break;
      }
    }

    // See if the value exists in the possible dropdown values
    if ($found) {
      update_option($item['id'], $value);
    } else {
      $this->errors[] = 'Die Auswahl von ' . $item['title'] . ' ist ungültig und wurde nicht gespeichert.';
    }
  }

  /**
   * Validates and saves a number field, adds errors to $this->errors
   * @param array $item the item to be saved
   */
  public function saveFieldNumber($item)
  {
    $value = intval($_POST[$item['id']]);
    $oldvalue = $value;
    // Raise the value to minimum if it's too low
    if ($item['config']['rangeFrom'] > $value) {
      $value = $item['config']['rangeFrom'];
      $this->errors[] = 'Der Wert ' . $oldvalue . ' für "' . $item['title'] . '" ist zu niedrig und wurde auf ' . $value . ' korrigiert.';
    }
    // Lower the value to maximum if it's too high
    if ($item['config']['rangeTo'] < $value) {
      $value = $item['config']['rangeTo'];
      $this->errors[] = 'Der Wert ' . $oldvalue . ' für "' . $item['title'] . '" ist zu hoch und wurde auf ' . $value . ' korrigiert.';
    }

    // The value can be saved now
    update_option($item['id'], $value);
  }

  /**
   * Validates and saves a text field, adds errors to $this->errors
   * @param array $item the item to be save
   */
  public function saveFieldText($item)
  {
    // values from textarea are escaped: we must strip the slashes
    $value = stripslashes($_POST[$item['id']]);
    // Check if this field is optional, don't save if it's empty / mandatory
    if (strlen($value) == 0 && isset($item['config']['optional']) && !$item['config']['optional']) {
      $this->errors[] = 'Das Feld "' . $item['title'] . '" muss einen Wert beinhalten. Der leere Inhalt wurde nicht gespeichert.';
    } else {
      // The value can be saved
      update_option($item['id'], $value);
    }
  }

  /**
   * Validates and saves a textarea field, adds errors to $this->errors
   * @param array $item the item to be saved
   */
  public function saveFieldTextarea($item)
  {
    // This can be done the same is text
    $this->saveFieldText($item);
  }

  /**
   * Validates and saves a editor field, adds errors to $this->errors
   * @param array $item the item to be saved
   */
  public function saveFieldEditor($item)
  {
    // This can be done the same is text
    $this->saveFieldText($item);
  }

  /**
   * @param $item
   */
  public function saveFieldCustom($item)
  {
    if (isset($item['config']['saveCallback']) && is_callable($item['config']['saveCallback'])) {
      /**
       * @var \WP_Error|mixed $result
       */
      $result = call_user_func($item['config']['saveCallback'], $item);
    }
    if (is_wp_error($result)) {
      foreach ($result->get_error_messages() as $errorMessage) {
        $this->errors[] = $errorMessage;
      }
    }
  }
}