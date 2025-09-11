<?php

namespace LBWP\Module\General\Cms;

use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;

/**
 * Adjust th wp erase user data with our S3 upload
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class EraseUserData extends \LBWP\Module\Base
{
  // Default 3 days expiration
  const FILE_EXPIRATION_TIME = 259200;

  // Upload folder on the S3
  const S3_UPLOAD_DIR = 'wp-personal-data-exports/';

  public function __construct()
  {
    parent::__construct();
    $this->initialize();

    add_action('cron_daily_1', array($this, 'deleteOldExportFiles'));
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_action('wp_privacy_personal_data_export_file_created', array($this, 'uploadExportFile'), 10, 2);

    add_action('before_delete_post', function($postId){
      if(isset($_GET['request_id']) && $_GET['action'] === 'delete' && get_post_type($postId) === 'user_request' && get_current_screen()->id === 'export-personal-data'){
        $requests = $_GET['request_id'];

        foreach($requests as $request){
          $this->deleteExportFile(intval($request), true);
        }
      }
    });
  }

  /**
   * Upload generated file to S3
   * @param $localpath string wordpress generated zip file
   * @param $url string where on the S3 to upload the file
   * @return void
   */
  public function uploadExportFile($localpath, $url){
    /** @var S3Upload $upload the uploader to the configured cdn */
    $upload = LbwpCore::getInstance()->getModule(('S3Upload'));
    $upload->handleUpload($localpath, $url);
  }

  /**
   * Delete old export files (older than 3 days)
   * @return void
   */
  public function deleteOldExportFiles(){
    $exports = get_posts(array(
      'post_type' => 'user_request',
      'post_status' => 'any',
      'post_name' => 'export_personal_data'
    ));

    foreach($exports as $export){
      $this->deleteExportFile($export->ID);
    }
  }

  /**
   * @param $id int the request id
   * @param $force bool if the timestamp of the file should be ignored
   * @return bool
   */
  private function deleteExportFile($id, $force = false){
    $fileName = get_post_meta($id, '_export_file_name', true);

    // The file hasn't been generated yet
    if($fileName === ''){
      return false;
    }

    // Get time of the request either through the file timestamp or the db
    $lastModified = intval(get_post_meta($id, '_wp_user_request_completed_timestamp', true));

    // Calculate file age in seconds
    $fileAge = time() - $lastModified;

    // If file is old enough delete it
    if($force || self::FILE_EXPIRATION_TIME < $fileAge){
      /** @var S3Upload $upload the uploader to the configured cdn */
      $upload = LbwpCore::getInstance()->getModule(('S3Upload'));
      $upload->deleteFile( LbwpCore::getCdnFileUri() . '/' . self::S3_UPLOAD_DIR . $fileName);
    }

    return true;
  }
}