<?php

namespace LBWP\Aboon\Base;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component;
use LBWP\Theme\Feature\SocialShare\Buttons;
use LBWP\Theme\Feature\SocialShare\SocialApis;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Helper\WooCommerce\Util;
use LBWP\Util\File;
use LBWP\Core;

class Shop extends Component
{
  /**
   * @var string
   */
  const TAX_PROPERTY = 'product_prop';
  /**
   * @var array
   */
  protected $taxConfig = array(
    'show_in_rest' => true,
    'show_in_nav_menus' => false,
    'show_in_quick_edit' => false,
    'meta_box_cb' => false
  );

  public function setup()
  {
    $this->handleAddToCartBots();
    $this->addTaxonomies();
  }

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    Util::disableShippingCache();
    // Call on wp when we have all is_* functions
    add_action('wp', function () {
      Util::setDefaultCountryOnly(is_cart());
    });
    // Also run it on updating cart shipping method
    if (isset($_GET['wc-ajax']) && $_GET['wc-ajax'] == 'update_shipping_method') {
      Util::setDefaultCountryOnly(true);
    }

    // Allow to search for sku on product relations ACF fields and show the sku in resultset
    add_filter('acf/fields/relationship/query', array($this, 'addAcfSearchBySku'), 10, 2);
    add_filter('acf/fields/relationship/result', array($this, 'addAcfSkuToResult'), 10, 3);
    add_action('cron_daily_22', array($this, 'checkTimeoutCancelledOrders'));
    add_action('cron_daily_23', array($this, 'removeOldWooSessions'));
    add_action('cron_daily_23', array($this, 'removeOldActionSchudlerRecords'));
    add_action('woocommerce_single_product_summary', array($this, 'showSaleInfo'));

    // Add infobox content
    add_action('woocommerce_cart_collaterals', array($this, 'addCartCollateralText'), 1);
    add_filter('send_email_change_email', '__return_false', 5);

    $config = Core::getInstance()->getConfig();

    // Add social buttons rendering
    if ($config['Privacy:PrivacyOptimizedShareButtons'] == 1) {
      add_action('woocommerce_share', array($this, 'renderSocialShareButtons'), 20);
      // Define minimal FA icon templates for the buttons (that need to be configured in settings)
      Buttons::init(array(
        'buttons' => array(
          SocialApis::EMAIL => array('template' => '<a href="{shareLink}" class="dropdown-item icon">' . $this->icon('shop-share-email') . '</a>'),
          SocialApis::FACEBOOK => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-facebook') . '</a>'),
          SocialApis::TWITTER => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-xcom') . '</a>'),
          SocialApis::WHATSAPP => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-whatsapp') . '</a>'),
          SocialApis::LINKED_IN => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-linkedin') . '</a>'),
          SocialApis::XING => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-xing') . '</a>'),
          SocialApis::PRINTBUTTON => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-print') . '</a>'),
          SocialApis::PINTEREST => array('template' => '<a href="{shareLink}" class="dropdown-item icon" target="_blank">' . $this->icon('shop-share-pinterest') . '</a>'),
        )
      ));
    }

