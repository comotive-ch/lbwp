<?php

namespace LBWP\Module\General;

use LBWP\Core;
use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Various author features and filters, also: helpers.
 * @author Michael Sebel <michael@comotive.ch>
 */
class AuthorHelper extends \LBWP\Module\Base
{
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
    // Only add filters for displaying, if needed
    if (is_admin() && isset($_REQUEST['user_id']) || stristr($_SERVER['REQUEST_URI'], 'profile.php') !== false) {
      add_action('admin_enqueue_scripts', array($this, 'addMediaResources'));
      add_action('show_user_profile', array($this, 'displayOptions'));
      add_action('edit_user_profile', array($this, 'displayOptions'));
      add_action('personal_options_update', array($this, 'saveOptions'));
      add_action('edit_user_profile_update', array($this, 'saveOptions'));
    }

    // Override avatar, if a user image is given
    add_filter('get_avatar', array($this, 'getUserAvatar'), 50, 2);
  }

  /**
   * Media and our own uploader config JS
   */
  public function addMediaResources()
  {
    wp_enqueue_media();
    $url = File::getResourceUri() . '/js/generic-media-upload.js';
    wp_enqueue_script('generic-media-upload', $url, array('jquery'), Core::REVISION, 'all');
  }

  /**
   * @param $fieldId
   * @param $title
   * @param $userId
   * @param $imageSize
   * @param string $description
   */
  public static function printImageUploadHtml($fieldId, $title, $userId, $imageSize, $description = '')
  {
    if ($fieldId == 'author-image-id') {
      $imageUrl = self::getAuthorImage($userId, $imageSize);
      $imageId = self::getAuthorImageId($userId);
    } else {
      $imageId = intval(get_user_meta($userId, $fieldId, true));
      list($imageUrl) = wp_get_attachment_image_src($imageId, $imageSize);
    }

    // Predefine HTML
    if (strlen($imageUrl) > 0) {
      $imageHtml = '
        <div id="' . $fieldId . '">
          <img src="' . $imageUrl . '" width="200" />
        </div>
      ';
    } else {
      $imageHtml = '
        <div id="' . $fieldId . '" class="hidden">
          <img src="" width="200" />
        </div>
      ';
    }

    // Print the output for the image editor
    echo '
      <tr>
        <th><label>' . $title . '</label></th>
        <td>
          <p class="hide-if-no-js">
            <a href="javascript:void(0);" title="' . __('Bild hochladen', 'lbwp') . '" class="generic-media-upload" data-save-to="' . $fieldId . '">' . __('Bild hochladen', 'lbwp') . '</a> |
            <a href="javascript:void(0);" title="' . __('Bild entfernen', 'lbwp') . '" class="remove-generic-media-upload" data-remove-image="' . $fieldId . '">' . __('Bild entfernen', 'lbwp') . '</a>
          </p>
          ' . $imageHtml . '
          <input type="hidden" name="' . $fieldId . '" value="' . $imageId . '" />
          <p class="description">' . $description . '</p>
        </td>
      </tr>
    ';
  }

  /**
   * Display the options
   */
  public function displayOptions()
  {
    global $user_id;
    $userId = intval($user_id);
    $authorShortDesc = get_user_meta($userId, 'short-description', true);

    echo apply_filters('AuthorHelper_author_section_title', '<h3>' . __('Angaben zum Autor', 'lbwp') . '</h3>');
    echo '<table class="form-table author-section">';

    if (apply_filters('AuthorHelper_show_author_image_field', true)) {
      self::printImageUploadHtml(
        'author-image-id',
        __('Autorenbild', 'lbwp'),
        $userId,
        'medium',
        __('Das Bild wird nur genutzt, wenn das Theme dies auch unterstützt.', 'lbwp')
      );
    }

    // Let theme developer decide to hide this field
    if (apply_filters('AuthorHelper_show_shortdesc_field', true)) {
      echo '
        <tr>
					<th>
						<label for="short-description">' . __('Kurzbeschreibung', 'lbwp') . '</label>
					</th>
					<td>
					  <input class="regular-text" type="text" id="short-description" name="short-description" value="' . esc_attr($authorShortDesc) . '">
					</td>
				</tr>
      ';
    }

    // Let theme developers add their own fields to the table
    do_action('AuthorHelper_display_additonal_fields', $userId);

    // Close the table
    echo '</table>';
  }

  /**
   * Saves the author options
   */
  public function saveOptions()
  {
    $userId = intval($_POST['user_id']);

    // Save the author image id
    if ($userId > 0 && isset($_POST['author-image-id'])) {
      $authorImageId = intval($_POST['author-image-id']);
      update_user_meta($userId, 'author-image-id', $authorImageId);
    }

    if ($userId > 0 && isset($_POST['short-description'])) {
      update_user_meta($userId, 'short-description', $_POST['short-description']);
    }

    do_action('AuthorHelper_save_additonal_fields', $userId);
  }

  /**
   * @param string $imageHtml given from gravatar function
   * @param int|\stdClass $authorId author id or comment object (go figure..)
   * @return string maybe a changed image object
   */
  public function getUserAvatar($imageHtml, $authorId)
  {
    if (!apply_filters('AuthorHelper_skip_user_avatar', false)) {
      // it is a comment object, if typeof stdClass
      if ($authorId instanceof \stdClass) {
        $authorId = intval($authorId->user_id);
      }

      if ($authorId > 0) {
        $avatarUrl = self::getAuthorImage($authorId);
        // If available, put it into the html code
        if (strlen($avatarUrl) > 0) {
          $currentUrl = Strings::parseTagProperty($imageHtml, 'src');
          $imageHtml = str_replace($currentUrl, $avatarUrl, $imageHtml);
        }
      }
    }

    return $imageHtml;
  }

  /**
   * @param int $authorId id of the user/author
   * @param string $imageSize desired image size, defaults to thumbnail
   * @return string url or NULL string to the image
   */
  public static function getAuthorImage($authorId, $imageSize = 'thumbnail')
  {
    $imageId = self::getAuthorImageId($authorId);
    list($imageUrl) = wp_get_attachment_image_src($imageId, $imageSize);
    return apply_filters('AuthorHelper_override_author_image_url', $imageUrl, $authorId, $imageSize);
  }

  /**
   * @param int $authorId id of the user/author
   * @return int the attachment id of the image used
   */
  public static function getAuthorImageId($authorId)
  {
    return intval(get_user_meta($authorId, 'author-image-id', true));
  }

  /**
   * @param int $userId the user to save
   * @param array $fields list of field objects with at least "id"
   */
  public static function saveAdditionalFields($userId, $fields)
  {
    if ($userId > 0) {
      foreach ($fields as $field) {
        if (isset($_POST[$field['id']])) {
          if (!is_array($_POST[$field['id']])) {
            $value = strip_tags($_POST[$field['id']], '<p><strong><em><a>');
          } else {
            $value = $_POST[$field['id']];
          }

          update_user_meta($userId, $field['id'], $value);
        }
      }
    }
  }

  /**
   * @param array $fields with id, title and value
   * @return string html code to display the fields
   */
  public static function getAdditionalInputs($fields)
  {
    $html = '';
    foreach ($fields as $field) {
      $html .= '
        <tr>
					<th>
						<label for="' . $field['id'] . '">' . $field['title'] . '</label>
					</th>
					<td>
					  <input class="regular-text" type="text" id="' . $field['id'] . '" name="' . $field['id'] . '" value="' . esc_attr($field['value']) . '">
					  <p class="description">' . $field['description'] . '</p>
					</td>
				</tr>
      ';
    }

    return $html;
  }

  /**
   * @param array $fields with id, title and value
   * @return string html code to display the fields
   */
  public static function getAdditionalTextareas($fields)
  {
    $html = '';
    foreach ($fields as $field) {
      $html .= '
        <tr>
					<th>
						<label for="' . $field['id'] . '">' . $field['title'] . '</label>
					</th>
					<td>
					  <textarea rows="3" cols="30" id="' . $field['id'] . '" name="' . $field['id'] . '">' . $field['value'] . '</textarea>
					  <p class="description">' . $field['description'] . '</p>
					</td>
				</tr>
      ';
    }

    return $html;
  }
}