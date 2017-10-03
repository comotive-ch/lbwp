<?php

namespace LBWP\Helper\MetaItem;

use LBWP\Core as LbwpCore;
use LBWP\Util\File;
use LBWP\Module\Backend\S3Upload;

/**
 * Helper object to provide html for native upload
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class NativeUpload
{
  protected static $printedScripts = false;
  /**
   * @param array $args list of arguments
   * @param string $key the field key
   * @param string $html the html template
   * @param array $fields list of known post fields
   * @return string html to represent the field
   */
  public static function displayNativeUpload($args, $key, $html, $fields = array())
  {
    // Get the current infos of the file, if given
    $fileInfo = get_post_meta($args['post']->ID, $args['key'], true);
    // If this is a first time call, add the inline scripts to handle upload/deletion
    if (!self::$printedScripts) {
      self::printScripts();
    }

    // Replace in the input field which is a file field
    $input = '<input type="file" id="' . $key . '" name="' . $key . '" />';
    // If there is file info, print info of the file and possibility to delete it
    $input .= self::getFileInformation($args, $fileInfo, $key);
    $html = str_replace('{input}', $input, $html);
    return $html;
  }

  /**
   * Inline callback to save the upload field or delete the file
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public static function saveNativeUpload($postId, $field, $boxId)
  {
    $key = $postId . '_' . $field['key'];
    $file = $_FILES[$key];
    /** @var S3Upload $uploader to the upload */
    $uploader = LbwpCore::getModule('S3Upload');

    // Only override if a new file is given
    if ($file['error'] == 0 && $file['size'] > 100) {
      $url = $uploader->uploadLocalFile($file, true);
      // Save everything we need to a post meta
      update_post_meta($postId, $field['key'], array(
        'url' => $url,
        'bytes' => $file['size'],
        'name' => $file['name'],
        'type' => $file['type'],
      ));
    }

    // Delete a file if desired
    if (isset($_POST['delete_' . $key]) && $_POST['delete_' . $key] == 1) {
      $fileInfo = get_post_meta($postId, $field['key'], true);
      $uploader->deleteFile($fileInfo['url']);
      delete_post_meta($postId, $field['key']);
    }
  }

  /**
   * @param array $args configuration arguments
   * @param array $file the infos about the file to display and reset it
   * @param string $key the display key of the file
   * @return string html
   */
  protected function getFileInformation($args, $file, $key)
  {
    $html = '';

    // Only display file info and deletion info, if a file is available
    if (is_array($file)) {
      $html .= '<p>
        <input type="hidden" id="delete_' . $key . '" name="delete_' . $key . '" value="0" />
        <a href="' . $file['url'] . '" target="_blank">' . $file['name'] . '</a>
        <a href="#" class="delete-native-file" data-id="delete_' . $key . '">(Löschen)</a>
      </p>';
    }

    return $html;
  }

  /**
   * Prints a simple script that handles the field infos
   */
  protected function printScripts()
  {
    echo '
      <script type="text/javascript">
        jQuery(function() {
          // Make sure the form is changed to enctype=multipart
          jQuery("form#post").attr("enctype", "multipart/form-data");
          // Have a handler on delete links and its hidden field
          jQuery(".delete-native-file").click(function() {
            var element = jQuery(this);
            var id = element.data("id");
            jQuery("#" + id).val(1);
            element.parent().find("a").remove();
          });
        });
      </script>
    ';

    // Set the scripts as printed
    self::$printedScripts = true;
  }
} 