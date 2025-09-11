<?php

namespace LBWP\Helper\WooCommerce;

/**
 * This can be used to manually create a simple order. You can add products and some
 * configurations and error pages if anything fails. This will not do an actual checkout
 * and can be used to create apps that are used by cashiers.
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 */
class ManualOrder
{
  /**
   * @var int the order id
   */
  protected $orderId = 0;
  /**
   * @var int total order price. I shit you not, we need to calculate this ourselves
   */
  protected $orderTotal = 0;
  /**
   * @var array list of all error pages to do redirects
   */
  protected $errorUrls = array();
  /**
   * @var bool determines if the class should do redirects or error messages
   */
  protected $redirectOnErrors = false;
  /**
   * @var array all purchased products, to recalculate stock
   */
  protected $purchasedProducts = array();
  /**
   * @var boolean
   */
  protected $isManualOrder = false;

  /**
   * Creates the order post object and saves the userId as billing address
   * @param int $userId the user to assign the order to
   */
  public function initializeOrder($userId = 0, $date = '')
  {
    if ($userId == 0) {
      $userId = get_current_user_id();
    }

    // Create default order data
    $orderData = apply_filters('woocommerce_new_order_data', array(
      'post_type' => 'shop_order',
      'post_title' => sprintf(
        __('Bestellung Nr. %s', 'woocommerce'),
        strftime(_x( '%b %d, %Y @ %I:%M %p', 'Order date parsed by strftime', 'woocommerce'))
      ),
      'post_status' => 'pending',
      'ping_status' => 'closed',
      'post_author' => 1,
      'post_password' => uniqid('order_')
    ));
    // Add date in the past or future if needed
    if (strlen($date) > 0) {
      $orderData['post_date_gmt'] = $date;
    }

    // insert order, post
    $this->orderId = wp_insert_post($orderData);
    if (is_wp_error($this->orderId)) {
      $this->errorHandler('create-order-error');
    } else {
      do_action('woocommerce_new_order', $this->orderId);
    }

    // Meta to find the order again
    update_post_meta($this->orderId, '_customer_user', $userId);
    update_post_meta($this->orderId, '_order_key', apply_filters('woocommerce_generate_order_key', uniqid('order_')));
    update_post_meta($this->orderId, '_order_currency', get_woocommerce_currency());
    update_post_meta($this->orderId, '_prices_include_tax', get_option( 'woocommerce_prices_include_tax' ));
    update_post_meta($this->orderId, '_customer_ip_address', $_SERVER['REMOTE_ADDR']);
    update_post_meta($this->orderId, '_customer_user_agent',  $_SERVER['HTTP_USER_AGENT']);

    // Order total values
    foreach (array('_order_shipping', '_order_discount', '_cart_discount', '_order_tax', '_order_shipping_tax', '_order_total') as $key) {
      update_post_meta($this->orderId, $key, wc_format_decimal(0));
    }

    // Order status
    wp_set_object_terms($this->orderId, 'pending', 'shop_order_status' );
    return $this->orderId;
  }

  /**
   * Define the various error pages, if anything fails within the order
   * @param string $noStock used, if the desired product is not in stock
   * @param string $orderCreateError used, if there is a creation error on the order object
   */
  public function setErrorPages($noStock, $orderCreateError)
  {
    $this->errorUrls = array(
      'no-stock' => $noStock,
      'create-order-error' => $orderCreateError
    );

    $this->redirectOnErrors = true;
  }

  /**
   * Which payment type is used for the order. This doesn't actually do the payment,
   * it is just for displaying later. Normally this is cash.
   * @param string $paymentId the payment id from woocommerce
   * @throws \Exception if initializeOrder wasn't called yet
   */
  public function setPaymentId($paymentId)
  {
    if ($this->orderId > 0) {
      // Find out the name of the payment method to create metadata
      $gateways = $this->getGateways();
      $paymentTitle = '';
      foreach ($gateways as $gateway) {
        if ($gateway->id == $paymentId) {
          $paymentTitle = $gateway->title;
        }
      }

      // Set the metadata for payment
      update_post_meta($this->orderId, '_payment_method', $paymentId);
      update_post_meta($this->orderId, '_payment_method_title', $paymentTitle);

    } else {
      throw new \Exception('Please call initializeOrder() before setting the payment id');
    }
  }

  /**
   * @return array returns all registered payment gateways
   */
  public function getGateways()
  {
    global $woocommerce;
    $gateways = $woocommerce->payment_gateways();
    return $gateways->payment_gateways;
  }

  /**
   * Adds a product to the order
   * @param int $productId the product to add
   * @param string $quantity the quantity of the product
   * @param array $variation the optional variation of the product
   */
  public function addProduct($productId, $quantity, $variation = array(), $taxclass = '', $checkStock = true)
  {
    // Did the order already initialize?
    if ($this->orderId == 0) {
      $this->initializeOrder();
    }

    $quantity = apply_filters('woocommerce_stock_amount', $quantity);
    $product = new \WC_Product_Subscription($productId);
    if (!$checkStock || $product->get_stock_quantity() >= $quantity) {
      // Add line item
      $order = new \WC_Order($this->orderId);
      $order->add_product($product, 1);
      $order->calculate_totals(true);
    } else {
      $this->errorHandler('no-stock');
    }
  }

