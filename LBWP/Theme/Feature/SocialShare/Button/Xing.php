<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;
use LBWP\Util\Templating;

/**
 * Implements the xing button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Xing extends BaseButton
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
        '{shareLink}' => '//www.xing.com/spi/shares/new?cb=0&amp;url=' . urlencode($link),
        '{buttonTitle}' => 'Xing',
        '{postTitle}' => $post->post_title,
        '{postLink}' => $link,
        '{staticImageUrl}' => '/wp-content/plugins/lbwp/resources/images/social/xing-' . substr($locale,0,2) . '.png'
      ));
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