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
  const PRINTBUTTON = 'printButton';
  const EMAIL = 'email';
  const WHATSAPP = 'whatsapp';
  const PINTEREST = 'pinterest';
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
                js.src = "//connect.facebook.net/' . self::$apiLocale[$key] . '/sdk.js#xfbml=1&version=v2.7&appId=' . self::FB_APP_ID . '";
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

          // Include the Pinterest API
          case self::PINTEREST:
            echo '<script async defer src="//assets.pinterest.com/js/pinit.js"></script>';
            break;

        }
      }
    }
  }

  /**
   * @param $post
   * @param $selector
   * @param string $fallbackSelector
   * @param string $urlParams
   * @return string
   */
  public static function getNativeSharing($post, $selector, $fallbackSelector = '', $urlParams = '')
  {
    $else = '';
    if (strlen($fallbackSelector)>0) {
      $else = 'jQuery("' . $fallbackSelector . '").toggleClass("show");';
    }
    return '
      <script type="text/javascript">
        var socialapi_hasRegisteredEvent;
        jQuery(function() {
          // Make sure to only register the event once if called multiple times
          if (socialapi_hasRegisteredEvent === true) {
            return;
          }
          socialapi_hasRegisteredEvent = true;
          
          jQuery(document).on("click", "' . $selector . '", function() {

            if (navigator.share) {
              jQuery(window).trigger("lbwp:nativesharing:click");
              navigator.share({
                  title: "' . esc_js($post->post_title) . '",
                  text: "' . esc_js(str_replace(PHP_EOL, ' ', $post->post_excerpt)) . '",
                  url: "' . get_permalink($post->ID) . $urlParams . '"
              });
            } else {
              '. $else . '
            }
          });
        });
      </script>
    ';
  }
}