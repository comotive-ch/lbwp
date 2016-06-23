<?php

namespace LBWP\Helper;
use LBWP\Util\Strings;

/**
 * Very simple job calling framework. Basically a developer can tell the system to guaranteedly
 * execute the "cron_job_$identifier" wordpress action at a specific time
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Cronjob
{
  /**
   * Creates one or more jobs to be executed
   * @param array $jobs array of timestamp=>identifier
   * @return bool true/false if it worked or not
   */
  public static function register($jobs)
  {
    // Post the data to the master view
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, 'http://' . MASTER_HOST . '/wp-content/plugins/lbwp/views/api/register-jobs.php');
    curl_setopt($call, CURLOPT_POST, 1);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($call, CURLOPT_POSTFIELDS, http_build_query(array(
      'jobs' => $jobs,
      'host' => $_SERVER['HTTP_HOST']
    )));

    $output = curl_exec($call);

    curl_close($call);

    if (defined('LOCAL_DEVELOPMENT')) {
      echo '<!--register-jobs.php master response:';
      var_dump($output);
      echo '--!>';
    }
  }

  /**
   * Creates one or more jobs to be executed
   * @param array $jobs array of timestamp=>identifier
   * @param string $host the host the be executing the jobs
   * @return bool true/false if it worked or not
   */
  public static function masterCallback($jobs, $host)
  {
    // If DB_MASTER is not set, nothing can be done. This method
    // is only called by the API register-job.php and hence executed on master
    if (defined('EXTERNAL_LBWP')) {
      return false;
    }

    // Connect to master using native mysqli
    $conn = mysqli_connect(DB_HOST, DB_MASTER_USR, DB_MASTER_PWD, DB_MASTER);

    // Add a job for the current page to be executed at $time
    foreach ($jobs as $timestamp => $identifier) {
      // Don't go further than a year
      if ($timestamp > time() + (86400 * 365)) {
        continue;
      }

      // Use server time difference for the jobs
      $sql = '
        INSERT INTO jobs (job_site, job_time, job_identifier)
        VALUES ({siteUrl}, {timestamp}, {identifier})
      ';

      mysqli_query($conn, Strings::prepareSql($sql, array(
        'siteUrl' => $host,
        'timestamp' => $timestamp + (time() - current_time('timestamp')),
        'identifier' => $identifier
      )));
    }

    // At last, close the connection
    mysqli_close($conn);
    return true;
  }
} 