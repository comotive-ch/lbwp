<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;

/**
 * Implements the xing button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Xing extends BaseButton
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
      $locale = $this->getLocaleString('_', 5);
      return '
        <a href="//www.xing.com/spi/shares/new?cb=0&amp;url=' . urlencode($link) . '" target="_blank">
        <img src="/wp-content/plugins/lbwp/resources/images/social/xing-' . substr($locale,0,2) . '.png" border="0"></a>
      ';
    } else {
      // Register the api
      SocialApis::add(SocialApis::XING);

      // Return the html
      return '
        <div
          data-type="xing/share"
          data-counter="right"
          data-lang="' . $this->getLocaleString('', 2) . '">
        </div>
      ';
    }
  }
} 