<?php

namespace LBWP\Util;

/**
 * Very simple class to manage temporary local usage of
 * files in a s3 compatible block storage
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class BlockFile
{
  /**
   * @var \AmazonS3 the s3 storage object
   */
  protected $storage = null;
  /**
   * @var string the bucket name to operate with
   */
  protected $bucket = '';

  /**
   * Create a connection to the S3 compatible endpoint
   * @param string $endpoint S3 compatible REST endpoint
   * @param string $bucket the bucket name
   * @param string $access the access key
   * @param string $secret the secret key
   */
  public function __construct($endpoint, $bucket, $access, $secret)
  {
    $this->bucket = $bucket;
    $this->storage = AwsFactory::getCustomS3Service($access, $secret);
    // Set a region even if not needed and the custom endpoint
    $this->storage->set_region(\AmazonS3::REGION_EU_W1);
    $this->storage->set_hostname($endpoint);
  }

  /**
   * @param string $localFile the local file on disk to be uploaded
   * @param string $destination the path of the file in the bucket
   * @param string $acl the ACL to be added to the file (default: private)
   * @return bool true if the upload suceeded
   */
  public function upload($localFile, $destination, $acl = \AmazonS3::ACL_PRIVATE)
  {
    $options = array(
      'fileUpload' => $localFile,
      'acl' => $acl,
      'curlopts' => array(CURLOPT_SSL_VERIFYPEER => false)
    );

    // Upload and return the url of the object
    $result = $this->storage->create_object($this->bucket, $destination, $options);
    return ($result->status == 200);
  }

  /**
   * @param string $file
   * @param string $destination
   */
  public function download($file, $destination)
  {
    $result = $this->storage->get_object($this->bucket, $file);
    file_put_contents($destination, $result->body);
  }

  /**
   *
   * @return string Base path to the amazon aws sdk
   */
  public static function getSdkPath()
  {
    return ABSPATH.PLUGINDIR.'/lbwp/resources/libraries/awsphpsdk_1_6/';
  }
}