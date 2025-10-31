<?php
define('CDN_TYPE', 'none');
define('DB_NAME', '');
define('SKIP_WP_STACK', true);
define('RUN_MINIMAL_STACK', true);
require_once '../../../../../wp-load.php';

$host = getLbwpHost();
$compare = 'M7dNehJrmnDhJ' . md5($host) . 'MndhWmshE78rMD0';

if ($_POST['key'] != $compare) {
  echo 'key match error';
  exit;
}

// Get the current array and extend it with the new challenge
$challenges = wp_cache_get('letsEncryptAcmeChallenge', 'certbot');
$challenges = is_array($challenges) ? $challenges : array();

$challenges[$_POST['domain']] = array(
  'path' => substr($_POST['path'], 0, 100),
  'content' => substr($_POST['content'], 0, 100)
);

wp_cache_set('letsEncryptAcmeChallenge', $challenges, 'certbot', 1800);
exit;
