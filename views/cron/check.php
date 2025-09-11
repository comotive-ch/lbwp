<?php
// Master config file, for db connection
define('SERVER_EMAIL', 'it@comotive.ch');
define('LAST_CALL_FILE_CRON', '/var/www/util/last_cron_run.txt');
define('LAST_CALL_FILE_JOB', '/var/www/util/last_cron_run_job.txt');
// After what time, we're alarmed
define('TRESHOLD_SECONDS', 3800);

lbwpCheckLastCron(LAST_CALL_FILE_CRON, TRESHOLD_SECONDS, 'run_cron');
lbwpCheckLastCron(LAST_CALL_FILE_JOB, TRESHOLD_SECONDS, 'run_job');

/**
 * @param string $file the file, containing an info
 * @param int $threshold the maximum time that can pass before an error is thrown
 * @param string $name name of the failing cron
 * @return bool the success state (true=success)
 */
function lbwpCheckLastCron($file, $threshold, $name)
{
  if (!file_exists($file)) {
    mail(SERVER_EMAIL, 'Cron failure: ' . $name, 'The cron file for "' . $name . '" is not existing.');
    echo 'The cron file for "' . $name . '" is not existing.';
    return false;
  }
  // Get last run time and add the threshold
  $age = intval(file_get_contents($file)) + $threshold;
  // If still older, it's an error state
  if ($age < time()) {
    $message = 'The cron job "' . $name . '" is not running.' . PHP_EOL;
    $message.= 'Last run time was: ' . date('d.m.Y H:i:s', file_get_contents($file));
    mail(SERVER_EMAIL, 'Cron failure: ' . $name, $message);
    echo $message;
    return false;
  }

  return true;
}
