<?php

namespace LBWP\Theme\Feature;

/**
 * Adds the user tracking script from Jaco
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class JacoUserSession
{
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'user' => array(
      'track_developer' => true,
      'track_backend' => false,
      'track_admin_in_frontend' => false
    ),
    'blacklist' => array(),
    'whitelist' => array(),
    // Not implemented yet, as it might be an overkill
    'timeframes' => array()
  );
  /**
   * @var JacoUserSession the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
    // Register the main filter to make the layouting (very late)
    if ($this->isTrackable()) {
      add_action('wp_head', array($this, 'addTrackingScript'));
      // Also include in backend, if tracking is allowed
      if ($this->config['user']['track_backend']) {
        add_action('admin_head', array($this, 'addTrackingScript'));
      }
    }
  }

  /**
   * Determines if the current user is trackable
   */
  protected function isTrackable()
  {
    $trackable = true;

    // Disable, if developer and developers are not allowed
    if (defined('LOCAL_DEVELOPMENT') && !$this->config['user']['track_developer']) {
      $trackable = false;
    }

    // If we are in admin, and it is disabled, disable tracking
    if (is_admin() && !$this->config['user']['track_backend']) {
      $trackable = false;
    }

    // If we are not in admin, but user is logged in, disable as well if not wandet
    if (is_user_logged_in() && !is_admin() && !$this->config['user']['track_admin_in_frontend']) {
      $trackable = false;
    }

    // If there is a whitelist, the request_uri must be in the whitelist
    if ($trackable && count($this->config['whitelist'])) {
      $trackable = false;
      foreach ($this->config['whitelist'] as $entry) {
        if (fnmatch($entry, $_SERVER['REQUEST_URI'])) {
          $trackable = true;
          break;
        }
      }
    }

    // If there is a blacklist, the request_uri must not be on the list
    if ($trackable && count($this->config['blacklist'])) {
      foreach ($this->config['blacklist'] as $entry) {
        if (fnmatch($entry, $_SERVER['REQUEST_URI'])) {
          $trackable = false;
          break;
        }
      }
    }

    return $trackable;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new JacoUserSession($options);
  }

  /**
   * Actual tracking script
   */
  public function addTrackingScript()
  {
    ?>
    <script type="text/javascript">
      (function (e, t) {
        function i(e, t) {
          e[t] = function () {
            e.push([t].concat(Array.prototype.slice.call(arguments, 0)));
          };
        }

        function s() {
          var e = t.location.hostname.match(/[a-z0-9][a-z0-9\-]+\.[a-z\.]{2,6}$/i), n = e ? e[0] : null, i = "; domain=." + n + "; path=/";
          t.referrer && t.referrer.indexOf(n) === -1 ? t.cookie = "jaco_referer=" + t.referrer + i : t.cookie = "jaco_referer=" + r + i;
        }

        var n = "JacoRecorder", r = "none";
        (function (e, t, r, o) {
          if (!r.__VERSION) {
            e[n] = r;
            var u = ["init", "identify", "startRecording", "stopRecording", "removeUserTracking", "setUserInfo"];
            for (var a = 0; a < u.length; a++) i(r, u[a]);
            s(), r.__VERSION = 2.1, r.__INIT_TIME = 1 * new Date;
            var f = t.createElement("script");
            f.async = !0, f.setAttribute("crossorigin", "anonymous"), f.src = o;
            var l = t.getElementsByTagName("head")[0];
            l.appendChild(f);
          }
        })(e, t, e[n] || [], "https://recorder-assets.getjaco.com/recorder_v2.js");
      }).call(window, window, document), window.JacoRecorder.push(["init", "33d25af9-a8dd-4c7d-b1b6-71bf40f1944a", {}]);
    </script>
    <?php
  }
}



