<?php

// Load basic config of the page
require_once '../../../../../wp-config.php';

if (defined('LBWP_WELL_KNOWN_APPLE_APPSITEASSOC_JSON_PATH')) {
  header('Content-Type: application/json');
  echo file_get_contents(ABSPATH . LBWP_WELL_KNOWN_APPLE_APPSITEASSOC_JSON_PATH);
} else {
  header('HTTP/1.1 404 Not Found');
  exit;
}
