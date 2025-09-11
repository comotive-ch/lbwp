<?php

use LBWP\Theme\Feature\ServiceWorker;

// Load basic config of the page
require_once '../../../../../wp-config.php';

$sw = ServiceWorker::getInstance();

if ($sw instanceof ServiceWorker) {
  header('Content-Type: text/javascript');
  $sw->template();
  // TODO: only activate if service worker is active in theme
}
