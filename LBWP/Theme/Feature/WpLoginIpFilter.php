<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;

/**
 * Restrict login for selected ips
 * @author Mirko Baffa <mirko@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class WpLoginIpFilter
{
  /**
   * @var WpLoginIpFilter the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $ipList = array();

  /**
   * Can only be called within init
   */
  protected function __construct($ipList)
  {
    $this->ipList = ArrayManipulation::deepMerge($this->ipList, $ipList);
  }

  /**
   * @return WpLoginIpFilter the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($ipList = array())
  {
    if(defined('LOCAL_DEVELOPMENT')){
      return;
    }

    self::$instance = new WpLoginIpFilter($ipList);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    if(!empty($this->ipList) && preg_match('/(wp-login.php)/', $_SERVER['REQUEST_URI'])){
      add_action('init', array($this, 'checkIps'));
    }
  }

  /**
   * @param string $ip the ip address to check
   * @param string $cidr the cidr to look in
   * @return bool true/false if the ip is whitin the cidr
   */
  protected function cidrMatch($ip, $cidr)
  {
    list($subnet, $mask) = explode('/', $cidr);
    if ((ip2long($ip) & ~((1 << (32 - $mask)) - 1) ) == ip2long($subnet)) {
      return true;
    }

    return false;
  }

  /**
   * Check the allowed ips from the list
   * @return void
   */
  public function checkIps(){
    $ip = $_SERVER['X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'];

    $ipFound = in_array($ip, $this->ipList, true);

    // FYI: Got this from google but seems legit
    if(!$ipFound){
      // Check if this IP is in CIDR
      foreach($this->ipList as $cidr){
        if(strpos($cidr, '/') !== false){
          $_ip = ip2long($ip);
          // expand the range of ips.
          list($net, $mask) = explode( '/', $cidr, 2);
          // subnet.
          $ipNet  = ip2long($net);
          $inMask = ~((1 << (32 - $mask)) - 1);
          if(($_ip & $inMask) === ($ipNet & $inMask)){
            $ipFound = true;
            break;
          }
        }
      }
    }

    if(!$ipFound){
      header('HTTP/1.0 403 Forbidden');
      wp_die('403 Forbidden');
      exit;
    }

  }
}