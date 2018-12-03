<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Helper\Document\Ghostscript;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Theme\Feature\SecureAssets;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Core as LbwpCore;
use LBWP\Util\Strings;

/**
 * This will display a file input field
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Upload extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Datei-Upload',
    'group' => 'Spezial-Felder'
  );
  /**
   * @var bool makes sure libraries are only added once
   */
  protected static $addedLibraries = false;

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'secure_upload' => array(
        'name' => 'Uploads schützen',
        'type' => 'radio',
        'hint' => '
          Die hochgeladenen Dateien können im versendeten E-Mail nur heruntergeladen werden, wenn man in WordPress angemeldet ist.
          Das macht in den meisten Fällen Sinn, damit die hochgeladenen Dateien nicht öffentlich einsehbar sind.
        ',
        'values' => array(
          'ja' => 'Ja',
          'nein' => 'Nein'
        )
      ),
      'filetypes' => array(
        'name' => 'Erlaubte Datei-Endungen',
        'type' => 'textfield',
        'help' => 'Liste von erlaubten Endungen (leer lassen für übliche/bekannte Dateitypen). Beispiel: jpg,gif,doc,xlsx'
      ),
      'pdf_validation' => array(
        'name' => 'Max. Anzahl Seiten für PDF',
        'type' => 'textfield',
        'help' => '
          Sofern diese Option ausgefüllt wird, werden nur noch PDF Dateien zum Upload erlaubt.
          Die Datei wird nur akzeptiert wenn Sie nicht mehr Seiten hat als erlaubt sind.
          Ausserdem wird grundsätzlich sichergestellt, dass die Datei ein konformes, ungefährliches PDF ist.
        '
      )
    ));
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['description'] = 'Datei-Upload - Der Besucher kann eine Datei hochladen';
    $this->params['secure_upload'] = 'ja';
    $this->params['filetypes'] = '';
    $this->params['pdf_validation'] = '';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $this->addFormFieldConditions($args['conditions']);
    // No required params as they wont work on the hidden
    $uploadattr = '';
    $attr = $this->getDefaultAttributes($args, '', '', false);
    if (isset($args['pflichtfeld'])) {
      if ($args['pflichtfeld'] == 'ja' || $args['pflichtfeld'] == 'yes') {
        $uploadattr .= ' required="required"';
      }
    }

    // We need to add the asterisk ourself, as we provide false to above function
    if (isset($args['pflichtfeld'])) {
      if ($args['pflichtfeld'] == 'ja' || $args['pflichtfeld'] == 'yes') {
        $args['feldname'] .= self::ASTERISK_HTML;
      }
    }

    // Save the field config in cache for 30 minutes
    $formId = intval($this->formHandler->getCurrentForm()->ID);
    $attr .= ' data-cfg-key="ff::' . $formId . '::' . $this->get('id') . '"';
    $url = $this->getValue($args);

    // The hidden field has the common params, while the file element is just doing the job
    $field = '<input type="file" ' . $uploadattr . ' name="uploader_' . $this->get('id') . '" />';
    $field.= '<input type="hidden"' . $attr . ' value="' . $url . '" />';

    // If the file was already uploaded, add a text (also, handle proxy files
    $text = '';
    if (Strings::checkUrl($url)) {
      $file = File::getFileOnly($url);
      if (Strings::startsWith($file, 'wp-file-proxy.php')) {
        $file = substr($file, strrpos($file, '%2F') + 3);
      }
      $text = sprintf(__('Datei %s wurde hochgeladen'), $file);
    }

    // Add the empty template container for the upload
    $field.= '
      <div class="upload-state-container">
        <div class="filename">
          <span class="progress-text" data-template="' . __('Datei {filename} wird hochgeladen', 'lbwp') . '">' . $text . '</span>
          <span class="progress-number" data-template=" ({number}%)"></span>
        </div>
        <div class="progress">
          <div class="progress-bar" style="width:0%"></div>
        </div>
      </div>
    ';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('upload-field ' . $this->params['class']), $html);
    $html = str_replace('{field}', $field, $html);

    // Only once, include the needed script in footer
    if (!self::$addedLibraries) {
      $deps = array('jquery', 'lbwp-form-frontend', 'lbwp-form-validate');
      wp_enqueue_script('dm-uploader', File::getResourceUri() . '/js/jquery.dm-uploader.min.js', $deps, LbwpCore::REVISION, true);
      self::$addedLibraries = true;
    }

    return $html;
  }

  /**
   * @param array $args the shortcode params
   * @return string the user value, if the form has been sent
   */
  public function getValue($args = array())
  {
    // Get the value from post, if set (the file url is in a hidden field
    if (isset($_POST[$this->get('id')])) {
      return $_POST[$this->get('id')];
    }

    return '';
  }

  /**
   * @return string
   */
  protected static function getExtensionList($types)
  {
    // Set defaults if nothing given
    if (strlen($types) == 0) {
      $mimes = array_keys(wp_get_mime_types());
      $mimes = str_replace('|', ',', $mimes);
      $types = implode(',', $mimes);
    }

    return explode(',', $types);
  }

  /**
   * Handle the upload of files (called from api/upload.php)
   * @param array $result predefined result object stating an error and an empty url
   * @return array hoepfully a success object
   */
  public static function handleNewFile($result)
  {
    // Get the config, we need it to proceed
    list($type, $formId, $fieldId) = explode('::', $_POST['cfgKey']);
    // Get the forms main instance to get the handler
    $forms = LbwpCore::getModule('Forms');
    /** @var FormHandler $handler */
    $handler = $forms->getFormHandler();
    $handler->loadForm(array('id' => $formId));
    /** @var Upload $item */
    foreach ($handler->getCurrentItems() as $item) {
      if ($item->get('id') == $fieldId) {
        $config = $item->getAllParams();
        $config['filetypes'] = self::getExtensionList($config['filetypes']);
        break;
      }
    }

    // Only start when there is no file error and the config is given
    if ($_FILES['file']['error'] == UPLOAD_ERR_OK && is_array($config)) {
      $originalName = $_FILES['file']['name'];
      $ext = strtolower(substr(File::getExtension($originalName), 1));
      // Immediately inform, if the file type doesn't match
      if (!in_array($ext, $config['filetypes'])) {
        $result['message'] = __('Dieses Dateiformat ist nicht erlaubt', 'lbwp');
        return $result;
      }

      // Check if we need to validate pdf pages
      if (intval($config['pdf_validation']) > 0) {
        $max =  intval($config['pdf_validation']);
        $pages = Ghostscript::countPdfPages($_FILES['file']['tmp_name']);
        // Message if the limit has exeeded
        if ($pages > $max) {
          if ($max == 1) {
            $result['message'] = sprintf(__('Es ist maximal eine Seite erlaubt. Die Datei enthält %s Seiten.', 'lbwp'), $pages);
          } else {
            $result['message'] = sprintf(__('Es sind maximal %s Seiten erlaubt. Die Datei enthält %s Seiten.', 'lbwp'), $max, $pages);
          }
          return $result;
        }
      }

      /** @var S3Upload $uploader Get our S3 component to upload the file */
      $uploader = LbwpCore::getModule('S3Upload');
      $url = $uploader->uploadLocalFile($_FILES['file'], false);

      // Secure the file if needed
      if ($config['secure_upload'] == 'ja') {
        $key = $uploader->getKeyFromUrl($url);
        $uploader->setAccessControl($key, S3Upload::ACL_PRIVATE);
        $url = SecureAssets::getProxyPathWithKey($key);
      }

      // Add a nice success message, mentioning the uploaded file
      $result['message'] = sprintf(__('Datei %s wurde hochgeladen'), $originalName);
      $result['status'] = 'success';
      $result['url'] = $url;
    }

    return $result;
  }
} 