<?php
require_once '../../../../../wp-load.php';

use LBWP\Helper\Cronjob;
use LBWP\Module\Frontend\HTMLCache;

// Only allow calls that present the shared master cron API secret
if (!defined('MASTER_CRON_API_SECRET') || !hash_equals(MASTER_CRON_API_SECRET, (string) ($_REQUEST['secret'] ?? ''))) {
  http_response_code(403);
  exit;
}

// Don't cache this site
if (class_exists('\LBWP\Module\Frontend\HTMLCache')) {
  HTMLCache::avoidCache();
}

// Do the master callback
Cronjob::registerJobsOnMaster(
  $_REQUEST['jobs'],
  $_REQUEST['host']
);
