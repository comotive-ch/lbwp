<?php
define('PREPEND_PATH', '/var/www/lbwp-prod/wp-content/');

// Include the file list
require_once '/var/www/lbwp-prod/wp-content/plugins/lbwp/views/includes/Minification_JsFiles.php';
echo 'Starting compile processes for ' . count($fileList) . ' JS files...' . PHP_EOL;
$time = microtime(true);

// Minify the files in the list
foreach ($fileList as $file) {
  $file = PREPEND_PATH . $file;
  // TODO Actual minification cli
  usleep(50000);
}

echo '...started processes in ' . number_format(microtime(true) - $time, 2) . 's!' . PHP_EOL;