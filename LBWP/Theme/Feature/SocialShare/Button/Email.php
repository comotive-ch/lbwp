<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Util\Templating;

/**
 * Implements the email html button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class Email extends BaseButton
{
  /**
   * @var string the html template to use
   */
  protected $template = '<a class="button" href="{shareLink}">{buttonTitle}</a>';

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
    // Prepare some variables
    $subject = rawurlencode(apply_filters('lbwpSocialShareEmailPrefix', __('Artikel', 'lbwp')) . ' ' . $post->post_title);
    $meta = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
    $body = rawurlencode(__('Ich empfehle dir', 'lbwp') . ': ' . $link . PHP_EOL . PHP_EOL . $meta);
    // Return the html
    return Templating::getBlock($this->template, array(
      '{shareLink}' => 'mailto:?subject=' . $subject . '&body=' . $body,
      '{buttonTitle}' => __('Per E-Mail versenden', 'lbwp'),
      '{postTitle}' => $post->post_title,
      '{postLink}' => $link
    ));
  }
} 