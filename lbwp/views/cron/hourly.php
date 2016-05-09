<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

// Allow devs do hook in here
do_action('cron_hourly');

// Run an hourly hookable daily cron
$hour = intval(date('G', current_time('timestamp')));
do_action('cron_daily_' . $hour);

// Run cron at day time
if (in_array($hour, array(7,9,11,13,15,17))) {
  do_action('cron_hourly_daytime');
}