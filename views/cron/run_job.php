<?php
define('LAST_CALL_FILE', '/var/www/util/last_cron_run_job.txt');
// Master config file, for db connection
$configPath = str_replace('wp-content/plugins/lbwp/views/cron', '', __DIR__);
require_once $configPath . 'wp-config/core/lbwp-main.config.php';

// Reject any call that isn't the CLI SAPI
if (PHP_SAPI !== 'cli') {
  exit;
}

define('LBWP_VIRTUAL_HOST_SALT', '3gnPGs98ejb4gSF|TsIt894bkjnkf6');

// Basic options to call the actual cronjob
$options = array(
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_USERAGENT => 'master-lbwp-job-cron-1.3',
  CURLOPT_AUTOREFERER => true,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSLVERSION => 6,
  CURLOPT_TIMEOUT => 5,
  CURLOPT_MAXREDIRS => 10,
);

// Blacklist of special jobs that have a less maximum of tries
$blacklist = array(
  'run_wp_cron' => 1
);

// set time limit, to not get into a race contition
set_time_limit(intval($argv[1]));
define('MAX_JOBS', 25);
define('MAX_TRIES', 5);

// open up a native sql connection to see if there are jobs
$conn = mysqli_connect($argv[2], DB_MASTER_USR, DB_MASTER_PWD, DB_MASTER);

// select the master db
$rowCount = 0;
$executedJobs = array();
$assistCronSites = array();
$res = mysqli_query($conn, '
  SELECT sit_url  FROM site
  WHERE sit_active = 1 AND sit_assist_cron = 1
');

while ($row = mysqli_fetch_assoc($res)) {
  $assistCronSites[] = $row['sit_url'];
}

$res = mysqli_query($conn, 'SELECT job_id,job_site,job_identifier,job_time,job_data,job_tries FROM jobs WHERE job_time < ' . time() . ' LIMIT 0,' . MAX_JOBS);
// go through all pages and call the desired cron
while ($row = mysqli_fetch_assoc($res)) {
  $key = md5($row['job_time']) . md5($row['job_site']) . md5($row['job_identifier']) . md5($row['job_data']);
  // Decide by key if a job is executed so an identical job is only run once
  // Happens very rarely, but possible if for example a newsletter is planned twice at the exact same time
  if (!in_array($key, $executedJobs)) {
    // Decide if cron should run on assist or load balanced
    if (in_array($row['job_site'], $assistCronSites)) {
      $salts = explode('|', LBWP_VIRTUAL_HOST_SALT);
      $check = md5($salts[0] . $row['job_site'] . $salts[1]);
      $virtualQuery = 'lbwp_virtual_host=' . $row['job_site'] . '&lbwp_virtual_host_hash=' . $check . '&jobId=' . $row['job_id'] . '&key=' . MASTER_CRON_API_SECRET . '&&identifier=' . $row['job_identifier'];
      $url = 'https://swi1-assist-lbwp.sdd1.ch/wp-content/plugins/lbwp/views/cron/job.php?' . $virtualQuery;
    } else {
      $url = 'http://' . $row['job_site'] . '/wp-content/plugins/lbwp/views/cron/job.php?jobId=' . $row['job_id'] . '&key=' . MASTER_CRON_API_SECRET . '&identifier=' . $row['job_identifier'];
    }
    if (isset($row['job_data']) && strlen($row['job_data']) > 0) {
      $url .= '&data=' . urlencode($row['job_data']);
    }

    // Count up the tries on that job
    mysqli_query($conn, 'UPDATE jobs SET job_tries = job_tries+1 WHERE job_id = ' . intval($row['job_id']));

    try {
      $curl = curl_init($url);
      curl_setopt_array($curl, $options);
      $buffer = curl_exec($curl);
    } catch (Exception $e) {

    }

    // debug if needed
    if (isset($argv[3]) && $argv[3] == 'debug') {
      echo $url . PHP_EOL;
      print_r($row);
      print_r(curl_errno($curl));
      print_r(curl_error($curl));
      echo 'Buffer:' . $buffer;
    }

    $executedJobs[] = $key;
    curl_close($curl);
  }

  // Delete blacklist jobs after their amount of tries
  if (in_array($row['job_identifier'], $blacklist) && $row['job_tries'] == $blacklist[$row['job_identifier']]) {
    mysqli_query($conn, 'DELETE FROM jobs WHERE job_id = ' . intval($row['job_id']));
  }

  // Inform if a job has too many tries, then delete it
  if ($row['job_tries'] == MAX_TRIES) {
    mail(
      'it+monitoring@comotive.ch',
      'Cron was running too many times with job ' . $row['job_id'] . '-' . $row['job_identifier'],
      'The job for ' . $row['job_site'] . ' has now tried ' . $row['job_tries'] . ' times.',
      'From: it@comotive.ch'
    );
  }

  // Forget about that job, as we informed and admin can run it manually again
  mysqli_query($conn, 'DELETE FROM jobs WHERE job_id = ' . intval($row['job_id']));

  // sleep for a moment for the next job to happen
  sleep(2);
  $rowCount++;
}

// Inform on too many jobs
if ($rowCount >= MAX_JOBS) {
  mail('it+monitoring@comotive.ch', 'job cron running more than ' . MAX_JOBS . ' jobs', $buffer);
}

// Close the connection for good
mysqli_close($conn);

// Save the last dance, err, call
file_put_contents(LAST_CALL_FILE, time());