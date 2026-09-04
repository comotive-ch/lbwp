<?php
require_once '../../../../../wp-config/core/lbwp-main.config.php';

if (!isset($_GET[MASTER_CRON_API_SECRET])) {
  echo 'no access.';
  exit;
}

echo exec('sudo /var/www/util/certificates.sh');
echo 'Updated certificates on ' . gethostname() . PHP_EOL;