  /**
   * Set the billing address
   * @param string $firstname the firstname
   * @param string $lastname the lastname
   * @param string $street the street
   * @param string $zip zip of the city
   * @param string $city the city
   * @param string $email email address
   * @param string $company company name
   * @param string $address address addition
   */
  public function setBillingAddress($firstname, $lastname, $street, $zip, $city, $email = '', $company = '', $address = '')
  {
    if ($this->orderId > 0) {
      $this->setAddress('_billing', $firstname, $lastname, $street, $zip, $city, $email, $company, $address);
    }
  }

  /**
   * Set the billing address
   * @param string $firstname the firstname
   * @param string $lastname the lastname
   * @param string $street the street
   * @param string $zip zip of the city
   * @param string $city the city
   * @param string $email email address
   * @param string $company company name
   * @param string $address address addition
   */
  public function setShippingAddress($firstname, $lastname, $street, $zip, $city, $email = '', $company = '', $address = '')
  {
    if ($this->orderId > 0) {
      $this->setAddress('_shipping', $firstname, $lastname, $street, $zip, $city, $email, $company, $address);
    }
  }

  /**
   * Set the billing address
   * @param string $prefix the metadata prefix
   * @param string $firstname the firstname
   * @param string $lastname the lastname
   * @param string $street the street
   * @param string $zip zip of the city
   * @param string $city the city
   * @param string $email email address
   * @param string $company company name
   * @param string $address address addition
   */
  protected function setAddress($prefix, $firstname, $lastname, $street, $zip, $city, $email, $company, $address)
  {
    if (strlen(trim($firstname)) > 0) {
      update_post_meta($this->orderId, $prefix . '_first_name', $firstname);
    }
    if (strlen(trim($lastname)) > 0) {
      update_post_meta($this->orderId, $prefix . '_last_name', $lastname);
    }
    if (strlen(trim($street)) > 0) {
      update_post_meta($this->orderId, $prefix . '_address_1', $street);
    }
    if (strlen(trim($zip)) > 0) {
      update_post_meta($this->orderId, $prefix . '_postcode', $zip);
    }
    if (strlen(trim($city)) > 0) {
      update_post_meta($this->orderId, $prefix . '_city', $city);
    }
    if (strlen(trim($email)) > 0) {
      update_post_meta($this->orderId, $prefix . '_email', $email);
    }
    if (strlen(trim($company)) > 0) {
      update_post_meta($this->orderId, $prefix . '_company', $company);
    }
    if (strlen(trim($address)) > 0) {
      update_post_meta($this->orderId, $prefix . '_address_2', $address);
    }
  }

  /**
   * Finishes the order and returns the order id
   * @return int the order id of the finished order
   */
  public function finishOrder()
  {
    $order = new \WC_Order($this->orderId);

    $this->registerFilters();
    $this->isManualOrder = true;
    $order->payment_complete();
    $order->update_status('completed');
    $this->isManualOrder = false;
    $this->unregisterFilters();

    return $this->orderId;
  }

  /**
   * Registers all filters for the email recipients
   */
  protected function registerFilters()
  {
    add_filter('woocommerce_email_recipient_customer_completed_order', array($this, 'filterRecipientEmailAddress'));
    add_filter('woocommerce_email_recipient_customer_processing_order', array($this, 'filterRecipientEmailAddress'));
    add_filter('woocommerce_email_recipient_customer_customer_invoice', array($this, 'filterRecipientEmailAddress'));
    add_filter('woocommerce_email_recipient_wootickets', array($this, 'filterRecipientEmailAddress'));
  }

  /**
   * Unregisters all filters after the emails were sent
   */
  protected function unregisterFilters()
  {
    remove_filter('woocommerce_email_recipient_customer_completed_order', array($this, 'filterRecipientEmailAddress'));
    remove_filter('woocommerce_email_recipient_customer_processing_order', array($this, 'filterRecipientEmailAddress'));
    remove_filter('woocommerce_email_recipient_customer_customer_invoice', array($this, 'filterRecipientEmailAddress'));
    remove_filter('woocommerce_email_recipient_wootickets', array($this, 'filterRecipientEmailAddress'));
  }

  /**
   * Returns a empty string if the recipient should be nothing
   *
   * @param string $recipient
   * @return string
   */
  public function filterRecipientEmailAddress($recipient)
  {
    if ($this->isManualOrder) {
      return '';
    }

    return $recipient;
  }

  /**
   * Redirects to an error page, if configured or to a default error page
   * @param string $id key in errorUrls, if not found, default error
   * @throws \Exception if there are no error urls configured
   */
  protected function errorHandler($id)
  {
    if ($this->redirectOnErrors) {
      if (isset($this->errorUrls[$id])) {
        $redirectUrl = $this->errorUrls[$id];
      } else {
        $redirectUrl = get_permalink() .'?manualOrderError=' . $id;
      }

      header('Location: ' . $redirectUrl);
      exit;
    } else {
      throw new \Exception(__CLASS__ . ' threw an error: ' . $id);
    }
  }
}