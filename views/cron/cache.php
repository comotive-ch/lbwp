<?php
/**
 * Cache Report Cron (runs every few minutes on swi1-assist
 * It checks everything that is good and bad and reports it
 */

// Include the lbwp nodes config by adding the wp-config
define('SKIP_WP_STACK', true);
$_SERVER['HTTP_HOST'] = 'master.lbwp.sdd1.ch';
require_once '/var/www/lbwp-prod/wp-config.php';
global $lbwpNodes;

// Initialize the information containers
$error = $info = $read = array();

// Starting time stamp
$info[] = 'starting checks at: ' . microtime(true);

// Connect to all the instances
try {
  $write = new Redis();
  $write->pconnect(REDIS_WRITE_NODE_IP, REDIS_CONNECTION_PORT, 0.5);
  $write->auth(REDIS_AUTH_KEY);
  $write->setOption(Redis::OPT_SERIALIZER, REDIS_WP_CACHE_SERIALIZER);
} catch (RedisException $ex) {
  $error = 'write connection: ' . $ex->getMessage();
}

foreach ($lbwpNodes['swi1'] as $id => $node) {
  try {
    $read[$id] = new Redis();
    $read[$id]->pconnect($node['intIp'], REDIS_CONNECTION_PORT, 0.5);
    $read[$id]->auth(REDIS_AUTH_KEY);
    $read[$id]->setOption(Redis::OPT_SERIALIZER, REDIS_WP_CACHE_SERIALIZER);
  } catch (RedisException $ex) {
    $error = 'read connection ' . $id . ': ' . $ex->getMessage();
  }
}

// Show successful connect if no errors happened yet
if (count($errors) == 0) {
  $info[] = 'connected to all instances successfully';
}

// Get all information of our master/write server
$writeInfo = $write->info();
// Check if number of slaves is the same as actual servers
if (count($read) == $writeInfo['connected_slaves']) {
  $nr = $writeInfo['connected_slaves'];
  $info[] = 'There are ' . $nr . ' of ' . $nr . ' slaves connected to master';
} else {
  $error = 'Only ' . $writeInfo['connected_slaves'] . ' slaves are currently connected';
}

// Add the slave info to the info array
for ($i = 0; $i < $writeInfo['connected_slaves']; ++$i) {
  $info[] = $writeInfo['slave' . $i];
}

// Add information of db0 to info array
$info[] = 'write db info: ' . $writeInfo['db0'];
$info[] = 'write db size: ' . $writeInfo['used_memory_human'];

// Error if the write cache is not the master
if ($writeInfo['role'] != 'master') {
  $error[] = 'write db is not master, it is: ' . $writeInfo['role'];
} else {
  $info[] = 'write db considers itself the master, which is nice';
}

/** @var Redis $redis Now check all the nodes */
foreach ($read as $id => $redis) {
  $readInfo = $redis->info();
  // Check if master connection is up
  if ($readInfo['master_link_status'] != 'up') {
    $error[] = 'slave ' . $id . ' has inconvenient master status: ' . $readInfo['master_link_status'];
  } else {
    $info[] = 'slave ' . $id . ' has connection to master and master is up';
  }
  // Error if the write cache is not the master
  if ($readInfo['role'] != 'slave') {
    $error[] = 'slave ' . $id . ' is not considering himself a slave, it is: ' . $readInfo['role'];
  }

  $info[] = 'slave id ' . $id . ' db size: ' . $readInfo['used_memory_human'];
  // Check if db0 info is the same
  if (substr($writeInfo['db0'],0,16) == substr($readInfo['db0'],0,16)) {
    $info[] = 'consistency between slave ' . $id . ' and master ensured';
    $info[] = $writeInfo['db0'] . ' == ' . $readInfo['db0'];
  } else {
    $error[] = 'possible inconsistency between slave ' . $id . ' and master';
    $error[] = $writeInfo['db0'] . ' !== ' . $readInfo['db0'];
  }
}

// Starting time stamp
$info[] = 'ending checks at: ' . microtime(true);

// Show direct output on url call
if (isset($_GET['output'])) {
  print_r($info);
  print_r($error);
}

// Only if errors, send a mail
if (count($error) > 0) {
  mail('it+monitoring@comotive.ch', 'Redis Cache Cron Checker', print_r($error, true) . PHP_EOL . print_r($info, true));
}
