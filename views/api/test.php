<?php
require_once '../../../../../wp-load.php';

var_dump(microtime(true));
$reader = new MaxMind\Db\Reader('/usr/share/GeoIP/GeoLite2-Country.mmdb');
$record = $reader->get($_SERVER['REMOTE_ADDR']);
var_dump(microtime(true));
$country = $record['country']['iso_code'] ?? 'XX';

var_dump($country, $_SERVER, $record);