<?php
require_once '../../../../../../wp-load.php';

use LBWP\Util\Strings;
use LBWP\Util\ArrayManipulation;

$newsletterId = intval($_GET['nl']);
$emailId = Strings::forceSlugString($_GET['m']);
$targetUrl = $_GET['url'];

// Unset tho main parameters, as we want to include everything else in $_GET to our url
unset($_GET['nl'],$_GET['m'],$_GET['url']);
foreach ($_GET as $key => $value) {
  $targetUrl = Strings::attachParam($key, $value, $targetUrl);
}

// Get click stats and add the record of the click
$clickStats = ArrayManipulation::forceArray(get_post_meta($newsletterId, 'clickStats', true));
$clickStats[] = array(
  'email' => $emailId,
  'url' => $targetUrl
);
update_post_meta($newsletterId, 'clickStats', $clickStats);

// Redirect user to actual target
header('Location: ' . $targetUrl, null, 308);