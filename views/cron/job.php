<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Helper\Cronjob;

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