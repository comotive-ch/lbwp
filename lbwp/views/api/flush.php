<?php
define('CACHE_FLUSH_KEY', 'MK8RNE8MQ8DNR8EHDN8rMFH65QM8ADHR');
define('CACHE_FLUSH_SECRET', 'md74bf71z93dkmnxv847t29wn9x46mf9m6zgb5sm9fzm3x4bhms');

if (!isset($_REQUEST[CACHE_FLUSH_KEY]) || $_REQUEST[CACHE_FLUSH_KEY] != CACHE_FLUSH_SECRET) {
  exit;
}

$customerKey = $_REQUEST['customer'];
$deletePrefix = $customerKey . '_';
$keySearch = '';

// Extend customer prefix with table prefix
if (isset($_REQUEST['prefix'])) {
  if (strlen($_REQUEST['prefix']) > 0) {
    $deletePrefix .= $_REQUEST['prefix'];
  }
}

// Needed to efficiently delete keys
$prefixLength = strlen($deletePrefix);

// Use a specified search to only delete specific keys
if (isset($_REQUEST['search']) && strlen($_REQUEST['search']) > 0) {
  $keySearch = $_REQUEST['search'];
}

// Create a memcached connection to all servers
$memcached = new Memcached();
$memcached->addServer('127.0.0.1', '11211', 10);
$keys = $memcached->getAllKeys();

if (strlen($keySearch) == 0) {
  foreach ($keys as $key) {
    if (substr($key, 0, $prefixLength) == $deletePrefix) {
      $memcached->delete($key);
    }
  }
} else {
  foreach ($keys as $key) {
    if (substr($key, 0, $prefixLength) == $deletePrefix && strpos($key, $keySearch) !== false) {
      $memcached->delete($key);
    }
  }
}

unset($keys);
$memcached->quit();