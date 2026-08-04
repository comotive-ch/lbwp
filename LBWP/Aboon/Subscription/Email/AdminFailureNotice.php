<?php

namespace LBWP\Aboon\Subscription\Email;

use LBWP\Util\External;

/**
 * Sends a one-off notice to the WordPress admin email when a subscription's payment has
 * definitively failed (after Payrexx's own dunning/retries). Internal-only, not customer
 * configurable, hence a plain PHPMailer send rather than a full WC_Email subclass.
 * @package LBWP\Aboon\Subscription\Email
 * @author Michael Sebel <michael@comotive.ch>
 */
class AdminFailureNotice
{
  /**
   * Sends the admin notice for a subscription in the definitive-failure state.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  public static function send(int $subscriptionPostId): void
  {
    $editLink = get_edit_post_link($subscriptionPostId, '');
    $title = get_the_title($subscriptionPostId);

    $mail = External::PhpMailer();
    $mail->addAddress(get_option('admin_email'));
    $mail->Subject = sprintf(__('Abonnement-Zahlung endgültig fehlgeschlagen: %s', 'lbwp'), $title);
    $mail->Body = sprintf(
      '<p>%s</p><p><a href="%s">%s</a></p>',
      sprintf(__('Die Zahlung für das Abonnement "%s" ist nach mehreren Versuchen endgültig fehlgeschlagen. Das Abonnement wurde auf Entwurf gesetzt.', 'lbwp'), esc_html($title)),
      esc_url((string) $editLink),
      __('Abonnement im Backend öffnen', 'lbwp')
    );
    $mail->send();
  }
}
