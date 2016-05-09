<?php
/*
Name: Memcached Object Cache
Description: Modern Memcached backend for the WP Object Cache.
Version: 3.1
URI: http://www.comotive.ch
Author: Michael Sebel
*/

function wp_cache_add($key, $data, $flag = '', $expire = 0)
{
  global $wp_object_cache;
  return $wp_object_cache->add($key, $data, $flag, $expire);
}

function wp_cache_incr($key, $n = 1, $flag = '')
{
  global $wp_object_cache;
  return $wp_object_cache->incr($key, $n, $flag);
}

function wp_cache_decr($key, $n = 1, $flag = '')
{
  global $wp_object_cache;
  return $wp_object_cache->decr($key, $n, $flag);
}

function wp_cache_close()
{
  global $wp_object_cache;
  return $wp_object_cache->close();
}

function wp_cache_delete($id, $flag = '')
{
  global $wp_object_cache;
  return $wp_object_cache->delete($id, $flag);
}

function wp_cache_delete_by_key($id, $flag = '')
{
  global $wp_object_cache;
  return $wp_object_cache->deleteByKey($id, $flag);
}

function wp_cache_flush()
{
  if (class_exists('LBWP\Core')) {
    $memcached = LBWP\Core::getModule('MemcachedAdmin');
    if ($memcached instanceof LBWP\Module\Backend\MemcachedAdmin) {
      $memcached->flushCache();
      return true;
    }
  }

  return false;
}

function wp_cache_get($id, $flag = '')
{
  global $wp_object_cache;
  return $wp_object_cache->get($id, $flag);
}

