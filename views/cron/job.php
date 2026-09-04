<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Helper\Cronjob;

// Check for the key to be valid
if (!isset($_GET['key']) || $_GET['key'] !== MASTER_CRON_API_SECRET) {
  header('HTTP/1.0 403 Forbidden');
  return;
}

// Run for a maximum of 55 seconds (because in 60 seconds the next cron might be coming and do the same
set_time_limit(55);

// Don't cache this site
if (class_exists('\LBWP\Module\Frontend\HTMLCache')) {
  HTMLCache::avoidCache();
}

// Allow devs do hook in here to one time jobs those jobs need to be added with the job framework
do_action('cron_job');
do_action('cron_job_' . $_REQUEST['identifier']);

Cronjob::confirm($_GET['jobId']);