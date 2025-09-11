<?php
use LBWP\Theme\Feature\FocusPoint;

/**
 * @param array $block the block config which is empty here
 * @return string post thumbnail with focuspoint if given
 */
$config['render_callback'] = function($block) {
  if (has_post_thumbnail()) {
    return '
      <div class="wp-block-lbwp-featured-image">
        ' . FocusPoint::getFeaturedImage(get_the_ID()) . '
      </div>
    ';
  }
};