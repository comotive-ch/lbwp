<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;

/**
 * Implements the google plus button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class GooglePlus extends BaseButton
{
  /**
   * @param array $config the config for the button
   * @param string $link the link to share
   * @param \WP_Post $post the current post
   * @return string html code for the button
   */
  public function getHtml($config, $link, $post)
  {
    if ($this->needsPrivacyCompliance()) {
      return '
        <a href="//plus.google.com/share?app=110&url=' .  urlencode($link) .'" target="_blank">
        <img src="/wp-content/plugins/lbwp/resources/images/social/googleplus.png" border="0"></a>
      ';
    } else {
      // Register the API
      SocialApis::add(SocialApis::GOOGLE_PLUS, $this->getLocaleString('', 2));

      // Return the html
      return '
        <div class="g-plusone"
          data-size="medium"
          data-href="' . esc_attr($link) . '">
        </div>
      ';
    }
  }
} 