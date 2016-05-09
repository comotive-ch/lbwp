<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

// Allow devs do hook in here to one time jobs those jobs need to be added with the job framework
do_action('cron_job');
do_action('cron_job_' . $_REQUEST['identifier']);