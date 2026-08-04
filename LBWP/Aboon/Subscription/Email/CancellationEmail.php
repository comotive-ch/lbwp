<?php

namespace LBWP\Aboon\Subscription\Email;

use LBWP\Aboon\Subscription\SubscriptionHelper;

/**
 * WooCommerce-configurable email sent to both the shop admin and the customer when a
 * subscription is cancelled (by the admin or by the customer themselves). Configurable under
 * WooCommerce > Settings > Emails.
 * @package LBWP\Aboon\Subscription\Email
 * @author Michael Sebel <michael@comotive.ch>
 */
class CancellationEmail extends \WC_Email
{
  /**
   * Sets up the email's id, titles and default texts.
   */
  public function __construct()
  {
    $this->id = 'lbwp_subscription_cancelled';
    $this->title = __('Abonnement gekündigt', 'lbwp');
    $this->description = __('Wird an den Shop-Administrator und den Kunden gesendet, sobald ein Abonnement gekündigt wird.', 'lbwp');
    $this->heading = __('Abonnement gekündigt', 'lbwp');
    $this->subject = __('Dein Abonnement wurde gekündigt', 'lbwp');

    parent::__construct();

    $this->recipient = $this->get_option('recipient', get_option('admin_email'));
  }

  /**
   * Sends the cancellation notice to the admin and the subscription's customer.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  public function trigger(int $subscriptionPostId): void
  {
    $this->setup_locale();

    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);
    if ($order === null || !$this->is_enabled()) {
      $this->restore_locale();
      return;
    }

    $this->object = $order;
    $recipients = array_unique(array_filter([$this->recipient, $order->get_billing_email()]));

    $this->send(implode(',', $recipients), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());

    $this->restore_locale();
  }

  /**
   * @return string the HTML email body
   */
  public function get_content_html(): string
  {
    return sprintf(
      '<p>%s</p><p>%s: %s</p>',
      esc_html__('Das Abonnement wurde gekündigt.', 'lbwp'),
      esc_html__('Bestellung', 'lbwp'),
      esc_html('#' . $this->object->get_order_number())
    );
  }

  /**
   * @return string the plain-text email body
   */
  public function get_content_plain(): string
  {
    return sprintf(
      "%s\n\n%s: #%s",
      __('Das Abonnement wurde gekündigt.', 'lbwp'),
      __('Bestellung', 'lbwp'),
      $this->object->get_order_number()
    );
  }

  /**
   * Registers this email class with WooCommerce so it appears under Settings > Emails.
   * @param array<string,\WC_Email> $emailClasses the registered WooCommerce email classes
   * @return array<string,\WC_Email> the updated list
   */
  public static function register(array $emailClasses): array
  {
    $emailClasses['lbwp_subscription_cancelled'] = new self();
    return $emailClasses;
  }
}
