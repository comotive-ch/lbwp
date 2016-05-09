<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

// Allow devs do hook in here
do_action('cron_daily');

// Allow to call a daily cron on a specific weekday
$weekday = intval(date('N', current_time('timestamp')));
do_action('cron_weekday_' . $weekday);

// Allow to call a daily cron once per month
$dayOfMonth = intval(date('j', current_time('timestamp')));
do_action('cron_monthly_' . $dayOfMonth);

// Call specific crons in dev mode for testing
if (isset($_GET ['specific'])) {
  do_action('cron_daily_' . intval($_GET ['specific']));
}
if (isset($_GET ['weekday'])) {
  do_action('cron_weekday_' . intval($_GET ['weekday']));
}
if (isset($_GET ['day'])) {
  do_action('cron_monthly_' . intval($_GET ['day']));
}