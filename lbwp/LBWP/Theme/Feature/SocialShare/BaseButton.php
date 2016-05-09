<?php

namespace LBWP\Theme\Feature\SocialShare;
use LBWP\Util\Multilang;

/**
 * Base class for a share button
 * @package LBWP\Theme\Feature\SocialShare
 */
abstract class BaseButton
{
  /**
   * @param string $separator a separator between de-DE
   * @param int $cut cut the locale at so many chars
   * @return string the locale
   */
  protected function getLocaleString($separator = '-', $cut = 5)
  {
    // Get wordpress locale as default
    $locale = get_option('WPLANG');

    // Get current language locale
    if (Multilang::isActive()) {
      $locale = Multilang::getCurrentLang('locale');
    }

    // Set separator and cut string
    return substr(str_replace('_', $separator, $locale), 0, $cut);
  }

  /**
   * @param array $config the config for the button
   * @param string $link the link to share
   * @param \WP_Post $post the current post
   * @return string html code for the button
   */
  abstract public function getHtml($config, $link, $post);
} 