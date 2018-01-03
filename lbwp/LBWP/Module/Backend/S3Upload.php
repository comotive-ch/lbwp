<?php

namespace LBWP\Module\Backend;

use AmazonS3;
use LBWP\Core;
use LBWP\Util\AwsFactory;
use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Hooks in to the media upload and synchronizes the media library with the s3,
 * so every uploaded file is loaded from s3 instead of the loadbalanced server.
 * This is what really makes this solution loadbalanceable.
 * @author Michael Sebel <michael@comotive.ch>
 */
class S3Upload extends \LBWP\Module\Base
{
  /**
   * @var bool tells if filters have been registered once
   */
  protected static $registeredFilters = false;
  /**
   * Defaults
   */
  const JPEG_QUALITY = 96;
  const MAX_IMAGE_SIZE = 1920;

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * This initializes the filters and actions
   */
  public function initialize()
  {
    // Don't handle anything if no CDN handling is at hands
    if (CDN_TYPE == CDN_TYPE_NONE) {
      return false;
    }

    // Register all the filters, but only once
    if (!self::$registeredFilters) {
      // filters to change the url and upload paths
      add_filter('option_upload_url_path', array($this, 'changeUploadUrl'));
      add_filter('option_upload_path', array($this, 'changeUploadPath'));
      add_filter('option_uploads_use_yearmonth_folders', array($this, 'disableYearMonthFolders'));
      add_filter('upload_dir', array($this, 'appendUploadHash'));
      // filters to handle file uploads, changes and deletions
      add_filter('wp_handle_upload', array($this, 'filterHandleUpload'));
      add_filter('wp_delete_file', array($this, 'filterDeleteFile'));
      // filters for uploading, only needed in admin
      add_filter('image_make_intermediate_size', array($this, 'filterHandleSizes'));
      add_filter('update_attached_file', array($this, 'filterUpdateAttachment'));
      add_filter('wp_generate_attachment_metadata', array($this, 'filterAttachmentMetadata'));
      add_filter('wp_save_image_file', array($this, 'filterSaveImage'), 10, 2);
      add_filter('wp_save_image_editor_file', array($this, 'filterEditorFile'), 10, 2);
      AwsFactory::setKeys(CDN_ACCESS_KEY, CDN_SECRET_KEY);
      self::$registeredFilters = true;
      // Set asset key constant if not defined in customer config
      if (!defined('ASSET_KEY')) {
        define('ASSET_KEY', CUSTOMER_KEY);
      }
      // Call wp_upload_dir once, since it could be caching from an earlier call without the filters
      wp_upload_dir(null, true, true);
    }
  }

  /**
   * @param null $saved not changed
   * @param string $filename the filename to edit
   * @return null
   */
  public function filterEditorFile($saved, $filename)
  {
    // create the to be saved folder locally if it doesn't (and it doesn't usually!) exist
    $folder = File::getFileFolder($filename);
    if (!file_exists($folder)) {
      mkdir($folder, 0755);
    }
    return $saved;
  }

  /**
   * Changes upload handling for S3 uploads
   */
  public function changeUploadPath($path)
  {
    return 'wp-content/uploads/' . ASSET_KEY;
  }

  /**
   * Changes upload handling for S3 uploads
   */
  public function changeUploadUrl($path)
  {
    return Core::getCdnFileUri();
  }

  /**
   * Changes upload handling for S3 uploads
   */
  public function disableYearMonthFolders($option)
  {
    return false;
  }

  /**
   * Changes upload handling for S3 uploads
   */
  public function appendUploadHash($array)
  {
    $array['path'] .= '/' . $_SERVER['REQUEST_TIME'];
    $array['url'] .= '/' . $_SERVER['REQUEST_TIME'];
    $array['subdir'] .= '/' . $_SERVER['REQUEST_TIME'];
    return $array;
  }

  /**
   * Filter which is fired when deleting media. This deletes it on S3 too.
   * @param string $filename The name of the file to delete (123456789/filename.jpg)
   * @return string The filename 1:1 since it's a filter
   */
  public function filterDeleteFile($filename)
  {
    if (stristr($filename, ASSET_KEY)) {
      $filename = substr($filename, strpos($filename, ASSET_KEY) + strlen(ASSET_KEY) + 1);
    }
    $filename = ASSET_KEY . '/files/' . $filename;

    $this->deleteFile($filename);
    // Return it 1:1 we just needed to delete it from S3
    return $filename;
  }

  /**
   * Deletes a file from S3
   * @param string $filename The name of the file to delete (123456789/filename.jpg)
   */
  public function deleteFile($filename)
  {
    $s3 = AwsFactory::getS3Service();
    $opts = array('curlopts' => array(CURLOPT_SSL_VERIFYPEER => false));
    // Delete it.
    $filename = str_replace(Core::getCdnProtocol() . '://' . Core::getCdnName() . '/', '', $filename);
    $s3->delete_object(CDN_BUCKET_NAME, $filename, $opts);
  }

