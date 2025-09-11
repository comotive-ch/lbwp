<?php

namespace LBWP\Module\General\Cms;

use LBWP\Core;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\BaseSingleton;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Allows users to replace files on the block store with the same link
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class ReplaceFile extends BaseSingleton
{
  /**
   * Called at admin menu, allows us to add a submenu for admins
   */
  public function run()
  {
    add_filter('attachment_fields_to_edit', array($this, 'addMediaReplaceLink'), 20, 2);
    add_filter('admin_menu', array($this, 'addSubmenuPage'), 20, 2);
  }

  /**
   * @return void
   */
  public function addSubmenuPage()
  {
    add_submenu_page(
      'upload.php',
      'Datei ersetzen',
      'Datei ersetzen',
      'administrator',
      'replace-file',
      array($this, 'displayUI')
    );
  }

  /**
   * @param $fields
   * @param $post
   * @return mixed
   */
  public function addMediaReplaceLink($fields, $post)
  {
    $mimeType = get_post_mime_type($post->ID);
    if ($mimeType === 'image/jpeg' || $mimeType === 'image/png') {
      $fields['file_replace_link'] = array(
        'label' => 'Bild ersetzen',
        'input' => 'html',
        'html' => '<a href="' . get_bloginfo('url') . '/wp-admin/upload.php?page=replace-file&attachment_id=' . $post->ID . '" target="_blank">Bild mit neuem Upload ersetzen</a>'
      );
    }

    return $fields;
  }

  /**
   * Displays the log information
   */
  public function displayUI()
  {
    $attachmentId = intval($_GET['attachment_id']);
    $attachment = false;

    if(wp_attachment_is_image($attachmentId)){
      $attachment = wp_get_attachment_metadata($attachmentId);
    }

    $replaceImage = '
      <p><strong>1.</strong> Link der zu ersetzenden Datei aus Mediathek kopieren</p>
      <p><input type="text" value="" name="replace-file-url" style="width:600px" /></p>';

    if($attachment !== false){
      $imageUrl = wp_get_attachment_image_url($attachmentId, 'original');
      $imageUrl = substr($imageUrl, 0, strripos($imageUrl,'?'));
      $replaceImage = '
        <p><strong>1.</strong> Die Datei «' . basename($attachment['file']) . '» wird ersetzt.</p>
        <p>
          <input type="hidden" name="replace-image-url" value="' . $imageUrl . '">
          <input type="hidden" name="replace-image-id" value="' . $attachmentId . '">
        </p>';
    }

    echo '
      <div class="wrap">
        <h2>Datei in der Mediathek ersetzen</h2>
        ' . $this->handleUpload() . '
        <p>Ersetze eine Datei, während der Link der Datei gleich bleibt. Bei sofort aktualisieren wird der Link geändert.</p>
        <form action="?page=replace-file&action=lbwp-save-replacement-file" method="post" enctype="multipart/form-data">
          ' . $replaceImage . '
          <p><strong>2.</strong> Wähle die Datei aus, welche die bestehende ersetzen soll</p>
          <p><input type="file" name="replacement-file" /></p>
          <p><label><input type="checkbox" name="burst-cache" value="on"> Datei sofort aktualisieren</label></p> 
          <input type="submit" value="Datei hochladen und ersetzen" class="button button-primary" />
        </form>
      </div>
    ';
  }

  /**
   * @return string
   */
  protected function handleUpload()
  {
    $message = '';
    // Check if we need to upload
    if (isset($_GET['action']) && $_GET['action'] == 'lbwp-save-replacement-file') {
      $error = false;
      $file = $_FILES['replacement-file'];
      // Leave if there is a general upload error
      if ($file['error'] != 0) {
        $error = true;
        $message = 'Beim Upload ist ein Fehler aufgetreten.';
      }

      /** @var S3Upload $s3 Get the basic variables from the url */
      $s3 = Core::getModule('S3Upload');

      // Make sure to replace the assets domain, as getKeyFromUrl expects LBWP_HOST as domain
      if(isset($_POST['replace-image-url']) && strlen($_POST['replace-image-url']) > 0){
        $url = str_replace('https://assets01.sdd1.ch/assets/lbwp-cdn/', '', $_POST['replace-image-url']);
      }else if (isset($_POST['replace-file-url']) && strlen($_POST['replace-file-url']) > 0) {
        $url = str_replace('https://assets01.sdd1.ch/assets/lbwp-cdn/', '', $_POST['replace-file-url']);
      } else {
        $url = wp_get_attachment_image_url($_POST['replace-image-id'], 'original');
      }

      $key = $s3->getKeyFromUrl($url);

      // Validate if the key starts with the customers prefix
      if (!Strings::startsWith($key, ASSET_KEY)) {
        $error = true;
        $message = 'Sie haben nicht die Berechtigung diese Datei zu ersetzen.';
      }
      // Check if file endings are identical
      $currentExt = File::getExtension($key);
      $uploadExt = File::getExtension($file['name']);
      if ($currentExt != $uploadExt) {
        $error = true;
        $message = 'Abgebrochen, da die neue Datei nicht vom gleichen Typ ist.';
      }

      if (strlen($message) == 0) {
        // Actually upload the new file to the old key
        $originalName = File::getFileOnly($key);

        if($_POST['burst-cache'] === 'on'){
          $oldPath = substr(File::getFileFolder($key), 0, -1);
          $newFile = time() . '/' . $originalName;
          $newPath = substr(File::getFileFolder($key), 0, strrpos($oldPath, '/')) . '/' . $newFile;
          $key = $newPath;
        }

        $originalPath = ABSPATH . 'wp-content/uploads/' . str_replace('/files/', '/', $key);
        $localFile = $s3->moveUploadedFile($file['tmp_name'], $originalName);
        mkdir(File::getFileFolder($originalPath), 0777, true);
        rename($localFile, $originalPath);
        $localFile = $originalPath;
        if ($s3->handleKeyUpload($localFile, $key)) {
          if(isset($_POST['burst-cache'])){
            $fileUrl = $_POST['replace-file-url'] ?? $_POST['replace-image-url'];
            $fileId = intval($_POST['replace-image-id']) > 0 ? $_POST['replace-image-id'] : WordPress::getAttachmentIdFromUrl($fileUrl);
            update_post_meta($fileId, '_wp_attached_file', $newFile);
          }

          if(isset($_POST['replace-image-id'])) {
            $meta = wp_generate_attachment_metadata($_POST['replace-image-id'], $localFile);
            wp_update_attachment_metadata($_POST['replace-image-id'], $meta);
          }

          $message = 'Die Datei wurde erfolgreich ersetzt.';
        } else {
          $error = true;
          $message = 'Fehler beim verschieben der lokalen Datei auf den entfernten Datenspeicher.';
        }
      }

      // Make the message printable
      if (strlen($message) > 0) {
        $class = ($error) ? 'notice-error' : 'notice-success';
        $message = '<div class="notice ' . $class . '"><p>' . $message . '</p></div>';
      }
    }

    return $message;
  }
}