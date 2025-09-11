<?php
/**
 * @param array $block the form block config
 * @return string hmtl to represent the formular
 */
$config['render_callback'] = function($block) {
  //return json_encode($block);

  if(!empty($block)) {
    return do_shortcode('[lbwp:formular id="' . $block['formId'] . '"]');
  }
};