    // Add custom pdf styles
    add_action('wpo_wcpdf_custom_styles', array($this, 'addCustomPdfStyles'));
  }

  public function checkTimeoutCancelledOrders()
  {
    // Get all orders that are cancelled within the last 24 hours
    $infos = array();
    $orders = wc_get_orders(array(
      'status' => 'cancelled',
      'date_created' => '>' . (time() - (3600+86400))
    ));

    // Loop trough
    foreach ($orders as $order) {
      $notes = wc_get_order_notes(array(
        'order_id' => $order->get_id(),
        'type' => 'system'
      ));
      $status = 'Status: Unknown / ';

      foreach ($notes as $note) {
        $note->content = strtolower($note->content);

        if (str_contains($note->content, 'timeout') || str_contains($note->content, 'zeitlimit')) {
          try {
            $instanceName = get_option('payrexx_configs_instance');
            $payrexxApiKey = get_option('payrexx_configs_api_key');
            $gatewayId = $order->get_meta('payrexx_gateway_id');

            $payrexx = new \Payrexx\Payrexx($instanceName, $payrexxApiKey, '', 'payrexx.aboon.ch');

            // Get transaction details
            $gateway = new \Payrexx\Models\Request\Gateway();
            $gateway->setId($gatewayId);

            $response = $payrexx->getOne($gateway);

            // Output transaction status
            $status = "Status: " . $response->getStatus() . ' / ';
          } catch (\Payrexx\PayrexxException $e) {
            SystemLog::mDebug('checkTimeoutCancelledOrders: Payrexx Gateway', $e->getMessage());
          }

          $infos[] = 'Bestellung <a href="' . $order->get_edit_order_url() . '">#' . $order->get_id() . '</a>: ' . $status . $note->content;
        }
      }
    }

    // Send mail to comotive monitoring with all infos
    if (!empty($infos)) {
      $body = '<p>Es wurden Bestellungen gefunden, die aufgrund eines Zeitlimits abgebrochen wurden:</p>';
      $body .= '<ul><li>' . implode('</li><li>', $infos) . '</li></ul>';
      $mail = External::PhpMailer();
      $mail->Subject = '[' . get_bloginfo('name') . '] Bestellungen mit Zeitlimit prüfen';
      $mail->Body = $body;
      $mail->addAddress('it+monitoring@comotive.ch');
      $mail->send();
    }
  }

  /**
   * @return void
   */
  public function showSaleInfo()
  {
    /** @var \WC_Product $product */
    global $product;

    // Show sale ending info if given
    if ($product->is_on_sale()) {
      $onSaleDate = $product->get_date_on_sale_to();
      if ($onSaleDate instanceof \WC_DateTime) {
        $onSaleDate = $onSaleDate->format('d.m.Y');
        echo '<p class="reduced-price">Angebotspreis gültig bis ' . $onSaleDate . '</p>';
      }
    }
  }

  /**
   * @param $name
   * @return mixed|null
   */
  protected function icon($name)
  {
    $icon = '';
    switch ($name) {
      case 'product-share-icon': $icon = '<i class="fa-light fa-share-nodes"></i>'; break;
      case 'shop-share-email': $icon = '<i class="fa-light fa-square-envelope"></i>'; break;
      case 'shop-share-facebook': $icon = '<i class="fa-brands fa-square-facebook"></i>'; break;
      case 'shop-share-whatsapp': $icon = '<i class="fa-brands fa-square-whatsapp"></i>'; break;
      case 'shop-share-xcom': $icon = '<i class="fa-brands fa-square-x-twitter"></i>'; break;
      case 'shop-share-linkedin': $icon = '<i class="fa-brands fa-linkedin"></i>'; break;
      case 'shop-share-xing': $icon = '<i class="fa-brands fa-square-xing"></i>'; break;
      case 'shop-share-print': $icon = '<i class="fa-light fa-print"></i>'; break;
      case 'shop-share-pinterest': $icon = '<i class="fa-brands fa-square-pinterest"></i>'; break;
    }

    return apply_filters('aboon_general_icon_filter', $icon, $name);
  }

  /**
   * Known bots calling add to cart and causing endless RAM loops in wc-cart.php:
   * Mozilla/5.0 (compatible; DataForSeoBot/1.0; +https://dataforseo.com/dataforseo-bot)
   * Googlebot/2.1 (+http://www.google.com/bot.html)
   * GPTBot/1.1 and GPTBot/2.0
   * @return void
   */
  public function handleAddToCartBots()
  {
    if (isset($_REQUEST['add-to-cart']) && Strings::isSearchEngineUserAgent()) {
      header('HTTP/1.1 204 No Content');
      exit;
    }
  }

  /**
   * Render the share buttons underneath the product summary
   */
  public function renderSocialShareButtons()
  {
    if (is_product()) {
      global $post;
      echo '
        <div class="share-container">
          <h4>' . $this->icon('product-share-icon') . ' ' . __('Produkt teilen', 'lbwp') . '</h4>
          <div class="desktop-share-buttons">' . Buttons::get() . '</div>
          <script type="text/javascript">
            jQuery(function() {
              if (navigator.share) {
                jQuery(".share-container h4 i").show();
                jQuery(".share-container h4").on("click", function() {
                  navigator.share({
                    title: "' . esc_js($post->post_title) . '",
                    text: "' . esc_js(str_replace(PHP_EOL, ' ', $post->post_excerpt)) . '",
                    url: "' . get_permalink($post->ID) . '"
                  });
                });
              } else {
                jQuery(".desktop-share-buttons").show();
              }
            });
          </script>
        </div>
		  ';
    }
  }

  /**
   * @return void
   */
  public function removeOldWooSessions()
  {
    $treshold = current_time('timestamp') - 86400;
    $db = WordPress::getDb();
    $sql = '
      DELETE FROM ' . $db->prefix . 'woocommerce_sessions
      WHERE LENGTH(session_value) < 3000 AND LENGTH(session_key) = 32
      AND session_expiry < ' . $treshold . ' LIMIT 3000
    ';

    // Make smaller delete statements to prevent deadlocks
    for ($i = 0; $i < 20; $i++) {
      sleep(1);
      $db->query($sql);
      // Break if nothing was deleted anymore
      if ($db->rows_affected == 0 && strlen($db->last_error) === 0) {
        break;
      }
    }
  }

  /**
   * @return void delete completed actions older a month
   */
  public function removeOldActionSchudlerRecords()
  {
    $treshold = current_time('timestamp') - 30 * 86400;
    $treshold = Date::getTime(Date::SQL_DATETIME, $treshold);
    $db = WordPress::getDb();

    $sqlLogs = '
      DELETE FROM ' . $db->prefix . 'actionscheduler_logs
      WHERE log_date_local < "' . $treshold . '" LIMIT 5000
    ';
    $sqlActions = '
      DELETE FROM ' . $db->prefix . 'actionscheduler_actions
      WHERE last_attempt_local < "' . $treshold . '" AND status = "complete"
      LIMIT 5000
    ';

    for ($i = 0; $i < 10; $i++) {
      $db->query($sqlActions);
      sleep(1);
      $db->query($sqlLogs);
      sleep(1);
      // Break if nothing was deleted anymore
      if ($db->rows_affected == 0 && strlen($db->last_error) === 0) {
        break;
      }
    }
  }

  /**
   * Enqueue scripts and styles
   */
  public function assets()
  {
    $base = File::getResourceUri();
    wp_enqueue_script('aboon-base-js', $base . '/js/aboon/base.js', array('jquery'), Core::REVISION, true);
  }

  /**
   * @param $args
   * @param $field
   * @return mixed
   */
  public function addAcfSearchBySku($args, $field)
  {
    if ($field['type'] == 'relationship' && in_array('product', $args['post_type']) && isset($args['s'])) {
      $args['meta_query'] = array(array(
        'key' => '_sku',
        'value' => $args['s'],
        'compare' => 'LIKE'
      ));
      // Add this to make the meta query OR with the "s" parameter with searches title, content
      add_filter('get_meta_sql', function ($sql) {
        static $nr = 0;
        if (0 != $nr++) return $sql;
        $sql['where'] = mb_eregi_replace('^ AND', ' OR', $sql['where']);
        return $sql;
      });
    }

    return $args;
  }

  /**
   * @param string $text
   * @param \WP_Post $post
   * @param array $field
   * @return string
   */
  public function addAcfSkuToResult($text, $post, $field)
  {
    $postType = is_array($field['post_type']) ? $field['post_type'] : array($field['post_type']);
    if ($field['type'] == 'relationship' && in_array('product', $postType)) {
      $text .= ' (' . get_post_meta($post->ID, '_sku', true) . ')';
    }

    return $text;
  }

  /**
   * Sets the current logged in user state in API calls
   */
  public static function setApiUserContext()
  {
    $db = WordPress::getDb();
    if (!isset($_COOKIE['wordpress_logged_in_' . COOKIEHASH])) {
      return;
    }

    list($email) = explode('|', $_COOKIE['wordpress_logged_in_' . COOKIEHASH]);
    if (strlen($email) > 0 && (Strings::isEmail($email) || $email == Strings::forceSlugString($email, true))) {
      $id = $db->get_var('SELECT ID FROM ' . $db->users . ' WHERE user_login = "' . $email . '"');
      wp_set_current_user($id);
    }
  }

  /**
   * @param $field
   * @return array
   */
  public static function getMetaListIdMap($field)
  {
    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT post_id, meta_value FROM ' . $db->postmeta . ' WHERE meta_key = "' . $field . '"');
    $data = array();
    foreach ($raw as $row) {
      $data[$row->post_id] = $row->meta_value;
    }

    return $data;
  }

  /**
   * @return array
   */
  public static function getStockListIdMap()
  {
    return self::getMetaListIdMap('_stock');
  }

  /**
   * @return array
   */
  public static function getPriceListIdMap()
  {
    return self::getMetaListIdMap('_price');
  }

  /**
   * @param array $skuIds
   * @return array
   */
  public static function translateSkuToId($skuIds)
  {
    $db = WordPress::getDb();
    return array_map('intval', $db->get_col('
      SELECT post_id FROM ' . $db->postmeta . '
      WHERE meta_key = "_sku"
      AND meta_value IN(' . implode(',', $skuIds) . ')
    '));
  }

  /**
   * @return array
   */
  public static function getSkuMap($force = false)
  {
    $skuMap = wp_cache_get('skuMap', 'Shop');
    if (is_array($skuMap) && count($skuMap) > 0 && !$force) {
      return $skuMap;
    }

    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT post_id, meta_value FROM ' . $db->postmeta . ' WHERE meta_key = "_sku"');
    $skuMap = array();
    foreach ($raw as $row) {
      $skuMap[$row->meta_value] = intval($row->post_id);
    }

    wp_cache_set('skuMap', $skuMap, 'Shop', 7200);

    return $skuMap;
  }

  /**
   * @return string
   */
  public function getUserName()
  {
    $user = wp_get_current_user();
    $name = trim($user->get('billing_first_name') . ' ' . $user->get('billing_last_name'));
    return strlen($name) > 0 ? $name : $user->display_name;
  }

  /**
   *
   */
  public function addTaxonomies()
  {
    if (defined('LOCAL_DEVELOPMENT')) {
      unset($this->taxConfig['meta_box_cb']);
    }
    // Allow categorization of brands
    WordPress::registerTaxonomy(self::TAX_PROPERTY, 'Property', 'Properties', '', $this->taxConfig, 'product');
  }

  /**
   * Add info text next to the cart totals
   *
   * @param string $totals the cart totals
   * @return void
   */
  public function addCartCollateralText($totals)
  {
    $msg = $this->getInfoMessageContent();

    if ($msg !== false) {
      echo apply_filters('aboon_cart_collateral_text', '<div class="aboon-custom-message" role="alert">
         ' . $msg . '
       </div>');
    }
  }

  /**
   * Get the infomessage content
   *
   * @return string|bool the text if defined else false
   */
  public function getInfoMessageContent()
  {
    $infoContent = get_field('infobox-content', 'option');

    if (!empty($infoContent) && $infoContent !== null) {
      return $infoContent;
    }

    return false;
  }

  /**
   * Add some custom styles to the pdf
   * @param $type string type of the document
   * @return void
   */
  public function addCustomPdfStyles($type)
  {
    echo '
      @page{
        margin-bottom: 2cm;
      }
    
      .order-details thead th{
        background-color: #d6d6d6;
        border-color: #d6d6d6;
        color: #000;
      }
      
      .order-paid-info{
        margin-bottom: 10px;
      }
      
      .order-paid-info i{
        display: inline-block;
        padding: 3px 5px;
        background: #000;
        color: #fff;
        font-size: 16px;
      }
      
      h1{
        margin-top: 0;
      }
    ';
  }

  /**
   * Little helper to most possibly to things on woocommerce_thankyou only once
   * @param mixed $id identifier, most likely a session or user id
   * @return bool true if action can be done (first time calling), false if not (subsequent calls)
   */
  public static function wcThankyouOnce($id, $prefix = '')
  {
    $didOnce = wp_cache_get($prefix . 'ThankYouOnce_' . $id, 'Shop');
    if ($didOnce !== 1) {
      wp_cache_set($prefix . 'ThankYouOnce_' . $id, 1, 'Shop', 3600);
      return true;
    }
    return false;
  }

  /**
   * @return array of tax configurations
   */
  public static function getStandardTaxes($class = '', $country = 'CH')
  {
    $key = trim('taxrates_' . $country . $class);
    $rates = wp_cache_get($key, 'Aboon');
    if (!is_array($rates)) {
      $db = WordPress::getDb();
      $sql = 'SELECT * FROM ' . $db->prefix . 'woocommerce_tax_rates WHERE tax_rate_country IN("", "'.$country.'")';
      if (strlen($class) > 0) {
        $sql .= ' AND tax_rate_class = "'.$class.'"';
      }
      $rates = $db->get_results($sql, ARRAY_A);
      wp_cache_set($key, $rates, 'Aboon', 7200);
    }

    return $rates;
  }

  /**
   * @param array $taxes a maybe changed array gotten from getStandardTaxes to save back to db settings
   * @return void
   */
  public static function setStandardTaxes($taxes)
  {
    $db = WordPress::getDb();
    foreach ($taxes as $taxrate) {
      $id = $taxrate['tax_rate_id'];
      unset($taxrate['tax_rate_id']);
      $db->update(
        $db->prefix . 'woocommerce_tax_rates',
        $taxrate,
        array('tax_rate_id' => $id)
      );
    }
  }

  /**
   * Delete unused shop accounts that have no orders and are older than 6 months. Only works if hpos is active.
   * Is not run by base shop, you need to implement own cron logic in child theme/component. i.e. cron_weekday_6
   * @return void
   */
  public function deleteUnusedShopAccounts()
  {
    $db = WordPress::getDb();
    // Only run if hpos is active
    if (!Util::isHposActive()) {
      return;
    }

    // 6 months in seconds
    $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months'));
    // Query: All customer users who have no orders and are older than 6 months
    $users = $db->get_results(
      $db->prepare('
        SELECT u.* FROM ' . $db->users . ' u
        INNER JOIN ' . $db->usermeta . ' um ON um.user_id = u.ID
        LEFT JOIN ' . $db->prefix . 'wc_orders o ON o.customer_id = u.ID
        WHERE um.meta_key = %s AND um.meta_value = %s
        AND o.id IS NULL
        AND u.user_registered < %s
        LIMIT 1000
      ', $db->prefix . 'capabilities', 'a:1:{s:8:"customer";b:1;}', $sixMonthsAgo)
    );

    /*
     * Debug information to check validity of the query
    // total users to have a comparison
    $totalUsers = $db->get_var(
      $db->prepare('
        SELECT COUNT(*) FROM ' . $db->users . ' u
        INNER JOIN ' . $db->usermeta . ' um ON um.user_id = u.ID
        WHERE um.meta_key = %s AND um.meta_value = %s
      ', $db->prefix . 'capabilities', 'a:1:{s:8:"customer";b:1;}')
    );

    echo 'deleted users: ' . count($users) . '<br>';
    echo 'total users: ' . $totalUsers . '<br>';
    */

    if (!empty($users)) {
      // Require user.php if function doesn't exist in context (cron)
      if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-includes/user.php';
      }
      foreach ($users as $user) {
        // Add hook to modify delete bool
        $delete = apply_filters('aboon_before_delete_fake_account', true, $user);

        if ($delete) {
          //$orderCount = count(wc_get_orders(array('customer_id' => $user->ID)));
          //echo 'deleting user ' . $user->user_email . ' (' . $user->ID . '), actual orders: '. $orderCount . '<br>';
          wp_delete_user($user->ID);
        }
      }
    }
  }
}

