<?php

namespace LBWP\Theme\Feature\SocialShare;

/**
 * Wrapper to make sure, that certain social apis are loaded only once
 * @package LBWP\Theme\Feature\SocialShare
 * @author Michael Sebel <michael@comotive.ch>
 */
class SocialApis
{
  /**
   * @var bool tell if the action is already registered
   */
  protected static $registeredAction = false;
  /**
   * @var array info if certain apis have loaded
   */
  protected static $loadCommand = array();
  /**
   * @var array list of locales per api
   */
  protected static $apiLocale = array();
  /**
   * @var string constants
   */
  const FACEBOOK = 'facebook';
  const TWITTER = 'twitter';
  const GOOGLE_PLUS = 'googleplus';
  const LINKED_IN = 'linkedin';
  const XING = 'xing';
  /**
   * @var string additional constants
   */
  const FB_APP_ID = '1445372189033706';

  /**
   * @param string $api one of the api constants
   * @param string $locale the api locale
   */
  public static function add($api, $locale = '')
  {
    // Make sure the action will be called when apis should be loaded
    if (!self::$registeredAction) {
      add_action('wp_footer', array('\LBWP\Theme\Feature\SocialShare\SocialApis', 'includeApis'));
      self::$registeredAction = true;
    }

    // Mark API as loaded and assign locale
    self::$loadCommand[$api] = true;
    self::$apiLocale[$api] = $locale;
  }

  /**
   * Include the registered social apis in footer area
   */
  public static function includeApis()
  {
    foreach (self::$loadCommand as $key => $active) {
      if ($active) {
        switch ($key) {
          // Include the Facebook API
          case self::FACEBOOK:
            echo '
              <div id="fb-root"></div>
              <script>(function(d, s, id) {
                var js, fjs = d.getElementsByTagName(s)[0];
                if (d.getElementById(id)) return;
                js = d.createElement(s); js.id = id;
                js.src = "//connect.facebook.net/' . self::$apiLocale[$key] . '/sdk.js#xfbml=1&version=v2.4&appId=' . self::FB_APP_ID . '";
                fjs.parentNode.insertBefore(js, fjs);
              }(document, "script", "facebook-jssdk"));
              </script>
            ';
            break;

          // Include the Twitter API
          case self::TWITTER:
            echo '
              <script>
                !function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?"http":"https";
                if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+"://platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}
                (document, "script", "twitter-wjs");
              </script>
            ';
            break;

          // Include the Google Plus API
          case self::GOOGLE_PLUS:
            echo '
              <script type="text/javascript">
                window.___gcfg = {lang: "' . self::$apiLocale[$key] . '"};
                (function() {
                  var po = document.createElement("script"); po.type = "text/javascript"; po.async = true;
                  po.src = "https://apis.google.com/js/platform.js";
                  var s = document.getElementsByTagName("script")[0]; s.parentNode.insertBefore(po, s);
                })();
              </script>
            ';
            break;

          // Include the LinkedIn API
          case self::LINKED_IN:
            echo '
              <script src="//platform.linkedin.com/in.js" type="text/javascript"> lang: ' . self::$apiLocale[$key] . '</script>
            ';
            break;

          // Include the Xing API
          case self::XING:
            echo '
              <script>
                ;(function (d, s) {
                  var x = d.createElement(s),
                    s = d.getElementsByTagName(s)[0];
                    x.src = "https://www.xing-share.com/plugins/share.js";
                    s.parentNode.insertBefore(x, s);
                })(document, "script");
              </script>
            ';
            break;

        }
      }
    }
  }
}