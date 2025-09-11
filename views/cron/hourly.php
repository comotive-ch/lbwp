<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

// Allow devs do hook in here
do_action('cron_hourly');

// Run an hourly hookable daily cron
$hour = intval(date('G', current_time('timestamp')));
$dayOfMonth = intval(date('j', current_time('timestamp')));

// Allow testing of the cron
if (isset($_GET['hour']) && $_GET['hour'] > 0 && $_GET['hour'] <= 23) {
  $hour = intval($_GET['hour']);
}
// Run the hourly cron (executed every hour) or specific our on day of month
do_action('cron_daily_' . $hour);
do_action('cron_monthly_' . $dayOfMonth . '_' . $hour);

// Run cron at day time
if (in_array($hour, array(7,9,11,13,15,17))) {
  do_action('cron_hourly_daytime');
}
if ($hour >= 7 && $hour <= 18) {
  do_action('cron_hourly_daytime_all');
}
if (in_array($hour, array(2,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,23))) {
  do_action('cron_hourly_lessatnight');
}
// Run the cron seldom but during day
if (in_array($hour, array(10, 14, 18))) {
  do_action('cron_hourly_daytime_less');
}