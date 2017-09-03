<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;

/**
 * Implements the email html button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Email extends BaseButton
{
  /**
   * @param array $config the config for the button
   * @param string $link the link to share
   * @param \WP_Post $post the current post
   * @return string html code for the button
   */
  public function getHtml($config, $link, $post)
  {
    $subject = urlencode(__('Artikel', 'lbwp') . ' ' . $post->post_title);
    $body = urlencode(__('Link', 'lbwp') . ': ' . get_permalink($post->ID));
    // Return the html
    return '
      <a class="button" href="mailto:?subject=' . $subject . '&body=' . $body . '">' . __('Per E-Mail versenden', 'lbwp') . '</a>
    ';
  }
} 