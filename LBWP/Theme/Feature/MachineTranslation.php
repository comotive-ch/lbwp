<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use LBWP\Util\Templating;
use LBWP\Util\WordPress;

/**
 * Provides possibility to set a focus point on feature images and
 * gives helper functions to generate html code for such images.
 * Auto registers and loads needed JS/CSS libraries.
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class MachineTranslation
{
  /**
   * @var FocusPoint the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'availablePostTypes' => array('post', 'page'),
    'addContentNotice' => false,
    'contentNoticeContainer' => '<p><em>{notice}</em></p>',
    'textContentNotice' => array(
      'de' => 'Hinweis: Dieser Artikel wurde mittels Google-Translate übersetzt.',
      'fr' => 'Remarque: Article traduit automatiquement avec Google Traduction',
      'en' => 'Notice: This article has been translated using Google-Translate.',
      'it' => 'Nota: contributo tradotto automaticamente con Google-Translate',
    ),
    'textTranslate' => 'Inhalt maschinell übersetzen',
    'textSource' => 'Quelle:',
    'textErrorNotSelected' => 'Bitte bestätigen Sie, dass sie diesen Inhalt maschinell übersetzen wollen.',
    'textConfirmationTranslation' => 'Sind Sie sicher, dass sie den Inhalt maschinell übersetzen wollen? Der bisherige Inhalt wird überschrieben.'
  );
  /**
   * @var string the api key to get translations
   */
  const API_KEY = 'AIzaSyBxIDzDx1X2qoBrEJPvcBSenk9amiYNz1A';

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return FocusPoint the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new MachineTranslation($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    add_action('add_meta_boxes', array($this, 'addMetaboxes'), 50);
    add_filter('wp_insert_post_data', array($this, 'runMachineTranslation'), 100, 1);
  }

  /**
   * Add the actual metabox for machine translations
   */
  public function addMetaboxes()
  {
    // Only show the box, if there is already another content where we can translate from
    $screen = get_current_screen();
    if (isset($screen->post_type) && in_array($screen->post_type, $this->config['availablePostTypes'])) {
      add_meta_box('machine-translation', 'Automatische Übersetzung', array($this, 'displayMetabox'), null, 'side', 'high');
    }
  }

  /**
   * Do the actual translation of contents
   * @param $data
   * @return mixed
   */
  public function runMachineTranslation($data)
  {
    if (isset($_POST['run-machine-translation']) && $_POST['run-machine-translation'] == 1) {
      $sourcePostId = intval($_POST['machine-translation-source']);
      $target = Multilang::getPostLang($_POST['post_ID']);
      $source = Multilang::getPostLang($sourcePostId);
      // Make a call for title and excerpt translation
      $this->runSimpleTranslations($data, $sourcePostId, $target, $source);
      // And run the content translation (which is more complex due to HTML)
      $this->runContentTranslation($data, $sourcePostId, $target, $source);
    }

    return $data;
  }

  /**
   * @param array $data referenced translatable array
   * @param int $sourceId the source post to use
   * @param string $target language
   * @param string $source language
   * @return array the translation data
   */
  protected function runSimpleTranslations(&$data, $sourceId, $target, $source)
  {
    $sourcePost = get_post($sourceId);
    // Get the strings, if given
    $strings = array();
    if (strlen($sourcePost->post_title)) {
      $strings[] = $sourcePost->post_title;
    }
    if (strlen($sourcePost->post_excerpt)) {
      $strings[] = $sourcePost->post_excerpt;
    }

    // Lets do some traslations
    $strings = self::getTranslation($strings, $source, $target);

    // Put this back into the data array, if useable
    if ($strings !== false) {
      if (strlen($sourcePost->post_title)) {
        $data['post_title'] = $strings[0];
      }
      if (strlen($sourcePost->post_excerpt)) {
        $data['post_excerpt'] = $strings[1];
      }
    }
  }

  /**
   * @param array $data referenced translatable array
   * @param int $sourceId the source post to use
   * @param string $target language
   * @param string $source language
   */
  protected function runContentTranslation(&$data, $sourceId, $target, $source)
  {
    $sourcePost = get_post($sourceId);
    // Very simple translation, loosing all formatting for now
    if (strlen($sourcePost->post_content) > 0) {
      $content = str_replace(PHP_EOL, '<br>', $sourcePost->post_content);
      $strings = self::getTranslation($content, $source, $target);
      if (strlen($strings[0]) > 0) {
        $data['post_content'] = str_replace('<br>', PHP_EOL, $strings[0]);
        // If needed, add a content translation notice
        if (strlen($this->config['addContentNotice']) > 0) {
          $data['post_content'] = $this->addContentNotice($data['post_content'], $target);
        }
      }
    }
  }

  /**
   * @param string $content the content
   * @param string $lang the target language
   * @return string content with added notice
   */
  protected function addContentNotice($content, $lang)
  {
    // If the content already contains the text, skip
    if (stristr($content, $this->config['textContentNotice'][$lang]) !== false) {
      return $content;
    }

    // Generate the notice html block
    $notice = Templating::getContainer(
      $this->config['contentNoticeContainer'],
      $this->config['textContentNotice'][$lang],
      '{notice}'
    );

    // Include it depending on configuration
    switch ($this->config['addContentNotice']) {
      case 'bottom':
        $content = $content . $notice;
        break;
      case 'top':
        $content = $notice . $content;
        break;
    }

    return $content;
  }

  /**
   * @param $strings
   * @param $source
   * @param $target
   * @param string $model
   * @return array|mixed|object
   */
  public static function getTranslation($strings, $source, $target, $model = 'nmt')
  {
    // Create the data array
    $data = array(
      'target' => $target,
      'source' => $source,
      'model' => $model,
      'key' => self::API_KEY
    );

    // Provide an array or one string for translation
    if (is_array($strings)) {
      if (count($strings) == 1) {
        $data['q'] = $strings[0];
      } else {
        $data['q'] = array($strings);
      }
    } else {
      $data['q'] = $strings;
    }

    // Make the call to goooooogle
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, 'https://translation.googleapis.com/language/translate/v2');
    curl_setopt($call, CURLOPT_POST, 1);
    curl_setopt($call, CURLOPT_SSLVERSION, 6);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($call, CURLOPT_POSTFIELDS, $data);

    $result = json_decode(curl_exec($call), true);
    curl_close($call);
    if (isset($result['data']['translations'])) {
      $strings = array();
      foreach ($result['data']['translations'] as $translation) {
        $strings[] = $translation['translatedText'];
      }
      return $strings;
    }

    return false;
  }

  /**
   * Add machine translations box including the simple scripts it uses
   */
  public function displayMetabox()
  {
    // Depending on state of the article to translate from, use hidden fields or dropdown
    if (isset($_GET['from_post']) && isset($_GET['new_lang'])) {
      $sourceSelection = '<input type="hidden" name="machine-translation-source" value="' . $_GET['from_post']  . '">';
    } else {
      // Let the user choose from maybe various posts if the posts is already existing
      $translations = Multilang::getPostTranslations(intval($_GET['post']), true);
      $sourceSelection = '
        <label for="machine-translation-source"><strong>' . $this->config['textSource'] . '</strong></label>
        <select name="machine-translation-source" id="machine-translation-source" style="width:75%;">
      ';
      // Get all the posts
      foreach ($translations as $language => $postId) {
        $sourceSelection .= '<option value="' . $postId . '">' . strtoupper($language) . ': ' . get_the_title($postId) . '</option>';
      }
      $sourceSelection .= '</select>';
    }

    // Print the html output for the box
    echo '
      <div class="mbh-item-normal">
        <div class="mbh-field machine-translation">
          <div class="mbh-input">
            <input type="checkbox" id="run-machine-translation" name="run-machine-translation" value="1" style="width:none;">
            <label for="run-machine-translation">' . $this->config['textTranslate'] . '</label>
          </div>
        </div>
        ' . $sourceSelection . '
      </div>
      <p>
        <a class="invoke-machine-translation button" href="#" >Übersetzung starten</a>
      </p>
    ';

    // Now, use some simple JS to invoke the translation
    echo '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".invoke-machine-translation").click(function() {
            if (!jQuery("#run-machine-translation").is(":checked")) {
              alert("' . esc_js($this->config['textErrorNotSelected']) . '");
              return;
            }
            if (confirm("' . esc_js($this->config['textConfirmationTranslation']) . '")) {
              jQuery("#title").val("{{placeholder}}");
              var saveButton = jQuery("#save-post");
              if (saveButton.length == 1) {
                saveButton.trigger("click");
              } else {
                jQuery("#publish").trigger("click");
              }
            }
          });
        });
      </script>
    ';
  }
}



