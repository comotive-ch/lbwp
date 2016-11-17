<?php
// URLs for graphite
define('URL_CACHED_GAUGE', 'http://graphite.comotive.ch:8080/render/?from=-15minutes&target=averageSeries(stats.gauges.lbwp.gauges.requests.cached)&format=json');
define('URL_UNCACHED_GAUGE', 'http://graphite.comotive.ch:8080/render/?from=-15minutes&target=averageSeries(stats.gauges.lbwp.gauges.requests.uncached)&format=json');
define('URL_404_ERRORS', 'http://graphite.comotive.ch:8080/render/?from=-15minutes&target=summarize(stats.counters.lbwp.rating._mass404.count, "30s")&format=json');
// Thresholds
define('E404_MAX_ERRORS_PER_PERIOD', 200);
define('E404_MAX_ERRORS_AVERAGE', 20);
define('UNCACHED_PERIOD_MAX_AVERAGE', 1000);
define('UNCACHED_REQUEST_PEAK_THRESHOLD', 1500);
define('UNCACHED_MAX_PEAKS_PER_PERIOD', 20);
define('CACHED_PERIOD_MAX_AVERAGE', 20);
define('CACHED_REQUEST_PEAK_THRESHOLD', 100);
define('CACHED_MAX_PEAKS_PER_PERIOD', 10);
// Configs
define('VERBOSE_OUTPUT', false);
define('LOG_EMAIL', 'it@comotive.ch');

/**
 * Class LbwpRatingTracker - Tracks ratings
 * @author Michael Sebel <michael@comotive.ch>
 */
class LbwpRatingTracker
{
  /**
   * @var array error array
   */
  protected $errors = array();
  /**
   * @var array verbose error array
   */
  protected $verbose = array();

  /**
   * LbwpRatingTracker constructor, directly run all checks
   */
  public function __construct()
  {
    $this->verbose[] = 'Starting checks at ' . date('d.m.Y, H:i:s');
    $this->verbose[] = 'Measuring a period of 15 minutes';
    // Run all the checks
    $this->checkCachedAverage();
    $this->checkUncachedAverage();
    $this->checkNotFoundErrors();
    // Send the logs, if needed or print verbose output
    $this->sendLogs();
  }

  /**
   * Check the response time of uncached requests
   */
  protected function checkUncachedAverage()
  {
    // Get data and convert to a valid simple array
    $points = array();
    $data = $this->getGraphiteData(URL_UNCACHED_GAUGE);
    foreach ($data as $set) {
      if ($set[0] != NULL) $points[] = $set[0];
    }

    // Get total and peaks
    $peaks = $totalMs = 0;
    foreach ($points as $point) {
      $totalMs += $point;
      if ($point > UNCACHED_REQUEST_PEAK_THRESHOLD) {
        $peaks++;
      }
    }

    // Calc average
    $averageMs = $totalMs / count($points);

    // Add verbose info
    $this->verbose[] = 'Total of uncached data points in period: ' . count($points);
    $this->verbose[] = 'Average uncached response time in period: ' . round($averageMs, 2) . 'ms';
    $this->verbose[] = 'Uncached Peaks above ' . UNCACHED_REQUEST_PEAK_THRESHOLD . 'ms in period: ' . $peaks;

    // Add errors, if thresholds are met
    if ($peaks >= UNCACHED_MAX_PEAKS_PER_PERIOD) {
      $this->errors[] = 'More than ' . UNCACHED_MAX_PEAKS_PER_PERIOD . ' uncached peaks per period: ' . $peaks;
    }
    if ($averageMs >= UNCACHED_PERIOD_MAX_AVERAGE) {
      $this->errors[] = 'Average uncached response time high: ' . $averageMs . 'ms';
    }
  }

