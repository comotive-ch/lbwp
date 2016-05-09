<?php

namespace LBWP\Helper;

/**
 * API helper to to master API calls
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class MasterApi
{
  /**
   * @var string endpoint names
   */
  const REQUEST_PUSH = 'request-push';
  const STATS_REQUESTS = 'request-stats';
  const STATS_UPTIME = 'uptime-stats';
  const STATS_RESPONSE_TIME = 'response-time';
  const BASIC_STATUS = 'status';
  const UPTIME_MONITORS = 'uptime-monitors';

  /**
   * Simple post request to the master API
   * @param string $endpoint name of the endpoint
   * @param array $data the posted data
   * @return array response from master
   */
  public static function post($endpoint, $data)
  {
    // Post the data to the master view
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, 'http://' . MASTER_HOST . '/api/' . $endpoint);
    curl_setopt($call, CURLOPT_POST, 1);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($call, CURLOPT_POSTFIELDS, http_build_query($data));

    $output = curl_exec($call);
    curl_close($call);
    return json_decode($output, true);
  }

  /**
   * Asynchronous post without result
   * @param string $url the endpoint url
   * @param array $data the post params
   */
  public static function postAsynchronous($url, $data)
  {
    $payload = http_build_query($data);

    if (!defined('LBWP_EXTERNAL') || defined('LBWP_USE_NON_BLOCKING_CACHE_FLUSH')) {
      // Use real async by forking a new curl process that doesn't block
      $cmd = 'curl -X POST --data "' . $payload . '" "' . $url . '" > /dev/null 2>&1 &';
      exec($cmd, $output);
    } else {
      // Use classic curl call that is actually "quite blocky"
      $call = curl_init();
      curl_setopt($call, CURLOPT_URL, $url . '?' . $payload);
      curl_setopt($call, CURLOPT_RETURNTRANSFER, false);
      curl_setopt($call, CURLOPT_FRESH_CONNECT, true);
      curl_exec($call);
      curl_close($call);
    }
  }

  /**
   * @param $url
   * @return string the html content
   */
  public static function requestAsBrowser($url)
  {
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, $url);
    curl_setopt($call, CURLOPT_HTTPHEADER, false);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($call, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($call, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

    $content = curl_exec($call);
    curl_close($call);

    return $content;
  }
} 