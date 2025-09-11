<?php

namespace LBWP\Aboon\Backend;

use Automattic\WooCommerce\Admin\Overrides\OrderRefund;
use LBWP\Theme\Base\Component;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provides APIs for the main aboon controller to change instance settings and read billing information
 * @package LBWP\Aboon\Backendt
 * @author Michael Sebel <michael@comotive.ch
 */
class BillingApi extends Component
{
  /**
   * Init API endpoint
   */
  public function init()
  {
    add_action('rest_api_init', array($this, 'registerApiEndpoint'));
    add_action('cron_monthly_1', array($this, 'fixPayrexxPaymentMethod'));
    add_action('cron_monthly_1', array($this, 'infoOnMissingPayrexxPaymentMethod'));
    // Send refund infos every week and also every start of month
    add_action('cron_monthly_1', array($this, 'sendRefundInfo'));
    add_action('cron_weekday_5', array($this, 'sendRefundInfo'));
  }

  /**
   * register API endpoint and its callback
   */
  public function registerApiEndpoint()
  {
    register_rest_route('aboon/settings', 'update', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'updateInstanceSettings')
    ));
    register_rest_route('aboon/get', 'sales', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'getOrders')
    ));
  }

  /**
   * Blindly update the instance settings with given data from request
   */
  public function updateInstanceSettings(\WP_REST_Request $request)
  {
    if ($this->authorization($request->get_header('x-aboon-token'))) {
      update_option('aboon-settings', $request->get_params());
    }
  }

  /**
   * @param $check string the toke to check
   * @return bool true if a key is the same, else false
   */
  protected function authorization($check)
  {
    $db = WordPress::getDb();
    $keys = $db->get_col('SELECT consumer_secret FROM ' . $db->prefix . 'woocommerce_api_keys');
    foreach ($keys as $key) {
      if ($check === md5($key)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get all sales base on year and month
   */
  public function getOrders(\WP_REST_Request $request)
  {
    if (!$this->authorization($request->get_header('x-aboon-sales'))) {
      return false;
    }

    $orderTypes = array(
      'invoice' => array(),
      'card_low' => array(),
      'card_high' => array(),
      'paypal' => array(),
    );
    $dateQuery = array('year' => isset($_GET['y']) ? $_GET['y'] : date('Y'));
    $dateQuery['month'] = isset($_GET['m']) ? $_GET['m'] : date('m');
    $orders = wc_get_orders(array(
      'posts_per_page' => -1,
      'date_query' => $dateQuery,
      'status' => array('wc-processing', 'wc-on-hold', 'wc-completed', 'wc-partial-refunded', 'wc-refunded')
    ));
		$currency = isset($_GET['cr']) ? strtoupper($_GET['cr']) : false;

    foreach ($orders as $order) {
			// First check currency
			if($order->get_currency() !== $currency && $currency !== false){
				continue;
			}

      if ($order instanceof OrderRefund) {
        // refunds don't actually know where they come from, assume bacs so we "repay" somehow
        $pMethod = 'bacs';
      } else {
        $pMethod = $order->get_payment_method();
      }

      // Note: payrexx submethod "paypal" and actual main method "paypal" are handled in the same way
      if ($pMethod === 'payrexx') {
        $pMethod = $order->get_meta('payrexx_payment_method');
      }

      $type = '';
      switch ($pMethod) {

        case 'twint':
        // payrexx provides different keys here sometimes (?)
        case 'postfinance-card':
        case 'postfinance_card':
        case 'postfinance-efinance':
        case 'postfinance_efinance':
          $type = 'card_low';
          break;
        case 'visa':
        case 'mastercard':
        // google/apple come with different keys
        case 'google-pay':
        case 'googlepay':
        case 'apple-pay':
        case 'applepay':
          $type = 'card_high';
          break;
        case 'paypal':
          $type = 'paypal';
          break;
        case 'cheque':
        case 'bacs':
        default:
          $type = 'invoice';
          break;
      }

      if (!Strings::isEmpty($type)) {
        $orderData = array(
          'date' => $order->get_date_created()->date('Y-m-d H:i:s'),
          'amount' => $order->get_total(),
          'tax' => $order->get_total_tax(),
          'currency' => $order->get_currency(),
          'abonnement' => get_post_meta($order->get_id(), '_subscription_renewal', true),
          'paymentMethod' => $pMethod
        );

        // On refunds, also provide the refund_for id
        if ($order->get_type() == 'shop_order_refund') {
          $parentId = $order->get_parent_id();
          $orderData['refund_of'] = $parentId;
          $orderData['paymentMethodRefund'] = get_post_meta($parentId, '_payment_method', true);
        }

        // Group orders by
        $orderTypes[$type][$order->get_id()] = $orderData;
      }
    }

    return $orderTypes;
  }

  /**
   * Send refund info on open refunds per customer as an html table by email
   */
  public function sendRefundInfo()
  {
    // Get all refunds with a refund state 2 (=can be refunded by comotive)
    $refunds = get_posts(array(
      'post_type' => 'shop_order',
      'posts_per_page' => -1,
      'post_status' => 'any',
      'meta_query' => array(
        array(
          'key' => 'aboon_refund_state',
          'value' => 2,
          'compare' => '='
        )
      )
    ));

    $url = get_admin_url();
    $baseUrl = get_bloginfo('url');
    $db = WordPress::getDb();
    $html = '
      Erstattungs-Status: Rückerstattung 1 = beantragt, 2 = bestätigt, 3 = ausgeführt<br><br>
      <table style="width:100%">
        <tr>
          <td>ID</td>
          <td>Adresse</td>
          <td>Bestellung Total</td>
          <td>Rückerstattungs-Betrag</td>
          <td>Erstattungs-Status</td>
          <td>Bestell-Status</td>
          <td>Bezahlt am</td>
          <td>Erledigt</td>
        </tr>
    ';

    foreach ($refunds as $refund) {
      $confirmUrl = $baseUrl. '/wp-content/plugins/lbwp/views/cron/job.php?identifier=aboon_finish_refund&data=' . $refund->ID;
      $currency = get_post_meta($refund->ID, '_order_currency', true);
      $address = trim(
        get_post_meta($refund->ID, '_billing_first_name', true) . ' ' .
        get_post_meta($refund->ID, '_billing_last_name', true) . ', ' .
        get_post_meta($refund->ID, '_billing_address_1', true) . ', ' .
        get_post_meta($refund->ID, '_billing_company', true)
      );
      $actualRefundId = $db->get_var('
        SELECT ID FROM ' . $db->posts . ' WHERE post_parent = ' . $refund->ID . ' AND post_type = "shop_order_refund"
      ');

      $html .= '
        <tr>
          <td><a href="'. $url .'/post.php?post=' . $refund->ID . '&action=edit" target="_blank">#' . $refund->ID . '</a></td>
          <td>' . $address . '</td>
          <td>' . $currency . ' ' . get_post_meta($refund->ID, '_order_total', true) . '</td>
          <td>' . $currency . ' ' . number_format(get_post_meta($actualRefundId, '_refund_amount', true), 2) . '</td>
          <td>' . get_post_meta($refund->ID, 'aboon_refund_state', true) . '</td>
          <td>' . $refund->post_status . '</td>
          <td>' . $refund->post_date . '</td>
          <td><a href="' . $confirmUrl . '" target="_blank">Erledigt markieren (Status=3)</a></td>
        </tr>
      ';
    }
    $html .= '</table>';

    if (count($refunds) > 0) {
      $mail = External::PhpMailer();
      $mail->addAddress(MONITORING_EMAIL);
      $mail->Subject = '[' . LBWP_HOST . '] Freigegebene Rückerstattungen auslösen';
      $mail->Body = $html;
      $mail->send();
    }
  }

  /**
   * Inform on still missing payrexx payment method
   */
  public function infoOnMissingPayrexxPaymentMethod()
  {
    $orders = get_posts(array(
      'post_type' => 'shop_order',
      'posts_per_page' => -1,
      'post_status' => array('wc-processing', 'wc-completed'),
      'date_query' => array(
        'after' => date('Y-m-d', current_time('timestamp') - 90*86400)
      ),
      'meta_query' => array(
        'relation' => 'AND',
        array(
          'key' => '_payment_method',
          'value' => 'payrexx'
        ),
        array(
          'key' => 'payrexx_payment_method',
          'compare' => 'NOT EXISTS'
        )
      )
    ));

    $url = admin_url();
    $html = '';
    foreach ($orders as $order) {
      $html .= '<a href="' . $url . 'post.php?post=' . $order->ID . '">#' . $order->ID . ' has no payrexx_payment_method</a><br>';
    }

    if (strlen($html) > 0) {
      $mail = External::PhpMailer();
      $mail->addAddress(MONITORING_EMAIL);
      $mail->Subject = '[' . LBWP_HOST . '] Fehlende Zahlungsmethoden für Abrechnung';
      $mail->Body = $html;
      $mail->send();
    }
  }

  /**
   * Copies the aboon-needed payrexx_payment_method from parent order, if not copied by payrexx plugin
   */
  public function fixPayrexxPaymentMethod()
  {
    // Only relevant for subscriptions plugin, if not available, leave
    if (!function_exists('wcs_get_subscriptions_for_order')) {
      return;
    }

    // Get all orders with no payrexx_payment_method
    $orders = get_posts(array(
      'post_type' => 'shop_order',
      'posts_per_page' => -1,
      'post_status' => array('wc-processing', 'wc-completed'),
      'date_query' => array(
        'after' => date('Y-m-d', current_time('timestamp') - 90*86400)
      ),
      'meta_query' => array(
        'relation' => 'AND',
        array(
          'key' => '_payment_method',
          'value' => 'payrexx'
        ),
        array(
          'key' => 'payrexx_payment_method',
          'compare' => 'NOT EXISTS'
        )
      )
    ));

    // Go trough all orders, get their main/first order to get the payment method (which is updated)
    foreach ($orders as $order) {
      $subscriptions = wcs_get_subscriptions_for_order($order->ID, array('order_type' => 'any'));
      foreach ($subscriptions as $subscription) {
        $relatedIds = array_keys($subscription->get_related_orders('ids'));
        sort($relatedIds, SORT_NUMERIC);
        $parentId = array_shift($relatedIds);
        // Get the payment method and eventually save to order
        $method = get_post_meta($parentId, 'payrexx_payment_method', true);
        if ($method!==false && strlen($method) > 0) {
          update_post_meta($order->ID, 'payrexx_payment_method', $method);
        }
        break;
      }
    }
  }
}