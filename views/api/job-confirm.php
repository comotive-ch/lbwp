<?php
require_once '../../../../../wp-load.php';

use LBWP\Helper\Cronjob;
use LBWP\Module\Frontend\HTMLCache;

// Don't cache this site
if (class_exists('\LBWP\Module\Frontend\HTMLCache')) {
  HTMLCache::avoidCache();
}

// Do the master callback to confirm the job
Cronjob::confirmJobOnMaster(
  $_REQUEST['jobId'],
  $_REQUEST['hash']
);