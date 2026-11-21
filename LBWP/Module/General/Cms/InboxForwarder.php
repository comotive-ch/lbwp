<?php

namespace LBWP\Module\General\Cms;

use LBWP\Util\Imap\Client;
use LBWP\Util\Imap\Message;
use LBWP\Util\Imap\MimePart;

/**
 * Empties the automail catch-all inbox and forwards every mail to the lbwp/core/inbox REST endpoint
 * of the instance encoded in the recipient address ("<host>_<filter>_<additional>@…").
 * @package LBWP\Module\General\Cms
 */
class InboxForwarder
{
  /**
   * Path of the receiving REST endpoint on the target host
   */
  const string ENDPOINT_PATH = '/wp-json/lbwp/core/inbox/';
  /**
   * User agent sent to the target host
   */
  const string USER_AGENT = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.43 Safari/537.31 Comotive-Fetch-1.0';

  /**
   * Forwards and deletes all mails of the inbox configured by the LBWP_AUTOMAIL_IMAP_* constants
   * @return void
   */
  public function run(): void
  {
    $inbox = Client::open(LBWP_AUTOMAIL_IMAP_CONN_STRING, LBWP_AUTOMAIL_IMAP_USER, LBWP_AUTOMAIL_IMAP_PASSWORD);
    $count = $inbox->count();

    for ($sequenceNumber = 1; $sequenceNumber <= $count; $sequenceNumber++) {
      $message = $inbox->fetchMessage($sequenceNumber);
      if ($message->isDeleted()) {
        continue;
      }

      $this->forward($message);
      $inbox->delete($sequenceNumber);
    }

    $inbox->expunge();
    $inbox->close();
  }

  /**
   * Sends the mail to the instance encoded in its recipient address, if the address has a host and filter
   * @param Message $message the mail to forward
   * @return void
   */
  protected function forward(Message $message): void
  {
    $recipient = $this->getRecipient($message);
    $localPart = substr($recipient, 0, (int) strrpos($recipient, '@'));
    [$host, $filter, $additional] = array_pad(explode('_', $localPart), 3, null);

    if (strlen($host ?? '') === 0 || strlen($filter ?? '') === 0) {
      return;
    }

    $postData = $this->buildPostData($message);
    $postData['filter'] = $filter;
    if ($additional !== null) {
      $postData['additional'] = explode('-', $additional);
    }

    $this->post('https://' . $host . self::ENDPOINT_PATH, $postData);
  }

  /**
   * The envelope-to holds the original address for forwarded mails, To would be the catch-all
   * @param Message $message the mail
   * @return string the original recipient address, empty if none
   */
  protected function getRecipient(Message $message): string
  {
    $address = $message->getAddresses('envelope-to')[0] ?? $message->getAddresses('to')[0] ?? null;
    return $address?->email ?? '';
  }

  /**
   * Builds the payload expected by the lbwp_incoming_mail_* actions
   * @param Message $message the mail
   * @return array{subject: string, from: string, fromname: string, text: string, html: string, charset: string, attachments: array<string, string>, additional: string[]}
   */
  protected function buildPostData(Message $message): array
  {
    $postData = [
      'subject' => $message->getSubject(),
      'from' => $message->getFrom()?->email ?? '',
      'fromname' => $message->getFrom()?->name ?? '',
      'text' => '',
      'html' => '',
      'charset' => 'UTF-8',
      'attachments' => [],
      'additional' => [],
    ];

    foreach ($message->getLeafParts() as $index => $part) {
      $this->addPart($part, $index, $postData);
    }

    return $postData;
  }

  /**
   * Adds a leaf part to the payload as attachment, plain text or html
   * @param MimePart $part the leaf part
   * @param int $index position of the part, used to name unnamed attachments
   * @param array $postData the payload to extend
   * @return void
   */
  protected function addPart(MimePart $part, int $index, array &$postData): void
  {
    $filename = $part->getFilename();
    if ($filename === '' && $part->getDisposition() === 'attachment') {
      $filename = 'attachment-' . ($index + 1) . '.' . substr(strrchr($part->getContentType(), '/') ?: '/bin', 1);
    }

    if ($filename !== '') {
      $postData['attachments'][$filename] = base64_encode($part->getContent());
      return;
    }

    if ($part->getContentType() === 'text/plain') {
      $postData['text'] .= trim($part->getText()) . "\n\n";
    } elseif ($part->getContentType() === 'text/html') {
      $postData['html'] .= $part->getText() . '<br><br>';
    }
  }

  /**
   * @param string $url the endpoint url
   * @param array $postData the payload, sent form-encoded
   * @return void
   */
  protected function post(string $url, array $postData): void
  {
    $request = curl_init($url);
    curl_setopt_array($request, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => self::USER_AGENT,
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_POSTFIELDS => http_build_query($postData),
      CURLOPT_CUSTOMREQUEST => 'POST',
    ]);
    curl_exec($request);
  }
}
