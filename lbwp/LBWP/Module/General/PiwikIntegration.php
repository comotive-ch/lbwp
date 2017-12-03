<?php

namespace LBWP\Module\General;
use LBWP\Util\Date;
use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Automatic integration into our central piwik server
 * @author Michael Sebel <michael@comotive.ch>
 */
class PiwikIntegration extends \LBWP\Module\Base
{
  /**
   * @var array the settings, loaded upon initialization
   */
  protected $settings = array();
  /**
   * @var array basic integration settings, can be filtered in a future release, if needed
   */
  protected $integration = array(
    'protocol' => 'https',
    'domain' => 'stats.comotive.ch'
  );
  /**
   * @var string option key for the settings
   */
  const SETTINGS_KEY = 'lbwpPiwikIntegrationSettings';
  /**
   * @var string admin auth token for creating of sites and users
   */
  const TOKEN_ADMIN_AUTH = 'b8736479f607eab93e56c3a8b111e479';
  /**
   * @var string the admin api server for our installations
   */
  const ADMIN_API_SERVER = 'stats.comotive.ch';
  /**
   * Registers all the daily and hourly jobs that the jobserver should do.
   * Only executed if DOING_LBWP_CRON is set, hence only in actual crons.
   */
  public function initialize()
  {
    // Get the options, and make sure it's an array
    $this->settings = get_option(self::SETTINGS_KEY, array());

    // Actually activate the features, if a site id is set. This is the minimum for tracking
    if (isset($this->settings['siteId']) && intval($this->settings['siteId']) > 0) {
      add_filter('wp_head', array($this, 'addDnsPrefetch'), 5);
      add_action('wp_footer', array($this, 'addTrackingCode'));
      add_action('wp_dashboard_setup', array($this, 'addDashboardWidget'), 20);
    }
  }

  /**
   * Add a prefetch info for the cookieless domain
   */
  public function addDnsPrefetch()
  {
    echo '<link rel="dns-prefetch" href="' . $this->integration['protocol'] . '//' . $this->integration['domain'] . '" />' . PHP_EOL;
  }


  /**
   * Adds the login and stats dashboard item
   */
  public function addDashboardWidget()
  {
    // LBWP news widget
    wp_add_dashboard_widget(
      'lbwp-piwik-integration',
      'Website Statistiken',
      array($this, 'getDashboardWidgetHtml')
    );
  }

  /**
   * Print the dashboard item html
   */
  public function getDashboardWidgetHtml()
  {
    // Create the login link
    $loginData = array(
      'module' => 'Login',
      'action' => 'logme',
      'login' => $this->settings['userName'],
      'password' => $this->settings['passwordMd5'],
      'idSite' => $this->settings['siteId'],
    );
    $loginLink = 'https://' . $this->integration['domain'] . '/index.php?' . http_build_query($loginData);
    $logoUrl = File::getResourceUri() . '/images/other/comotive-piwik.png';

    // Print html with logo and login link
    echo '
      <a href="' . $loginLink . '" target="_blank">
        <img src="' . $logoUrl . '" class="piwik-logo" />
      </a>
      <p>
        Die Besucher-Statistiken werden automatisch aufgezeichnet und in unserem Rechenzentrum in der Schweiz gespeichert.
        <a href="' . $loginLink . '" target="_blank">Statistiken ansehen</a>.
      </p>
    ';

    // If there is token auth, provide stats
    if ($this->isApiAvailable()) {
      echo $this->getDashboardSimpleStats();
    }
  }

  /**
   * @return string html table to represent simple stats
   */
  protected function getDashboardSimpleStats()
  {
    $html = wp_cache_get('PiwikDashboardSimple', 'Piwik');

    if ($html == false) {
      $ts = current_time('timestamp');
      $from = Date::getTime(Date::SQL_DATE, $ts - (30 * 86400));
      $to = Date::getTime(Date::SQL_DATE, $ts);

      // Get some main data for the last 30 days
      $data = array(
        'period' => 'range',
        'date' => $from . ',' . $to
      );

      $visitTimePerUser = $actionsPerUser = 0;
      $visits = $this->queryApi('VisitsSummary.getVisits', $data);
      $actions = $this->queryApi('VisitsSummary.getActions', $data);
      if ($actions['value'] > 0 && $visits['value'] > 0) {
        $actionsPerUser = floor($actions['value'] / $visits['value']);
      }

      // Get visiting time data
      $visitTime = $this->queryApi('VisitTime.getVisitInformationPerServerTime', array(
        'period' => 'day',
        'date' => 'yesterday'
      ));

      $totalVisitTime = $totalUsers = 0;
      foreach ($visitTime as $dataSet) {
        if ($dataSet['sum_visit_length'] == 0) {
          continue;
        }
        $visitTimePerUser = ($dataSet['sum_visit_length'] / $dataSet['nb_uniq_visitors']);
        $totalUsers += $dataSet['nb_uniq_visitors'];
        // Do a plausibility check (of three hours)
        if ($visitTimePerUser < 10800) {
          $totalVisitTime += $visitTimePerUser;
        }
      }

      // Visit time per user
      if ($totalVisitTime > 0 && $totalUsers > 0) {
        $visitTimePerUser = ceil($totalVisitTime / $totalUsers);
      }

      // Create HTML output
      $html = '
        <table class="dashboard-generic">
          <tr>
            <td>' . __('Besucher in den letzten 30 Tagen', 'lbwp') . '</td>
            <td class="right-fat">' . $visits['value'] . '</td>
          </tr>
          <tr>
            <td>' . __('Durchschnittliche Anzahl Klicks pro Besucher', 'lbwp') . '</td>
            <td class="right-fat">' . $actionsPerUser . '</td>
          </tr>
          <tr>
            <td>' . __('Durchschnittliche Besuchszeit', 'lbwp') . '</td>
            <td class="right-fat">' . gmdate("H:i:s", $visitTimePerUser) . '</td>
          </tr>
        </table>
      ';

      // Set HTML to cache
      wp_cache_set('PiwikDashboardSimple', $html, 'Piwik', 21600);
    }

    return $html;
  }

