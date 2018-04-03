<?php

namespace LBWP\Util;

use Aws\S3\S3Client;

/**
 * Factory class to use the amazon sdk services
 * @author Michael Sebel <michael@comotive.ch>
 */
class AwsFactoryV3 {

  /**
   * @var string Access Key
   */
  public static $CDN_ACCESS_KEY = '';
  /**
   * @var string Secret Key
   */
  public static $CDN_SECRET_KEY = '';

  /**
   * Set the keys to instantiate the classes
   * @param string $access An Amazon IAM/Main User Access Key
   * @param string $secret An Amazon IAM/Main User Secret Key
   */
  public static function setKeys($access,$secret)
  {
    self::$CDN_ACCESS_KEY = $access;
    self::$CDN_SECRET_KEY = $secret;
  }

  /**
   * returns an instance of the amazon s3 service object
   * @return S3Client instance of the amazon s3 service object
   */
  public static function getS3Service()
  {
    require_once self::getSdkPath() . 'aws-autoloader.php';

    $config = array(
      'credentials' => array(
        'key' => self::$CDN_ACCESS_KEY,
        'secret' => self::$CDN_SECRET_KEY,
      ),
      'signature_version' => 'v4',
      'version' => '2006-03-01',
      'region' => CDN_REGION_ID
    );

    // Set custom endpoint, if given when exoscale is active
    if (defined('CDN_API_NAME')) {
      $config['endpoint'] = 'https://' . CDN_API_NAME;
      $config['use_path_style_endpoint'] = true;
    }

    return new S3Client($config);
  }

  /**
   *
   * @return string Base path to the amazon aws sdk
   */
  public static function getSdkPath() {
    return ABSPATH.PLUGINDIR.'/lbwp/resources/libraries/awsphpsdk_3_52_29/';
  }
}