<?php
/**
 * Global handler of incoming uploads via form tool
 */

require_once '../../../../../wp-load.php';

use LBWP\Util\WordPress;
use LBWP\Module\Forms\Item\Upload;

// Use a default message for errors
$result = array(
  'status' => 'error',
  'url' => '',
  'message' => __('Es ist ein Fehler aufgetreten.', 'lbwp')
);

if (isset($_POST['cfgKey']) && isset($_FILES['file'])) {
  $result = Upload::handleNewFile($result);
}

// Send a response
WordPress::sendJsonResponse(array(
  'status' => $result['status'],
  'url' => $result['url'],
  'message' => $result['message']
));