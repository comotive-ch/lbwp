<?php

namespace LBWP\Util;

use Aws\S3\S3Client;

/**
 * TODO PHP8 rework needed as old SDK doesn't work anymore, migrate to V3 object here
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
   * @var S3Client|null
   */
  protected $storageV3 = null;
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
    // Also open a storageV3 component to migrate
    $this->storageV3 = AwsFactoryV3::getS3CustomEndpointService(
      $access,
      $secret,
      CDN_API_NAME,
      CDN_REGION_ID,
      AwsFactoryV3::getSdkPath()
    );
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
  public function download($file, $destination, &$info = array())
  {
    try {
      $result = $this->storageV3->getObject(array(
        'Bucket' => $this->bucket,
        'Key' => $file
      ));
    } catch (\Exception $e) {
      return false;
    }

    if ($result->get('@metadata')['statusCode']== 200) {
      $info = $result;
      file_put_contents($destination, $result->get('Body'));
      return true;
    }

    return false;
  }

  /**
   * @param string $file
   */
  public function direct($file)
  {
    /** @var Aws\Result $result */
    $result = $this->storageV3->getObject(array(
      'Bucket' => $this->bucket,
      'Key' => $file
    ));
    if ($result->get('@metadata')['statusCode']== 200) {
      return $result->get('Body');
    }

    return '';
  }

  /**
   * @param string $prefix
   * @return array full list of objects with that prefix
   */
  public function listFiles($prefix)
  {
    $marker = '';
    $breaker = 0;
    $list = array();
    while (true || ++$breaker <= 100) {
      sleep(1);
      $result = $this->storageV3->listObjects(array(
        'Bucket' => $this->bucket,
        'Prefix' => $prefix,
        'Marker' => $marker,
        'MaxKeys' => 1000
      ));

      foreach ($result->get('Contents') as $file) {
        $list[(string) $file['Key']] = array(
          'ts' => strtotime((string)$file['LastModified']),
          'size' => (int) $file['Size']
        );
      }

      $marker = (string) $result->get('NextMarker');
      if (empty($marker) || $marker === false || $marker === NULL) {
        break;
      }
    }

    return $list;
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