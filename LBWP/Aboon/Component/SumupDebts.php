<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\Helper\SubscriptionPeriod;
use LBWP\Theme\Base\Component;
use LBWP\Util\External;
use LBWP\Util\Strings;

/**
 * Sums up debts on open recurring orders
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class SumupDebts extends Component
{

  /**
   * Initialize the component
   */
  public function init()
  {
    if (get_option('options_sumup-debts')[0] == 1) {
      add_action('cron_daily_9', array($this, 'sumupOpenDebts'));
    }
  }

  /**
   * Sums up open debts of recurring orders
   */
  public function sumupOpenDebts()
  {
    $status = array('pending', 'failed');
    $subscriptions = wcs_get_subscriptions(array(
      'subscription_status' => 'on-hold',
      'orderby' => 'start_date',
      'order' => 'ASC',
    ));

    /** @var \WC_Subscription $subscription */
    foreach ($subscriptions as $subscription) {
      // Get the latest order of that subscription
      $order = wc_get_order($subscription->get_last_order());
      // Skip if the order is not pending/failed anymore
      if ($order === false || !in_array($order->get_status(), $status)) {
        continue;
      }

      // Order is pending, we can work with that :-), calculate the number of cycles already passed
      $now = new \DateTime();
      $createDate = $order->get_date_created();
      $difference = $createDate->diff($now);
      // Cycles are dependend on billing period
      $cycles = SubscriptionPeriod::getCyclesByDiff($difference, $subscription->get_billing_period());

      // Loop trough items in that order and add up cycles
      $madeChanges = false;
      foreach ($order->get_items() as $item) {
        if ($item->get_quantity() < $cycles) {
          $price = wc_get_product($item->get_product())->get_price();
          $item->set_quantity($cycles);
          $item->set_total($cycles * $price);
          $item->save();
          $madeChanges = true;
        }
      }

      // Changes were made, recalculate totals and send email
      if ($madeChanges) {
        $order->calculate_totals(true);
        $order->save();
        // Send informational email to customer
        $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $paymentUrl = $order->get_checkout_payment_url();
        $mail = External::PhpMailer();
        $mail->addAddress($order->get_billing_email(), $name);
        $mail->Subject = '[' . LBWP_HOST . ']: ' . __('Offene Rechnung / Abonnement verlängert', 'lbwp');
        $mail->Body = '
          ' . __('Guten Tag', 'lbwp') . ' ' . $name . '<br>
          <br>
          ' . __('Ihr Abonnement wurde automatisch verlängert. Ihre offene Rechnung wurde um eine Position erweitert.', 'lbwp') . '<br>
          ' . __('Bitte bezahlen Sie die offene Rechnung mit folgendem Link', 'lbwp') . '<br>
          <br>
          <a href="' . $paymentUrl . '">' .$paymentUrl  . '</a><br>
          <br>
          ' . __('Freundliche Grüsse', 'lbwp') . '<br>
          ' . get_bloginfo('name') . '
        ';
        $mail->AltBody = Strings::getAltMailBody($mail->Body);
        $mail->send();
      }
    }
  }
} 