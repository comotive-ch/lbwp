<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;
use LBWP\Util\Templating;

/**
 * Implements the pinterest button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Pinterest extends BaseButton
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
        '{shareLink}' => 'https://pinterest.com/pin/create/bookmarklet/?url=' . urlencode($link) . '&description=' . urlencode($post->post_title),
        '{buttonTitle}' => 'Pinterest',
        '{postTitle}' => $post->post_title,
        '{postLink}' => $link,
        '{staticImageUrl}' => '/wp-content/plugins/lbwp/resources/images/social/pinterest.png'
      ));
    } else {
      // Register the api
      SocialApis::add(SocialApis::PINTEREST, '');

      // Return the html
      return '
        <a 
          data-pin-do="buttonBookmark"
          data-pin-lang="' . $this->getLocaleString('', 2) . '"
          href="https://de.pinterest.com/pin/create/button/"></a>
        ';
    }
  }
} 