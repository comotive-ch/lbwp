<?php
use LBWP\Theme\Feature\FocusPoint;

/**
 * @param array $block the slider block config
 * @return string hmtl to represent the slider gallery
 */
$config['render_callback'] = function($block) {
  if (isset($block['imageIds']) && is_array($block['imageIds'])) {
    return '
      <div class="wp-block-lbwp-slider ' . $block['className'] . '">
        ' . FocusPoint::getFocusPointGalleryByIds($block['imageIds']) . '
      </div>
    ';
  }
};