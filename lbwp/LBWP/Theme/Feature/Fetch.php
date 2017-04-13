<?php

namespace LBWP\Theme\Feature;

/**
 * Basic fetch functions
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class Fetch
{
  /**
   * @var string prefix to all option pages
   */
  const OPTION_PREFIX = 'fetch_';
  /**
   * @var string the username, if basic auth is needed
   */
  public static $username = '';
  /**
   * @var string the password, if basic auth is needed
   */
  public static $password = '';

  /**
   * @param string $url the url to fetch
   * @param bool $ignore404 uf set to true, 4xx Errors will be ignored
   * @return string HTML Code that was fetched
   */
  public static function getContent($url = '', $ignore404 = false, $useProxy = false)
  {
    // The URL is set, try to get the contents with curl so we get HTTP Status too
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false, // do not verify ssl certificates (fails if they are self-signed)
      CURLOPT_HEADER => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.43 Safari/537.31 Comotive-Fetch-1.0',
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_COOKIEJAR => 'tempCookie',
    );

    // If required, go via the comotive proxy
    if ($useProxy) {
      $options[CURLOPT_PROXY] = 'http://46.101.12.125';
      $options[CURLOPT_PROXYPORT] = '3128';
      $options[CURLOPT_PROXYUSERPWD] = 'comotive:Kv8gnr9qd5erSquid';
    }

    // Use basic auth if requested
    if (strlen(self::$username) > 0) {
      $options[CURLOPT_USERPWD] = self::$username . ':' . self::$password;
    }

    $res = curl_init($url);
    curl_setopt_array($res, $options);
    $content = curl_exec($res);
    $http_status = curl_getinfo($res, CURLINFO_HTTP_CODE);
    curl_close($res);

    // Check if there is some content, if not, exit immediately
    if (strlen($content) <= 1) {
      exit('there seems to be no content');
    }
    if (stristr($content, 'Service Unavailable') !== false) {
      exit('the site says: service unavailable 503');
    }

    // Check for the status code, over 400 is bad
    if ($ignore404) {
      if ($http_status > 404) {
        exit('status code above 404: ' . $http_status);
      }
    } else {
      if ($http_status >= 400) {
        exit('status code above or equal to 400: ' . $http_status);
      }
    }

    // If we come here, return the content
    return $content;
  }

  /**
   * @param string $partKey the key to save to
   * @param string $content the content to save
   * @param string $lang the language to save in
   */
  public static function saveContent($partKey, $content, $lang = 'de')
  {
    update_option(self::getOptionKey($partKey, $lang), $content);
  }

  /**
   * @param string $partKey the key to get
   * @param string $lang language to display
   */
  public static function includeContent($partKey, $lang = 'de')
  {
    eval('?>' . get_option(self::getOptionKey($partKey, $lang)));
  }

  /**
   * @param string $partKey the key of the part to save
   * @param string $lang the language to save to
   * @return string the option key
   */
  protected static function getOptionKey($partKey, $lang = 'de')
  {
    global $table_prefix;
    return self::OPTION_PREFIX . CUSTOMER_KEY . '_' . $table_prefix . $partKey . '_' . $lang;
  }
} 