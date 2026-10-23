<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

/**
 * Checks external urls in parallel with curl_multi. Sends HEAD first and falls back to a
 * size limited GET for servers that answer HEAD wrongly.
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class UrlChecker
{
  /**
   * @var int max parallel requests
   */
  const MAX_CONCURRENT = 8;
  /**
   * @var int max parallel requests to the same host
   */
  const MAX_PER_HOST = 2;
  /**
   * @var int connection timeout in seconds
   */
  const CONNECT_TIMEOUT = 8;
  /**
   * @var int total request timeout in seconds
   */
  const TIMEOUT = 15;
  /**
   * @var int bytes of the body read on GET before aborting
   */
  const MAX_BODY_BYTES = 16384;
  /**
   * @var int[] HEAD response codes that are confirmed with a GET request (0 = no response)
   */
  const GET_RETRY_CODES = [0, 400, 403, 404, 405, 406, 429, 500, 501, 503];
  /**
   * @var string browser like user agent, bot agents are blocked by most WAFs
   */
  const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
  /**
   * @var string[] request headers sent along like a browser does
   */
  const HEADERS = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: de-CH,de;q=0.9,en;q=0.8',
  ];

  /**
   * Check a list of urls
   *
   * @param array $urls url_hash => url
   * @return array url_hash => [code, errno, error, final_url, redirects]
   */
  public function checkAll(array $urls)
  {
    $results = $this->runMulti($urls, true);

    $retry = [];
    foreach ($results as $hash => $result) {
      if (in_array($result['code'], self::GET_RETRY_CODES)) {
        $retry[$hash] = $urls[$hash];
      }
    }

    if (count($retry) > 0) {
      $results = array_merge($results, $this->runMulti($retry, false));
    }

    return $results;
  }

  /**
   * Run requests in parallel, respecting the concurrency limits
   *
   * @param array $urls url_hash => url
   * @param bool $head true for HEAD, false for a limited GET
   * @return array url_hash => result
   */
  protected function runMulti(array $urls, $head)
  {
    $multi = curl_multi_init();
    $queue = $urls;
    $active = [];
    $hostCount = [];
    $results = [];

    while (count($queue) > 0 || count($active) > 0) {
      $this->fillPool($multi, $queue, $active, $hostCount, $head);

      curl_multi_exec($multi, $running);
      curl_multi_select($multi, 1.0);

      while (($info = curl_multi_info_read($multi)) !== false) {
        $handle = $info['handle'];
        $id = spl_object_id($handle);
        list($hash, $host) = $active[$id];

        $results[$hash] = $this->readResult($handle, $info['result']);
        $hostCount[$host]--;
        unset($active[$id]);
        curl_multi_remove_handle($multi, $handle);
      }
    }

    curl_multi_close($multi);
    return $results;
  }

  /**
   * Add queued urls to the pool until a limit is reached
   *
   * @param \CurlMultiHandle $multi the multi handle
   * @param array $queue url_hash => url, consumed
   * @param array $active handle id => [url_hash, host]
   * @param array $hostCount host => number of active requests
   * @param bool $head true for HEAD requests
   * @return void
   */
  protected function fillPool($multi, array &$queue, array &$active, array &$hostCount, $head)
  {
    foreach ($queue as $hash => $url) {
      if (count($active) >= self::MAX_CONCURRENT) {
        return;
      }

      $host = strtolower((string) parse_url($url, PHP_URL_HOST));
      if (($hostCount[$host] ?? 0) >= self::MAX_PER_HOST) {
        continue;
      }

      $handle = $this->createHandle($url, $head);
      curl_multi_add_handle($multi, $handle);
      $active[spl_object_id($handle)] = [$hash, $host];
      $hostCount[$host] = ($hostCount[$host] ?? 0) + 1;
      unset($queue[$hash]);
    }
  }

  /**
   * Create a configured curl handle
   *
   * @param string $url the url to request
   * @param bool $head true for HEAD, false for a limited GET
   * @return \CurlHandle the handle
   */
  protected function createHandle($url, $head)
  {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
      CURLOPT_TIMEOUT => self::TIMEOUT,
      CURLOPT_USERAGENT => self::USER_AGENT,
      CURLOPT_HTTPHEADER => self::HEADERS,
      CURLOPT_ENCODING => '',
      CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
      CURLOPT_NOBODY => $head,
      CURLOPT_HEADER => false,
    ]);

    if (!$head) {
      // Abort after the first bytes, the status code is all we need
      $received = 0;
      curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($handle, $data) use (&$received) {
        $received += strlen($data);
        return $received > self::MAX_BODY_BYTES ? 0 : strlen($data);
      });
    } else {
      curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    }

    return $handle;
  }

  /**
   * Read the result of a finished request
   *
   * @param \CurlHandle $handle the finished handle
   * @param int $errno curl error number of the transfer
   * @return array code, errno, error, final_url, redirects
   */
  protected function readResult($handle, $errno)
  {
    $code = intval(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));

    // An aborted body download is expected and fine, as long as we got a status
    if ($errno === CURLE_WRITE_ERROR && $code > 0) {
      $errno = CURLE_OK;
    }

    return [
      'code' => $errno === CURLE_OK ? $code : 0,
      'errno' => $errno,
      'error' => $errno === CURLE_OK ? '' : curl_strerror($errno),
      'final_url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
      'redirects' => intval(curl_getinfo($handle, CURLINFO_REDIRECT_COUNT)),
    ];
  }
}
