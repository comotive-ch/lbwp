<?php

namespace LBWP\Util;

/**
 * Sms functionality
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Sms
{
  /**
   * @var string the from name of the email to make it work
   */
  const FROM_EMAIL = 'ecall.smsgateway@comotive.ch';
  /**
   * @var string the template to send to
   */
  const GATEWAY_EMAIL = '{number}@sms.ecall.ch';

  /**
   * @param string $number the number to send the sms to
   * @param string $tag subject before text in parantheses
   * @param string $message the actual message
   */
  public static function send($number, $tag, $message)
  {
    $number = Strings::forceSlugString($number);
    $recipient = str_replace('{number}', $number, self::GATEWAY_EMAIL);
    // Send a simple mail to generate the SMS
    $mail = External::PhpMailer();
    $mail->isHTML(false);
    $mail->addAddress($recipient);
    $mail->From = self::FROM_EMAIL;
    $mail->FromName = 'Comotive SMS Gateway';
    $mail->Subject = '[' . $tag . ']';
    $mail->Body = $message;
    $mail->send();
  }
}