  /**
   * Check the response time of cached requests
   */
  protected function checkCachedAverage()
  {
    // Get data and convert to a valid simple array
    $points = array();
    $data = $this->getGraphiteData(URL_CACHED_GAUGE);
    foreach ($data as $set) {
      if ($set[0] != NULL) $points[] = $set[0];
    }

    // Get total and peaks
    $peaks = $totalMs = 0;
    foreach ($points as $point) {
      $totalMs += $point;
      if ($point > CACHED_REQUEST_PEAK_THRESHOLD) {
        $peaks++;
      }
    }

    // Calc average
    $averageMs = $totalMs / count($points);

    // Add verbose info
    $this->verbose[] = 'Total of cached data points in period: ' . count($points);
    $this->verbose[] = 'Average cached response time in period: ' . round($averageMs, 2) . 'ms';
    $this->verbose[] = 'Cached Peaks above ' . CACHED_REQUEST_PEAK_THRESHOLD . 'ms in period: ' . $peaks;

    // Add errors, if thresholds are met
    if ($peaks >= CACHED_MAX_PEAKS_PER_PERIOD) {
      $this->errors[] = 'More than ' . CACHED_MAX_PEAKS_PER_PERIOD . ' cached peaks per period: ' . $peaks;
    }
    if ($averageMs >= CACHED_PERIOD_MAX_AVERAGE) {
      $this->errors[] = 'Average cached response time high: ' . $averageMs . 'ms';
    }
  }

  /**
   * Check upon 404 errors
   */
  protected function checkNotFoundErrors()
  {
    // Get a hold of 404 errors
    $data = $this->getGraphiteData(URL_404_ERRORS);
    $totalErrors = 0;
    foreach ($data as $key => $set) {
      if ($set[0] != NULL) {
        $totalErrors += $set[0];
      } else {
        unset($data[$key]);
      }
    }

    // Get an average of those
    $averageErrors = ceil($totalErrors / count($data));
    $this->verbose[] = '404 errors in period: ' . $totalErrors;
    $this->verbose[] = '404 average errors per minute: ' . $averageErrors;

    // Test the values
    if ($totalErrors > E404_MAX_ERRORS_PER_PERIOD) {
      $this->errors[] = 'Max 404 errors per period above ' . E404_MAX_ERRORS_PER_PERIOD . ': ' . $totalErrors;
    }
    if ($averageErrors > E404_MAX_ERRORS_AVERAGE) {
      $this->errors[] = '404 errors average in period above ' . E404_MAX_ERRORS_AVERAGE . ': ' . $averageErrors;
    }
  }

  /**
   * Send the logs and print verbose output, if needed
   */
  protected function sendLogs()
  {
    // Send email if problems happened
    if (count($this->errors) > 0) {
      $this->verbose[] = 'sent email to ' . LOG_EMAIL . ' due to errors';
      mail(LOG_EMAIL, 'Graphite rate treshold reached', print_r($this->errors, true), 'From: ' . LOG_EMAIL);
    }

    // Print verbose output
    if (VERBOSE_OUTPUT) {
      $this->verbose[] = 'Finished checks at ' . date('d.m.Y, H:i:s');
      header('Content-Type: text/plain');
      echo 'Infos: ';
      echo print_r($this->verbose, true);
      echo 'Errors: ';
      echo print_r($this->errors, true);
    }
  }

  /**
   * @param string $url the graphite url
   * @return array $data
   */
  protected function getGraphiteData($url) {
    $curl = curl_init($url);
    curl_setopt_array($curl, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      // do not verify ssl since test is using self signed certs
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_PROXY => '46.101.12.125:3128',
      CURLOPT_PROXYUSERPWD => 'comotive:Kv8gnr9qd5erSquid',
      CURLOPT_HEADER => false,
      CURLOPT_ENCODING => '',
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_USERAGENT => 'LBWP-1.0-',
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_HTTPHEADER => array()
    ));

    $data = json_decode(curl_exec($curl), true);
    return $data[0]['datapoints'];
  }
}

new LbwpRatingTracker();
