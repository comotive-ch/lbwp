<?php

namespace LBWP\Module\General;

use LBWP\Module\BaseSingleton;
use LBWP\Module\Frontend\HTMLCache;

/**
 * Implements the maintenance mode (earlier Blogwerk_Maintenance Plugin)
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Module\General
 */
class MaintenanceMode extends BaseSingleton
{
  /**
   * @var array maintenance mode setting defaults
   */
  protected $settings = array(
    'pageTitle' => 'Webseite im Wartungsmodus',
    'bodyBackgroundCss' => 'background: url(https://assets01.sdd1.ch/assets/lbwp-cdn/comotive/files/1424341079/comotive-icon-bg.png) no-repeat center -280px;',
    'additionalCss' => '',
    'logoUrl' => 'https://assets01.sdd1.ch/assets/lbwp-cdn/comotive/files/1424341081/comotive-logo.png',
    'pageInfoTitle' => '&lt;/comotive@work&gt;',
    'pageInfoText' => 'Die Webseite <strong>%s</strong> befindet sich im Wartungsmodus.',
    'showLoginLinkIfPassword' => false
  );
  /**
   * @var bool the password login, if true, maintenance mode can be ommited
   */
  protected $hasPasswordLogin = false;
  /**
   * @var string the cookie hash
   */
  const COOKIE_HASH = 'a6fe8fue83d76z82fhu4h974529tgz7werg';
  /**
   * @var string salt for omitting maintenance mode for certain pages
   */
  const OMITTING_SALT = 'hd83jek8';
  /**
   * @var int valid cookie time
   */
  const COOKIE_EXPIRE = 31536000;

  /**
   * Adds filters to support maintenance mode and some options to configure it
   */
  protected function run()
  {
    add_action('after_setup_theme', array($this, 'setupMaintenanceMode'));
  }

  public function setupMaintenanceMode()
  {
    // Try a simple password login, if needed
    if (isset($_GET['tryMaintenanceLogin'])) {
      $this->tryMaintenanceLogin();
    }

    // If a valid cookie is present, set password login
    if (isset($_COOKIE['MMValidLogin']) && $_COOKIE['MMValidLogin'] == self::COOKIE_HASH) {
      $this->hasPasswordLogin = true;
    }

    // Make sure to not cache, if there is a password login
    if ($this->hasPasswordLogin) {
      HTMLCache::avoidCache();
    }

    // Use the maintenance mode if needed
    if ($this->useMaintenanceMode()) {
      $this->settings = apply_filters('maintenance_mode_config', $this->settings);
      add_filter('template_redirect', array($this, 'sendMaintenanceHtmlCode'));
      add_filter('status_header', array($this, 'sendHeader'), 20, 4);
      add_filter('template', array($this, 'getThemeName'), 20);
      add_filter('stylesheet', array($this, 'getThemeName'), 20);
      // Don't cache the maintenance mode, because of possible password cookie logins
      HTMLCache::avoidCache();
    }
  }

  /**
   * Try a maintenance login and set the cookie (and session)
   */
  protected function tryMaintenanceLogin()
  {
    // Try a login and set a cookie if valid, also allow a direct one time login
    if (isset($_POST['maintenancePassword']) && $_POST['maintenancePassword'] == $this->config['Various:MaintenancePassword']) {
      setcookie('MMValidLogin', self::COOKIE_HASH, time() + self::COOKIE_EXPIRE, '/', LBWP_HOST);
      $this->hasPasswordLogin = true;
    }
  }

  /**
   * Called only in maintenance mode
   * @param string $currentTheme the current theme (not used)
   * @return string same string, to not crash in some cases
   */
  public function getThemeName($currentTheme)
  {
    return $currentTheme;
  }

  /**
   * Sends the correct header, if the maintenance mode is active
   *
   * @param int $statusHeader the current status header
   * @param string $header
   * @param string $text
   * @param string $protocol
   * @return string the new $statusHeaer
   */
  public function sendHeader($statusHeader, $header, $text, $protocol)
  {
    // Don't return another header if it's a feed. set it and let the music fade
    if (is_feed()) {
      header($protocol . ' 423 Resource Locked');
      exit;
    } else {
      return $protocol . ' 423 Resource Locked';
    }
  }

