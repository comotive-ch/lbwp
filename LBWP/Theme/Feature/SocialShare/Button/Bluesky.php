<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Util\Templating;

/**
 * Implements the Bluesky share button
 * @package LBWP\Theme\Feature\SocialShare\Button
 */
class Bluesky extends BaseButton
{
  /**
   * @var string the html template to use
   */
  protected $template = '
    <a href="{shareLink}" target="_blank">
    <img src="{staticImageUrl}" border="0"></a>
  ';

  /**
   * @param array $config the config for the button
   * @param string $link the link to share
   * @param \WP_Post $post the current post
   * @return string html code for the button
   */
  public function getHtml($config, $link, $post)
  {
    // Maybe use custom template
    $this->template = isset($config['template']) ? $config['template'] : $this->template;
    return Templating::getBlock($this->template, array(
      '{shareLink}' => 'https://bsky.app/intent/compose?text=' . urlencode($post->post_title . ' ' . $link),
      '{buttonTitle}' => 'Bluesky',
      '{postTitle}' => $post->post_title,
      '{postLink}' => $link,
      '{staticImageUrl}' => '/wp-content/plugins/lbwp/resources/images/social/bluesky.png'
    ));
  }
}
