<?php
require_once '../../../../../wp-load.php';

use LBWP\Helper\Cronjob;
use LBWP\Module\Frontend\HTMLCache;

// Don't cache this site
if (class_exists('\LBWP\Module\Frontend\HTMLCache')) {
  HTMLCache::avoidCache();
}

// Do the master callback
Cronjob::registerJobsOnMaster(
  $_REQUEST['jobs'],
  $_REQUEST['host']
);
