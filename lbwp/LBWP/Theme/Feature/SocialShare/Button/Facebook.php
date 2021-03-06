<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Theme\Feature\SocialShare\SocialApis;

/**
 * Implements the facebook button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Facebook extends BaseButton
{
  /**
   * @param array $config the config for the button
   * @param string $link the link to share
   * @param \WP_Post $post the current post
   * @return string html code for the button
   */
  public function getHtml($config, $link, $post)
  {
    if ($this->needsPrivacyCompliance()) {
      $locale = $this->getLocaleString('_', 5);
      return '
        <a href="//www.facebook.com/share.php?u=' .  urlencode($link) .'&amp;t=' .  urlencode($post->post_title) . '&amp;locale=' . $locale . '" target="_blank">
        <img src="/wp-content/plugins/lbwp/resources/images/social/share-' . substr($locale,0,2) . '.png" border="0"></a>
      ';
    } else {
      // Register the api
      SocialApis::add(SocialApis::FACEBOOK, $this->getLocaleString('_', 5));
      // The type is normally like, but can be changed to share
      $type = (isset($config['share_only'])) ? 'fb-share-button' : 'fb-like';
      // Return the html
      return '
        <div class="' . $type . '"
          data-href="' . esc_attr($link) . '"
          data-layout="' . $config['layout'] . '"
          data-action="' . $config['action'] . '"
          data-show-faces="false"
          data-share="' . ($config['share'] ? 'true' : 'false') . '"></div>
      ';
    }
  }
} 