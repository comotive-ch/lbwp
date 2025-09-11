<?php

// Load basic config of the page
require_once '../../../../../wp-config.php';

$file = apply_filters('lbwp_app_assetlinks_json_path', '');

if (strlen($file) > 0) {
  $content = file_get_contents($file);
} else {
  $content = '[]';
}

header('Content-Type: application/json');
echo $content;
