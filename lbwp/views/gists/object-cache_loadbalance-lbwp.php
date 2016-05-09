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
   * @var Memcached
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
    $this->mc = new Memcached(MC_PERSISTENT_CONNECTION_HASH);

    // Add servers if not already connected
    if (count($this->mc->getServerList()) == 0) {
      $this->mc->addServers($memcachedServers);

      // Set options for failover and loadbalancing
      $this->mc->setOptions(array(
        Memcached::OPT_CONNECT_TIMEOUT => 50,
        Memcached::OPT_SERVER_FAILURE_LIMIT => 2,
        Memcached::OPT_RETRY_TIMEOUT => 1
      ));
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
   * Returns the connection for the given group name
   * @param string $group deprecated
   * @return Memcached
   */
  public function getConnection($group)
  {
    return $this->mc;
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
    $mc =& $this->getConnection('default');
    foreach ($keys as $key => $expiration) {
      $mc->delete($key);
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
   * 
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
   * 
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
   * Adds a value with its key to the memcached server
   * 
   * @param string $key
   * @param mixed $data
   * @param string $group
   * @param integer $expire
   * @return boolean 
   */
  public function add($key, $data, $group = 'default', $expire = 0)
  {
    $key = $this->getInstanceKey($key, $group);
    // Cache for this request locally
    $this->cache[$key] = $data;

    // Do not add the content to the memcached, if it should be permanently cached
    if (in_array($group, $this->no_mc_groups)) {
      return true;
    }

    $mc = $this->getConnection($group);
    $expire = $this->getExpirationTime($expire);

    return $mc->add($key, $data, $expire);;
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
    $key = $this->getInstanceKey($key, $group);
    $mc = $this->getConnection($group);

    return $mc->increment($key, $n);
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
    $key = $this->getInstanceKey($key, $group);
    $mc = $this->getConnection($group);

    return $mc->decrement($key, $n);
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
    $mc = $this->getConnection($group);

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
    $mc = $this->getConnection($group);

    return $mc->set($key, $data, $expire);;
  }

  /**
   * Deletes a value for the given key on the memcached key
   * 
   * @param string $key
   * @param string $group
   * @return boolean 
   */
  public function delete($key, $group = 'default')
  {
    $key = $this->getInstanceKey($key, $group);

    /**
     * If the value is in a non-memcached group we do not delete the item 
     * on the memcached server 
     */
    if (in_array($group, $this->no_mc_groups)) {
      unset($this->cache[$key]);
      
      return true;
    }

    $mc = $this->getConnection($group);
    $result = $mc->delete($key);

    /**
     * If the value is deleted on the memcached server we should delete
     * the value in the memory too
     */
    if (false !== $result) {
      unset($this->cache[$key]);
    }

    return $result;
  }

  /**
   * Makes sure a key is directly deleted
   * @param string $key the key to delete (Full key!)
   * @param string $group the connection group
   */
  public function deleteByKey($key, $group = 'default')
  {
    // Direct delete from memcached and local cache
    unset($this->cache[$key]);
    $this->getConnection($group)->delete($key);
  }

  /**
   * Deletes the whole content on every memcached server 
   */
  public function flush()
  {
    foreach ($this->mc as $bucket => $mc) {
      $mc->flush();
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
    $mc = $this->getConnection($group);

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
      $value = $mc->get($key);
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
   * Returns all keys and values in the given group
   * 
   * @param string $groups
   * @return array 
   */
  public function getMulti($groups)
  {
    $return = array();
    
    foreach ($groups as $group => $keys) {
      $mc = $this->getConnection($group);
      
      foreach ($keys as $key) {
        $key = $this->getInstanceKey($key, $group);
        
        if (isset($this->cache[$key])) {
          $return[$key] = $this->cache[$key];
          continue;
        } else if (in_array($group, $this->no_mc_groups)) {
          $return[$key] = false;
          continue;
        } else {
          $return[$key] = $mc->get($key);
        }
      }
      
      if ($to_get) {
        $vals = $mc->getMulti($to_get);
        $return = array_merge( $return, $vals );
      }
    }
    
    $this->cache = array_merge($this->cache, $return);
    
    return $return;
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
    $key = $this->getInstanceKey($key, $group);
    $expire = $this->getExpirationTime($expire);
    $mc = $this->getConnection($group);
    $this->cache[$key] = $data;
    $mc->replace($key, $data, $expire);

    return $mc->replace($key, $data, $expire);
  }

  /**
   * Saves a value with a key on the memcached server
   * 
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

    $mc = $this->getConnection($group);
    $expire = $this->getExpirationTime($expire);

    return $mc->set($key, $data, $expire);
  }
}