<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provides a color picker on image level to for example set
 * a filling background for each inserted image
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class ImageColorPicker
{
  /**
   * @var ImageColorPicker the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array();

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = ArrayManipulation::deepMerge($this->config, $options);
  }

  /**
   * @return ImageColorPicker the mail service instance
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
    self::$instance = new ImageColorPicker($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    // In admin, load the specific scripts and filters
    if (is_admin()) {
      add_filter('attachment_fields_to_edit', array($this, 'addAttachmentField'), 10, 2);
      add_action('admin_footer', array($this, 'addScripts'));
      add_filter('edit_attachment', array($this, 'savePickedColor'));
    } else {
      add_action('wp_footer', array($this, 'handleBackgroundWrappers'));
    }
  }

  /**
   * Add a colored wrapper around imaged by mapping the color by id of all color meta items
   */
  public function handleBackgroundWrappers()
  {
    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT post_id, meta_value FROM ' . $db->postmeta . ' WHERE meta_value LIKE "%imageColorPicker%"');
    $mapping = array();
    foreach ($raw as $row) {
      $meta = maybe_unserialize($row->meta_value);
      $mapping[$row->post_id] = $meta['imageColorPicker'];
    }

    // Print an object to be used by the wrapper code
    echo '
      <script type="text/javascript">
        var imageColorPickerMapping = ' . json_encode($mapping) . ';        
        // Look at all wp-image images and maybe wrap them
        jQuery(function() {
          jQuery("[class*=wp-image]").each(function() {
            var image = jQuery(this);
            var classes = image.attr("class").split(" ");
            var id = 0;
            
            if (typeof(classes) == "object") {
              // Try finding out the id by looping trough classes
              for (var i in classes) {
                if (classes[i].indexOf("wp-image-") >= 0) {
                  id = parseInt(classes[i].replace("wp-image-", ""));
                }
              }
              // If the id is a number proceed to search for a color
              if (typeof(id) == "number" && typeof(imageColorPickerMapping[id]) == "string") {
                image.wrap(\'<div class="inline-image-wrapper" style="background-color:#\' + imageColorPickerMapping[id] + \'"></div>\')
              }
            }
          });
        });
      </script>
    ';
  }

  /**
   * Add some backend js to handle initialization of color picker within backend
   */
  public function addScripts()
  {
    echo '
      <script type="text/javascript" src="/wp-content/plugins/lbwp/resources/js/components/jscolor.js"></script>
      <script type="text/javascript">
        jQuery(function() {
          setInterval(function() {
            jQuery("input[id*=imageColorPicker]").each(function() {
              var element = jQuery(this);
              if (element.data("hasjscolor") != 1) {
                element.attr("onchange", "closeImagePicker();");
                element.data("hasjscolor", 1);
                var native = document.getElementById(jQuery(this).attr("id"));
                var picker = new jscolor(native);
              }
            })
          }, 1000);
        });
        // Close the image picker on select, by "searching" it
        function closeImagePicker() {
          if (jQuery(".jscolor-active").length == 1) {
            var inner = jQuery("body").find("div").last();
            if (inner.css("display") == "none") {
              inner.parent().parent().parent().remove();
            }
          }
        }
      </script>
    ';
  }

  /**
   * Saves the picked color into the main meta array of the attachment
   * @param $attachmentId
   */
  public function savePickedColor($attachmentId)
  {
    if ($attachmentId > 0 && $_POST['action'] == 'save-attachment-compat') {
      // Get current meta data to be update
      $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($attachmentId));
      $meta['imageColorPicker'] = Strings::forceSlugString($_POST['attachments'][$attachmentId]['imageColorPicker']);
      // Save the attachment meta data with the security state
      wp_update_attachment_metadata($attachmentId, $meta);
    }
  }

  /**
   * @param array $fields the fields
   * @param \WP_Post $attachment the attachment object
   * @return array $fields
   */
  public function addAttachmentField($fields, $attachment)
  {
    if (stristr($attachment->post_mime_type, 'image') !== false) {
      $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($attachment->ID));
      // Add the item to our fields array to be displayed
      $fields['imageColorPicker'] = array(
        'input' => 'text',
        'value' => $meta['imageColorPicker'],
        'label' => 'Hintergrundfarbe'
      );
    }

    return $fields;
  }
}



