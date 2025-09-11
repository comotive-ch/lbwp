<?php

require_once '../../../../../wp-load.php';

$ts = microtime(true);
$inbox = imap_open(LBWP_AUTOMAIL_IMAP_CONN_STRING, LBWP_AUTOMAIL_IMAP_USER, LBWP_AUTOMAIL_IMAP_PASSWORD);
// Get total count in inbox to access all inbox mails to be worked with
$emailCount = imap_num_msg($inbox);
// There's not mails to be read, end
if ($emailCount === 0) {
  imap_close($inbox);
  exit;
}

// Fetch basic data for all mails in the inbox
$mails = imap_fetch_overview($inbox, '1:' . $emailCount, 0);

$mailDump = '';
// Loop trough email to get details
foreach ($mails as $index => $mail) {
  // Skip if marked for deletion already
  if ($mail->deleted !== 0) {
    continue;
  }
  // Get subject and full body of email
  $subject = $mail->subject;
  $body = imap_body($inbox, ($index + 1));
  $structure = imap_fetchstructure($inbox, $index + 1);
  // Get the actual envelope-to (toaddress) to get the original email not the catchall
  $headers = imap_headerinfo($inbox, ($index + 1));
  $email = $headers->toaddress;
  // Handle utf8 subjects correctly
  if (str_contains($subject, '=?UTF-8?')) {
    $subject = imap_utf8($subject);
  }

  $postData = array(
    'subject' => $subject,
    'from' => $email,
    'text' => '',
    'html' => '',
    'attachments' => array(),
    'additional' => array()
  );

  // Override from email with "more readable" values
  if(isset($headers->from)){
    $postData['from'] = $headers->from[0]->mailbox . '@' . $headers->from[0]->host;
    $postData['fromname'] = $headers->from[0]->personal;
  }

  // Get the envelope-to address (for forwarded messages)
  $rawHeaderData = imap_fetchheader($inbox, $index + 1);
  $headerData = array('info' => array());
  foreach(explode(PHP_EOL, $rawHeaderData) as $line){
    $line = explode(':', $line, 2);
    if(!isset($line[1])){
      $headerData['info'][] = trim($line[0]);
    }else{
      $headerData[strtolower(trim($line[0]))] = trim($line[1]);
    }
  }

  if(isset($headerData['envelope-to'])){
    $email = $headerData['envelope-to'];
  }

  if (!$structure->parts) {
    imap_getEmailContentPart($inbox, ($index + 1), $structure, 0, $postData);
  } else {
    foreach ($structure->parts as $partNum => $p) {
      imap_getEmailContentPart($inbox, ($index + 1), $p, $partNum + 1, $postData);
    }
  }

  // Split the mail into our syntax for eventually more data
  $emailNamePart = substr($email, 0, strrpos($email, '@'));
  list($host, $filter, $additional) = explode('_', $emailNamePart);
  // If theres additional, put that into its place
  if ($additional !== NULL) {
    $postData['additional'] = explode('-', $additional);
  }

  // Send that shit to an API of yo choice
  if (strlen($host) > 0 && strlen($filter) > 0) {
    $postData['filter'] = $filter;
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSLVERSION => 6,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.43 Safari/537.31 Comotive-Fetch-1.0',
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_POSTFIELDS => http_build_query($postData),
      CURLOPT_CUSTOMREQUEST => 'POST'
    );

    $res = curl_init('https://' . $host . '/wp-json/lbwp/core/inbox/');
    curl_setopt_array($res, $options);
    $result = curl_exec($res);
  }

  // Delete the email from the server, once done (or even before calling the api?)
  imap_delete($inbox, ($index+1));
}

echo $mailDump;

// Delete everything marked for deletion and close connection
imap_expunge($inbox);
imap_close($inbox);

// Get message part
// Inspired by: https://www.techfry.com/php-tutorial/how-to-read-emails-using-php
function imap_getEmailContentPart($inbox, $id, $part, $partNum, &$content)
{
  $data = ($partNum) ? imap_fetchbody($inbox, $id, $partNum) : imap_body($inbox, $id);

  // Decode
  if ($part->encoding == 4) {
    $data = quoted_printable_decode($data);
  } else if ($part->encoding == 3) {
    $data = base64_decode($data);
  }

  // Email Parameters
  $eparams = array();
  if ($part->parameters) {
    foreach ($part->parameters as $x) {
      $eparams[strtolower($x->attribute)] = $x->value;
    }
  }
  if ($part->dparameters) {
    foreach ($part->dparameters as $x) {
      $eparams[strtolower($x->attribute)] = $x->value;
    }
  }

  // Attachments
  if ($eparams['filename'] || $eparams['name']) {
    $filename = ($eparams['filename']) ? $eparams['filename'] : $eparams['name'];
    $content['attachments'][$filename] = base64_encode($data);
  }

  // Text Messaage
  if ($part->type == 0 && $data) {
    if (strtolower($part->subtype) == 'plain') {
      $content['text'] .= trim($data) . "\n\n";
    } else {
      $content['html'] .= $data . '<br><br>';
    }
    $content['charset'] = $eparams['charset'];

  } else if ($part->type == 2 && $data) {
    $content['text'] .= $data . "\n\n";
  }

  // Subparts Recursion
  if ($part->parts) {
    foreach ($part->parts as $innerPartNum => $innerPart) {
      imap_getEmailContentPart($inbox, $id, $innerPart, $partNum . '.' . ($innerPartNum + 1), $content);
    }
  }
}