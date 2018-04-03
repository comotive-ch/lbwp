<?php

namespace LBWP\Util;

/**
 * Allows to access the json based instance config
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class InstanceConfig
{
  /**
   * @var array actual holder of config
   */
  protected static $config = array();

  /**
   * Loads the config from file or cache
   */
  public static function load()
  {
    $key = 'confData_' . md5(LBWP_INSTANCE_CONFIG);
    self::$config = wp_cache_get($key, 'InstanceConfig');

    // Load from local or remote file (which is why we even cache)
    if (self::$config === false) {
      // Load it from local path or remote url
      $path = LBWP_INSTANCE_CONFIG;
      if (Strings::startsWith(LBWP_INSTANCE_CONFIG, 'wp')) {
        $path = ABSPATH . LBWP_INSTANCE_CONFIG;
      }

      // Get raw json data and convert

      self::$config = json_decode(file_get_contents($path), true);
      wp_cache_set($key, self::$config, 'InstanceConfig', 3600);
    }
  }

  /**
   * @param string $key the key to access
   * @return mixed|bool false if not available, mixed if avaliable
   */
  public static function get($key)
  {
    if (isset(self::$config[$key])) {
      return self::$config[$key];
    }

    return false;
  }
}