  /**
   * Sends the html code for the maintenance message
   */
  public function sendMaintenanceHtmlCode()
  {
    echo '
      <!doctype html>
      <html class="no-js" lang="en">
      <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>' . $this->settings['pageTitle'] . ' - ' . LBWP_HOST . '</title>
        <style type="text/css">

        body {
          font-family: "Montserrat", sans-serif;
        }

        .logo {
          position: relative;
          top: 50px;
          text-align: center;
        }

        .content {
          width: 80%;
          margin: 100px auto 0;
          text-align: center;
          color: rgb(22, 34, 42); /* 94, 119, 140 */
        }

        .maintenance-password, .maintenance-submit {
          border: 1px solid #cfcfcf;
          border-radius:4px;
          padding:6px;
          font-size:14px;
        }

        h1 {
          font-size: 1.5em;
        }
        p {
         font-size: 1em;
        }

        a,a:visited {
          color: rgb(94, 119, 140);
        }
        a:hover {
          color: rgb(22, 34, 42);
        }

        @media only screen and (min-width: 60em) {
          body {
            ' . $this->settings['bodyBackgroundCss'] . '
          }

          .logo {
            position: absolute;
            width: auto;
            top: 50px;
            left: 50px;
          }
          .content {
            width: 40%;
            margin: 350px auto 0;
          }
          h1 {
            font-size: 2.5em;
          }
        }
        ' . $this->settings['additionalCss'] . '

        </style>
        <link href="//fonts.googleapis.com/css?family=Montserrat:400,700" rel="stylesheet" type="text/css">
      </head>
      <body>

      <div class="logo">
        <img src="' . $this->settings['logoUrl'] . '" alt="">
      </div>

      <div class="content">
        <h1>' . $this->settings['pageInfoTitle'] . '</h1>
        <p>' . sprintf($this->settings['pageInfoText'], LBWP_HOST) . '</p>
        ' . $this->getLoginHtml() . '
      </div>

      </body>
      </html>
    ';
    exit;
  }

  /**
   * @return string html code
   */
  protected function getLoginHtml()
  {
    $html = '';

    if (isset($this->config['Various:MaintenancePassword']) && strlen($this->config['Various:MaintenancePassword']) > 0) {
      $html .= '
        <form method="POST" action="?tryMaintenanceLogin">
          <span class="password-text">Passwort:</span>
          <input type="password" name="maintenancePassword" class="maintenance-password" />
          <input type="submit" class="maintenance-submit" value="Anmelden" />
        </form>
      ';
    }

    // Still show the login link?
    if ($this->settings['showLoginLinkIfPassword']) {
      $html .= '<p><a href="/wp-admin">Hier geht\'s zum Login.</a></p>';
    }

    return $html;
  }

  /**
   * Returns true if the maintenance mode is active and the user
   * should be redirected
   *
   * @return boolean
   */
  protected function useMaintenanceMode()
  {
    $isActive = $this->config['Various:MaintenanceMode'];
    // If active, be sure to add a filter to handle robots
    if ($isActive == 1) {
      add_filter('robots_txt', array($this, 'denyRobotsAccess'), 20);
    }

    return (
      $isActive == 1 &&
      !is_user_logged_in() &&
      !$this->isNeededPublicFile($_SERVER['SCRIPT_FILENAME']) &&
      !$this->hasPasswordLogin &&
      !$this->omitByUriHash()
    );
  }

  /**
   * An URI can contain an md5 hash that uses the OMITTING_SALT
   * and the actual URI before the questionmark to actually
   * skip the maintenance mode and make the single site public/cachable
   */
  public function omitByUriHash()
  {
    if (strlen($_SERVER['QUERY_STRING']) == 32) {
      $requestUri = substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'));
      $matcher = md5(self::OMITTING_SALT . $requestUri);
      return ($matcher == $_SERVER['QUERY_STRING']);
    }

    return false;
  }

  /**
   * @param string $uri the checked uri or file name
   * @return bool if the file is a needed public file like cron, fetch or upload
   */
  public function isNeededPublicFile($uri)
  {
    if (
      (strpos($_SERVER['REQUEST_URI'], 'robots.txt') === false) &&
      (strpos($uri, 'async-upload.php') === false) &&
      (strpos($uri, 'lbwp/views/cron/daily.php') === false) &&
      (strpos($uri, 'lbwp/views/cron/hourly.php') === false) &&
      (strpos($uri, 'lbwp/views/cron/job.php') === false) &&
      (strpos($uri, 'lbwp/views/cron/trace.php') === false) &&
      (strpos($uri, 'lbwp/views/api/') === false)
    ) {
      // Not one of the files, this isn't needed
      return false;
    }

    // Seems to be one of the always visible files
    return true;
  }

  /**
   * Called only in maintenance mode, changing the robots.txt disallow all search engines
   * @param string $robots the current robots.txt content
   * @return string the new robots.txt content (previous content is deleted)
   */
  public function denyRobotsAccess($robots)
  {
    $robots = "User-agent: *\n";
    $robots .= "Disallow: /\n";
    return $robots;
  }
}