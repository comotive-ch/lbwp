<?php
// Master config file, for db connection
define('LAST_CALL_FILE', '/var/www/util/last_cron_run.txt');
$configPath = str_replace('wp-content/plugins/lbwp/views/cron', '', __DIR__);
require_once $configPath . 'wp-config/core/lbwp-main.config.php';

// Reject any call that isn't the CLI SAPI
if (PHP_SAPI !== 'cli') {
  exit;
}

// Change in passwd.php
define('CRON_HASH', 'd6g483jd8743zt9ohg2oi4zt93houefhvgkjweho2iz0fvoe54nto2z6o4igou3gv89be40ufh9724hg9');
define('LBWP_VIRTUAL_HOST_SALT', '3gnPGs98ejb4gSF|TsIt894bkjnkf6');

// check the type parameter
switch ($argv[1]) {
  case 'daily':
  case 'hourly':
  case 'test':
  case 'passwd':
    $call = $argv[1] . '.php';
    $seconds = intval($argv[2]);
    break;
  default:
    exit;
}

$options = array(
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_USERAGENT => 'master-lbwp-' . $argv[1] . '-cron-1.2',
  CURLOPT_AUTOREFERER => true,
  CURLOPT_CONNECTTIMEOUT => 3,
  CURLOPT_SSLVERSION => 6,
  CURLOPT_TIMEOUT => 3,
  CURLOPT_MAXREDIRS => 5,
);

// set time limit, this could take a little longer
set_time_limit($seconds);

// open up a native sql connection to see what sites should be called
$conn = mysqli_connect($argv[3], DB_MASTER, DB_MASTER_PWD, DB_MASTER_USR);

// select the master db
$res = mysqli_query($conn, '
  SELECT sit_url, sit_assist_cron FROM site
  WHERE sit_active = 1 AND sit_type = "site"
');
// go through all pages and call the desired cron
while ($row = mysqli_fetch_assoc($res)) {
  if ($row['sit_assist_cron'] == 1) {
    $salts = explode('|', LBWP_VIRTUAL_HOST_SALT);
    $check = md5($salts[0] . $row['sit_url'] . $salts[1]);
    $virtualQuery = '?lbwp_virtual_host=' . $row['sit_url'] . '&lbwp_virtual_host_hash=' . $check . '&hash=' . CRON_HASH;
    $url = 'https://swi1-assist-lbwp.sdd1.ch/wp-content/plugins/lbwp/views/cron/' . $call . $virtualQuery;
  } else {
    $url = 'https://' . $row['sit_url'] . '/wp-content/plugins/lbwp/views/cron/' . $call . '?hash=' . CRON_HASH;
  }
  $curl = curl_init($url);
  curl_setopt_array($curl, $options);
  $buffer = curl_exec($curl);

  // debug if needed
  if (isset($argv[4]) && $argv[4] == 'debug') {
    echo $url . PHP_EOL;
    echo $buffer . PHP_EOL;
    echo curl_error($curl);
  }

  // Check if curl error an inform by mail
  $err = curl_errno($curl);
  if ($err > 0 && $err !== CURLE_OPERATION_TIMEDOUT) {
    mail('it+monitoring@comotive.ch', 'Cron Curl Error ' . $row['sit_url'], curl_error($curl));
  }

  curl_close($curl);

  // Wait for a second after each call
  sleep(2);
}

mysqli_close($conn);

// Save the last dance, err, call
file_put_contents(LAST_CALL_FILE, time());