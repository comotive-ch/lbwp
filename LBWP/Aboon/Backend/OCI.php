<?php

namespace LBWP\Aboon\Backend;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provides integration for OCI shops
 * @package LBWP\Aboon\Backendt
 * @author Michael Sebel <michael@comotive.ch
 */
class OCI extends Component
{
  /**
   * Init API endpoint
   */
  public function init()
  {
    add_action('rest_api_init', array($this, 'registerApiEndpoint'));
    add_action('woocommerce_thankyou', array($this, 'sendOciPunchbackForm'));
    add_action('woocommerce_checkout_before_customer_details', array($this, 'printOciCheckoutInfo'));
    add_action('woocommerce_order_button_text', array($this, 'changeOrderButtonText'));

    // Disable woocommerce email send
    $this->disableWcMails();
  }

  /**
   * register API endpoint and its callback
   */
  public function registerApiEndpoint()
  {
    register_rest_route('aboon/oci', 'punchout', array(
      'methods' => \WP_REST_Server::READABLE,
      'callback' => array($this, 'receivePunchout')
    ));
  }

  /**
   * Check if is OCI session/logged in
   * @return bool
   */
  public static function isOCI(){
    $user = wp_get_current_user();
    return $user !== false && intval(get_user_meta($user->ID, 'lbwp_oci_active', true)) == 1;
  }

  /**
   * @return bool true if valid OCI purchase
   */
  protected function isValidOciPurchase()
  {
    // Check if even logged in
    if (!is_user_logged_in()) {
      return false;
    }

    // When logged in, is it an OCI user?
    $user = wp_get_current_user();
    if (intval(get_user_meta($user->ID, 'lbwp_oci_active', true)) != 1) {
      return false;
    }

    // Check if OCI session active and OCI hook URL is present and valid
    return Strings::checkURL($this->getUserOciHookUrl($user->ID));
  }

  /**
   * Get hook url, use session or cache as fallback if not set anymore
   * @param $userId
   * @return false|string url or false if not present
   */
  protected function getUserOciHookUrl($userId)
  {
    return $_SESSION['oci_hook_url'] ?? wp_cache_get('oci_hook_url_fallback_' . $userId, 'oci');
  }

  /**
   * @return void
   */
  public function sendOciPunchbackForm($orderId)
  {
    if (!$this->isValidOciPurchase()) {
      return;
    }

    // Get the user and order object
    $user = wp_get_current_user();
    $order = wc_get_order($orderId);

    // Build form elements for every item
    $currency = get_woocommerce_currency();
    $formHtml = '';
    $fixedIndex = 0;
    foreach ($order->get_items() as $id => $item) {
      $product = wc_get_product($item->get_product_id());
      $formHtml .= '
        <input type="hidden" name="NEW_ITEM-DESCRIPTION['.$fixedIndex.']" value="' . esc_attr($item->get_name()) . '" />
        <input type="hidden" name="NEW_ITEM-QUANTITY['.$fixedIndex.']" value="' . $item->get_quantity() . '" />
        <input type="hidden" name="NEW_ITEM-PRICE['.$fixedIndex.']" value="' . number_format($product->get_price(), 2, '.', '') . '" />
        <input type="hidden" name="NEW_ITEM-CURRENCY['.$fixedIndex.']" value="' . $currency . '" />
        <input type="hidden" name="NEW_ITEM-VENDORMAT['.$fixedIndex.']" value="' . $product->get_sku() . '" />
        <input type="hidden" name="NEW_ITEM-UNIT['.$fixedIndex.']" value="Stück" />
      ';
      ++$fixedIndex;
    }

    // Add delivery cost as well, if given
    $shippingCost = floatval($order->get_shipping_total());
    if ($shippingCost > 0) {
      $formHtml .= '
        <input type="hidden" name="NEW_ITEM-DESCRIPTION['.$fixedIndex.']" value="Versandkosten" />
        <input type="hidden" name="NEW_ITEM-QUANTITY['.$fixedIndex.']" value="1" />
        <input type="hidden" name="NEW_ITEM-PRICE['.$fixedIndex.']" value="' . number_format($shippingCost, 2, '.', '') . '" />
        <input type="hidden" name="NEW_ITEM-CURRENCY['.$fixedIndex.']" value="' . $currency . '" />
        <input type="hidden" name="NEW_ITEM-VENDORMAT['.$fixedIndex.']" value="VERSAND" />
        <input type="hidden" name="NEW_ITEM-UNIT['.$fixedIndex.']" value="Stück" />
      ';
    }

    // Print the form and script used for the OCI callback
    echo '
      <form id="oci-form" method="post" action="' . $this->getUserOciHookUrl($user->ID) . '">
        ' . $formHtml . '
      </form>
      <script>
        jQuery(function() {
          // Make standards invisible as not used
          jQuery(".woocommerce-order")
            .hide()
            .after("<p>Einen Moment, wir leiten Sie zurück in Ihr System.</p>");
          jQuery("#oci-form").submit();
        });
      </script>
    ';
  }

