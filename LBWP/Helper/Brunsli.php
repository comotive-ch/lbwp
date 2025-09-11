<?php

namespace LBWP\Helper;

/**
 * API helper to call brunsli API
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class Brunsli
{
  /**
   * @var string the api key
   */
  const API_KEY = 'dnh9FV3EJLaqvM6ujcTmH3X7Ja8nMRuV8cvPXFPU9tqdzZFLCaaBCwgzGF28x5Ps';
  /**
   * @var string the api base
   */
  const API_BASE = 'https://brunsli.comotive.ch/api';

  /**
   * Simple convert call to brunsli
   * @param array $data the posted data
   * @return array response from master
   */
  public static function convert($data)
  {
    // Post the data to the master view
    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, self::API_BASE . '/convert/');
    curl_setopt($call, CURLOPT_POST, 1);
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($call, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($call, CURLOPT_HTTPHEADER, array(
      'x-api-key: ' . self::API_KEY
    ));

    $output = curl_exec($call);
    curl_close($call);
    return json_decode($output, true);
  }
} 