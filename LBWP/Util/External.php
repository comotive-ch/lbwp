<?php

namespace LBWP\Util;

/**
 * Serves as a handler to create instances of external classes
 * @author Michael Sebel <michael@comotive.ch>
 */
class External
{

  /**
   * @return \PHPMailer\PHPMailer\PHPMailer a php mailer instance
   */
  public static function PhpMailer($warmup = false)
  {
    // Make some preconfigurations
    $mail = new FilterablePhpMailer();
    $mail->IsHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->FromName = apply_filters('lbwpPhpMailerFromName', get_bloginfo('name'));
    $mail->From = LBWP_CUSTOM_FROM_EMAIL;

    if (!defined('EXTERNAL_LBWP') && !defined('LOCAL_DEVELOPMENT')) {
      $mail->isSMTP();
      if ($warmup) {
        $mail->Host = getSmtpRelayWarmupHost();
      } else {
        $mail->Host = getSmtpRelayHost();
      }
      $mail->Port = '25';
      $mail->SMTPAuth = false;
      $mail->SMTPAutoTLS = false;
      $mail->SMTPSecure = '';
      $mail->XMailer = null;
    }

    return $mail;
  }
}