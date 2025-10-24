<?php

namespace LBWP\Util;

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';

/**
 * Extends PHPMailer to allow for actions to be before and after sending.
 * runs phpmailer_init on our own built mails the same way wp_mail does.
 */
class FilterablePhpMailer extends \PHPMailer\PHPMailer\PHPMailer
{
  /**
   * @return bool
   * @throws \PHPMailer\PHPMailer\Exception
   */
  public function send()
  {
    add_action('phpmailer_custom_before_send', $this);
    return parent::send();
  }
}