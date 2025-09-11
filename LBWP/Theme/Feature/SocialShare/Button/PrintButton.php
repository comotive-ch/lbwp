<?php

namespace LBWP\Theme\Feature\SocialShare\Button;

use LBWP\Theme\Feature\SocialShare\BaseButton;
use LBWP\Util\Templating;

/**
 * Implements the print html button
 * @package LBWP\Theme\Feature\SocialShare\Button
 * @author Michael Sebel <michael@comotive.ch>
 */
class PrintButton extends BaseButton
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
    // Return the html
    return Templating::getBlock($this->template, array(
      '{shareLink}' => 'javascript:window.print()',
      '{buttonTitle}' => __('Drucken', 'lbwp'),
      '{postTitle}' => $post->post_title,
      '{postLink}' => $link
    ));
  }
} 