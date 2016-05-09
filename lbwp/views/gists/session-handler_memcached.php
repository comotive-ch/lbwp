<?php

/**
 * Custom session handler to save all session from an instance
 * to memcached instead of the file system
 */
class SessionSaveHandler
{
  /**
   * Number of seconds, a session shall live without changes
   */
  const SESSION_LIVETIME = 1200;
  /**
   * Register the alternative session handler
   */
  public function __construct() 
  {
    session_set_save_handler(
      array($this, 'open'),
      array($this, 'close'),
      array($this, 'read'),
      array($this, 'write'),
      array($this, 'destroy'),
      array($this, 'gc')
    );

    // To call the write process before the object cache is destroyed
    register_shutdown_function('session_write_close');
  }

  /**
   * Opens a connection - Not in use
   * @param string $savePath
   * @param string $sessionName
   * @return boolean 
   */
  public function open($savePath, $sessionName)
  {
    return true;
  }

  /**
   * Closing the connection - Not in use
   * @return boolean 
   */
  public function close()
  {
    return true;
  }

  /**
   * Returns the datas for the given session id
   * @global WP_Object_Cache $wp_object_cache
   * @param string $id
   * @return mixed 
   */
  public function read($id)
  {
    global $wp_object_cache;
    return $wp_object_cache->get($this->prepareId($id), 'sessions');
  }

  /**
   * Saves the datas for the given session id
   * @global WP_Object_Cache $wp_object_cache
   * @param string $id
   * @param mixed $data
   * @return bool true
   */
  public function write($id, $data)
  {
    global $wp_object_cache;
    $wp_object_cache->set($this->prepareId($id), $data, 'sessions', self::SESSION_LIVETIME);
    return true;
  }

  /**
   * Deletes the data for the given session id
   * @global WP_Object_Cache $wp_object_cache
   * @param string $id
   * @return bool true
   */
  public function destroy($id)
  {
    global $wp_object_cache;
    $wp_object_cache->delete($this->prepareId($id), 'sessions');
    return true;
  }

  /**
   * Garbage collection - not in use - we have no garbage!
   * @param integer $maxlifetime
   * @return boolean 
   */
  public function gc($maxlifetime)
  {
    return true;
  }
  
  /**
   * Returns the id with a prefix
   * @param string $id
   * @return string
   */
  protected function prepareId($id)
  {
    return 'WP_SESSION_' . CUSTOMER_KEY . '_' . $id;
  }
}

// Creates the object and register the new session handler
new SessionSaveHandler();
