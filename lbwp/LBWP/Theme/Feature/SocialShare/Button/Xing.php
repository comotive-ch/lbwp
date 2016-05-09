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