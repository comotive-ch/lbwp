<?php
require_once '../../../../../wp-config/core/lbwp-main.config.php';

if (!isset($_GET[MASTER_CRON_API_SECRET])) {
  echo 'no access.';
  exit;
}

exec('sh /var/www/util/update', $output);

echo 'Releasing files on ' . gethostname() . PHP_EOL;
foreach ($output as $key => $line) {
  echo ($key + 1) .': ' . $line . PHP_EOL;
}
