<?php
// Load the needed Redis by loading config without wp stack, so plugins/themes can't exit before flushing
define('SKIP_WP_STACK', true);
require_once '../../../../../wp-config.php';

// check for the api secrets (loaded by wp-config.php)
if (!isset($_REQUEST[CACHE_FLUSH_KEY]) || $_REQUEST[CACHE_FLUSH_KEY] != CACHE_FLUSH_SECRET) {
  header('HTTP/1.0 403 Forbidden');
  exit;
}

/**
 * Scans the object cache redis and gradually unlinks the keys to be flushed
 * @param Redis $redis connection to the object cache redis
 * @param string $deletePrefix the key prefix to flush
 * @param bool $debug whether to dump the flushed keys
 * @return void
 */
function flushObjectCache(Redis $redis, string $deletePrefix, bool $debug): void
{
  $it = NULL;
  while ($keys = $redis->scan($it, $deletePrefix . '*', 10000)) {
    $redis->unlink($keys);
    if ($debug) var_dump($keys);
  }
}

/**
 * Scans the html cache redis and gradually unlinks the keys to be flushed, except bot cache keys
 * @param bool $debug whether to dump the flushed keys
 * @return void
 */
function flushHtmlCache(bool $debug): void
{
  global $table_prefix;
  $redisHtml = new Redis();
  $redisHtml->pconnect(REDIS_HTML_CACHE_SERVER_HOST, REDIS_CONNECTION_PORT, 2);
  $redisHtml->auth(REDIS_AUTH_KEY);
  $redisHtml->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
  $it = NULL;
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

// Seconds a flush lock may live at most, so a crashed flush can't block further flushes forever
const FLUSH_LOCK_TTL = 120;
// Maximum number of reruns of a flush, if identical requests arrived while it was running
const FLUSH_MAX_RERUNS = 3;

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

// Connection to the write redis, used for the flush lock and the object cache flush
$redis = new Redis();
$redis->pconnect(REDIS_HOST, REDIS_CONNECTION_PORT, 2);
$redis->auth(REDIS_AUTH_KEY);
$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

// Identical requests share one lock. The lock key must not start with a customer key, so a flush never deletes it
$lockRequest = $_REQUEST;
ksort($lockRequest);
$lockKey = 'flushlock:' . md5(serialize([$lockRequest, CUSTOMER_KEY, $table_prefix]));
$pendingKey = $lockKey . ':pending';
$lockToken = bin2hex(random_bytes(16));

// Atomically acquire the lock, or flag the running flush to rerun once it's done (changes may have
// been written after it already scanned past the affected keys) and bail early
$acquireScript = <<<LUA
if redis.call('set', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then
  return 1
end
redis.call('set', KEYS[2], 1, 'EX', ARGV[2])
return 0
LUA;
if (!$redis->eval($acquireScript, [$lockKey, $pendingKey, $lockToken, FLUSH_LOCK_TTL], 2)) {
  if ($debug) var_dump('identical flush running, flagged rerun');
  header('HTTP/1.0 202 Accepted');
  exit;
}

// Atomically either consume a pending rerun flag (keeping and refreshing the lock) or release the lock.
// Being atomic with the acquire script, no rerun request can get lost between flushing and releasing
$releaseScript = <<<LUA
if redis.call('get', KEYS[1]) ~= ARGV[1] then
  return 0
end
if ARGV[3] == '1' and redis.call('del', KEYS[2]) == 1 then
  redis.call('expire', KEYS[1], ARGV[2])
  return 1
end
redis.call('del', KEYS[1], KEYS[2])
return 0
LUA;

$runs = 0;
try {
  do {
    // Flush the object cache, if not a sole html flush request
    if ($_REQUEST['search'] != $htmlCacheFlushKey) {
      flushObjectCache($redis, $deletePrefix, $debug);
    }
    // Same with the html cache node, if applicable
    if (FRONTEND_CACHE_REDIS_ENABLED && $doHtmlFlush) {
      flushHtmlCache($debug);
    }
    $allowRerun = ++$runs <= FLUSH_MAX_RERUNS ? '1' : '0';
  } while ($redis->eval($releaseScript, [$lockKey, $pendingKey, $lockToken, FLUSH_LOCK_TTL, $allowRerun], 2));
} catch (Throwable $e) {
  $redis->eval($releaseScript, [$lockKey, $pendingKey, $lockToken, FLUSH_LOCK_TTL, '0'], 2);
  throw $e;
}
