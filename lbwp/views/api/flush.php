<?php
define('CACHE_FLUSH_KEY', 'MK8RNE8MQ8DNR8EHDN8rMFH65QM8ADHR');
define('CACHE_FLUSH_SECRET', 'md74bf71z93dkmnxv847t29wn9x46mf9m6zgb5sm9fzm3x4bhms');
define('REDIS_AUTH_KEY', 'Md7EmwzQ28Ejw9dJem5WlqK9eW');

if (!isset($_REQUEST[CACHE_FLUSH_KEY]) || $_REQUEST[CACHE_FLUSH_KEY] != CACHE_FLUSH_SECRET) {
  exit;
}

// See if external depending on host
$isExternal = stristr($_SERVER['HTTP_HOST'], 'sdd1.ch') !== false ? false : true;
$customerKey = $_REQUEST['customer'];
$deletePrefix = $customerKey . ':';
$keySearch = '';

// Extend customer prefix with table prefix
if (isset($_REQUEST['prefix'])) {
  if (strlen($_REQUEST['prefix']) > 0) {
    $deletePrefix .= str_replace('_', ':', $_REQUEST['prefix']);
  }
}

// Needed to efficiently delete keys
$prefixLength = strlen($deletePrefix);

// Use a specified search to only delete specific keys
if (isset($_REQUEST['search']) && strlen($_REQUEST['search']) > 0) {
  $keySearch = $_REQUEST['search'];
}

// Create a memcached connection to all servers
$redis = new Redis();
$redis->pconnect('127.0.0.1');
$redis->auth(REDIS_AUTH_KEY);
$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

// Get all keys with a wildcard search
$keys = $redis->keys($deletePrefix . $keySearch . '*');

// Just delete all found keys by providing the array as list of arguments in a single call
call_user_func(array($redis, 'delete'), $keys);

// Make sure to delete the keys from RAM
unset($keys);