function wp_cache_init()
{
  global $wp_object_cache;
  $wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace($key, $data, $flag = '', $expire = 0)
{
  global $wp_object_cache;
  return $wp_object_cache->replace($key, $data, $flag, $expire);
}

function wp_cache_set($key, $data, $flag = '', $expire = 0)
{
  global $wp_object_cache;
  if ( defined('WP_INSTALLING') == false ) {
    return $wp_object_cache->set($key, $data, $flag, $expire);
  } else {
    return true;
  }
}

function wp_cache_reset_keys($keys = array())
{
  global $wp_object_cache;
  return $wp_object_cache->reset_keys($keys);
}

function wp_cache_add_global_groups($groups)
{
  global $wp_object_cache;
  $wp_object_cache->addGlobalGroups($groups);
}

function wp_cache_add_non_persistent_groups($groups)
{
  global $wp_object_cache;
  $wp_object_cache->addNonPersistentGroups($groups);
}

function wp_cache_get_key($key, $group)
{
  global $wp_object_cache;
  return $wp_object_cache->getInstanceKey($key, $group);
}

function wp_cache_parse_key($key)
{
  global $wp_object_cache;
  return $wp_object_cache->parseInstanceKey($key);
}

function wp_cache_array_flush($keys)
{
  global $wp_object_cache;
	return $wp_object_cache->arrayFlush($keys);
}
function wp_cache_get_shared($sharedSpace, $key)
{
  global $wp_object_cache;
  return $wp_object_cache->getShared($sharedSpace, $key, '_shared');
}
function wp_cache_set_shared($sharedSpace, $key, $data, $expire = 0)
{
  global $wp_object_cache;
  return $wp_object_cache->setShared($sharedSpace, $key, $data, '_shared', $expire);
}

/**
 * @param $bucket the bucket name (default)
 * @return Memcached the bucket
 */
function wp_get_cache_bucket() {
  global $wp_object_cache;
  return $wp_object_cache->mc;
}

class WP_Object_Cache
{
  public $no_mc_groups = array( 'comment', 'counts' );
  public $autoload_groups = array ('options');
  public $cache = array();
  /**
   * @var Memcached[]
   */
  public $mc = NULL;
  public $stats = array();
  public $group_ops = array();
  public $global_groups = array();
  public $ids = null;
  public $table_prefix = '';
  public $keys_loaded = false;
  public $cache_enabled = true;
  public $default_expiration = self::DEFAULT_EXPIRATION;
  public $keyPrefix = '';
  // Default expiration
  const DEFAULT_EXPIRATION = 432000;
  const LOCAL_MC = 'f528764d624db129b32c21fbca0cb8d6';
  
  /**
   * Constructs the object
   * 
   * @global array $memcached_servers
   * @global string $table_prefix 
   */
  public function __construct()
  {
    global $memcachedServers, $table_prefix;

    // Declare prefix, key list and the persistent connection
    $this->keyPrefix = CUSTOMER_KEY . '_' . $table_prefix;

    // Add their own connection
    foreach ($memcachedServers as $host => $node) {
      $node[0] = (gethostname() == $host) ? '127.0.0.1' : $node[0];
      $hash = md5($node[0]);
      $this->mc[$hash] = new Memcached($hash);
      if (count($this->mc[$hash]->getServerList()) == 0) {
        $this->mc[$hash]->addServer($node[0], $node[1], 10);
        // Set options for failover and loadbalancing
        $this->mc[$hash]->setOptions(array(
          Memcached::OPT_NO_BLOCK => 1,
          Memcached::OPT_CONNECT_TIMEOUT => 50,
          Memcached::OPT_SERVER_FAILURE_LIMIT => 1,
          Memcached::OPT_RETRY_TIMEOUT => 1
        ));
      }
    }

    // Fallback to make sure there is a local instance (even if no server is available)
    if (!isset($this->mc[self::LOCAL_MC])) {
      $this->mc[self::LOCAL_MC] = new Memcached();
      $this->mc[self::LOCAL_MC]->addServer('127.0.0.1', 11211, 10);
    }
  }
  
  /**
   * Closes all memcached connections 
   */
  public function close()
  {
    // Does nothing, since connections are persistent
  }
  
  /**
   * Returns the reading connection
   * @return Memcached
   */
  public function getConnection()
  {
    return $this->mc[self::LOCAL_MC];
  }
  
  /**
   * Returns the whole instance key for the given key
   * 
   * @param string $key
   * @param string $group
   * @return string 
   */
  public function getInstanceKey($key, $group)
  {
    if (empty($group)) {
      $group = 'default';
    }

    $prefix = $this->keyPrefix . $group . '_' . $key;

    return str_replace(' ', '', $prefix);
  }

  /**
   * @param array $keys flushes all the keys
   */
  public function arrayFlush($keys)
  {
    foreach ($keys as $key => $expiration) {
      $this->deleteByKey($key);
    }
  }
  
  /**
   * Parses an instance key with the format DBNAME:PREFIX:GROUP:NAME
   * 
   * @param string $key
   * @return array 
   */
  public function parseInstanceKey($key)
  {
    $parts = explode(':', $key);
    unset($parts[0]);
    unset($parts[1]);

    return array(
      'group' => $parts[2],
      'name' => $parts[3]
    );
  }
  
  /**
   * Returns the expiration time
   * 
   * @param type $expire
   * @return type 
   */
  public function getExpirationTime($expire)
  {
    if ($expire == 0 || ($expire > $this->default_expiration && $expire <= 2592000)) {
      return $this->default_expiration;
    }
    
    return $expire;
  }
  
  /**
   * Adds a global group
   * @param array $groups
   */
  public function addGlobalGroups($groups)
  {
    if (!is_array($groups)) {
      $groups = (array) $groups;
    }

    $this->global_groups = array_merge($this->global_groups, $groups);
    $this->global_groups = array_unique($this->global_groups);
  }

  /**
   * Adds a non persistent group
   * @param array $groups
   */
  public function addNonPersistentGroups($groups)
  {
    if (!is_array($groups)) {
      $groups = (array) $groups;
    }

    $this->no_mc_groups = array_merge($this->no_mc_groups, $groups);
    $this->no_mc_groups = array_unique($this->no_mc_groups);
  }

  /**
   * To keep things consistent use set to override at all times
   * @param string $key
   * @param mixed $data
   * @param string $group
   * @param integer $expire
   * @return boolean 
   */
  public function add($key, $data, $group = 'default', $expire = 0)
  {
    return $this->set($key, $data, $group, $expire);
  }

  /**
   * Increments a value on the memcached server
   * 
   * @param string $key
   * @param integer $n
   * @param string $group
   * @return integer
   */
  public function incr($key, $n, $group)
  {
    $current = $this->get($key, $group);
    $key = $this->getInstanceKey($key, $group);

    $success = true;
    foreach ($this->mc as $mc) {
      if (!$mc->increment($key, $n)) {
        $success = false;
      }
    }

    // All good, if success
    if ($success) return true;

    // If not, rollback to previous (ignoring errors for good)
    foreach ($this->mc as $mc) {
      $mc->set($key, $current);
    }

    return false;
  }

  /**
   * Decrements a value on the memcached server
   * 
   * @param string $key
   * @param integer $n
   * @param string $group
   * @return integer 
   */
  public function decr($key, $n, $group)
  {
    $current = $this->get($key, $group);
    $key = $this->getInstanceKey($key, $group);

    $success = true;
    foreach ($this->mc as $mc) {
      if (!$mc->decrement($key, $n)) {
        $success = false;
      }
    }

    // All good, if success
    if ($success) return true;

    // If not, rollback to previous (ignoring errors for good)
    foreach ($this->mc as $mc) {
      $mc->set($key, $current);
    }

    return false;
  }

  /**
   * Returns the value for the given key
   * @param string $space
   * @param string $key
   * @param string $group
   * @return mixed
   */
  public function getShared($space, $key, $group = '_shared')
  {
    $key = $group . '_' . $space . '_' . $key;
    $mc = $this->getConnection();

    if (isset($this->cache[$key])) {
      $value = $this->cache[$key];
    } else if (in_array($group, $this->no_mc_groups)) {
      $value = false;
    } else {
      $value = $mc->get($key);
    }

    if ($value === null) {
      $value = false;
    }

    // Set value to local ram, for faster use next time
    $this->cache[$key] = $value;

    return $value;
  }

  /**
   * Saves a value with a key on the memcached server
   * @param string $space
   * @param string $key
   * @param mixed $data
   * @param string $group
   * @param integer $expire
   * @return boolean
   */
  public function setShared($space, $key, $data, $group = '_shared', $expire = 0)
  {
    $key = $group . '_' . $space . '_' . $key;
    // Cache for this request locally
    $this->cache[$key] = $data;
    if (in_array($group, $this->no_mc_groups)) {
      return true;
    }

    foreach ($this->mc as $mc) {
      if (!$mc->set($key, $data, $expire)) {
        $this->deleteByKey($key);
        return false;
      }
    }

    return true;
  }

  /**
   * Deletes a value for the given key on the memcached key
   * @param string $key
   * @param string $group
   * @return boolean 
   */
  public function delete($key, $group = 'default')
  {
    $key = $this->getInstanceKey($key, $group);

    // If the value is in a non-memcached group we do not delete the item on the memcached server
    if (in_array($group, $this->no_mc_groups)) {
      unset($this->cache[$key]);
      return true;
    }

    $success = true;
    foreach ($this->mc as $mc) {
      if (!$mc->delete($key)) {
        $success = false;
      }
    }

    // If the value is deleted on the memcached server we should delete the value in the memory too
    if ($success) {
      unset($this->cache[$key]);
    }

    return $success;
  }

  /**
   * Makes sure a key is directly deleted
   * @param string $key the key to delete (Full key!)
   */
  public function deleteByKey($key)
  {
    // Direct delete from memcached and local cache
    unset($this->cache[$key]);
    foreach ($this->mc as $mc) {
      $mc->delete($key);
    }
  }
  
  /**
   * Returns the value for the given key
   * 
   * @param string $key
   * @param string $group
   * @return mixed 
   */
  public function get($key, $group = 'default')
  {
    $key = $this->getInstanceKey($key, $group);

    /**
     * first: If the cache value is loaded return the value from the memory
     * second: If the group is in a non-memcached group return false
     * third: Load the value from the memcached
     */
    if (isset($this->cache[$key])) {
      $value = $this->cache[$key];
    } else if (in_array($group, $this->no_mc_groups)) {
      $value = false;
    } else {
      $value = $this->getConnection()->get($key);
    }

    // If the value is null, return false instead
    if ($value === null) {
      $value = false;
    }

    // Set value to local ram, for faster use next time
    $this->cache[$key] = $value;

    return $value;
  }

  /**
   * Replaces a value on the memcached server for the given key
   * 
   * @param string $key
   * @param mixed $data
   * @param string $group
   * @param integer $expire
   * @return mixed 
   */
  public function replace($key, $data, $group = 'default', $expire = 0)
  {
    $previousData = $this->get($key, $group);
    $key = $this->getInstanceKey($key, $group);
    $expire = $this->getExpirationTime($expire);

    $success = true;
    foreach ($this->mc as $mc) {
      if (!$mc->replace($key, $data, $expire)) {
        $success = false;
      }
    }

    // If success, set cache locally and return with success info
    if ($success) {
      $this->cache[$key] = $data;
      return true;
    }

    // If not, rollback to previous (ignoring errors for good)
    foreach ($this->mc as $mc) {
      $mc->set($key, $previousData, $expire);
    }

    return false;
  }

  /**
   * Saves a value with a key on the memcached server
   * @param string $key
   * @param mixed $data
   * @param string $group
   * @param int $expire
   * @return boolean 
   */
  public function set($key, $data, $group = 'default', $expire = 0)
  {
    $key = $this->getInstanceKey($key, $group);

    // Cache for this request locally
    $this->cache[$key] = $data;

    // If the group is a non-memcached group we should'nt save it but return success
    if (in_array($group, $this->no_mc_groups)) {
      return true;
    }

    $expire = $this->getExpirationTime($expire);

    foreach ($this->mc as $mc) {
      if (!$mc->set($key, $data, $expire)) {
        $this->deleteByKey($key);
        return false;
      }
    }

    return true;
  }
}