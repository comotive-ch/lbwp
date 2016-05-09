<?php

namespace LBWP\Newsletter\Component;

use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\Config\Settings as LbwpSettings;
use LBWP\Newsletter\Core;
use LBWP\Newsletter\Component\Base;
use LBWP\Newsletter\Service\Definition;
use LBWP\Newsletter\Template\Standard\StandardSingle;

/**
 * This class handles settings for the standard newsletter tool
 * @package LBWP\Newsletter\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class StandardSettings extends Base
{
  /**
   * @var array the settings
   */
  protected $settings = array(
    'salutation' => 'Anrede der Empfänger',
    'bodyColor' => 'Hintergrundfarbe',
    'bodyDarkColor' => 'Hintergrund im Kopfbereich',
    'innerBackground' => 'Hintergrundfarbe der Artikel',
    'buttonColor' => 'Farbe Artikel-Link Button',
    'buttonColorHover' => 'Farbe bei Mouse-Over (Link)',
    'buttonTextColor' => 'Text-Farbe des Artikel-Link',
    'buttonText' => 'Text des Artikel-Link',
    'fontColor' => 'Schriftfarbe',
    'linkColor' => 'Linkfarbe im Text',
    'headerColor' => 'Schriftfarbe Betreff',
    'logoWidth' => 'Breite des Logo (Pixel)',
    'logoHeight' => 'Höhe des Logo (Pixel)',
    //'logoUrl' => 'http://placekitten.com/180/50', // This is made hard coded
  );
  /**
   * @var LbwpSettings instance of the feautre module
   */
  protected $feature = NULL;

  /**
   * Called after component construction
   */
  public function load() { }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Make a reference to the feature backend
    if (is_admin()) {
      $this->feature = LbwpCore::getModule('LbwpConfig');
      $this->saveDesignSettings();
    }

    // Image size for thumbs in standard newsletter
    add_image_size('standard-nl-thumb', 180, 180, true);
  }

  /**
   * Saves the settings if needed and redirects
   */
  protected function saveDesignSettings()
  {
    if (isset($_POST['saveDesignSettings'])) {

      // First save all standard settings
      foreach ($this->settings as $key => $title) {
        $optionKey = 'standardNewsletter_' . $key;
        $newValue = trim($_POST[$optionKey]);

        if (strlen($newValue) > 0) {
          update_option($optionKey, $newValue);
        }
      }

      /** @var S3Upload $upload to the upload */
      $upload = LbwpCore::getModule('S3Upload');
      if ($upload->isImage($_FILES['logoUpload'])) {
        $url = $upload->uploadLocalFile($_FILES['logoUpload']);
        update_option('standardNewsletter_logoUrl', $url);
      }

      // Redirect to the same page with a message
      header('Location: ?page=' . $_GET['page'] . '&msg=saved');
      exit;
    }
  }

  /**
   * Displays the settings backend
   */
  public function displayBackend()
  {
    $html = '<div class="wrap lbwp-config">';

    // Add the settings CSS
    wp_enqueue_style('lbwp-config', '/wp-content/plugins/lbwp/resources/css/config.css', array(), '1.1');

    $html .= $this->getStandardSettings();

    // Close the wrapper and return
    echo $html . '</div>';
  }

  /**
   *
   */
  public function getStandardSettings()
  {
    // Eventually generate a user message
    $message = '';
    if ($_GET['msg'] == 'saved') {
      $message = '<div class="updated"><p>Daten wurden gespeichert.</p></div>';
    }

    // Display a header
    $html = '
      <h2>Newsletter &raquo; Design</h2>
      ' . $message . '
      <p>Hier können Sie alle Einstellungen für die Standard-Designs ändern.</p>
      <form action="?page=' . $_GET['page'] . '" method="post" enctype="multipart/form-data">
    ';

    $template = $this->feature->getTplNodesc();
    $templateDesc = $this->feature->getTplDesc();
    $defaults = StandardSingle::getDefaults();

    // Add all text fields
    foreach ($this->settings as $key => $title) {
      $optionKey = 'standardNewsletter_' . $key;

      // Get the current value or default
      $value = get_option($optionKey);
      if (strlen($value) == 0) {
        $value = $defaults[$key];
      }

      // Create input for sender default name
      $input = '<input type="text" name="' . $optionKey . '" id="' . $optionKey . '" value="' . $value . '" class="cfg-field-text">';

      // Create the form field from template
      $html .= str_replace('{title}', $title, $template);
      $html = str_replace('{input}', $input, $html);
      $html = str_replace('{fieldId}', $key, $html);
    }

    // And at last, add the upload field
    $width = get_option('standardNewsletter_logoWidth');
    $height = get_option('standardNewsletter_logoHeight');
    $url = get_option('standardNewsletter_logoUrl');

    // Overwrite with defaults, if not set
    if (strlen($width) == 0) {
      $width = $defaults['logoWidth'];
    }
    if (strlen($height) == 0) {
      $height = $defaults['logoHeight'];
    }
    if (strlen($url) == 0) {
      $url = $defaults['logoUrl'];
    }

    // And set the description with the data
    $description = '
      Bitte laden Sie das Logo in der Grösse ' . $width . 'x' . $height . ' hoch.
    ';

    // And the Upload / Logo displaying
    $input = '
      <div class="image-upload-small">
        <input type="file" name="logoUpload" />
        <img src="' . $url . '" width="' . $width . '" height="' . $height . '" />
      </div>
    ';

    $html .= str_replace('{title}', 'Logo-Upload', $templateDesc);
    $html = str_replace('{description}', $description, $html);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{fieldId}', $key, $html);


    // Add a save button and close the form
    $html .= '
        <input type="submit" value="Speichern" name="saveDesignSettings" class="button-primary" />
      </form>
    ';

    return $html;
  }
} 