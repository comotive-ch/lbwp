<?php
require_once '../../../../../wp-load.php';

use LBWP\Helper\Cronjob;
use LBWP\Module\Frontend\HTMLCache;

// Do the master callback
Cronjob::masterCallback($_POST['jobs'], $_POST['host']);

// Don't cache this site
if (class_exists('\LBWP\Module\Frontend\HTMLCache')) {
  HTMLCache::avoidCache();
}
