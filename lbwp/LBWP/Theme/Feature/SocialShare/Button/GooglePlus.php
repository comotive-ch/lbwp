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