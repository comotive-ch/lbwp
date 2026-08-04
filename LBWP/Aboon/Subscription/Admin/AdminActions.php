<?php

namespace LBWP\Aboon\Subscription\Admin;

use LBWP\Aboon\Subscription\Api\ChargeLog;
use LBWP\Aboon\Subscription\Api\SubscriptionApi;
use LBWP\Aboon\Subscription\Api\TransactionApi;
use LBWP\Aboon\Subscription\Order\OrderLinker;
use LBWP\Aboon\Subscription\PostType;
use LBWP\Aboon\Subscription\SubscriptionHelper;
use LBWP\Util\External;

/**
 * Handles the admin-post.php actions triggered from the subscription detail meta box: generate
 * a payment-method link, cancel a subscription, refund one or more charges.
 * @package LBWP\Aboon\Subscription\Admin
 * @author Michael Sebel <michael@comotive.ch>
 */
class AdminActions
{
  public const string ACTION_GENERATE_LINK = 'lbwp_sub_generate_link';
  public const string ACTION_CANCEL = 'lbwp_sub_cancel';
  public const string ACTION_REFUND = 'lbwp_sub_refund';
  public const string ACTION_CREATE_WITHOUT_PAYMENT = 'lbwp_sub_create_without_payment';

  /**
   * Registers the admin-post.php handlers.
   * @return void
   */
  public function register(): void
  {
    add_action('admin_post_' . self::ACTION_GENERATE_LINK, [$this, 'generatePaymentMethodLink']);
    add_action('admin_post_' . self::ACTION_CANCEL, [$this, 'cancelSubscription']);
    add_action('admin_post_' . self::ACTION_REFUND, [$this, 'refundCharges']);
    add_action('admin_post_' . self::ACTION_CREATE_WITHOUT_PAYMENT, [$this, 'createWithoutPayment']);
  }

  /**
   * Creates an on-hold order and a linked draft subscription for a customer without charging
   * anything yet, then emails the customer a Payrexx link to add a payment method (which
   * triggers the first real charge once completed).
   * @return void
   */
  public function createWithoutPayment(): void
  {
    if (!current_user_can('manage_woocommerce')) {
      wp_die(esc_html__('Keine Berechtigung.', 'lbwp'));
    }
    check_admin_referer(self::ACTION_CREATE_WITHOUT_PAYMENT);

    $userId = (int) ($_POST['user_id'] ?? 0);
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

    $linker = new OrderLinker();
    $order = $linker->createAwaitingPaymentOrder($userId, $productId, $quantity);
    $subscriptionPostId = $linker->createLinkedSubscriptionDraft($order);

    $link = (new SubscriptionApi())->buildPaymentMethodUpdateLink((string) $subscriptionPostId);
    if ($link !== null) {
      $this->emailPaymentMethodLink($order->get_billing_email() ?: (get_userdata($userId)->user_email ?? ''), $link);
    }

    wp_safe_redirect((string) get_edit_post_link($subscriptionPostId, ''));
    exit;
  }

  /**
   * Generates a Payrexx payment-method link and emails it to the subscription's customer.
   * @return void
   */
  public function generatePaymentMethodLink(): void
  {
    $subscriptionPostId = $this->authorizeRequest(self::ACTION_GENERATE_LINK);
    $order = SubscriptionHelper::getLinkedOrder($subscriptionPostId);

    if ($order !== null) {
      $link = (new SubscriptionApi())->buildPaymentMethodUpdateLink((string) $subscriptionPostId);
      if ($link !== null) {
        $this->emailPaymentMethodLink($order->get_billing_email(), $link);
      }
    }

    $this->redirectBack($subscriptionPostId);
  }

  /**
   * Cancels the subscription on Payrexx and locally.
   * @return void
   */
  public function cancelSubscription(): void
  {
    $subscriptionPostId = $this->authorizeRequest(self::ACTION_CANCEL);
    (new OrderLinker())->cancelSubscription($subscriptionPostId);
    $this->redirectBack($subscriptionPostId);
  }

  /**
   * Refunds one or more selected charge log rows.
   * @return void
   */
  public function refundCharges(): void
  {
    $subscriptionPostId = $this->authorizeRequest(self::ACTION_REFUND);
    $chargeLogIds = array_map('intval', (array) ($_POST['charge_log_ids'] ?? []));
    $chargeLog = new ChargeLog();
    $transactionApi = new TransactionApi();

    foreach ($chargeLog->getForSubscription($subscriptionPostId) as $row) {
      if (!in_array((int) $row['id'], $chargeLogIds, true) || $row['payrexx_transaction_id'] === '') {
        continue;
      }

      $refunded = $transactionApi->refund($row['payrexx_transaction_id']);
      if ($refunded !== null) {
        $chargeLog->markRefunded((int) $row['id'], $refunded->getStatus());
      }
    }

    $this->redirectBack($subscriptionPostId);
  }

  /**
   * Checks the capability and nonce for an incoming request, and reads the subscription post id.
   * @param string $action the admin-post action name, used as the nonce action
   * @return int the subscription post id
   */
  protected function authorizeRequest(string $action): int
  {
    if (!current_user_can('manage_woocommerce')) {
      wp_die(esc_html__('Keine Berechtigung.', 'lbwp'));
    }

    $subscriptionPostId = (int) ($_POST['subscription_id'] ?? 0);
    check_admin_referer($action . '_' . $subscriptionPostId);

    if (get_post_type($subscriptionPostId) !== PostType::SLUG) {
      wp_die(esc_html__('Ungültiges Abonnement.', 'lbwp'));
    }

    return $subscriptionPostId;
  }

  /**
   * Emails a payment-method link to a customer.
   * @param string $email the recipient's email address
   * @param string $link the Payrexx hosted page link
   * @return void
   */
  protected function emailPaymentMethodLink(string $email, string $link): void
  {
    $mail = External::PhpMailer();
    $mail->addAddress($email);
    $mail->Subject = __('Zahlungsmethode für dein Abonnement hinterlegen', 'lbwp');
    $mail->Body = sprintf(
      '<p>%s</p><p><a href="%s">%s</a></p>',
      esc_html__('Bitte hinterlege deine Zahlungsmethode über folgenden Link:', 'lbwp'),
      esc_url($link),
      esc_html($link)
    );
    $mail->send();
  }

  /**
   * Redirects back to the subscription's edit screen.
   * @param int $subscriptionPostId the lbwp-subscription post id
   * @return void
   */
  protected function redirectBack(int $subscriptionPostId): void
  {
    wp_safe_redirect((string) get_edit_post_link($subscriptionPostId, ''));
    exit;
  }
}
