<?php
define('PLAYGROUND_OUTPUT_KEY', 'jhfgda6134uhgre98zu4q3tnorge9834qtonia');
define('PLAYGROUND_OUTPUT_SECRET', 'u34joirg9u83698uerzjohi9z3ijotzepjorejreg9z87439z43ouheroi');

if (!isset($_REQUEST[PLAYGROUND_OUTPUT_KEY]) || $_REQUEST[PLAYGROUND_OUTPUT_KEY] != PLAYGROUND_OUTPUT_SECRET) {
  exit;
}

/*
use LBWP\Helper\Mail\AmazonSES;

require_once '../../../../../wp-load.php';

$mailer = new AmazonSES();

$mailer->configure(array(
  'accessKey' => 'AKIAIL34G7CNI7QVEPDQ',
  'secretKey' => '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'
));

$mailer->addAddress('michael@comotive.ch');
$mailer->setFrom('info@badewelten.ch', 'BadeWelten Genossenschaft');
$mailer->setSubject('Test E-Mail SES Upgrade');
$mailer->setBody('Test E-Mail für das <strong>SES Upgrade</strong> hier.');
$mailer->setAltBody('Test E-Mail für das SES Upgrade hier.');
$mailer->send();
*/