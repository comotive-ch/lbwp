<?php

namespace LBWP\Module\General\Cms;

use LBWP\Helper\Cronjob;
use LBWP\Module\BaseSingleton;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use function Weglot\Client\Api\array_keys_exists;

/**
 * Implements dead link checking functions
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class DeadLinkChecker extends BaseSingleton
{
  /**
   * Links with those endings and beginnings are excluded from the index
   */
  const EXLUDES = array(
    'endings' => array(
      'jpg', 'jpeg', 'gif', 'png', 'svg', 'eps', 'csv'
    ),
    'beginnings' => array(
      'tel:', 'mailto:', '#'
    )
  );
  /**
   * @var int code for newly added links to the index
   */
  const CODE_NEWLY_ADDED = 901;
  const CODE_UNREADABLE = 902;
  const CODE_NOT_FOUND = 404;
  /**
   * @var array setting for the links packages
   */
  const PACKAGES_SETTINGS = array(
    'max_cron' => 20,
    'min_links' => 30,
    'days' => 25
  );
  /**
   * If HEAD fails on websites, check again with GET, when these codes are returned
   */
  const DOUBLE_CHECK_CODES = array(
    403, 404, 405
  );
  /**
   * Max num of links to show on the page
   */
  const SHOW_MAX_LINKS = 100;
  /**
   * Labels for the status codes
   */
  const STATUS_CODE_LABEL = array(
    100 => 'Continue',
    101 => 'Switching Protocols',
    102 => 'Processing', // WebDAV; RFC 2518
    103 => 'Early Hints', // RFC 8297
    200 => 'OK',
    201 => 'Created',
    202 => 'Accepted',
    203 => 'Non-Authoritative Information', // since HTTP/1.1
    204 => 'No Content',
    205 => 'Reset Content',
    206 => 'Partial Content', // RFC 7233
    207 => 'Multi-Status', // WebDAV; RFC 4918
    208 => 'Already Reported', // WebDAV; RFC 5842
    226 => 'IM Used', // RFC 3229
    300 => 'Multiple Choices',
    301 => 'Moved Permanently',
    302 => 'Found', // Previously "Moved temporarily"
    303 => 'See Other', // since HTTP/1.1
    304 => 'Not Modified', // RFC 7232
    305 => 'Use Proxy', // since HTTP/1.1
    306 => 'Switch Proxy',
    307 => 'Temporary Redirect', // since HTTP/1.1
    308 => 'Permanent Redirect', // RFC 7538
    400 => 'Bad Request',
    401 => 'Passwort benötigt', // RFC 7235
    402 => 'Payment Required',
    403 => 'Zugriff blockiert',
    404 => 'Nicht gefunden',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    407 => 'Proxy Authentication Required', // RFC 7235
    408 => 'Request Timeout',
    409 => 'Conflict',
    410 => 'Gone',
    411 => 'Length Required',
    412 => 'Precondition Failed', // RFC 7232
    413 => 'Payload Too Large', // RFC 7231
    414 => 'URI Too Long', // RFC 7231
    415 => 'Unsupported Media Type', // RFC 7231
    416 => 'Range Not Satisfiable', // RFC 7233
    417 => 'Expectation Failed',
    418 => 'I\'m a teapot', // RFC 2324, RFC 7168
    421 => 'Misdirected Request', // RFC 7540
    422 => 'Unprocessable Entity', // WebDAV; RFC 4918
    423 => 'Locked', // WebDAV; RFC 4918
    424 => 'Failed Dependency', // WebDAV; RFC 4918
    425 => 'Too Early', // RFC 8470
    426 => 'Upgrade Required',
    428 => 'Precondition Required', // RFC 6585
    429 => 'Too Many Requests', // RFC 6585
    431 => 'Request Header Fields Too Large', // RFC 6585
    451 => 'Unavailable For Legal Reasons', // RFC 7725
    500 => 'Server Fehler',
    501 => 'Not Implemented',
    502 => 'Server Fehler',
    503 => 'Server Fehler',
    504 => 'Server Timeout',
    505 => 'HTTP Version Not Supported',
    506 => 'Variant Also Negotiates', // RFC 2295
    507 => 'Insufficient Storage', // WebDAV; RFC 4918
    508 => 'Loop Detected', // WebDAV; RFC 5842
    510 => 'Not Extended', // RFC 2774
    511 => 'Network Authentication Required', // RFC 6585
    // Unofficial codes
    420 => 'Enhance Your Calm', // Twitter
    444 => 'No Response', // nginx
    494 => 'Request header too large', // nginx
    495 => 'SSL Certificate Error', // nginx
    496 => 'SSL Certificate Required', // nginx
    497 => 'HTTP Request Sent to HTTPS Port', // nginx
    499 => 'Client Closed Request', // nginx
    902 => 'Ungültige Antwort', // nginx
    999 => 'Linkedin blocking'
  );

  /**
   * Called at admin menu, allows us to add a submenu for admins
   */
  public function run()
  {
    add_filter('init', array($this, 'registerCrons'));
    add_filter('admin_menu', array($this, 'registerBackendPage'));
  }

  /**
   * Display the index of dead links
   */
  public function registerBackendPage()
  {
    add_management_page(
      'Link Checker',
      'Link Checker',
      'administrator',
      'lbwp-dlc',
      array($this, 'dlcAdminPage')
    );
  }

  /**
   * Render the dead link checker backend page
   */
  public function dlcAdminPage()
  {
    $html = '';
    $linksHtml = '
      <form method="POST">
        <table class="wp-list-table widefat striped posts float-left-top-margin dlc-table">
          <thead>
            <tr>
              <th>Fehlerhafte Links</th>
              <th>Quelle</th>
              <th>Geprüft</th>
              <th>Code</th>
              <th>
                <label for="remove-link-all"><span class="dashicons-before dashicons-trash"></span></label>
                <input type="checkbox" id="remove-link-all">
                <input type="submit" class="remove-link" name="removeLink-all" value="Alle Löschen">
              </th>
            </tr>
          </thead>
          <tbody>
    ';
    $getLinks = WordPress::getJsonOption('deadlink_checker_index');
    $getLinks = ArrayManipulation::forceArray($getLinks);

    // Delete link
    if (!empty($_POST)) {
      $delIndex = explode('-', array_keys($_POST)[0])[1];

      // Delete all links
      if($delIndex === 'all'){
        foreach($getLinks as $index => $link){
          if($link['code'] <= 400){
            continue;
          }

          unset($getLinks[$index]);
        }
      }else{
        // Remove the link and restore the array
        unset($getLinks[intval($delIndex)]);
        $getLink = array_values($getLinks);
        // Unset the $_POST value (not necessary, but safety first)
        unset($_POST[array_keys($_POST)[0]]);
      }

      WordPress::updateJsonOption('deadlink_checker_index', $getLinks);
    }

    //array_multisort(array_column($getLinks, 'checked'), SORT_DESC, SORT_NUMERIC, $getLinks);
    // TODO: Sort array by date

    $linksStats = array(
      'checks' => 0,
      'success' => array(),
      'redirect' => array(),
      'failed' => array(),
    );

    // if more than X links it breaks and shows a text
    $shownLinks = 0;
    $maxLinkReached = false;

    // Setup the html
    foreach ($getLinks as $index => $link) {
      if($link['checked'] == 0){
        continue;
      }

      if (intval($link['code']) <= 300) {
        $linksStats['success'][] = $link;
        continue;
      }

      if (intval($link['code']) <= 400) {
        $linksStats['redirect'][] = $link;
        continue;
      }

      $linksStats['failed'][] = $link;
      if(!$maxLinkReached) {
        $linksHtml .=
          '<tr>
            <td><a href="' . $link['link'] . '" target="_blank">' . Strings::chopStringCenter($link['link'], 50, 10) . '</a></td>
            <td><a href="' . get_edit_post_link($link['source']) . '" target="_blank">' . get_the_title($link['source']) . '</a></td>
            <td>' . ($link['checked'] == 0 ? 'Nein' : date('d.m.Y, H:i:s', $link['checked'])) . '</td>
            <td>' . $link['code'] . ' ' . self::STATUS_CODE_LABEL[$link['code']] . '</td>
            <td>
              <label for="remove-link-button' . $index . '"><span class="dashicons-before dashicons-trash"></span></label>
              <input type="checkbox" id="remove-link-button' . $index . '">
              <input type="submit" class="remove-link" name="removeLink-' . $index . '" value="Löschen">
            </td>
          </tr>';
      }

      if($shownLinks >= self::SHOW_MAX_LINKS){
        $maxLinkReached = true;
      }else{
        $shownLinks++;
      }
    }

    $totalLinks = wp_cache_get('deadlink_total_links');
    if($totalLinks === false){
      $totalLinks = count($this->getDatabaseLinks());
      wp_cache_set('deadlink_total_links', $totalLinks, '', 60 * 60 * 24 * 10);
    }

    $linksHtml .= '</tbody></table></form></div>' . ($maxLinkReached ? '<p>Um weitere Links anzuzeigen bitte Links korrigieren oder löschen.</p>' : '');
    $html = '
      <h1>Überprüfen von Links</h1>
        <div class="wrap">
        <p>Alle Links in den Inhalten werden regelmässig überprüft. Fehlerhafte werden unten in einer Liste angezeigt und sollten bereinigt werden.</p>
          <div class="links-stats">
            <div class="links-num">
              <p><span>Überprüfte Links</span>' . (
                count($linksStats['success']) + count($linksStats['redirect']) + count($linksStats['failed'])
              ) . '/' . $totalLinks . '</p>
            </div>
            <div class="links-results">
              <p><span>Korrekte Links</span>' . count($linksStats['success']) . '</p>
              <p><span>Weiterleitungen</span>' . count($linksStats['redirect']) . '</p>
              <p><span>Fehlerhafte Links</span>' . count($linksStats['failed']) . '</p>
            </div>
          </div>' . $linksHtml;

    echo $html;
  }

  /**
   * Register crons needed to run dead link checking
   */
  public function registerCrons()
  {
    add_action('cron_monthly_1_2', array($this, 'rebuildLinkIndex'));
    add_action('cron_monthly_1_4', array($this, 'buildCheckPackages'));
    add_action('cron_job_deadlink_check_package', array($this, 'checkForDeadLinks'));
  }

  /**
   * Parse database for links and build the link index option:
   * array of (numeric index):
   * - link
   * - source (id), oder 0, wenn mehrere
   * - checked
   * - status
   */
  public function rebuildLinkIndex()
  {
    // Get the current index an aquire all links from the database
    $index = ArrayManipulation::forceArray(WordPress::getJsonOption('deadlink_checker_index'));
    $links = $this->getDatabaseLinks();

    // Put only the links into a separete array to facilitate the comparison
    $alreadyIndexedLinks = (isset($index[0]['link'])) ? array_combine(array_keys($index), array_column($index, 'link')) : array();
    $newIndex = [];

    foreach($links as $link => $source){
      // Check if the link is already in the index
      $currentIndex = array_search($link, $alreadyIndexedLinks);

      if($currentIndex !== false){
        // If so, just readd it
        $newIndex[$currentIndex] = $index[$currentIndex];
      }else{
        // If not, add it as new link
        $newIndex[] = array(
          'link' => $link,
          'source' => $source,
          'checked' => 0,
          'code' => self::CODE_NEWLY_ADDED
        );
      }
    }

    // Sort by key, because the key is used later to build chunks
    ksort($newIndex);

    // Write new index back into our option
    WordPress::updateJsonOption('deadlink_checker_index', $newIndex);
  }

  /**
   * Build link packages from the index. We build packages of at most 20 crons per day in den next 25 days.
   * Calculates the number of links for each cron package depending on the number of links in the index.
   * Crons are registered only during night hours (21 - 5)
   */
  public function buildCheckPackages()
  {
    // Get the links and initialize the jobs array
    $theLinks = ArrayManipulation::forceArray(WordPress::getJsonOption('deadlink_checker_index'));
    $numLinks = count($theLinks);
    $jobs = array();
    // Simplify class constants
    $mCron = self::PACKAGES_SETTINGS['max_cron'];
    $mLinks = self::PACKAGES_SETTINGS['min_links'];
    $days = self::PACKAGES_SETTINGS['days'];
    // Num of the link per cron (min 20) and use it to cut the links array into chunks
    $chunkSize = $numLinks / $mLinks / $days < $mLinks ? $mLinks : intval(ceil($numLinks / $mLinks / $days));
    $cron = array_chunk($theLinks, $chunkSize, true);
    // Calc an average of crons done per day
    $averageCronsPerDay = count($cron) / $days;
    // Indexes used for distribute crons evenly over the max days
    $index = $averageCronsPerDay > $mCron ? $mCron : $averageCronsPerDay;
    $curIndex = 0;

    for ($d = intval(date('j')) + 1; $d < $days; $d++) {
      // Loop through the defined days and check if the ceiled index is higher than one
      $fIndex = ceil($index);
      if ($fIndex > 0 && $fIndex !== $curIndex) {
        // If it is, get some crons (max 20)
        $cSlice = array_slice($cron, $curIndex, $fIndex - $curIndex);

        if (empty($cSlice)) {
          break;
        }

        // Set the start timestamp. Randomly starting between 01:00 and 01:19
        $ts = strtotime($d . '.' . date('m.Y') . ' 01:' . mt_rand(0, 1) . mt_rand(0, 9) . ':00');
        // Devide 6 hours over the number of crons
        $time = 60 * 60 * 6 / count($cSlice);

        for ($c = 0; $c < count($cSlice); $c++) {
          $arrayKeys = array_keys($cSlice[$c]);
          $jobs[intval(round($c * $time + $ts))] = 'deadlink_check_package::' . $arrayKeys[0] . '-' . end($arrayKeys);
        }

        $curIndex = $fIndex;
      }
      $index += $averageCronsPerDay;
    }
    // Finally register the cronjobs
    Cronjob::register($jobs);
  }

  /**
   * These are called within 25 days after the monthly crons and contain an number of links to be chacked
   * in the index. These are given in the $_GET['data'] attribute as 0-90, accessing direct array-indizes in the index
   */
  public function checkForDeadLinks()
  {
    set_time_limit(1800);
    list($from, $to) = array_map('intval', explode('-', $_GET['data']));
    $index = WordPress::getJsonOption('deadlink_checker_index');
    $time = current_time('timestamp');

    // Run trough part of index and update, with index accessing for loop
    for ($i = $from; $i <= $to; ++$i) {
      // Check if the link still exists
      if (!isset($index[$i])) {
        continue;
      }
      // Ask the remote to get us the link
      $errno = '';
      $result = $this->checkUrl($index[$i]['link'], true, 5, 5, $errno);
      // See if we have content and header
      if ($errno == CURLE_OK) {
        // Remove HTTP notation
        $code = $this->extractHttpCode($result);
        // Try again with GET, if needed
        if (in_array($code, self::DOUBLE_CHECK_CODES)) {
          $result = $this->checkUrl($index[$i]['link'], false, 10, 10, $errno);
          $code = $this->extractHttpCode($result);
        }
        // If other then known code , set fallback
        $code = ($code === 0 || $code < 200) ? self::CODE_UNREADABLE : $code;
        // Update in index
        $index[$i]['code'] = $code;
      } else {
        // Assume not found error
        $index[$i]['code'] = self::CODE_NOT_FOUND;
      }
      // Update time and close connection
      $index[$i]['checked'] = $time;

      usleep(500000);
    }

    // Put the checked package back into
    WordPress::updateJsonOption('deadlink_checker_index', $index);
  }

  /**
   * @param string $response any html response from curl
   * @return int the code returned in headers
   */
  protected function extractHttpCode($response)
  {
    $response = str_replace(array('HTTP/1.1 ', 'HTTP/2 '), '', $response);
    return intval(substr($response, 0, 3));
  }

  /**
   * @param string $url the url to be checked
   * @param bool $head true for HEAD false for GET request
   * @param int $ct connection timeout in s
   * @param int $rt request timeout in s
   * @param int $errno filled with the curl error
   * @return string header only or header with full html
   */
  protected function checkUrl($url, $head, $ct, $rt, &$errno)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $ct);
    curl_setopt($ch, CURLOPT_TIMEOUT, $rt);
    curl_setopt($ch, CURLOPT_USERAGENT, "comotive/deadlinkchecker-v1.0");
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($head) curl_setopt($ch, CURLOPT_NOBODY, true);
    $result = curl_exec($ch);
    $errno = curl_errno($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($errno == CURLE_OK && $httpCode > 300 && $httpCode < 309 && !empty($location)) {
      // Follow the redirect
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_URL, $location);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $ct);
      curl_setopt($ch, CURLOPT_TIMEOUT, $rt);
      curl_setopt($ch, CURLOPT_USERAGENT, "comotive/deadlinkchecker-v1.0");
      curl_setopt($ch, CURLOPT_HEADER, true);
      curl_setopt($ch, CURLOPT_NOBODY, true); // This makes it a HEAD request
      $result = curl_exec($ch);
      $errno = curl_errno($ch);
      curl_close($ch);
    }

    return $result;
  }

  /**
   * Get all links from the database and return them as array:
   * multidimensinal array (assotiative):
   *    'intern' => array with intern links
   *    'extern' => array with extern links
   * @return array[] all the links divided in intern and extern
   */
  private function getDatabaseLinks()
  {
    // Domain needed for relativ links
    $domain = get_bloginfo('url');

    // We have to do a native query here as wpdb->get_results is inefficient
    global $table_prefix;
    $db = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $query = apply_filters('lbwp_dlc_db_query', '
      SELECT ID, post_content AS Content FROM ' . $table_prefix . 'posts
      WHERE LENGTH(post_content) > 10 AND post_type != "revision"
      UNION SELECT post_id AS ID, meta_value AS Content FROM ' . $table_prefix . 'postmeta
      INNER JOIN ' . $table_prefix . 'posts ON ' . $table_prefix . 'posts.ID = ' . $table_prefix . 'postmeta.post_id
      WHERE LENGTH(meta_value) > 10');
    $result = mysqli_query($db, $query, MYSQLI_USE_RESULT);

    $links = array();
    while ($row = mysqli_fetch_assoc($result)) {
      extract($row, EXTR_PREFIX_ALL, 'post');
      $text = $post_Content;

      // Directly add link to list if it's a plain link and not HTML/text (e.g. from meta fields)
      if(filter_var($text, FILTER_VALIDATE_URL) !== false){
        $links[$text] = $row['ID'];
        continue;
      }

      // Check if there even are things to do
      if (strpos($text, 'href="') !== false || strpos($text, ': \'') !== false) {
        // Search for links
        $candidates = array();

        preg_match_all('/href="/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $linkPos) {
          $linkPos[1] = $linkPos[1] + 6;
          $candidates[] = substr($text, $linkPos[1], strpos($text, '"', $linkPos[1]) - $linkPos[1]);
        }
        preg_match_all('/: "http/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $linkPos) {
          $linkPos[1] = $linkPos[1] + 3;
          $candidates[] = stripslashes(substr($text, $linkPos[1], strpos($text, '"', $linkPos[1]) - $linkPos[1]));
        }

        // Add links to the array after checks
        foreach ($candidates as $link) {
          $link = utf8_encode($link);
          // Fix relative links
          if (Strings::startsWith($link, '/')) {
            $link = $domain . $link;
          }
          if (!isset($links[$link]) && !$this->excludeLink($link)) {
            // Finally put link as index into array with the matching content id
            $links[$link] = $post_ID;
          }
        }
      }
    }

    // Close resultset and connection
    $result->free_result();
    $db->close();

    return $links;
  }

  /**
   * Check if the link can be excluded.
   * @param string $checkLink the link to check
   * @return bool true if the link can be excluded
   */
  private function excludeLink($checkLink)
  {
    // Clean the link and get the ending
    $checkLink = trim(html_entity_decode($checkLink));
    $ending = substr($checkLink, strrpos($checkLink, '.') + 1);

    // Check if starts or ends with something that can be exlcluded or if its empty
    if (
      Strings::startsWithOne($checkLink, self::EXLUDES['beginnings']) ||
      in_array($ending, self::EXLUDES['endings']) ||
      Strings::isEmpty($checkLink)
    ) {
      return true;
    }

    return false;
  }
}