  /**
   * Creates the folder to locally save the image for upload, if it doesn't exist.
   * This is needed for the image manipulation features
   * @param mixed $saved bogus var, we shall return as it is
   * @param string $filename The filename to be written
   * @return mixed return input as is
   */
  public function filterSaveImage($saved, $filename)
  {
    $path = File::getFileFolder($filename);
    if (!file_exists($path))
      mkdir($path, 0777);
    return $saved;
  }

  /**
   * @param string $url the url to to download the file
   * @param string $filename the filename (optional), if not given, it takes the name from url
   * @return string the s3 link
   */
  public function importFileFromUri($url, $filename = '')
  {
    // Get the filename from url, if not given
    if (strlen($filename) == 0) {
      $filename = File::getFileOnly($url);
    }
    // Save the file locally
    $localFile = File::getNewUploadFolder() . $filename;
    file_put_contents($localFile, file_get_contents($url));
    // Generate the url it should have on the s3
    $s3Url = $this->changeUploadUrl('') . '/' .  time() . '/' . $filename;

    // Return the s3 Url if the upload is successful
    if ($this->handleUpload($localFile, $s3Url)) {
      return $s3Url;
    } else {
      // Import failed, return an empty string
      return '';
    }
  }

  /**
   * @param array $metadata the image metadata
   * @return array returns metadata 1:1
   */
  public function filterAttachmentMetadata($metadata)
  {
    $folder = substr($metadata['file'], 0, strpos($metadata['file'], '/'));
    $path = WP_CONTENT_DIR . '/uploads/' . ASSET_KEY . '/' . $folder;
    File::deleteFolder($path);
    return $metadata;
  }

  /**
   * Used to upload an edited original image to S3 (image modification feature)
   * @param string $local_file The local name of the new file
   * @param int $attachment_id The id of the attachment (not always transferred)
   * @return string returns the unchanged path or NULL if the upload failed
   */
  public function filterUpdateAttachment($local_file, $attachment_id = 0)
  {
    return $this->filterHandleSizes($local_file);
  }

  /**
   * Upload of different image sizes. Hell yes!
   * @param string $local_file The local filename
   * @return string Returns NULL if the S3 Upload fails
   */
  public function filterHandleSizes($local_file)
  {
    $local_file = $this->fixAndRename($local_file);
    // Create the URL
    $file_url = substr($local_file, strpos($local_file, ASSET_KEY) + strlen(ASSET_KEY));
    $file_url = Core::getCdnFileUri() . $file_url;
    // Upload and return data 1:1 if everything went fine
    if ($this->handleUpload($local_file, $file_url)) {
      return $local_file;
    }
  }

  /**
   * Handles the single file upload (main image i.e.)
   * @param $data Array of file, url and typoe
   * @return array Returns it 1:1 if success and NULL if the upload failed
   */
  public function filterHandleUpload($data)
  {
    $data['file'] = $this->fixAndRename($data['file']);
    $data['url'] = $this->fixAndRename($data['url']);
    // Upload and return data 1:1 if everything went fine
    if ($this->handleUpload($data['file'], $data['url'], $data['type'])) {
      return $data;
    }
  }

  /**
   * @param array $file entry from $_FILES
   * @return string the final url of the file
   */
  public function uploadLocalFile($file, $skipMaxImageSize = false)
  {
    // Move the file to a local temp path
    $localFile = $this->moveUploadedFile($file['tmp_name'], $file['name']);
    // Create the url
    $s3Path = '/' . time() . '/' . File::getFileOnly($localFile);
    $s3Url = $this->changeUploadUrl('') . $s3Path;
    // Do the upload
    if ($this->handleUpload($localFile, $s3Url, $file['type'], $skipMaxImageSize)) {
      return $s3Url;
    }

    return '';
  }

  /**
   * @param array $file entry from $_FILES
   * @return string the final url of the file
   */
  public function uploadDiskFile($file, $type = '')
  {
    // Move the file to a local temp path
    $localFile = $this->fixAndRename($file);
    // Create the url
    $s3Path = '/' . time() . '/' . File::getFileOnly($localFile);
    $s3Url = $this->changeUploadUrl('') . $s3Path;
    // Do the upload
    if ($this->handleUpload($localFile, $s3Url, $type)) {
      return $s3Url;
    }

    return '';
  }

  /**
   * @param array $file the file path
   * @param string $name the file name it should have afterwards
   * @return string the full path of the file
   */
  public function moveUploadedFile($file, $name)
  {
    // Create the path
    $path = ABSPATH . 'wp-content/uploads/' . ASSET_KEY . '/' . time() . '/';
    if (!file_exists($path)) {
      mkdir($path, 0777, true);
    }

    // Move the file
    $renamedFile = $path . $name;
    $renamedFile = $this->fixAndRename($renamedFile);
    move_uploaded_file($file, $renamedFile);

    return $renamedFile;
  }

