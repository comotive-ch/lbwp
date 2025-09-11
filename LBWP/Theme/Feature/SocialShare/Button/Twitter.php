<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;
use LBWP\Util\Templating;

/**
 * Implements the twitter button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Twitter extends BaseButton
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
    if ($this->needsPrivacyCompliance()) {
      // Maybe use custom template
      $this->template = isset($config['template']) ? $config['template'] : $this->template;
      return Templating::getBlock($this->template, array(
        '{shareLink}' => '//x.com/intent/tweet?url=' . urlencode($link) . '&text=' . urlencode($post->post_title),
        '{buttonTitle}' => 'Twitter',
        '{postTitle}' => $post->post_title,
        '{postLink}' => $link,
        '{staticImageUrl}' => '/wp-content/plugins/lbwp/resources/images/social/twitter.png'
      ));
    } else {
      // Register the api
      SocialApis::add(SocialApis::TWITTER, '');

      // Return the html
      return '
        <a href="https://x.com/share"
          class="twitter-share-button"
          data-url="' . esc_attr($link) . '"
          data-text="'  . esc_attr(strip_tags($post->post_title)) . '"
          data-lang="' . $this->getLocaleString('', 2) . '"
        >' . $config['fallback'] . '</a>
      ';
    }
  }
} 