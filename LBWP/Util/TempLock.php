<?php

namespace LBWP\Util;
use LBWP\Module\General\Cms\SystemLog;

/**
 * Allows temporary locks within the code, based on wp cache
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class TempLock
{
  /**
   * @param string $name name of the lock
   * @param int $duration duration of the lock
   */
  public static function set($name, $duration = 300)
  {
    $db = WordPress::getDb();
    $expiration = time() + $duration;
    
    // Delete previous lock
    self::raise($name);

    // Insert a new lock
    $db->insert($db->options, array(
      'option_name' => 'Lbwp_TempLock_' . $name,
      'option_value' => $expiration,
      'autoload' => 'no'
    ));
  }

  /**
   * @param string $name the name of the lock
   * @return bool true, if the lock is active
   */
  public static function check($name)
  {
    $lockActive = false;
    $db = WordPress::getDb();
    $sql = 'SELECT option_value FROM {sql:optionTable} WHERE option_name LIKE {lockName}';
    $lock = intval($db->get_var(Strings::prepareSql($sql, array(
      'optionTable' => $db->options,
      'lockName' => 'Lbwp_TempLock_' . $name
    ))));

    // Check the lock if is has a time and it expired
    if ($lock > 0) {
      if (time() < $lock) {
        $lockActive = true;
        SystemLog::add('TempLock', 'debug', 'TempLock "' . $name . '" prevented double execution of method"');
      } else {
        self::raise($name);
      }
    }

    return $lockActive;
  }

  /**
   * Raises/Removes a lock (doesn't check for its existance)
   * @param string $name the name of the lock
   */
  public static function raise($name)
  {
    $db = WordPress::getDb();
    $sql = 'DELETE FROM {sql:optionTable} WHERE option_name LIKE {lockName}';
    $db->query(Strings::prepareSql($sql, array(
      'optionTable' => $db->options,
      'lockName' => 'Lbwp_TempLock_' . $name
    )));
  }
}