  /**
   * @param array $file entry from $_FILES
   * @return bool true/false if image or not
   */
  public function isImage($file)
  {
    switch ($file['type']) {
      case 'image/png':
      case 'image/gif':
      case 'image/jpg':
      case 'image/jpeg':
        return true;
    }

    return false;
  }

  /**
   * Handles the upload to CDN
   * @param string $localFile The local file name
   * @param string $url The URL it should have on the CDN
   * @param string $mime_type The mime type of the file
   * @param bool $skipMaxImageSize skip max image size
   * @return bool true/false if the upload is successful
   */
  public function handleUpload($localFile, $url, $mime_type = '', $skipMaxImageSize = false)
  {
    $s3 = AwsFactory::getS3Service();
    // Just for the backup-image sake, we need to handle the error that a local file does not exist as a non-error
    if (!file_exists($localFile)) {
      return true;
    }

    // Change to maximum image size, if known ending and image type
    if (!$skipMaxImageSize) {
      $this->handleMaxImageSize($localFile);
    }

    // Set some cache headers
    $headers = array(
      'Cache-Control' => 'max-age=' . (315360000),
      'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 315360000)
    );

    $options = array(
      'fileUpload' => $localFile,
      'acl' => AmazonS3::ACL_OPEN,
      'headers' => $headers,
      'curlopts' => array(CURLOPT_SSL_VERIFYPEER => false)
    );
    if (strlen($mime_type) > 0) {
      $options['contentType'] = $mime_type;
    }
    $s3_path = str_replace(Core::getCdnProtocol() . '://' . Core::getCdnName() . '/', '', $url);
    $result = $s3->create_object(CDN_BUCKET_NAME, $s3_path, $options);
    // Error or not determined by the request status
    if ($result->status == 200) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * @param string $localFile the file to be possibly resized
   * @return bool true if it worked
   */
  protected function handleMaxImageSize($localFile)
  {
    $editor = wp_get_image_editor($localFile);

    if ($editor instanceof \WP_Image_Editor) {
      $editor->set_quality(self::JPEG_QUALITY);
      $size = $editor->get_size();

      // Max size and default if not configured
      $maxSize = intval($this->config['Various:MaxImageSize']);
      if ($maxSize == 0) {
        $maxSize = self::MAX_IMAGE_SIZE;
      }

      if ($size['width'] >= $size['height'] && $size['width'] >= $maxSize) {
        // Resize by width parameter
        $editor->resize($this->config['Various:MaxImageSize'], NULL, false);
      } else if ($size['height'] >= $size['width'] && $size['height'] >= $maxSize) {
        // Resize by height parameter
        $editor->resize(NULL, $this->config['Various:MaxImageSize'], false);
      }

      // Save the image to the same file (without calling any endless looping filters)
      remove_filter('image_make_intermediate_size', array($this, 'filterHandleSizes'));
      $editor->save($localFile);
      add_filter('image_make_intermediate_size', array($this, 'filterHandleSizes'));

      return true;
    }

    return false;
  }

  /**
   * Strip any special character from the filename
   * @param string $file input filename
   * @return string output filename
   */
  protected function fixAndRename($file)
  {
    // Windows compatibility for local development
    if (is_file($file)) {
      $directory = dirname($file);
      $file_name = basename($file);
    } else {
      $file = str_replace('\\', '/', $file);
      $directory = substr($file, 0, strrpos($file, '/'));
      $file_name = substr($file, strrpos($file, '/') + 1);
    }
    // Replace known umlaut chars
    $file_name = str_replace('%c3%bc', 'ue', $file_name);
    $file_name = str_replace('%c3%a4', 'ae', $file_name);
    $file_name = str_replace('%c3%b6', 'oe', $file_name);
    // Strip everything fancy left
    Strings::alphaNumFiles($file_name);
    // Rename the file
    $new_file = $directory . '/' . $file_name;
    if (is_file($file))
      rename($file, $new_file);
    return $new_file;
  }

  /**
   * Returns the filesize in bytes for the given file name
   *
   * @param string $fileName
   * @return integer
   */
  public function getFileSize($fileName)
  {
    // Get the client
    AwsFactory::setKeys(CDN_ACCESS_KEY, CDN_SECRET_KEY);
    $s3 = AwsFactory::getS3Service();

    try {
      // Send the head request for the file
      $headData = $s3->get_object_headers(CDN_BUCKET_NAME, $fileName);
      // Return the file size if available
      if (is_object($headData)) {
        return intval($headData->header['content-length']);
      }
    } catch (\Exception $exception) {
      return 0;
    }

    return 0;
  }
}