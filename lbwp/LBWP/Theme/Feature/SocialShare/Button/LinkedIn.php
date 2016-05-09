<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;

/**
 * Implements the linkedin button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class LinkedIn extends BaseButton
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
    SocialApis::add(SocialApis::LINKED_IN, $this->getLocaleString('_'));

    // Return the html
    return '
      <script type="IN/Share"
        data-url="' . esc_attr($link) . '"
        data-counter="right">
      </script>
    ';
  }
} 