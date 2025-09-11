<?php
require_once '../../../../../../wp-load.php';

use LBWP\Util\LbwpData;
use LBWP\Util\Strings;

// Then, follow with the tracking
$stats = new LbwpData('localmail_stats_' . intval($_GET['nl']));
$rowId = Strings::forceSlugString($_GET['m']);
$row = $stats->getRow($rowId);
// Up the opens and save back to db
$row['data']['opens']++;
$stats->updateRow($rowId, $row['data']);

// Print and send the gif first
header('Cache-Control: private, no-cache, no-cache=Set-Cookie, proxy-revalidate');
header('Expires: Wed, 11 Jan 2000 12:59:00 GMT');
header('Last-Modified: Wed, 11 Jan 2006 12:59:00 GMT');
header('Pragma: no-cache');
header('Content-Type: image/gif');
die(hex2bin('47494638396101000100900000ff000000000021f90405100000002c00000000010001000002020401003b'));