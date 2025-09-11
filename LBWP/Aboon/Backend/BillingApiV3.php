<?php

namespace LBWP\Aboon\Backend;

use Automattic\WooCommerce\Admin\Overrides\OrderRefund;
use LBWP\Helper\Metabox;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Theme\Base\Component;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provides APIs for the main aboon controller to change instance settings and read billing information
 * @package LBWP\Aboon\Backendt
 * @author Michael Sebel <michael@comotive.ch
 */
class BillingApiV3 extends Component
{
  /**
   * Init API endpoint
   */
  public function init()
  {
    add_action('rest_api_init', array($this, 'registerApiEndpoint'));

    $this->setupMetaboxes();
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
    register_rest_route('aboon/v3/get', 'sales', array(
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
    HTMLCache::avoidCache();
    if (!$this->authorization($request->get_header('x-aboon-sales')) && !defined('LOCAL_DEVELOPMENT')) {
      return false;
    }

    $invoiceOrders = array();
    $dateQuery = array('year' => isset($_GET['y']) ? $_GET['y'] : date('Y'));
    $dateQuery['month'] = isset($_GET['m']) ? $_GET['m'] : date('m');

    if(isset($_GET['d'])){
      $dateQuery['day'] = $_GET['d'];
    }

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

      $type = self::getPaymentType($pMethod);

      if (!Strings::isEmpty($type) && $type === 'invoice') {
        $orderData = array(
          'date' => $order->get_date_created()->date('Y-m-d H:i:s'),
          'amount' => $order->get_total(),
          'tax' => $order->get_total_tax(),
          'currency' => $order->get_currency(),
        );

        // On refunds, also provide the refund_for id
        if ($order->get_type() == 'shop_order_refund') {
          $parentId = $order->get_parent_id();
          $orderData['refund_of'] = $parentId;
          $orderData['paymentMethodRefund'] = wc_get_order($parentId)->get_payment_method();
        }

        // Add product order list if needed
        if(isset($_GET['productList'])){
          $productList = array();
          foreach ($order->get_items() as $item){
            $product = wc_get_product($item->get_product_id());
            $productData = $item->get_data();
            $productList[$productData['id']] = array(
              'title' => $productData['name'],
              'sku' => $product->get_sku(),
              'price' => $productData['total'],
              'qty' => $productData['quantity']
            );
          }

          $orderData['productList'] = $productList;
        }

        if(isset($_GET['customerData'])){
          $orderData['customerData'] = $order->get_data()['billing'];
        }

        // Group orders by
        $invoiceOrders[$order->get_id()] = $orderData;
      }
    }

    return $invoiceOrders;
  }

  /**
   * Filter the payment method
   * @param $pMethod
   * @return string
   */
  public static function getPaymentType($pMethod){
    switch ($pMethod) {
      case 'payrexx':
        $type = 'payrexx';
        break;
      case 'cheque':
      case 'bacs':
        $type = 'invoice';
        break;
      default:
        $type = '';
    }

    return $type;
  }

  private function setupMetaboxes()
  {
    if(function_exists('wcs_is_subscription') && wcs_is_subscription($_GET['post'])){
      $helper = Metabox::get('shop_subscription');
      $helper->addMetabox('abo-settings', 'Abo Einstellungen');
      $helper->addInputText('shop-url', 'abo-settings', 'Shop URL');
      $helper->addInputText('woocommerce-key', 'abo-settings', 'WooCommerce Key');
      $helper->addInputText('invoice-interests', 'abo-settings', 'Gebühr für Rechnung');
    }
  }
}