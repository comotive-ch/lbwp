<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Util\ArrayManipulation;
use LBWP\Module\Backend\S3Upload;
use LBWP\Util\File;
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
    'help' => 'Der Besucher kann eine Datei hochladen',
    'group' => 'Spezial-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'filetypes' => array(
        'name' => 'Erlaubte Datei-Endungen',
        'type' => 'textfield',
        'help' => 'Liste von erlaubten Endungen (leer lassen für übliche/bekannte Dateitypen). Beispiel: jpg,gif,doc,xlxs'
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
    $this->params['filetypes'] = '';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $attr = $this->getDefaultAttributes($args);

    // Make the field
    $field = '<input type="file"' . $attr . '/>';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('text-field ' . $this->params['class']), $html);
    $html = str_replace('{field}', $field, $html);

    return $html;
  }

  /**
   * @param array $args the shortcode params
   * @return string the user value, if the form has been sent
   */
  public function getValue($args = array())
  {
    // Get the value from post, if set
    if (isset($_FILES[$this->get('id')]) && $_FILES[$this->get('id')]['error'] == 0) {
      $url = '';
      $file = $_FILES[$this->get('id')];
      $uploader = new S3Upload();
      $uploader->initialize();
      if ($this->isValidFile($file)) {
        $extension = File::getExtension($file['name']);
        $file['name'] = Strings::getRandom(40) . $extension;
        $url = $uploader->uploadLocalFile($file);
      }

      return $url;
    }

    return '';
  }

  /**
   * @param array $file a $_FILES file
   * @return bool true if the file is valid
   */
  protected function isValidFile($file)
  {
    $types = $this->params['filetypes'];
    // Set defaults if nothing given
    if (strlen($types) == 0) {
      $mimes = array_keys(wp_get_mime_types());
      $mimes = str_replace('|', ',', $mimes);
      $types = implode(',', $mimes);
    }

    // Check primitively for validatity
    $isValid = false;
    foreach (explode(',', $types) as $extension) {
      if (Strings::endsWith($file['name'], $extension)) {
        $isValid = true;
        break;
      }
    }

    return $isValid;
  }
} 