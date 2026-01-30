<?php
if (!defined('LOCAL_DEVELOPMENT')) {
  return;
}

use LBWP\Util\Strings;
use LBWP\Util\File;

require_once '../../../../../../wp-load.php';

set_time_limit(600);

$base = 'https://spitex-report.ch/wp-content/uploads/';
$api = 'https://spitex-report.ch/wp-json/wp/v2/media/';
$outputPath = '/var/www/lbwp/wp-content/themes/spitex-report/assets/import/files.json';
$config = array(
  'per_page' => 100,
  'page' => 2
);

foreach ($config as $key => $value) {
  $api = Strings::attachParam($key, $value, $api);
}

$path = '/tmp/mediaoutput/';
if (!file_exists($path)) {
  mkdir($path);
}

$output = json_decode(file_get_contents($outputPath), true);

// Get the file data
$data = json_decode(Strings::genericRequest($api, array(), 'GET'), true);

foreach ($data as $image) {
  $files = array();
  // Get the main file and the sizes
  foreach ($image['media_details']['sizes'] as $size) {
    $filepath = str_replace($base, '', $size['source_url']);
    $filename = File::getFileOnly($filepath);
    $renamed = $filename;
    Strings::alphaNumFiles($renamed);
    $files[] = array(
      'original' => $filepath,
      'renamed' => str_replace($filename, $renamed, $filepath),
      'url' => $size['source_url']
    );
  }

  // Download the files and rename them locally
  foreach ($files as $file) {
    $binary = file_get_contents($file['url']);
    $fullpath = $path . $file['renamed'];
    $filepath = File::getFileFolder($fullpath);
    if (!file_exists($filepath)) {
      mkdir($filepath, 0777, true);
    }
    file_put_contents($path . $file['renamed'], $binary);
    $output[] = array(
      'before' => $file['url'],
      'after' => 'https://assets01.sdd1.ch/assets/lbwp-cdn/spitex-report/files/' . $file['renamed']
    );
  }
}

file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT));