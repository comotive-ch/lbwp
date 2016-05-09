<?php

namespace LBWP\Util;

use AmazonS3;
use AmazonDynamoDB;

/**
 * Factory class to use the amazon sdk services
 * @author Michael Sebel <michael@comotive.ch>
 */
class AwsFactory {

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
   * @return AmazonS3 instance of the amazon s3 service object
   */
  public static function getS3Service()
  {
    require_once self::getSdkPath().'services/s3.class.php';
    $s3 = new AmazonS3(array(
      'key' => self::$CDN_ACCESS_KEY,
      'secret' => self::$CDN_SECRET_KEY
    ));
    // Scumbag amazon api. needs the region after 1.6.
    $s3->set_region(AmazonS3::REGION_EU_W1);
    if (defined('CDN_API_NAME')) {
      $s3->set_hostname(CDN_API_NAME);
    }

    return $s3;
  }

  /**
   *
   * @return string Base path to the amazon aws sdk
   */
  public static function getSdkPath() {
    return ABSPATH.PLUGINDIR.'/lbwp/resources/libraries/awsphpsdk_1_6/';
  }
}

// Include the basic core library
require_once(AwsFactory::getSdkPath().'sdk.class.php');