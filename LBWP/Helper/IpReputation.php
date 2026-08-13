<?php

namespace LBWP\Helper;

/**
 * Rates the reputation of an IP address by using external, freely available sources.
 * All lookups are cached and fail open, so a broken source never blocks a visitor.
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class IpReputation
{
  /**
   * @var string object cache group of all reputation caches
   */
  const CACHE_GROUP = 'lbwpIpReputation';
  /**
   * @var string cache key for the cached tor exit node list
   */
  const TOR_LIST_KEY = 'torExitNodes';
  /**
   * @var string source of the official tor exit node list
   */
  const TOR_LIST_URL = 'https://check.torproject.org/torbulkexitlist';
  /**
   * @var int cache duration of the tor exit node list in seconds
   */
  const TOR_LIST_TTL = 43200;
  /**
   * @var int cache duration of an empty tor exit node list after a failed request
   */
  const TOR_LIST_FAIL_TTL = 300;
  /**
   * @var string prefix for the cached reputation result of a single ip
   */
  const IP_CACHE_PREFIX = 'ipRep_';
  /**
   * @var int cache duration of a single ip reputation result in seconds
   */
  const IP_CACHE_TTL = 86400;
  /**
   * @var string endpoint of the stopforumspam lookup api
   */
  const SFS_API_URL = 'https://api.stopforumspam.org/api';
  /**
   * @var int timeout in seconds for every external request
   */
  const REQUEST_TIMEOUT = 3;
  /**
   * @var int score added when the ip is a known tor exit node
   */
  const SCORE_TOR_EXIT = 3;
  /**
   * @var int score added when the ip is a well known form spammer
   */
  const SCORE_KNOWN_SPAMMER = 3;
  /**
   * @var int score added when the ip appeared in spam reports occasionally
   */
  const SCORE_SUSPICIOUS = 1;
  /**
   * @var float stopforumspam confidence from which on an ip counts as known spammer
   */
  const SFS_CONFIDENCE_HIGH = 25;
  /**
   * @var float stopforumspam confidence from which on an ip counts as suspicious
   */
  const SFS_CONFIDENCE_LOW = 5;

  /**
   * Get the reputation score of an ip address, higher means worse reputation
   * @param string $ip the ip to check, defaults to the ip of the current request
   * @return int the reputation score, 0 if unknown or not determinable
   */
  public static function getScore($ip = '')
  {
    if (strlen($ip) == 0) {
      $ip = self::getRequestIp();
    }

    // Only rate public ip addresses, everything else is meaningless
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return 0;
    }

    // A score of zero is a valid result, hence the found flag decides on a cache hit
    $found = false;
    $cacheKey = self::IP_CACHE_PREFIX . md5($ip);
    $cached = wp_cache_get($cacheKey, self::CACHE_GROUP, false, $found);
    if ($found) {
      return intval($cached);
    }

    $score = self::isTorExitNode($ip) ? self::SCORE_TOR_EXIT : 0;
    $score += self::getStopForumSpamScore($ip);
    wp_cache_set($cacheKey, $score, self::CACHE_GROUP, self::IP_CACHE_TTL);

    return $score;
  }

  /**
   * Tells if the given ip is a currently known tor exit node
   * @param string $ip the ip to check
   * @return bool true if the ip is a tor exit node
   */
  public static function isTorExitNode($ip)
  {
    $nodes = self::getTorExitNodes();
    return isset($nodes[$ip]);
  }

  /**
   * Get the list of tor exit nodes as a lookup map, cached for TOR_LIST_TTL
   * @return array map of ip addresses to true
   */
  public static function getTorExitNodes()
  {
    $nodes = wp_cache_get(self::TOR_LIST_KEY, self::CACHE_GROUP);
    if (is_array($nodes)) {
      return $nodes;
    }

    $body = self::request(self::TOR_LIST_URL);
    if ($body === false) {
      // Cache an empty list shortly, so a broken source doesn't hammer the request
      wp_cache_set(self::TOR_LIST_KEY, [], self::CACHE_GROUP, self::TOR_LIST_FAIL_TTL);
      return [];
    }

    $nodes = [];
    foreach (array_filter(array_map('trim', explode("\n", $body))) as $ip) {
      if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $nodes[$ip] = true;
      }
    }

    wp_cache_set(self::TOR_LIST_KEY, $nodes, self::CACHE_GROUP, self::TOR_LIST_TTL);
    return $nodes;
  }

  /**
   * Ask stopforumspam about the given ip and translate the answer to a score
   * @param string $ip the ip to check
   * @return int the score contributed by stopforumspam
   */
  public static function getStopForumSpamScore($ip)
  {
    $url = self::SFS_API_URL . '?json&ip=' . urlencode($ip);
    $body = self::request($url);
    if ($body === false) {
      return 0;
    }

    $data = json_decode($body, true);
    if (!isset($data['success']) || $data['success'] != 1 || !isset($data['ip'])) {
      return 0;
    }

    // The api gives the confidence in percent as a float value
    $confidence = floatval($data['ip']['confidence'] ?? 0);
    $appears = intval($data['ip']['appears'] ?? 0);
    $isTorExit = intval($data['ip']['torexit'] ?? 0) == 1;

    if ($isTorExit) {
      return self::SCORE_TOR_EXIT;
    }
    if ($appears == 1 && $confidence >= self::SFS_CONFIDENCE_HIGH) {
      return self::SCORE_KNOWN_SPAMMER;
    }
    if ($appears == 1 && $confidence >= self::SFS_CONFIDENCE_LOW) {
      return self::SCORE_SUSPICIOUS;
    }

    return 0;
  }

  /**
   * Get the ip address of the current request
   * @return string the ip address or an empty string
   */
  public static function getRequestIp()
  {
    return $_SERVER['REMOTE_ADDR'] ?? '';
  }

  /**
   * Do an external get request and return its body
   * @param string $url the url to call
   * @return string|bool the response body or false on any error
   */
  protected static function request($url)
  {
    $response = wp_remote_get($url, [
      'timeout' => self::REQUEST_TIMEOUT,
      'redirection' => 2,
      'user-agent' => 'LBWP IpReputation'
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
      return false;
    }

    return wp_remote_retrieve_body($response);
  }
}