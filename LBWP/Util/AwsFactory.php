<?php

namespace LBWP\Util;

use \AmazonS3;

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
   * @return \AmazonS3 instance of the amazon s3 service object
   */
  public static function getS3Service()
  {
    require_once self::getSdkPath().'services/s3.class.php';
    $s3 = new AmazonS3(array(
      'key' => self::$CDN_ACCESS_KEY,
      'secret' => self::$CDN_SECRET_KEY
    ));

    // Set the region and if given a custom hostname
    $s3->set_region(AmazonS3::REGION_EU_W1);
    if (defined('CDN_API_NAME')) {
      $s3->set_hostname(CDN_API_NAME);
    }

    return $s3;
  }

  /**
   * @param string $key
   * @param string $secret
   * @return \AmazonS3
   */
  public static function getCustomS3Service($key, $secret)
  {
    require_once self::getSdkPath().'services/s3.class.php';
    return new AmazonS3(array(
      'key' => $key,
      'secret' => $secret
    ));
  }

  /**
   * returns an instance of the amazon s3 service object
   * @return \AmazonSES instance of the amazon s3 service object
   */
  public static function getSesService($key, $secret, $region)
  {
    require_once self::getSdkPath().'services/ses.class.php';
    $ses = new \AmazonSES(array(
      'key' => $key,
      'secret' => $secret
    ));

    // Set the region
    $ses->set_region($region);

    return $ses;
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