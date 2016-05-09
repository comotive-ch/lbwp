<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;

/**
 * Implements the twitter button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Twitter extends BaseButton
{
  /**
   * @param array $config the config for the button
   * @param string $link the link to share
   * @param \WP_Post $post the current post
   * @return string html code for the button
   */
  public function getHtml($config, $link, $post)
  {
    // Register the api
    SocialApis::add(SocialApis::TWITTER, '');

    // Return the html
    return '
      <a href="https://twitter.com/share"
        class="twitter-share-button"
        data-url="' . esc_attr($link) . '"
        data-text="'  . esc_attr(strip_tags($post->post_title)) . '"
        data-lang="' . $this->getLocaleString('', 2) . '"
      >' . $config['fallback'] . '</a>
    ';
  }
} 