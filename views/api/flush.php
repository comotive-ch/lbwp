<?php
define('CACHE_FLUSH_KEY', 'MK8RNE8MQ8DNR8EHDN8rMFH65QM8ADHR');
define('CACHE_FLUSH_SECRET', 'md74bf71z93dkmnxv847t29wn9x46mf9m6zgb5sm9fzm3x4bhms');
define('SKIP_WP_STACK', true);

if (!isset($_REQUEST[CACHE_FLUSH_KEY]) || $_REQUEST[CACHE_FLUSH_KEY] != CACHE_FLUSH_SECRET) {
  exit;
}

// Load the needed Redis by loading config without wp stack
require_once '../../../../../wp-config.php';

// See if external depending on host
$htmlCacheFlushKey = 'htmlCache';
$debug = isset($_REQUEST['debug']) && $_REQUEST['debug'] == 1;
$customerKey = $_REQUEST['customer'];
$deletePrefix = $customerKey . ':';
$keySearch = '';

// Extend customer prefix with table prefix
if (isset($_REQUEST['prefix'])) {
  if (strlen($_REQUEST['prefix']) > 0) {
    $deletePrefix .= str_replace('_', ':', $_REQUEST['prefix']);
  }
}

// Use a specified search to only delete specific keys
if (isset($_REQUEST['search']) && strlen($_REQUEST['search']) > 0) {
  $deletePrefix .= '*' . $_REQUEST['search'];
}

// Is it a sole html flush?
$doHtmlFlush = str_contains($deletePrefix, $htmlCacheFlushKey) || strlen($_REQUEST['search']) == 0;

if ($debug) {
  var_dump($deletePrefix);
  var_dump($_REQUEST);
  var_dump('---------');
}

// Create a connection to the write redis to flush, if not a sole html flush request
if ($_REQUEST['search'] != $htmlCacheFlushKey) {
  $redis = new Redis();
  $redis->pconnect(REDIS_HOST, REDIS_CONNECTION_PORT, 2);
  $redis->auth(REDIS_AUTH_KEY);
  $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
  // Scan redis and gradually unlink keys to be flushed
  $it = NULL;
  while ($keys = $redis->scan($it, $deletePrefix . '*', 10000)) {
    $redis->unlink($keys);
    if ($debug) var_dump($keys);
  }
}

// Same with the html cache node, if applicable
if (FRONTEND_CACHE_REDIS_ENABLED && $doHtmlFlush) {
  $redisHtml = new Redis();
  $redisHtml->pconnect(REDIS_HTML_CACHE_SERVER_HOST, REDIS_CONNECTION_PORT, 2);
  $redisHtml->auth(REDIS_AUTH_KEY);
  $redisHtml->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
  // Scan redis and gradually unlink keys ot be flushed
  $it = NULL;
  global $table_prefix;
  $deleteKey = CUSTOMER_KEY . '_' . $table_prefix . '*';
  while ($keys = $redisHtml->scan($it, $deleteKey, 10000)) {
    // Remove all keys ending with _bot, we dont flush bot cache
    $keys = array_filter($keys, function ($key) {
      return !str_ends_with($key, '_bot');
    });
    $redisHtml->unlink($keys);
    if ($debug) var_dump($keys);
  }
}