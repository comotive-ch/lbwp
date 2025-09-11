<?php
$file = '/var/www/lbwp/wp-content/plugins/lbwp/resources/libraries/ch-latlng/ch-latlng.txt';
$json = array();

$csvData = array();
if (($handle = fopen($file, 'r')) !== false) {
  while (($data = fgetcsv($handle, 0, "\t", '"')) !== false) {
    if (!isset($json[$data[1]])) {
      $json[$data[1]] = array(
        'city' => $data[2],
        'lat' => $data[9],
        'lng' => $data[10]
      );
    } else {
      $json[$data[1]]['city'] .= ', ' . $data[2];
    }
  }
  fclose($handle);
}

file_put_contents('ch-latlng.json', json_encode($json));