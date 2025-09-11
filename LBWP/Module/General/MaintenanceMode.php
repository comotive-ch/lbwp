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
    'additionalCss' => '',
    'pageInfoTitle' => 'comotive is at work here.',
    'pageInfoText' => 'Die Webseite <strong>%s</strong> befindet sich im Wartungsmodus.',
    'pageLoginText' => 'Im Wartungsmodus anmelden',
    'showLoginLinkIfPassword' => false
  );
  /**
   * @var bool the password login, if true, maintenance mode can be ommited
   */
  protected $hasPasswordLogin = false;
  /**
   * @var bool if the login fails set to true
   */
  protected $failedLogin = false;
  /**
   * @var string some additonal header code to individualize
   */
  protected static $header = '';
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
    if (isset($_POST['maintenancePassword'])) {
      if($_POST['maintenancePassword'] == $this->config['Various:MaintenancePassword']){
        setcookie('MMValidLogin', self::COOKIE_HASH, time() + self::COOKIE_EXPIRE, '/', LBWP_HOST);
        $this->failedLogin = false;
        $this->hasPasswordLogin = true;
      }else{
        $this->failedLogin = true;
      }
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
    $lbwpPath = get_bloginfo('url') . '/wp-content/plugins/lbwp/resources/css/';
    $comotiveThemePath = get_bloginfo('url') . '/wp-content/themes/comotive-v3/';
    $comotiveColors = array(
      'rgba(32,171,217,1)',
      'rgba(189,159,64,1)',
      'rgba(225,56,49,1)'
    );
    shuffle($comotiveColors);
    $loginHtml = $this->getLoginHtml();

    $mtnceHtml = '<!doctype html>
      <html class="no-js" lang="de">
      <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>' . $this->settings['pageTitle'] . ' - ' . LBWP_HOST . '</title>
        ' . self::$header . '
        <style>
          /* rubik-regular - latin */
          @font-face {
            font-family: "Inter";
            font-style: normal;
            font-weight: 400;
            src: url("' . $comotiveThemePath . 'assets/fonts/inter-v18-latin-regular.woff2") format("woff2");
          }
          
          @font-face {
            font-family: "Inter";
            font-style: normal;
            font-weight: 600;
            src: url("' . $comotiveThemePath . 'assets/fonts/inter-v18-latin-600.woff2") format("woff2");
          }         
        
          
          .bubble:nth-child(1) path{
            fill: ' . $comotiveColors[0] . ';
          }
          
          .bubble:nth-child(2) path{
            fill: ' . $comotiveColors[1] . ';
          }
          
          .bubble:nth-child(3) path{
            fill: ' . $comotiveColors[2] . ';
          }
          
          .maintenance-bottom__bubble svg path{
            fill: ' . $comotiveColors[2] . ';
          }
  
          ' . $this->settings['additionalCss'] . '
        </style>
        
        <link rel="stylesheet" href="' . $lbwpPath . 'lbwp-maintenance-mode.css">
        
        
      </head>
      <body>
      
      <div class="maintenance-site-wrapper"> 
        <div class="maintenance-site-background"> 
          <div class="maintenance-bg__gradients">
            <div class="bubble--move maintenance-bg__gradient maintenance-bg__gradient--blue" data-movespeed="0.1"></div>
            <div class="bubble--move maintenance-bg__gradient maintenance-bg__gradient--lime" data-movespeed="0.5"></div>
            <div class="bubble--move maintenance-bg__gradient maintenance-bg__gradient--yellow" data-movespeed="1.2"></div>
          </div>
        
        </div>
        <div class="maintenance-site-content"> 
          <header class="maintenance-header">
        <div class="maintenance-header__inner"> 
          <div class="maintenance-header__logo"> 
            <a href="https://comotive.ch/" target="_blank" tabindex="-1">
              ' . file_get_contents($comotiveThemePath . 'assets/img/svg/logo-comotive.svg') . '
            </a>
          </div>
        </div>
      </header>
      
      <div class="maintenance-content"> 
      
        <section class="maintenance-content__intro"> 
           <div class="maintenance-container"> 
             <p>' . $this->settings['pageInfoTitle'] . '</p>
             <h1>' . sprintf($this->settings['pageInfoText'], LBWP_HOST) . '</h1>
           </div>
        </section>      
      </div>
      
      <section class="maintenance-bottom">        
        <div class="maintenance-container">        
        
          {CONTENT_BUBBLE_3}
          
          <footer class="maintenance-footer">         
            <div class="maintenance-footer__contact"> 
              comotive GmbH
            </div>
            <div class="maintenance-footer__legal"> 
              <ul> 
                <li> 
                  <a href="https://comotive.ch/impressum" target="_blank"> 
                    Impressum
                  </a>
                </li>
                <li> 
                  <a href="https://comotive.ch/datenschutzerklaerung/" target="_blank"> 
                    Datenschutzerklärung
                  </a>
                </li>
              </ul>
            </div>
          </footer>
        </div>
        
      </section>
        
        </div>
      
      </div>
      
      
      
      
      <script>
        let bubbles = document.querySelectorAll(".bubble--move");
    
        // Speichere die ursprüngliche Position jedes Elements
        let bubblePositions = [];
    
        bubbles.forEach(function(item, index) {
            bubblePositions[index] = {
                left: item.offsetLeft,
                top: item.offsetTop
            };
        });
    
        document.body.addEventListener("mousemove", function(e) {
            if (window.innerWidth > 580) {
                bubbles.forEach(function(item, index) {
                    let multiplicator = item.getAttribute("data-movespeed");
                    let originalPosition = bubblePositions[index];
    
                    // Bewegung wird relativ zur ursprünglichen Position berechnet
                    let movementX = (e.clientX - window.innerWidth / 2) * multiplicator;
                    let movementY = (e.clientY - window.innerHeight / 2) * multiplicator;
    
                    item.style.left = originalPosition.left + movementX + "px";
                    item.style.top = originalPosition.top + movementY + "px";
                });
            }
        });
    
        let preferredColorScheme = (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
        document.body.classList.add(preferredColorScheme);
    </script>

      </body>
      </html>
    ';

    $replaceStrings = array(
      '<h1>' . $this->settings['pageInfoTitle'] . '</h1>',
      '<p class="page-info-text">' . sprintf($this->settings['pageInfoText'], LBWP_HOST) . '</p>',
      ''
    );

    if(strlen($loginHtml) > 0){
//      array_splice($replaceStrings, 1, 0, '<div class="login-form"><p>' . $this->settings['pageLoginText'] . '</p>{LOGIN_HTML}</div>');
      $replaceStrings[2] = '<div class="maintenance-login"><div class="login-form"><p>' . $this->settings['pageLoginText'] . '</p>{LOGIN_HTML}</div></div>';
    }

    $mtnceHtml = str_replace(array('{CONTENT_BUBBLE_1}', '{CONTENT_BUBBLE_2}', '{CONTENT_BUBBLE_3}'), $replaceStrings, $mtnceHtml);

    $mtnceHtml = apply_filters('lbwp_maintenance_customize_html', $mtnceHtml, self::$header, $this->settings);
    $mtnceHtml = str_replace('{LOGIN_HTML}', $loginHtml, $mtnceHtml);

    echo $mtnceHtml;
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
          <div class="maintenance-input-btn-group">
            <input type="password" name="maintenancePassword" class="maintenance-password" placeholder="Password" />
            <button type="submit" class="maintenance-submit" value="Anmelden">Anmelden</button>
          </div>
          ' . ($this->failedLogin ? '<p class="failed-login-message">Das Password stimmt nicht.</p>' : '') . '
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

    // Omit maintenance mode with sha1'd url parameter matching
    if (isset($_GET['mm_login']) && $_GET['mm_login'] == sha1($this->config['Various:MaintenancePassword'])) {
      return false;
    }

    return (
      $isActive == 1 &&
      !is_user_logged_in() &&
      !$this->isOnIpWhitelist() &&
      !$this->isNeededPublicFile($_SERVER['SCRIPT_FILENAME']) &&
      !$this->hasPasswordLogin &&
      !$this->omitByUriHash()
    );
  }

  /**
   * @return bool
   */
  protected function isOnIpWhitelist()
  {
    if (strlen($this->config['Various:MaintenanceIPWhiltelist']) > 0) {
      $ipList = array_map('trim', explode(',', $this->config['Various:MaintenanceIPWhiltelist']));
      return in_array($_SERVER['REMOTE_ADDR'], $ipList);
    }

    return false;
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

  /**
   * @param $header
   */
  public static function setHeader($header)
  {
    self::$header = $header;
  }
}