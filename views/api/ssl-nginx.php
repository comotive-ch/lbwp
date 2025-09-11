<?php

if (!isset($_GET['079wer87w3utwhhurwruhrwew398pwtweuoerwiogehogephoh9greuohrgejsgjbkw349wur899t3hugreuherghupq3t9h3'])) {
  echo 'no access.';
  exit;
}

exec('sudo /var/www/util/nginx-update-config.sh', $output);

echo 'Updating nginx configs on ' . gethostname() . PHP_EOL;
foreach ($output as $key => $line) {
  echo ($key + 1) .': ' . $line . PHP_EOL;
}
