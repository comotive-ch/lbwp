<?php
require_once '../../../../../wp-load.php';

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

// Don't cache this site
if (class_exists('\LBWP\Module\Frontend\HTMLCache')) {
  HTMLCache::avoidCache();
}

// If DB_MASTER is not set, nothing can be done. This method
// is only called by the API job-register.php and hence executed on master
if (defined('EXTERNAL_LBWP')) {
  return false;
}

// Connect to master using native mysqli
$conn = mysqli_connect(DB_MASTER_HOST, DB_MASTER_USR, DB_MASTER_PWD, DB_MASTER);
// Validate the host (a-z und . allowed)
$host = $_REQUEST['host'];
Strings::alphaNumLowFiles($host);

// Get all jobs for the domain
$data = array('list' => array(), 'count' => 0);
$res = mysqli_query($conn, '
  SELECT job_id, job_time, job_identifier, job_data
  FROM jobs WHERE job_site = "' . $host . '"
  ORDER BY job_time ASC
');

while ($row = mysqli_fetch_assoc($res)) {
  $data['list'][] = $row;
  $data['count']++;
}

// At last, close the connection and send data
mysqli_close($conn);
WordPress::sendJsonResponse($data);
