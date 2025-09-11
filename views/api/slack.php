<?php
/**
 * Global handler of incoming Slack API events
 */

require_once '../../../../../wp-load.php';

use LBWP\Util\WordPress;

// Get the actual data from POSTed php input and make a json of it
$json = file_get_contents('php://input');
$request = json_decode($json, true);

// If we have a challenge, respond to it
if ($request['type'] == 'url_verification') {
  do_action('slack_catch_verfication_event', $request, $request['type']);
  WordPress::sendJsonResponse(array(
    'challenge' => $request['challenge']
  ));
}

// If we have a proper request, let a developer catch it via actions
do_action('slack_catch_global_event', $request, $request['type']);