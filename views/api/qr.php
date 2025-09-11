<?php
require_once '../../../../../wp-load.php';

use LBWP\Theme\Feature\SwissQrIban;
use LBWP\Util\Strings;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;

$receiver = array(
  'Testfirma GmbH',
  'Teststrasse 22',
  '9999 Testhausen',
  'CH'
);

$payer = array(
  'Zahli Zahler',
  'Testgasse 19',
  '9998 Teststadt',
  'CH'
);

$swissQrIban = SwissQrIban::getInstance();
$qrBill = $swissQrIban->getQrBillInstance($receiver, $payer, 'CHF', 250.50, 'Das ist der Hilfetext', 1234567);

// Write an image
$path = '/tmp/' . Strings::getRandom(20) . '.pdf';
try {
  require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/tcpdf/tcpdf.php';
  $tcPdf = new \TCPDF('P', 'mm', 'A4', true, 'ISO-8859-1');
  $tcPdf->setPrintHeader(false);
  $tcPdf->setPrintFooter(false);
  $tcPdf->AddPage();
  $output = new TcPdfOutput($qrBill, 'de', $tcPdf);
  $output->setPrintable(false)->getPaymentPart();
  $tcPdf->Output($path, 'F');
  var_dump($path);
} catch (Exception $e) {
  foreach($qrBill->getViolations() as $violation) {
    print $violation->getMessage()."\n";
  }
  exit;
}
