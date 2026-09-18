<?php

namespace LBWP\Aboon\AgenticCommerce;

use WC_Order;

/**
 * Turns a completed session (draft order) into a real order: writes all meta and addresses
 * first, then transitions the status so every WooCommerce hook sees the finished order.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class OrderFactory
{
  /**
   * Finalises the order of a session.
   * @param Session $session the session (totals already final)
   * @param string $txnId PSP transaction id or empty
   * @param string $handlerId payment handler id
   * @param string $paymentTitle payment method title
   * @param bool $captured whether the PSP captured the amount
   * @param array $extra order_notes, marketing_consents, risk_signals, platform, buyer_ip, user_agent
   * @return WC_Order the finalised order
   */
  public static function finalize(Session $session, string $txnId, string $handlerId, string $paymentTitle, bool $captured, array $extra = []): WC_Order
  {
    $order = wc_get_order($session->getOrder()->get_id());
    $buyer = (array) $session->get('buyer', []);
    if (!empty($buyer['email']) && is_email($buyer['email'])) {
      $order->set_billing_email($buyer['email']);
    }
    if ($order->get_billing_first_name() === '' && $order->get_shipping_first_name() !== '') {
      $order->set_billing_first_name($order->get_shipping_first_name());
      $order->set_billing_last_name($order->get_shipping_last_name());
    }
    if (!empty($extra['order_notes'])) {
      $order->set_customer_note(sanitize_textarea_field((string) $extra['order_notes']));
    }
    if (!empty($extra['buyer_ip'])) {
      $order->set_customer_ip_address(sanitize_text_field((string) $extra['buyer_ip']));
    }
    if (!empty($extra['user_agent'])) {
      $order->set_customer_user_agent(sanitize_text_field((string) $extra['user_agent']));
    }
    $order->set_payment_method($handlerId);
    $order->set_payment_method_title($paymentTitle);
    if ($txnId !== '') {
      $order->update_meta_data(Session::META_PAYMENT_TXN, $txnId);
    }
    if (!empty($extra['platform'])) {
      $order->update_meta_data(Session::META_PLATFORM, sanitize_text_field((string) $extra['platform']));
    }
    if (!empty($extra['marketing_consents'])) {
      $order->update_meta_data('_agentic_marketing_consents', wp_json_encode($extra['marketing_consents']));
    }
    if (!empty($extra['risk_signals'])) {
      $order->update_meta_data('_agentic_risk_signals', wp_json_encode($extra['risk_signals']));
    }
    $order->update_meta_data(Session::META_STATE, wp_json_encode(self::slimState($session->getState())));
    $note = sprintf('Agentic Checkout %s via %s, Session %s', strtoupper($session->getProtocol()), (string) ($extra['platform'] ?: $order->get_meta(Session::META_PLATFORM) ?: 'unknown'), $session->getId());
    if (Settings::isTestMode()) {
      $note = 'TESTMODUS – ' . $note;
    }
    $order->add_order_note($note);
    $order->save();
    if (!empty($extra['marketing_consents'])) {
      do_action('aboon_agentic_marketing_consent', $order, $extra['marketing_consents']);
    }
    // Silent hop to pending so the regular pending_to_* mails and hooks fire afterwards
    $order->set_status('pending');
    $order->save();
    if ($captured) {
      $order->payment_complete($txnId);
    } else {
      $order->update_status(Settings::statusWithoutCapture(), Settings::isTestMode() ? 'TESTMODUS: keine Zahlung eingezogen' : 'Zahlung ohne Einzug (Rechnung / Zahlungsziel)');
    }
    do_action('aboon_agentic_order_finalized', $order, $session);
    return wc_get_order($order->get_id());
  }

  /**
   * Keeps only what a later GET needs from the session state.
   * @param array $state full state
   * @return array slim state
   */
  protected static function slimState(array $state): array
  {
    $keep = ['protocol', 'version', 'lang', 'locale', 'buyer', 'fulfillment', 'selected_option', 'coupons', 'capabilities', 'created'];
    $slim = array_intersect_key($state, array_flip($keep));
    $slim['status'] = Session::STATUS_COMPLETED;
    $selected = (string) ($state['selected_option'] ?? '');
    if ($selected !== '') {
      $option = Shipping::find((array) ($state['fulfillment_options'] ?? []), $selected);
      $slim['fulfillment_options'] = $option === null ? [] : [$option];
    }
    return $slim;
  }

  /**
   * Returns the public order link (carries the order key, no login needed).
   * @param WC_Order $order the order
   * @return string url
   */
  public static function permalink(WC_Order $order): string
  {
    return $order->get_checkout_order_received_url();
  }
}
