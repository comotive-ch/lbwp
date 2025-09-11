<?php

namespace LBWP\Theme\Component;

use CXml\CXmlParser;
use CXml\Models\CXml;
use CXml\Models\Header;
use CXml\Models\Messages\ItemIn;
use CXml\Models\Messages\PunchOutOrderMessage;
use CXml\Models\Messages\PunchOutOrderMessageHeader;
use CXml\Models\Requests\PunchOutSetupRequest;
use CXml\Models\Responses\PunchOutSetupResponse;
use CXml\Models\Responses\Status;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Module\General\MaintenanceMode;
use LBWP\Theme\Base\Component as BaseComponent;

/**
 * Component for defautl PunchOut XML
 * @package LBWP\Theme\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class CXMLPunchOut extends BaseComponent{
  public function setup(){
    // Avoid maintenance mode
    setcookie('MMValidLogin', MaintenanceMode::COOKIE_HASH, time() + MaintenanceMode::COOKIE_EXPIRE, '/', LBWP_HOST);
    $_COOKIE['MMValidLogin'] = MaintenanceMode::COOKIE_HASH;
  }

  public function init(){
    if (isset($_GET['lbwp-cxml-punchout'])) {
      $this->sendPunchOutResponse();
    }

    if (isset($_GET['punchout_start'])) {
      $_SESSION['punchout_data'] = wp_cache_get($_GET['punchout_start'], 'punchout_security_hash');

      $this->loginPunchOutUser();
    }

    if(isset($_SESSION['punchout_data'])) {
      add_action('woocommerce_after_cart_totals', [$this, 'renderPunchOutCart']);
    }

    add_action('rest_api_init', [$this, 'registerApiRoutes']);
  }

  /**
   * Register API route for PunchOut to create WooCommerce order
   * @return void
   */
  public function registerApiRoutes() {
    register_rest_route('cxml/v1', '/create-order', array(
      'methods'  => 'POST',
      'callback' => [$this, 'createWoocommerceOrder'],
      'permission_callback' => '__return_true', // Adjust security as needed
    ));
  }

  /**
   * Generate a start page URL with a hash and stores it in the cache
   * @param $user
   * @param $password
   * @param $buyerCookie
   * @param $postUrl
   * @param $fromIdentity
   * @return string the generated URL
   */
  public function generateStartPageUrl($user, $password, $buyerCookie, $postUrl, $fromIdentity){
    // Store data in your database
    $data = [
      'user' => $user,
      'password' => $password,
      'buyerCookie' => $buyerCookie,
      'postUrl' => $postUrl,
      'fromIdentity' => $fromIdentity,
    ];

    // Generate a hash
    $hash = md5(json_encode($data));
    // Set hash to later give access to the user when he comes back
    wp_cache_set($hash, $data, 'punchout_security_hash', 3600);

    // Create a URL with the hash
    return get_bloginfo('url') . '?punchout_start=' . $hash;
  }

  /**
   * Response to PunchOut request and save user data
   * @return void
   * @throws \Exception
   */
  public function sendPunchOutResponse(){
    require ABSPATH . 'wp-content/plugins/lbwp/resources/libraries/cxml/vendor/autoload.php';

    // Parse incoming cXML
    $requestXml = file_get_contents("php://input");

    SystemLog::mDebug('PunchOut Received', $requestXml);

    // Parse request XML (PunchOutSetupRequest)
    $xmlParser = new CXmlParser();
    $cXmlRequest = $xmlParser->parse($requestXml);

    /** @var PunchOutSetupRequest $setupRequest */
    $setupRequest = $cXmlRequest->getRequests()[0] ?? null;

    // Check request
    if (!$setupRequest || !$setupRequest instanceof PunchOutSetupRequest) {
      SystemLog::mDebug('PunchOut Received: Invalid request', $setupRequest);
    }

    // Get credentials
    $user = $cXmlRequest->getHeader()->getSenderIdentity();
    $password = $cXmlRequest->getHeader()->getSenderSharedSecret();

    $xml = new \SimpleXMLElement($requestXml);
    $fromHeader = $xml->xpath('Header/From/Credential/Identity');
    $fromIdentity = '';

    if(is_array($fromHeader) && isset($fromHeader[0])){
      $fromIdentity = (string)$fromHeader[0];
    }

    // Get punchout data
    $buyerCookie = $setupRequest->getBuyerCookie();
    $postUrl = $setupRequest->getBrowserFormPostUrl();

    // Create startPageUrl (store submitted data in your database and generate a login URL with a hash)
    $startPageUrl = $this->generateStartPageUrl($user, $password, $buyerCookie, $postUrl, $fromIdentity);

    // Create cXML envelope and status
    $cXml = $cxml = new CXml();
    $cxml->setPayloadId(time() . '@' . get_bloginfo('url'));
    $cXml->addResponse(new Status());

    // Create PunchOutSetupResponse
    $response = new PunchOutSetupResponse();
    $response->setStartPageUrl($startPageUrl);
    $cXml->addResponse($response);

    // Return response XML
    header('Content-Type: text/xml');
    echo $cXml->render();
    exit;
  }

  /**
   * Render PunchOut form in cart to proceed to checkout (in punchout)
   * @return void
   */
  public function renderPunchOutCart(){
    require ABSPATH . 'wp-content/plugins/lbwp/resources/libraries/cxml/vendor/autoload.php';

    $userData = $_SESSION['punchout_data'];

    // XML envelope
    $cXml = new CXml();
    $cXml->setPayloadId(time() . '@' . get_bloginfo('url'));
    $cXml->setHeader(new Header());

    // Message
    $message = (new PunchOutOrderMessage())
      ->setBuyerCookie($userData['buyerCookie'])
      ->setCurrency(get_woocommerce_currency())
      ->setLocale(get_locale());
    $cXml->addMessage($message);

    $cart = WC()->cart;

    // Message header
    $header = (new PunchOutOrderMessageHeader())
      ->setTotalAmount(floatval($cart->get_total('edit')))
      ->setShippingCost($cart->get_shipping_total())
      ->setShippingDescription('Shipping cost')
      ->setTaxSum($cart->get_total_tax())
      ->setTaxDescription('Tax value');
    $message->setHeader($header);

    // Item (not sure if needed)
    foreach ($cart->get_cart() as $cartItemKey => $cartItem) {
      $product = $cartItem['data'];
      $articleNum = $product->get_sku();
      $unspscNum = $product->get_meta('unspsc');
      $unspscNum = apply_filters('lbwp_punchout_default_unspsc', $unspscNum, $product);

      $item = (new ItemIn())
        ->setQuantity(intval($cartItem['quantity']))
        ->setSupplierPartId($articleNum)
        ->setUnitPrice($product->get_price())
        ->setDescription($product->get_name())
        ->setUnitOfMeasure('EA') // Must be one of UN/CEFACT codes, EA = each
        ->setClassificationDomain('UNSPSC')
          ->setClassification($unspscNum);

        $manufacturer = $product->get_meta('gtin');
        if($manufacturer === '' || $manufacturer === null){
          $manufacturer = $product->get_meta('provider-sku');
        }

        if($manufacturer !== '' && $manufacturer !== null){
          $item->setManufacturerName($manufacturer);
        }

      $message->addItem($item);
    }

    // Render
    $xml_string = $cXml->render();

    // Send cXML to JAGGAER over form post
    $formHtml = '<html lang="de">
      <body>
        <form method=post action="' . $userData['postUrl'] . '">
          <input type="hidden" name="cxml-urlencoded" value="' . esc_attr($xml_string) . '">
          <input class="checkout-button button alt wc-forward punchout-proceed-button" type=submit value="' . __('Weiter zur Kasse', 'lbwp') . '">
        </form>
      </body>
    </html>
    <script>
      let wcButton = document.querySelector(".wc-proceed-to-checkout");
      if (wcButton) {
        wcButton.remove();
      }
    </script>
    <style>.wc-proceed-to-checkout{display: none;}</style>';

    echo $formHtml;
  }

  /**
   * Create WooCommerce order from PunchOut data and echo punchout cxml
   * @param \WP_REST_Request $request
   * @return void it echoes the cxml response
   */
  public function createWoocommerceOrder(\WP_REST_Request $request) {
    require ABSPATH . 'wp-content/plugins/lbwp/resources/libraries/cxml/vendor/autoload.php';
    SystemLog::mDebug('PunchOut Order Received', $request->get_body());

    // Parse request XML
    $xml = new \SimpleXMLElement($request->get_body());

    /** @var PunchOutSetupRequest $setupRequest */
    //$order_data = $cXmlRequest->getRequests()[0] ?? null;
    //var_dump($order_data);
    $items = $xml->xpath('request/orderrequest/itemout');

    if(empty($items)){
      $items = $xml->xpath('Request/OrderRequest/ItemOut');
    }

    if(empty($items)){
      SystemLog::mDebug('PunchOut Order Received: No items found', $xml);
      return false;
    }

    $order = wc_create_order();

    foreach ($items as $item) {
      $sku = (int) $item->xpath('ItemID/SupplierPartID')[0];
      $product_id = wc_get_product_id_by_sku($sku); // Find product by SKU
      $quantity = (int) $item->attributes()['quantity'];

      if ($product_id) {
        $order->add_product(wc_get_product($product_id), $quantity);
      }
    }

    // Set order details
    $customerId = apply_filters('lbwp_punchout_customer_id', 0, $request);
    $order->set_customer_id($customerId);
    $order->set_status('processing');

    // Set default woocommerce shipping cost
    $shippingItem = apply_filters('lbwp_punchout_shipping_cost', null, $order);

    if($shippingItem !== null){
      $order->add_item($shippingItem);
    }

    // TODO Setting address is not needed specifically, as depending on user (but should be set for SAP)
    //$order->set_address($order_data['billing'], 'billing');
    //$order->set_address($order_data['shipping'], 'shipping');

    $order->calculate_totals();
    $order->save();

    // After save, run the function that creates the SAP order from it
    do_action('lbwp_punchout_order_created', $order->get_id());

    // Empty cart after order creation
    $cart = WC()->cart;
    if(is_null($cart)){
      wc_load_cart();
      $cart = WC()->cart;
    }
    $cart->empty_cart();

    // Create cXML envelope and status
    $cXml = $cxml = new CXml();
    $cxml->setPayloadId(time() . '@' . get_bloginfo('url'));
    $cXml->addResponse(new Status());

    // Create PunchOutSetupResponse
    $response = new PunchOutSetupResponse();
    $cXml->addResponse($response);

    // Return response XML
    header('Content-Type: text/xml');
    echo $cXml->render();
  }

  /**
   * Login PunchOut user
   * @return void
   */
  private function loginPunchOutUser(){
    $hash = md5(json_encode($_SESSION['punchout_data']));
    if(isset($_GET['punchout_start']) && $_GET['punchout_start'] === $hash){
      SystemLog::mDebug('PunchOut Login', $_SESSION['punchout_data']);
      wp_set_auth_cookie(apply_filters('lbwp_punchout_customer_id', 0, $_SESSION), false, true);

      // Empty cart after login
      $cart = WC()->cart;
      if(is_null($cart)){
        wc_load_cart();
        $cart = WC()->cart;
      }
      $cart->empty_cart();
    }
  }
}