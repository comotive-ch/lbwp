<?php

namespace LBWP\Util;

/**
 * Stop worrying about setcookie/times and cookie names. store everything here in this handy
 * helper class within a cookie that's valid for-virtually-ever. It does only handle basic datatypes
 * @author Michael Sebel <michael@comotive.ch>
 */
class Cookie
{
  /**
   * @var array Local data array
   */
  private static $data = array();
  /**
   * @var int The offset time where to cookie will expire
   */
  private static $offset = 0;

  /**
   * Initializes the empty cookie if it doesn't exist
   */
  private static function initialize()
  {
    if (self::$offset == 0)
      self::$offset = time() + (5 * 365 * 24 * 3600);
    if (!isset($_COOKIE['lbwpjc'])) {
      $secure = !defined('LOCAL_DEVELOPMENT');
      setcookie('lbwpjc', json_encode(array()), array(
        'expires' => self::$offset,
        'path' => '/',
        'secure' => $secure,
        'httponly' => $secure,
        'samesite' => 'Strict'
      ));
    } else {
      if (strlen($_COOKIE['lbwpjc']) < 10000) {
        self::$data = json_decode($_COOKIE['lbwpjc'], true);
      } else {
        self::$data = array();
      }
    }
  }

  /**
   * Pushes a new or overwrites an existing value to the cookie
   * Can be used only once as it calls setcookie, use multi for more
   * @param string $key the key to store the value in
   * @param string $value the value to be stored
   */
  public static function set($key, $value)
  {
    self::initialize();
    self::$data[$key] = $value;
    $_COOKIE['lbwpjc'] = json_encode(self::$data);
    $secure = !defined('LOCAL_DEVELOPMENT');
    setcookie('lbwpjc', $_COOKIE['lbwpjc'], array(
      'expires' => self::$offset,
      'path' => '/',
      'secure' => $secure,
      'httponly' => $secure,
      'samesite' => 'Strict'
    ));
  }



  /**
   * Pushes a new or overwrites an existing value to the cookie
   * @param array $multi key values
   */
  public static function multi($multi)
  {
    self::initialize();
    foreach ($multi as $key => $value) {
      self::$data[$key] = $value;
    }
    $_COOKIE['lbwpjc'] = json_encode(self::$data);
    $secure = !defined('LOCAL_DEVELOPMENT');
    setcookie('lbwpjc', $_COOKIE['lbwpjc'], array(
      'expires' => self::$offset,
      'path' => '/',
      'secure' => $secure,
      'httponly' => $secure,
      'samesite' => 'Strict'
    ));
  }

  /**
   * Gets the information of a specific key
   * @param string $key The key you want to get the value of
   * @param mixed $default default value, if the key is not found
   * @return mixed the value of the stored key
   */
  public static function get($key,$default = false)
  {
    self::initialize();
    if (isset(self::$data[$key])) {
      return self::$data[$key];
    } else {
      return $default;
    }
  }
}