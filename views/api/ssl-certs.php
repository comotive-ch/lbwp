<?php

if (!isset($_GET['079wer87w3utwhhurwruhrwew398pwtweuoerwiogehogephoh9greuohrgejsgjbkw349wur899t3hugreuherghupq3t9h3'])) {
  echo 'no access.';
  exit;
}

echo shell_exec('sh /var/www/util/certificates.sh');
echo 'Updated certificates on ' . gethostname() . PHP_EOL;