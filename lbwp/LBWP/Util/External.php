<?php

namespace LBWP\Util;

use PHPMailer;

/**
 * Serves as a handler to create instances of external classes
 * @author Michael Sebel <michael@comotive.ch>
 */
class External
{

  /**
   * @return PHPMailer a php mailer instance
   */
  public static function PhpMailer()
  {
    require_once ABSPATH.WPINC.'/class-phpmailer.php';
    // Make some preconfigurations
    $mail = new PHPMailer();
    $mail->IsHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->FromName = apply_filters('lbwpPhpMailerFromName', get_bloginfo('name'));
    $mail->From = LBWP_CUSTOM_FROM_EMAIL;

    return $mail;
  }
}