<?php

namespace LBWP\Aboon\Component;

use Banholzer\Component\Shop;
use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Module\Forms\Item\HtmlItem;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Request an offer for the cart content
 * @package Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class OfferRequest extends Component{
  /**
   * @var null|array with patterns or email addresses to disable the request form
   */
  private static $mailBlacklist = null;

  private string $sendToMail;

  public function init()
  {
    $this->sendToMail = apply_filters('aboon_send_offer_to_mail', get_bloginfo('admin_email'));

    add_action('wp_footer', array($this, 'offerRequestModal'));
    add_action('woocommerce_cart_actions', array($this, 'addOfferButton'));
    add_action('lbwp_form_save_dynamic_send_offer_mail', array($this, 'sendMail'), 10, 3);
  }

  private static function show(){
    return is_cart() && (!is_user_logged_in() || !Shop::isPrivateCustomer()) && self::filterByMail();
  }

  /**
   * @return bool
   */
  private static function filterByMail(){
    $result = true;

    if(is_array(self::$mailBlacklist)) {
      $userMail = get_user_meta(get_current_user_id(), 'billing_email', true);

      if ($userMail !== false) {
        foreach(self::$mailBlacklist as $pattern){
          if(fnmatch($pattern, $userMail)){
            $result = false;
            break;
          }
        }
      }
    }

    return $result;
  }

  /**
   * @param $patterns array with the patterns to check
   * @return void
   */
  public static function setMailFilter($patterns){
    self::$mailBlacklist = $patterns;
  }

  /**
   * The HTML of the modal
   * @return void
   */
  public function offerRequestModal(){
    if(!self::show()){
      return;
    }

    $userIsLoggedIn = is_user_logged_in();
    $userId = get_current_user_id();

    if($userIsLoggedIn){
      $userMeta = WordPress::getAccessibleUserMeta($userId);
      $_POST['company'] = $userMeta['billing_company'];
      $_POST['lastname'] = $userMeta['billing_last_name'];
      $_POST['firstname'] = $userMeta['billing_first_name'];
      $_POST['email'] = $userMeta['billing_email'];
      $_POST['phone'] = $userMeta['billing_phone'];
      $_POST['customer_id'] = $userMeta['billing_customer_id'];
      $_POST['user_id'] = $userId;
    }

    echo '<div class="offer-request-modal' . ($userIsLoggedIn ? ' logged-in' : '') . '">
      <div class="modal__back"></div>
      <div class="modal__container">
        <div class="modal__content">
          <h3>' . __('Offerte anfordern', 'banholzer') . '</h3>
          <p>' . __('Weitere Informationen zur Anfrage gerne hier eingeben.', 'banholzer') . '</p>
          <div class="modal__close">' . $this->icon('offer-request-close') . '</div>
          ' . do_shortcode('[' . FormHandler::SHORTCODE_DISPLAY_FORM . ' id="' . BANHOLZER_OFFER_REQUEST_FORM_ID . '"]') . '
        </div>
        <div class="modal__footer">
        </div>
      </div>
    </div>';
  }

  /**
   * @param $name
   * @return mixed|null
   */
  protected function icon($name)
  {
    $icon = '';
    switch ($name) {
      case 'offer-request-close': $icon = '<i class="fal fa-times"></i>'; break;
    }

    return apply_filters('aboon_general_icon_filter', $icon, $name);
  }

  /**
   * Adds offer request button on the cart page
   * @return void
   */
  public function addOfferButton(){
    if(!self::show()){
      return;
    }

    echo '<button type="submit" class="button wp-element-button" name="request_offer" id="request-offer-btn">' . __('Offerte anfordern', 'banholzer') . '</button>';
  }

  /**
   * Gets cart by user id
   * @param $userId
   * @return string
   */
  private function getCart($userId){
    $html = '<table cellpadding="4px"><tr style="background: #006161; color: #fff; text-align: left;">
      <th><b>Produkt</b></th>
      <th><b>SKU</b></th>
      <th><b>Menge</b></th>
      <th><b>Stückpreis</b></th>
    </tr>';

    foreach(WC()->cart->get_cart() as $item){
      $product = $item['data'];

      $html .= '<tr>
        <td style="padding: 4px; border-bottom: 1px solid #006161;">' . $product->get_title() . '</td>
        <td style="padding: 4px; border-bottom: 1px solid #006161;">' . $product->get_sku() . '</td>
        <td style="padding: 4px; border-bottom: 1px solid #006161;">' . $item['quantity'] . '</td>
        <td style="padding: 4px; border-bottom: 1px solid #006161;">' . WC()->cart->get_product_price($product) . '</td>
      </tr>';
    }

    $html .= '</table>';

    return $html;
  }

  /**
   * Sends the offer request email
   * @param $success
   * @param $data
   * @param $param
   * @return mixed
   * @throws \PHPMailer\PHPMailer\Exception
   */
  public function sendMail($success, $data, $param){
    $content = '';
    $userId = 0;

    foreach ($data as $item) {
      if($item['item']->get('id') === 'user_id'){
        $userId = $item['item']->getValue();
        continue;
      }

      $content .= '<b>' . $item['item']->get('feldname') . ':</b> ' . $item['item']->getValue() . '<br>';
    }

    $content .= '<b>Warenkorb:</b> ' . $this->getCart($userId);

    $mail = External::PhpMailer();
    $mail->Subject = get_bloginfo('name') . ' - Offerte Anfrage';
    $mail->Body = $content;
    $mail->AddAddress($param);
    $mail->AddAddress($this->sendToMail);
    $mail->send();

    return $success;
  }
}