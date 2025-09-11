<?php
define('DOING_LBWP_CRON',true);
require '../../../../../wp-load.php';

// Allow devs do hook in here
do_action('cron_daily');

$time = current_time('timestamp');
// Allow to call a daily cron on a specific weekday
$weekday = intval(date('N', $time));
do_action('cron_weekday_' . $weekday);

// Allow to call a daily cron once per month
$dayOfMonth = intval(date('j', $time));
do_action('cron_monthly_' . $dayOfMonth);
$currentMonth = intval(date('n', $time));
do_action('cron_monthly_m' . $currentMonth . '_d' . $dayOfMonth);

// Call specific crons in dev mode for testing
if (isset($_GET['specific'])) {
  do_action('cron_daily_' . intval($_GET['specific']));
}
if (isset($_GET['weekday'])) {
  do_action('cron_weekday_' . intval($_GET['weekday']));
}
if (isset($_GET['day'])) {
  do_action('cron_monthly_' . intval($_GET['day']));
}
if (isset($_GET['day']) && isset($_GET['hour'])) {
  do_action('cron_monthly_' . intval($_GET['day']) . '_' . intval($_GET['hour']));
}
if (isset($_GET['month']) && isset($_GET['day'])) {
  do_action('cron_monthly_m' . intval($_GET['month']) . '_d' . intval($_GET['day']));
}