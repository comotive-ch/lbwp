<?php

namespace LBWP\Module\General;
use LBWP\Util\File;

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
    'domain' => 'stats.comotive.ch'
  );
  /**
   * @var string option key for the settings
   */
  const SETTINGS_KEY = 'lbwpPiwikIntegrationSettings';
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
      add_action('wp_footer', array($this, 'addTrackingCode'));
      add_action('wp_dashboard_setup', array($this, 'addDashboardWidget'), 20);
    }
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

    // Print html
    echo '
      <a href="' . $loginLink . '" target="_blank">
        <img src="' . $logoUrl . '" class="piwik-logo" />
      </a>
      <p>
        Die Besucher-Statistiken werden automatisch aufgezeichnet und in unserem Rechenzentrum in der Schweiz gespeichert.
        <a href="' . $loginLink . '" target="_blank">Statistiken ansehen</a>.
      </p>
    ';
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
          var u="//' . $this->integration['domain'] . '/";
          _paq.push(["setTrackerUrl", u + "piwik.php"]);
          _paq.push(["setSiteId", ' . $siteId . ']);
          var d=document, g=d.createElement("script"), s=d.getElementsByTagName("script")[0];
          g.type="text/javascript"; g.async=true; g.defer=true; g.src=u+"piwik.js"; s.parentNode.insertBefore(g,s);
        })();
      </script>
      <noscript>
        <p><img src="//' . $this->integration['domain'] . '/piwik.php?idsite=' . $siteId . '" style="border:0;" alt="" /></p>
      </noscript>
    ';
  }

  /**
   * TODO add full integration here
   * Installs the tracker, if not already present on the piwik server.
   * Locally saves all information needed.
   */
  public static function installTracker()
  {
    // For now, just set an empty option, if not present
    $settings = get_option(self::SETTINGS_KEY, array());
    if (!is_array($settings) || count($settings) == 0) {
      $settings = array(
        'userName' => 'int_pagename_ch',
        'siteId' => 0,
        'passwordMd5' => '00000000000000000000000000000000',
        'authToken' => '00000000000000000000000000000000'
      );

      // Save the new settings to database
      update_option(self::SETTINGS_KEY, $settings);
    }
  }
}