  /**
   * @return bool true, if the api is available for use
   */
  protected function isApiAvailable()
  {
    return intval($this->settings['siteId']) > 0 && strlen($this->settings['authToken']) == 32;
  }

  /**
   * @param string $method the piwik api method
   * @param array $data the data to be requested
   * @return array result array
   */
  protected function queryApi($method, $data = array())
  {
    $url = 'https://' . $this->integration['domain'] . '/?module=API';
    $url.= '&method=' . $method;
    $url.= '&token_auth=' . $this->settings['authToken'];
    $url.= '&idSite=' . $this->settings['siteId'];
    $url.= '&format=json';
    if (count($data) > 0) {
      $url .= '&' . http_build_query($data);
    }

    return json_decode(file_get_contents($url), true);
  }

  /**
   * @param string $method the piwik api method
   * @param array $data the data to be requested
   * @return array result array
   */
  protected static function queryAdminApi($method, $data = array())
  {
    $url = 'https://' . self::ADMIN_API_SERVER . '/?module=API';
    $url.= '&method=' . $method;
    $url.= '&token_auth=' . self::TOKEN_ADMIN_AUTH;
    $url.= '&format=json';
    if (count($data) > 0) {
      $url .= '&' . http_build_query($data);
    }

    return json_decode(file_get_contents($url), true);
  }

  /**
   * Add the tracking code
   */
  public function addTrackingCode()
  {
    $siteId = intval($this->settings['siteId']);
    echo '
      <script type="text/javascript">
        var _paq = _paq || [];
        _paq.push(["trackPageView"]);
        _paq.push(["enableLinkTracking"]);
        (function() {
          var u="' . $this->integration['protocol'] . '://' . $this->integration['domain'] . '/";
          _paq.push(["setTrackerUrl", u + "piwik.php"]);
          _paq.push(["setSiteId", ' . $siteId . ']);
          var d=document, g=d.createElement("script"), s=d.getElementsByTagName("script")[0];
          g.type="text/javascript"; g.async=true; g.defer=true; g.src=u+"piwik.js"; s.parentNode.insertBefore(g,s);
        })();
      </script>
      <noscript>
        <p><img src="' . $this->integration['protocol'] . '://' . $this->integration['domain'] . '/piwik.php?idsite=' . $siteId . '" style="border:0;" alt="" /></p>
      </noscript>
    ';
  }

  /**
   * Installs the tracker, if not already present on the piwik server.
   * Locally saves all information needed.
   */
  public static function installTracker()
  {
    // For now, just set an empty option, if not present
    $settings = get_option(self::SETTINGS_KEY, array());
    if (!is_array($settings) || count($settings) == 0) {
      // Get all sites, to see if we need to create one
      $siteId = 0;
      $allSites = self::queryAdminApi('SitesManager.getAllSites');
      $siteName = str_replace('www', '', getLbwpHost());
      $userName = 'int_' . str_replace('.', '_', $siteName);

      // Search the site by name
      $siteAlreadyAvailable = false;
      foreach ($allSites as $site) {
        if ($site['name'] == $siteName) {
          $siteAlreadyAvailable = true;
          $siteId = intval($site['idsite']);
        }
      }

      // Create a new site, if not already available
      if (!$siteAlreadyAvailable) {
        $result = self::queryAdminApi('SitesManager.addSite', array(
          'siteName' => $siteName,
          'urls' => get_bloginfo('url'),
          'ecommerce' => '0',
          'siteSearch' => '1',
          'searchKeywordParameters' => 'q,query,s,search,searchword,k,keyword',
          'excludeUnknownUrls' => '0',
          'timezone' => 'Europe/Zurich',
          'currency' => 'CHF',
          'type' => 'website'
        ));

        // Get back the new site ID
        $siteId = intval($result['value']);
      }

      var_dump($siteId);

      // If we have a valid site id, let's create a new user
      if ($siteId > 0) {
        // See if we already have users for the page
        $users = self::queryAdminApi('UsersManager.getUsersAccessFromSite', array(
          'idSite' => $siteId
        ));

        // Proceed if we don't have any users yet
        if (count($users) == 0) {
          $password = Strings::getRandom(24);
          // Create the new user
          $result = self::queryAdminApi('UsersManager.addUser', array(
            'userLogin' => $userName,
            'alias' => $userName,
            'email' => 'it+' . str_replace('int_', '', $userName) . '@comotive.ch',
            'password' => $password
          ));

          // Give the user access to the page
          self::queryAdminApi('UsersManager.setUserAccess', array(
            'userLogin' => $userName,
            'idSites' => $siteId,
            'access' => 'view'
          ));

          // Get the auth token for the user
          $tokenAuth = self::queryAdminApi('UsersManager.getTokenAuth', array(
            'userLogin' => $userName,
            'md5Password' => md5($password)
          ));
        }
      }

      // Create the settings array
      $settings = array(
        'userName' => $userName,
        'siteId' => $siteId,
        'passwordMd5' => md5($password),
        'authToken' => $tokenAuth['value']
      );

      // Save the new settings to database
      update_option(self::SETTINGS_KEY, $settings);
    }
  }
}