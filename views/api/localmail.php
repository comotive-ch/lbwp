<?php
require_once '../../../../../wp-load.php';

$key = 'LocalMail_Mailing_' . $_GET['key'];
$data = get_option($key);

$max = ($_GET['length']) > 0 ? intval($_GET['length']) : 10;

echo '<pre>';
for ($i = 0; $i < $max; ++$i) {
  echo $data[$i]['html'] . PHP_EOL;
}
echo '</pre>';