  /**
   * Make the checkout a little more OCI comprehensive for the user
   * @return void
   */
  public function printOciCheckoutInfo()
  {
    $user = wp_get_current_user();
    $oci = $user !== false && intval(get_user_meta($user->ID, 'lbwp_oci_active', true)) == 1;
    // Do nothing here, if no OCI is active
    if (!$oci) {
      return;
    }

    echo apply_filters('aboon_checkout_oci_message_html', '
      <p>
        Ihre Bestellung wird nach Abschluss auf dieser Seite an Ihr System weitergeleitet. 
        Nach definitiver Freigabe im Zielsystem wird die Bestellung bei uns ausgelöst.
      </p>
    ');

    // Also add some css to hide unneeded fields in this context
    echo '
      <style>
        #customer_details { display:none; }
        ul.wc_payment_methods { display:none; }
      </style>
    ';
  }

  /**
   * Register a punchout from OCI
   */
  public function receivePunchout()
  {
    $db = WordPress::getDb();
    $errors = array();
    $userId = intval($_GET['user']);
    $key = Strings::forceSlugString($_GET['key']);
    $hook = $_GET['HOOK_URL'];

    // Check if the user's key is valid
    $checkId = intval($db->get_var('
      SELECT user_id FROM ' . $db->usermeta . '
      WHERE meta_key = "lbwp_oci_key" AND meta_value = "' . $key . '"
    '));

    if ($checkId !== $userId) {
      $errors[] = 'user id and key did not match';
    }

    // Also check if the user is active
    $checkId = intval($db->get_var('
      SELECT user_id FROM ' . $db->usermeta . '
      WHERE user_id = "' . $userId . '" AND meta_key = "lbwp_oci_active" AND meta_value = "1"
    '));

    if ($checkId !== $userId) {
      $errors[] = 'user id is not allowed to use OCI checkout';
    }

    if (!apply_filters('aboon_validate_oci_hook_url', true, $hook)) {
      $errors[] = 'hook url syntax is not know or not allowed';
    }

    // If anything is wrong with the hook or the user, show error
    if (count($errors)) {
      return array(
        'message' => __('Fehler bei der OCI Anmeldung', 'lbwp'),
        'errors' => $errors
      );
    }

    // Everything looks nice, make sure to remember the hook
    $_SESSION['oci_hook_url'] = $hook;
    $_SESSION['is_oci_session'] = true;
    wp_cache_set('oci_hook_url_fallback_' . $userId, $hook, 'oci', 40000);
    // Login the user
    $user = get_user_by('ID', $userId);
    wp_set_auth_cookie($user->ID, false, true);
    wp_set_current_user($user->ID);
    // And redirect to the home page for the user to start shopping
    header('Location: ' . get_bloginfo('url'));
    exit;
  }

  /**
   * Disable the woocommerce emails if over OCI
   * @return void
   */
  public function disableWcMails(){
    if(!$this->isValidOciPurchase()){
      return;
    }

    $emailsId = array(
      'new_order',
      'customer_on_hold_order',
      'customer_processing_order',
      'customer_completed_order',
      'customer_refunded_order',
      'customer_partially_refunded_order',
      'cancelled_order',
      'failed_order',
      'customer_reset_password',
      'customer_invoice',
      'customer_new_account',
      'customer_note',
    );

    foreach($emailsId as $mailId){
      add_filter('woocommerce_email_enabled_' . $mailId, '__return_false', 999);
    }

    SystemLog::add('OCI Email', 'debug', 'Email desabled', 'yes');
  }

  /**
   * Change the button text (if OCI)
   * @return string
   */
  public function changeOrderButtonText($text){
    $user = wp_get_current_user();
    $oci = $user !== false && intval(get_user_meta($user->ID, 'lbwp_oci_active', true)) == 1;

    // Only change the text if is OCI
    if ($oci) {
      $text = __('Bestellung fortsetzen', 'banholzer');
    }

    return $text;
  }
}