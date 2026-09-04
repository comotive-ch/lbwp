<?php
require_once '../../../../../wp-config/core/lbwp-main.config.php';

if (!isset($_GET[MASTER_CRON_API_SECRET])) {
  echo 'no access.';
  exit;
}

exec('sudo /var/www/util/nginx-update-config.sh', $output);

echo 'Updating nginx configs on ' . gethostname() . PHP_EOL;
foreach ($output as $key => $line) {
  echo ($key + 1) .': ' . $line . PHP_EOL;
}
