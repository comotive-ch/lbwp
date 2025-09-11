<?php

namespace LBWP\Helper\WooCommerce;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Various small woocommerce util functions
 * @package LBWP\Helper\WooCommerce
 * @author Michael Sebel <michael@comotive.ch>
 */
class Util
{
  /**
   * @var string[]
   */
  protected static $formalTranslations = array('woocommerce','woocommerce-subscriptions');
  /**
   * @var null
   */
  protected static $isHposActive = null;
  /**
   * @var bool
   */
  protected static $isHposCompatible = null;

  /**
   * Disabled shipping cache and lets calculate the rates every time
   */
  public static function disableShippingCache()
  {
    add_action('wp', function () {
      if (WC()->cart instanceof \WC_Cart) {
        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $key => $value) {
          $shipping_session = 'shipping_for_package_' . $key;
          unset(WC()->session->$shipping_session);
        }
      }
    });
  }

  public static function setHposIncompatible()
  {
    if (self::$isHposCompatible === null) {
      add_action('before_woocommerce_init', function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
          \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', 'lbwp/lbwp.php', false);
        }
      });
      self::$isHposCompatible = false;
    }
  }

  public static function isHposActive()
  {
    if (self::$isHposActive === null) {
      self::$isHposActive = wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled();
    }

    return self::$isHposActive;
  }

  /**
   * @param \WC_Order $order
   * @return array
   */
  public static function getDeliveryAddressWithFallback($order)
  {
    $row = array();
    if ($order->get_billing_address_1() !== $order->get_shipping_address_1() && !empty($order->get_shipping_address_1())) {
      // Use shipping if given
      $row['firstname'] = $order->get_shipping_first_name();
      $row['lastname'] = $order->get_shipping_last_name();
      $row['company'] = $order->get_shipping_company();
      $row['street'] = $order->get_shipping_address_1();
      $row['addition'] = $order->get_shipping_address_2();
      $row['postcode'] = $order->get_shipping_postcode();
      $row['city'] = $order->get_shipping_city();
    } else {
      $row['firstname'] = $order->get_billing_first_name();
      $row['lastname'] = $order->get_billing_last_name();
      $row['company'] = $order->get_billing_company();
      $row['street'] = $order->get_billing_address_1();
      $row['addition'] = $order->get_billing_address_2();
      $row['postcode'] = $order->get_billing_postcode();
      $row['city'] = $order->get_billing_city();
    }

    return array_filter($row);
  }

  /**
   * @return void
   */
  public static function addQueryTransactionHelper()
  {
    add_filter('woocommerce_order_query_args', function ($args) {
      wp_cache_start_transaction();
      return $args;
    });
    add_filter('woocommerce_order_query', function ($results) {
      wp_cache_commit_transaction();
      return $results;
    });
  }

  /**
   * Forces woocommerce to load its formal translations when called within "setup"
   * @return void
   */
  public static function useFormalTranslations()
  {
    add_action('plugin_locale', function ($locale, $domain) {
      if ($locale == 'de_DE' && in_array($domain, self::$formalTranslations)) {
        $locale .= '_formal';
      }
      return $locale;
    }, 10, 2);

    // In some cases, switch locale and reload the plugin domain of "toilet"
    if (get_locale() == 'de_DE' && $_SERVER['REQUEST_METHOD'] == 'POST') {
      switch_to_locale('de_DE_formal');
      load_plugin_textdomain('woocommerce');
    }
  }

  /**
   * @param bool $apply tell if the filter should be applied, usefull to generate calls to this function with is_cart() && is_checkout() and the likes
   * @return void
   */
  public static function setDefaultCountryOnly($apply)
  {
    if ($apply) {
      add_filter('woocommerce_get_base_location', function ($location) {
        return substr($location, 0, 2);
      });
    }
  }

  /**
   * WARN: Not HPOS compatible, only use in compat mode!
   * @param int $user_var
   * @param int $product_ids
   * @return bool
   */
  public static function hasBoughtProducts($user_var = 0, $product_ids = 0)
  {
    global $wpdb;

    // Based on user ID (registered users)
    if (is_numeric($user_var)) {
      $meta_key = '_customer_user';
      $meta_value = $user_var == 0 ? (int)get_current_user_id() : (int)$user_var;
    } // Based on billing email (Guest users)
    else {
      $meta_key = '_billing_email';
      $meta_value = sanitize_email($user_var);
    }

    $paid_statuses = array_map('esc_sql', wc_get_is_paid_statuses());
    $product_ids = is_array($product_ids) ? implode(',', $product_ids) : $product_ids;

    $line_meta_value = $product_ids != (0 || '') ? 'AND woim.meta_value IN (' . $product_ids . ')' : 'AND woim.meta_value != 0';

    // Count the number of products
    $count = $wpdb->get_var("
        SELECT COUNT(p.ID) FROM {$wpdb->prefix}posts AS p
        INNER JOIN {$wpdb->prefix}postmeta AS pm ON p.ID = pm.post_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON p.ID = woi.order_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS woim ON woi.order_item_id = woim.order_item_id
        WHERE p.post_status IN ( 'wc-" . implode("','wc-", $paid_statuses) . "' )
        AND pm.meta_key = '$meta_key'
        AND pm.meta_value = '$meta_value'
        AND woim.meta_key IN ( '_product_id', '_variation_id' ) $line_meta_value 
    ");

    // Return true if count is higher than 0 (or false)
    return $count > 0 ? true : false;
  }

  /**
   * @param $countryList
   * @return void
   */
  public static function forceDeliveryAddressForCountries($countryList)
  {
    // Add a script that will reload the UI when the gateway is changed
    add_action('woocommerce_review_order_before_payment', function () use ($countryList) {
      ?>
      <script type="text/javascript">
        function lbwp_forceDeliveryAddressForCountries() {
          var countryList = <?php echo json_encode($countryList); ?>;
          // Get current billing country selected
          var selectedCountry = jQuery('#billing_country').val();
          // If the selected country is not on our list, we need a delivery address
          var needsDeliveryAddress = jQuery.inArray(selectedCountry, countryList) < 0;

          // Wenn needed, force a delivery address and make the field readonly to not unselected it
          if (needsDeliveryAddress) {
            var checkbox = jQuery('#ship-to-different-address-checkbox');
            if (!checkbox.is(':checked')) {
              checkbox.trigger('click');
            }
            checkbox.hide();
            jQuery('#ship-to-different-address label').on('click', function () {
              return false;
            });
          } else {
            // If no more delivery address is needed, remove the readonly state
            jQuery('#ship-to-different-address-checkbox').show();
            jQuery('#ship-to-different-address label').off('click');
          }
        }

        // Run once and on every billing country change
        jQuery(function () {
          lbwp_forceDeliveryAddressForCountries();
          jQuery('#billing_country').on('change', lbwp_forceDeliveryAddressForCountries);
        });
      </script>
      <?php
    });
  }

  /**
   * @param array $gateways
   * @param float $percent
   * @param string $name
   */
  public static function addPaymentGatewayFee($gateways, $percent, $name, $taxable = true)
  {
    add_action('woocommerce_cart_calculate_fees', function () use ($gateways, $percent, $name, $taxable) {
      $currentGateway = WC()->session->get('chosen_payment_method');
      if (in_array($currentGateway, $gateways)) {
        $total = (float)WC()->cart->get_cart_contents_total();
        $fee = (($total * (100 + $percent)) / 100) - $total;
        WC()->cart->add_fee($name . ' (' . $percent . '%)', $fee, $taxable);
      }
    });

    // Add a script that will reload the UI when the gateway is changed
    add_action('woocommerce_review_order_before_payment', function () {
      ?>
      <script type="text/javascript">
        (function ($) {
          $('form.checkout').on('change', 'input[name^="payment_method"]', function () {
            $('body').trigger('update_checkout');
          });
        })(jQuery);
      </script>
      <?php
    });
  }

  /**
   * @param string $orderStatus eg cancelled (storniert, wc- is adapted when needed)
   * @param string $subscriptionStatus eg expired (abgelaufen) or cancelled (gekündigt)
   */
  public static function autoTransitionSubscriptionOrder($orderStatus, $subscriptionStatus)
  {
    add_action('woocommerce_order_edit_status', function ($orderId, $status) use ($orderStatus, $subscriptionStatus) {
      // If order status matched with the desired order status
      if ($status == $orderStatus) {
        // Get subscriptions for that order
        $subscriptions = wcs_get_subscriptions_for_order($orderId);
        // If available, set the subscription status to the desired one
        foreach ($subscriptions as $subscription) {
          $subscription->set_status($subscriptionStatus, 'automatically cancelled due to order cancellation', false);
          $subscription->save();
        }
      }
    }, 10, 2);
  }

  /**
   * When an order is completed after it was cancelled before, reactivate connected subscriptions
   */
  public static function autoReactivateCancelledSubscription()
  {
    add_action('woocommerce_order_edit_status', function ($orderId, $status) {
      $order = wc_get_order($orderId);
      // When status changes frommm cancelled to completed
      if ($order->get_status() == 'cancelled' && $status == 'completed') {
        $subscriptionId = intval(get_post_meta($orderId, '_subscription_renewal', true));
        /** @var \WC_Subscription $subscription */
        $subscription = wcs_get_subscription($subscriptionId);
        // If the subscription is expired, make it active again
        if ($subscription->get_status() == 'expired') {
          $subscription->set_status('active', 'automatically reactivated due to corresponding order being changed from cancelled to completed', false);
          // Calculate next payment date
          $timestamp = current_time('timestamp');
          $interval = $subscription->get_billing_interval();
          switch ($subscription->get_billing_period()) {
            case 'day':
              $timestamp += (1 * 86400) * $interval;
              break;
            case 'week':
              $timestamp += (7 * 86400) * $interval;
              break;
            case 'month':
              $timestamp += (30 * 86400) * $interval;
              break;
            case 'year':
              $timestamp += (365 * 86400) * $interval;
              break;
          }
          // Set the new renewal date
          $subscription->update_dates(array('next_payment' => date('Y-m-d h:i:s', $timestamp)));
          $subscription->save_dates();
          $subscription->save();
        }
      }
    }, 10, 2);
  }

  /**
   * Changes status from $status to $change on orders that are older than $days
   * @param string $status the orders by status that should be checked
   * @param string $change the new status of the orders, when number of days is reached
   * @param string $days number of days
   */
  public static function autoChangeOrdersByStatus($status, $change, $days)
  {
    add_action('cron_daily_2', function () use ($status, $change, $days) {
      // Get all orders with the certain status
      $now = new \DateTime(current_time('mysql'));
      $orders = wc_get_orders(array(
        'status' => array('wc-' . $status),
        'limit' => -1
      ));

      // Loop trough each order and maybe change his state if days match exactly
      foreach ($orders as $order) {
        $date = $order->get_date_created();
        $interval = $date->diff($now);
        if ($interval->format('%a') == $days) {
          // Change the order state manually
          $order->set_status($change, 'automatically changed state to ' . $change . ' after ' . $days . ' days', true);
          $order->save();
        }
      }
    });
  }

  /**
   * @param $css
   */
  public static function addEmailCss($css)
  {
    add_filter('woocommerce_email_styles', function ($styles) use ($css) {
      return $styles . PHP_EOL . $css;
    });
  }

  /**
   * Allows to round kaufmännisch in woocommerce cart totals
   */
  public static function addTotalCartRound()
  {
    // Filter on prio 1001 to omit subscriptions on prio 1000
    add_filter('woocommerce_calculated_total', function ($total) {
      return round(($total + 0.000001) * 20) / 20;
    }, 1001);
  }

  /**
   * @return void
   */
  public static function allowHigherExporterLimits($timelimit = 120, $maxmemory = '1024M', $omitcache = true)
  {
    if (isset($_REQUEST['page']) && $_REQUEST['page'] == 'woo_ce' && isset($_REQUEST['tab'])) {
      set_time_limit($timelimit);
      ini_set('memory_limit', $maxmemory);
      if ($omitcache) {
        global $wp_object_cache;
        $wp_object_cache->can_write = false;
      }
    }
  }

  /**
   * Check for the cart resetter, and run it if needed
   */
  public static function initCartSessionResetter()
  {
    if (isset($_GET['wc_cart_reset'])) {
      HTMLCache::avoidCache();
      $time = intval($_GET['wc_cart_reset']);
      if ($time + 600 > current_time('timestamp') || $time === 1) {
        if (!isset(WC()->cart) || '' === WC()->cart)
          WC()->cart = new WC_Cart();
        // Make sure to clean the cart for logged in (persistent) carts and non logged in carts
        WC()->cart->empty_cart(true);
      }
    }
  }

  /**
   * In a caching function in WCS they return false or empty array instead of an empty string, which causes
   * some subscriptions not to show. Luckily there's a filter to fix that bug of wcs :-).
   */
  public static function fixInvalidTransientReturnValue()
  {
    add_filter('wcs_get_cached_users_subscription_ids', function ($subscriptions, $userId) {
      if (is_array($subscriptions) && count($subscriptions) == 0 || $subscriptions === false) {
        return '';
      }
      return $subscriptions;
    }, 100, 2);
  }

  /**
   * Syncs main user name and display fields from current billing address
   * @param int $userId
   */
  public static function syncUserNameFromBillingAddress($userId)
  {
    $firstName = get_user_meta($userId, 'billing_first_name', true);
    $lastName = get_user_meta($userId, 'billing_last_name', true);
    // If given, update main user display name
    if (strlen($firstName) > 0 && strlen($lastName) > 0) {
      update_user_meta($userId, 'first_name', $firstName);
      update_user_meta($userId, 'last_name', $lastName);
      update_user_meta($userId, 'display_name', $firstName . ' ' . $lastName);
    }
  }

  /**
   * Hide shipping est message
   */
  public static function hideShippingEstimateMessage()
  {
    add_filter('woocommerce_shipping_estimate_html', '__return_empty_string');
  }

  /**
   * Adds the resetter param for the cart to be resetted to the url
   */
  public static function addCartResetterParam($url)
  {
    return Strings::attachParam('wc_cart_reset', current_time('timestamp'), $url);
  }

  /**
   * @return bool
   */
  public static function isWoocommerceActive()
  {
    return function_exists('WC');
  }

  /**
   * @param int $id the product id
   * @return string json ld metadata
   */
  public static function getProductMetaJson($id)
  {
    /** @var \WC_Product $product can be any type of products */
    $product = wc_get_product($id);
    $url = get_permalink($id);
    $currency = get_woocommerce_currency();
    $price = number_format($product->get_price(), 2);


    return json_encode(array(
      '@context' => 'https://schema.org/',
      '@type' => 'Product',
      '@id' => $url,
      'name' => $product->get_name(),
      'url' => $url,
      'description' => $product->get_description(),
      'image' => get_the_post_thumbnail_url($id),
      'sku' => $product->get_sku(),
      'offers' => array(array(
        '@type' => 'Offer',
        'price' => $price,
        'priceValidUntil' => (date('Y') + 1) . '-12-31',
        'priceSpecification' => array(
          'price' => $price,
          'priceCurrency' => $currency,
          'valueAddedTaxIncluded' => wc_prices_include_tax()
        ),
        'priceCurrency' => $currency,
        'availability' => 'http://schema.org/InStock',
        'url' => $url,
        'seller' => array(
          '@type' => 'Organization',
          'name' => get_bloginfo('name'),
          'url' => get_bloginfo('url')
        )
      ))
    ));
  }
}