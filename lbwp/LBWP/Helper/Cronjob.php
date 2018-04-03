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
   * Hash needed to be transferred to confirm a job
   */
  const CONFIRM_HASH = 'M8Snqz3Le8DmAyC9D4EmhTzD39';
  /**
   * Creates one or more jobs to be executed
   * @param array $jobs array of timestamp=>identifier
   * @return bool true/false if it worked or not
   */
  public static function register($jobs)
  {
    // Post the data to the master view
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, MASTER_HOST_PROTO . '://' . MASTER_HOST . '/wp-content/plugins/lbwp/views/api/job-register.php');
    curl_setopt($call, CURLOPT_POST, 1);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($call, CURLOPT_POSTFIELDS, http_build_query(array(
      'jobs' => $jobs,
      'host' => $_SERVER['HTTP_HOST']
    )));

    curl_exec($call);
    curl_close($call);
  }

  /**
   * Confirms a job being done
   * @param int $jobId array of timestamp=>identifier
   * @return bool true/false if it worked or not
   */
  public static function confirm($jobId)
  {
    // Post the data to the master view
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, MASTER_HOST_PROTO . '://' . MASTER_HOST . '/wp-content/plugins/lbwp/views/api/job-confirm.php');
    curl_setopt($call, CURLOPT_POST, 1);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($call, CURLOPT_POSTFIELDS, http_build_query(array(
      'jobId' => intval($jobId),
      'hash' => self::CONFIRM_HASH
    )));

    curl_exec($call);
    curl_close($call);
  }

  /**
   * Creates one or more jobs to be executed
   * @param array $jobs array of timestamp=>identifier
   * @param string $host the host the be executing the jobs
   * @return bool true/false if it worked or not
   */
  public static function registerJobsOnMaster($jobs, $host)
  {
    // If DB_MASTER is not set, nothing can be done. This method
    // is only called by the API job-register.php and hence executed on master
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

      // Split identifier and data, if needed
      $data = '';
      if (stristr($identifier, '::') !== false) {
        list($identifier, $data) = explode('::', $identifier);
      }

      // Use server time difference for the jobs
      $sql = '
        INSERT INTO jobs (job_site, job_time, job_identifier, job_data, job_tries)
        VALUES ({siteUrl}, {timestamp}, {identifier}, {data}, 0)
      ';

      mysqli_query($conn, Strings::prepareSql($sql, array(
        'siteUrl' => $host,
        'timestamp' => $timestamp + (time() - current_time('timestamp')),
        'identifier' => $identifier,
        'data' => $data
      )));
    }

    // At last, close the connection
    mysqli_close($conn);
    return true;
  }

  /**
   * @param int $jobId the job id
   * @param string $hash the check hash
   * @return bool
   */
  public static function confirmJobOnMaster($jobId, $hash)
  {
    // If DB_MASTER is not set, nothing can be done. This method
    // is only called by the API job-confirm.php and hence executed on master
    if (defined('EXTERNAL_LBWP')) {
      return false;
    }

    if ($hash != self::CONFIRM_HASH) {
      return false;
    }

    // Connect to master using native mysqli
    $conn = mysqli_connect(DB_HOST, DB_MASTER_USR, DB_MASTER_PWD, DB_MASTER);
    $jobId = intval($jobId);

    if ($jobId > 0) {
      // Get full job data, and delete every identifier with the same time
      $set = mysqli_query($conn, 'SELECT job_identifier,job_time,job_data FROM jobs WHERE job_id = ' . $jobId);
      $data = mysqli_fetch_assoc($set);
      // If both are set, delete by identifier/time to confirm all jobs that would have done the same and are now useless
      if (isset($data['job_identifier']) && strlen($data['job_identifier']) > 0 && isset($data['job_time']) && $data['job_time'] > 0) {
        mysqli_query($conn, '
          DELETE FROM jobs WHERE
          job_identifier = "' . $data['job_identifier'] . '" AND
          job_data = "' . $data['job_data'] . '" AND
          job_time = ' . intval($data['job_time']
        ));
      } else {
        // If not found that way, delete by ID
        mysqli_query($conn, 'DELETE FROM jobs WHERE job_id = ' . $jobId);
      }
    }

    // At last, close the connection
    mysqli_close($conn);
    return true;
  }
} 