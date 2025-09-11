<?php

if (!isset($_GET['34ctn0q4x7hp973hor9j27x3qot8g3435ho9mb7th0m2837JG8h07k4osr2ph3aruhmfgiudare'])) {
  echo 'no access.';
  exit;
}

exec('sh /var/www/util/update', $output);

echo 'Releasing files on ' . gethostname() . PHP_EOL;
foreach ($output as $key => $line) {
  echo ($key + 1) .': ' . $line . PHP_EOL;
}
