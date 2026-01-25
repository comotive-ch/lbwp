<?php
define('SKIP_WP_STACK', true);
require_once '../../../../../wp-load.php';

header('Content-Type: text/plain');
if (defined('LBWP_SECURITY_TXT_OUTPUT')) {
    echo LBWP_SECURITY_TXT_OUTPUT;
} else {
  echo 'Contact: mailto:it@comotive.ch' . PHP_EOL;
  echo 'Expires: 2030-31-12T23:59:59.000Z' . PHP_EOL;
  echo 'Preferred-Languages: de, en';
}