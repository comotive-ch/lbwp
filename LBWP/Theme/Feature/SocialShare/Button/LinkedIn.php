<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;
use LBWP\Util\Templating;

/**
 * Implements the linkedin button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class LinkedIn extends BaseButton
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
      $locale = $this->getLocaleString('_', 5);
      return Templating::getBlock($this->template, array(
        '{shareLink}' => '//www.linkedin.com/cws/share?url=' .  urlencode($link) .'&isFramed=false&lang=' . $locale,
        '{buttonTitle}' => 'LinkedIn',
        '{postTitle}' => $post->post_title,
        '{postLink}' => $link,
        '{staticImageUrl}' => '/wp-content/plugins/lbwp/resources/images/social/linkedin-' . substr($locale,0,2) . '.png'
      ));
    } else {
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
} 