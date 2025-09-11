<?php

namespace LBWP\Helper;

use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Class Location for locate the user based on IP or browser information
 * @package LBWP\Helper
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Location
{
  /**
   *
   */
  const IPINFO_DB_URL = 'https://assets01.sdd1.ch/assets/lbwp-cdn/comotive/lbwp/country.csv';

  /**
   * Get the prefered user language from the browser
   *
   * @return string the user browser language
   */
  public static function getLangFromBrowser()
  {
    $getLang = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0];
    return strtolower(substr($getLang, 0, 2));
  }

  /**
   * Get user location information based on the ip
   *
   * @return array|bool an array with locational information or false on failure
   */
  private static function getLocationFromIp()
  {
    $crawlerUA = array(
      'Googlebot',
      'Mediapartners-Google',
      'AdsBot-Google',
      'Bingbot',
      'Slurp',
      'DuckDuckBot',
      'Baiduspider',
      'YandexBot',
      'Sogou',
      'exabot',
      'facebot',
      'facebookexternalhit',
      'ia_archiver',
    );

    if (isset($_SERVER['HTTP_USER_AGENT']) && Strings::containsOne($_SERVER['HTTP_USER_AGENT'], $crawlerUA)) {
      return array(
        'ip' => '192.168.200.1',
        'country' => 'CH'
      );
    } else if (isset($_SERVER['X_REAL_IP'])) {
      $ip = $_SERVER['X_REAL_IP'];
    } else if (isset($_SERVER['REMOTE_ADDR'])) {
      $ip = $_SERVER['REMOTE_ADDR'];
    } else {
      $ip = false;
    }

    $cachedLocation = wp_cache_get('lbwp_user_location_' . $ip, 'Location');
    if ($cachedLocation !== false) {
      return $cachedLocation;
    }

    // Download and cache database if not given
    if ($ip !== false) {
      $database = wp_cache_get_shared('lbwp_ipinfo_database_v2', 'db_json');
      if ($database === false) {
        $database = self::loadIpInfoDatabase();
      } else {
        $database = json_decode($database);
      }
      $response = array(
        'ip' => $ip,
        'country' => 'CH',
      );

      $long = ip2long($ip);
      foreach ($database as $line) {
        if ($long <= $line[1] && $line[0] <= $long) {
          $response['country'] = $line[2];
          break;
        }
      }

      wp_cache_set('lbwp_user_location_' . $ip, $response, 'Location', 86400);
      return $response;
    }

    return false;
  }

  /**
   * @return array
   */
  private static function loadIpInfoDatabase()
  {
    // Load the database gz
    ini_set('memory_limit', '1024M');
    $tempFile = File::getNewUploadFolder() . 'country.csv';
    $binary = file_get_contents(self::IPINFO_DB_URL);
    file_put_contents($tempFile, $binary);

    $database = array();
    if (($handle = fopen($tempFile, 'r')) !== false) {
      while (($data = fgets($handle)) !== false) {
        $line = str_getcsv($data);
        $long = ip2long($line[0]);
        if ($long !== false) {
          $database[] = array($long, ip2long($line[1]), $line[2]);
        }
      }
      fclose($handle);
    }

    // Save to general cache and return
    wp_cache_set_shared('lbwp_ipinfo_database_v2', 'db_json', json_encode($database), 864000);
    return $database;
  }

  /**
   * Get user location infos
   *
   * @param string $data the information to get. To get multiple data separate the data names with a comma.
   * Possible params:
   *    ip        -> the ip address
   *    country  -> the country (short version e.g. "CH")
   * @return string|array Default returns the country string else an array with locational information
   */
  public static function getUserLocation($data = 'country')
  {
    // Allow admin override mode
    if (current_user_can('administrator') || current_user_can('shop_manager')) {
      if (isset($_GET['country_code']) && strlen($_GET['country_code']) === 2) {
        $_SESSION['LocationHelper_country'] = strtoupper($_GET['country_code']);
      } else if (isset($_GET['country_code']) && strlen($_GET['country_code']) === 0) {
        unset($_SESSION['LocationHelper_country']);
      }
    }

    $locationInfo = apply_filters('lbwp_user_location_info', self::getLocationFromIp());

    // After getting info, maybe override with test mode
    if (isset($_SESSION['LocationHelper_country'])) {
      $locationInfo['country'] = $_SESSION['LocationHelper_country'];
    }

    // Return all information
    if ($data === 'all') {
      return $locationInfo;
    }

    // Return only choosen (multiple) inforamtion
    if (strpos($data, ',') !== false) {
      $multipleData = array();
      $data = explode(',', $data);

      foreach ($data as $param) {
        $param = trim($param);
        $multipleData[$param] = $locationInfo[$param];
      }

      return $multipleData;
    }

    // Defaul: return the country
    return $locationInfo[$data];
  }
} 