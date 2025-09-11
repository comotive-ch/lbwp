<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use Standard03\Component\ACF;

/**
 * Simple affiliate modell
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch
 */
class SimpleAffiliate extends ACFBase
{
  /**
   * SimpleAffiliate instance
   */
  protected static $instance;

  /**
   * Permalink in the woocommerce menu
   */
  const PERMALINK = 'empfehlungen';

  /**
   * Order status
   */
  const ORDER_STATUS = array(
    'in-process' => 'Bereit zur Auszahlung',
    'paid' => 'Ausgezahlt'
  );

  /**
   * Name of the affiliate cookie
   */
  const COOKIE_NAME = 'aboon-affiliate-id';

  /**
   * Initialize the affiliate component, which is nice
   */
  public function init()
  {
    self::$instance = $this;

    add_rewrite_endpoint(self::PERMALINK, EP_ROOT | EP_PAGES);
    add_action('woocommerce_account_menu_items', array($this, 'addAffiliateAccountMenu'), 9999);
    add_action('woocommerce_account_' . self::PERMALINK . '_endpoint', array($this, 'affiliateAccountPage'));
    add_action('wp', array($this, 'redirectAffiliateLink'));
    add_action('woocommerce_thankyou', array($this, 'checkForAffiliateOrder'));

    // Need to declare the subpage twice so that it works with the ACF menu...
    $pageSettings = array(
      'page_title' => 'Aboon &raquo; Offene Zahlungen (Affiliate)',
      'menu_title' => 'Offene Zahlungen',
      'capability' => 'administrator',
      'menu_slug' => 'aboon-affiliate-payments',
      'parent_slug' => 'aboon-settings'
    );
    acf_add_options_page($pageSettings);
    add_action('admin_menu', function () use ($pageSettings) {
      add_submenu_page(
        null,
        $pageSettings['page_title'],
        $pageSettings['menu_title'],
        $pageSettings['capability'],
        $pageSettings['menu_slug'],
        array($this, 'listPayments'),
      );
    });
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_64fed003e43eb',
      'title' => 'Affiliate Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_650311e5ef037',
          'label' => '<b>Affiliate Daten</b>',
          'name' => '',
          'aria-label' => '',
          'type' => 'message',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => '',
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
        array(
          'key' => 'field_64fed004c747a',
          'label' => 'IBAN',
          'name' => 'affiliate-iban',
          'aria-label' => '',
          'type' => 'text',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_650310ef9049a',
          'label' => 'Beteiligung',
          'name' => 'affiliate-interest',
          'aria-label' => '',
          'type' => 'number',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'min' => '',
          'max' => '',
          'placeholder' => '',
          'step' => '',
          'prepend' => '',
          'append' => '',
        ),
        array(
          'key' => 'field_64fed06bc747b',
          'label' => 'Bestellungen',
          'name' => 'affiliate-orders',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 0,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'rows_per_page' => 20,
          'sub_fields' => array(
            array(
              'key' => 'field_64fed07dc747c',
              'label' => 'Bestellnummer',
              'name' => 'order-nr',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64fed06bc747b',
            ),
            array(
              'key' => 'field_650311439049b',
              'label' => 'Nachname',
              'name' => 'lastname',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64fed06bc747b',
            ),
            array(
              'key' => 'field_650311579049c',
              'label' => 'Ort',
              'name' => 'place',
              'aria-label' => '',
              'type' => 'text',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'maxlength' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64fed06bc747b',
            ),
            array(
              'key' => 'field_650311669049d',
              'label' => 'Betrag',
              'name' => 'amount',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_64fed06bc747b',
            ),
            array(
              'key' => 'field_6503117a9049e',
              'label' => 'Status',
              'name' => 'status',
              'aria-label' => '',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => self::ORDER_STATUS,
              'default_value' => false,
              'return_format' => 'value',
              'multiple' => 0,
              'allow_null' => 0,
              'ui' => 0,
              'ajax' => 0,
              'placeholder' => '',
              'parent_repeater' => 'field_64fed06bc747b',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'user_role',
            'operator' => '==',
            'value' => 'all',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks()
  {
  }

  /**
   * Get the instance of the affiliate class
   *
   * @return SimpleAffiliate
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * Add the affiliate menu to the account page
   *
   * @param array $menuLinks the array with the menu items/links
   * @return array the menu links
   */
  public function addAffiliateAccountMenu($menuLinks)
  {
    $position = 4;
    $menuLinks = array_slice($menuLinks, 0, $position, true) + array(
        'empfehlungen' => apply_filters('lbwp_affiliate_account_menu_name', __('Empfehlungen', 'lbwp'))
      ) + array_slice($menuLinks, $position, null, true);

    return $menuLinks;
  }

  /**
   * Setup the affiliate account page
   * @return void
   * @throws \PHPMailer\PHPMailer\Exception
   */
  public function affiliateAccountPage()
  {
    $this->handleFormSubmitions();
    $userId = get_current_user_id();

    $usermeta = WordPress::getAccessibleUserMeta($userId);
    $interest = floatval($usermeta['affiliate-interest']) === 0.0 ? floatval(ACF::option('standard-interest')) : floatval($usermeta['affiliate-interest']);
    $ordersHtml = '';
    $totalDueToBePaid = 0;

    $orders = get_field('affiliate-orders', 'user_' . $userId);
    if (!empty($orders)) {
      $ordersHtml = '<table>
        <tr>
          <th>' . __('Bestell Nr.', 'lbwp') . '</th>
          <th>' . __('Nachname', 'lbwp') . '</th>
          <th>' . __('Ort', 'lbwp') . '</th>
          <th>' . __('Betrag', 'lbwp') . '</th>
          <th>' . __('Status', 'lbwp') . '</th>
        </tr>';

      foreach ($orders as $order) {
        $ordersHtml .= '<tr>
          <td>' . $order['order-nr'] . '</td>
          <td>' . $order['lastname'] . '</td>
          <td>' . $order['place'] . '</td>
          <td>' . $order['amount'] . ' CHF</td>
          <td>' . self::ORDER_STATUS[$order['status']] . '</td>
        </tr>';
        if ($order['status'] == 'in-process') {
          $totalDueToBePaid += $order['amount'];
        }
      }

      $ordersHtml .= '</table>';

      if ($totalDueToBePaid > 0) {
        $ordersHtml .= '<p>Aktuell sind ' . $totalDueToBePaid . ' CHF bereit zur Auszahl zum Monatsende.</p>';
      }
    }

    echo
      '<div class="affiliate__page-text">' . ACF::option('affiliate-page-text') . '</div>
      <div class="affiliate__link">
        <label for="affiliate-link-field">' . __('Dein Empfehlungs-Link:', 'lbwp') . '</label>
        <div class="affiliate__link-field">
          <p>' . __('Link kopiert', 'lbwp') . '</p>
          <input id="affiliate-link-field" type="text" value="' . get_bloginfo('url') . '/a/u' . get_current_user_id() . '" readonly>
        </div>
        <script>
          let aftCopyField = document.getElementById("affiliate-link-field");
          let aftNotification = document.querySelector(".affiliate__link-field p"); 
          
          aftCopyField.addEventListener("click", function(){
            aftCopyField.select();
            aftCopyField.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(aftCopyField.value);
            aftNotification.classList.add("copied");
            
            window.setTimeout(()=>{aftNotification.classList.remove("copied");}, 1600);
          });
        </script>
      </div>
      <div class="affiliate__iban">
        <form method="post">
          <label>
            <span>' . __('Deine Bankverbindung für Auszahlungen', 'lbwp') . '</span>
            <input type="text" name="affiliate-iban" value="' . $usermeta['affiliate-iban'] . '" placeholder="' . __('IBAN ggf. Bankname, Adresse, sofern abweichend von Adresse im Konto', 'lbwp') . '" required> 
          </label>
          <div class="affiliate__send-button">
            <input type="submit" class="btn btn--primary" value="' . __('Speichern', 'lbwp') . '" name="save-iban">
          </div>
        </form>
      </div>
      <div class="affiliate__orders">
        <p>' . sprintf(__('Deine Beteiligung an Empfehlungen ist %.' . strlen(substr(strrchr($interest, "."), 1)) . 'f%% des jeweiligen Kaufpreises exkl. Lieferkosten'), $interest) . '.</p>
        ' . $ordersHtml . '
        <h3>' . __('Neue Empfehlung eintragen', 'lbwp') . '</h3>
        <p>' . __('Wenn du einen Kunden empfohlen hast, der bei uns bestellt hat, gibt hier bitte Nachname, Ort und wenn vorhanden Bestellnummer an. Du bekommst eine Bestätigung sobald wir die Empfehlung geprüft haben.', 'lbwp') . '</p>
        <form method="post">
          <label>
            <span>' . __('Nachname', 'lbwp') . '</span>
            <input type="text" name="recommendation-name" required>
          </label>
          <label>
            <span>' . __('Ort', 'lbwp') . '</span>
            <input type="text" name="recommendation-place" required>
          </label>
          <label>
            <span>' . __('Bestellnummer', 'lbwp') . '</span>
            <input type="text" name="recommendation-order-nr">
          </label>
          <div class="affiliate__send-button">
            <input type="submit" class="btn btn--primary" value="' . __('Absenden', 'lbwp') . '" name="recommendation-submit">
          </div>
        </form>
      </div>
      ';
  }

  /**
   * Handle form submitions (iban save, add affiliate order)
   * @return void
   * @throws \PHPMailer\PHPMailer\Exception
   */
  public function handleFormSubmitions()
  {
    if (isset($_POST['save-iban'])) {
      $iban = substr($_POST['affiliate-iban'], 0, 50);
      update_user_meta(get_current_user_id(), 'affiliate-iban', $iban);
    }

    if (isset($_POST['recommendation-submit'])) {
      if (
        !Strings::isEmpty($_POST['recommendation-name']) &&
        !Strings::isEmpty($_POST['recommendation-place'])
      ) {
        $userId = get_current_user_id();
        $usermeta = WordPress::getAccessibleUserMeta($userId);
        $interest = floatval($usermeta['affiliate-interest']) === 0.0 ? floatval(ACF::option('standard-interest')) : floatval($usermeta['affiliate-interest']);
        $domain = str_replace('https://', '', get_site_url());

        $mailContent = __('Guten Tag', 'lbwp') . PHP_EOL .
          PHP_EOL .
          __('Danke für deine Empfehlung, wir prüfen diese und geben Bescheid, wenn die Provision zur Auszahlung freigegeben wurde.', 'lbwp') . PHP_EOL .
          'Deine Empfehlung: ' . $_POST['recommendation-name'] . ', ' . $_POST['recommendation-place'] .
          (!Strings::isEmpty($_POST['recommendation-order-nr']) ? ', #' . $_POST['recommendation-order-nr'] : '') .
          PHP_EOL .
          'Deine Angaben: Kundennummer #' . $userId . ', ' . $usermeta['billing_first_name'] . ' ' . $usermeta['billing_last_name'] . ' ' . $usermeta['billing_email'] .
          PHP_EOL .
          sprintf('Voraussichtliche Provision: %.' . strlen(substr(strrchr($interest, "."), 1)) . 'f%%', $interest) .
          PHP_EOL .
          PHP_EOL .
          'Grüsse' . PHP_EOL .
          $domain;

        $mail = External::PhpMailer();
        $mail->Subject = sprintf(__('Deine Empfehlung bei %s', 'lbwp'), $domain);
        $mail->Body = $mailContent;
        $mail->AddAddress(get_currentuserinfo()->user_email);
        $mail->addBCC(get_option('admin_email'));
        $mail->isHTML(false);
        $mail->send();
      }
    }
  }

  /**
   * Redirect the url that sets the affiliate-cookie
   * @return void
   */
  public function redirectAffiliateLink()
  {
    $url = parse_url($_SERVER['REQUEST_URI']);
    $productAffiliate = is_product();

    if($productAffiliate && isset($_GET['refcode'])){
      $userId = intval($_GET['refcode']);
    }else{
      $productAffiliate = false;
    }

    if (is_404() && Strings::startsWith($url['path'], '/a/u') || $productAffiliate !== false){
      $userId = $productAffiliate === false ? intval(substr($url['path'], 4)) : $userId;

      // Check if user exists and it's not himself
      if (get_user_by('ID', $userId) !== false && get_current_user_id() !== $userId) {
        $expires = intval(ACF::option('affiliate-cookie-expiration'));
        $home = get_bloginfo('url');
        $domain = str_replace(['http://', 'https://', 'www.'], '', $home);

        setcookie(self::COOKIE_NAME, $userId, current_time('timestamp') + ($expires*86400), '/', $domain, true, false);

        if($productAffiliate === false){
          $redirect = get_permalink(ACF::option('affiliate-redirect'));
          wp_redirect($redirect !== false ? $redirect : $home);
        }
      }
    }
  }

  /**
   * Handle the affiliation on a new order
   * @param $orderId
   * @return void
   * @throws \PHPMailer\PHPMailer\Exception
   */
  public function checkForAffiliateOrder($orderId)
  {
    // Check if already sent email and cookie still exists
    if (isset($_COOKIE[self::COOKIE_NAME]) && !$_SESSION['sent-affilate-info-mail']) {
      $userId = $_COOKIE[self::COOKIE_NAME];
      $user = get_user_by('ID', $userId);

      if ($user !== false && get_current_user_id() !== $userId) {
        $order = wc_get_order($orderId);
        $data = array(
          'order-nr' => $orderId,
          'lastname' => $order->get_billing_last_name(),
          'place' => $order->get_billing_city(),
          'amount' => $order->get_total(),
          'status' => 'in-process'
        );

        add_row('affiliate-orders', $data, 'user_' . $_COOKIE[self::COOKIE_NAME]);

        $replace = ['http://', 'https://', 'www.'];
        $mail = External::PhpMailer();
        $mail->Subject = str_replace($replace, '', get_bloginfo('url')) . ' - Neuer Affiliate-Eintrag';
        $mail->Body = 'Folgender Affiliate-Eintrag wurde beim User <a href="' . get_edit_user_link($userId) . '">#' . $userId . '</a> hinzugefügt:<br>
          <b>Bestell-Nr.:</b>' . $data['order-nr'] . '<br>
          <b>Nachname:</b>' . $data['lastname'] . '<br>
          <b>Ort</b>' . $data['place'] . '<br>
          <b>Betrag Total (muss runtergerechnet werden):</b>' . $data['amount'] . '<br>';
        $mail->AddAddress(get_option('admin_email'));
        $mail->send();

        setcookie(self::COOKIE_NAME, 0, -1, '/', str_replace($replace, '', get_bloginfo('url')));
        $_SESSION['sent-affilate-info-mail'] = 1;
        unset($_COOKIE[self::COOKIE_NAME]);
      }
    }
  }

  /**
   * List all affiliate payments
   * @return void
   */
  public function listPayments(){
    $users = get_users(array(
      'role' => 'customer',
      'meta_query' => array(
        array(
          'key' => 'affiliate-orders',
          'compare' => '!=',
        ),
      ),
    ));
    $html = '';

    foreach ($users as $user) {
      $amount = 0.0;
      foreach(get_field('affiliate-orders', 'user_' . $user->ID) as $affiliateOrder){
        if($affiliateOrder['status'] === 'in-process'){
          $amount += floatval($affiliateOrder['amount']);
        }
      }

      $meta = WordPress::getAccessibleUserMeta($user->ID);
      $html .= '<tr>
        <td><a href="' . get_edit_user_link($user->ID) . '">' . $user->ID . '</a></td>
        <td>' . $meta['first_name'] . ' ' . $meta['last_name'] . '</td>
        <td>' . $meta['affiliate-iban'] . '</td>
        <td>' . $meta['billing_address_1'] . '</td>
        <td>' . $meta['billing_postcode'] . '</td>
        <td>' . $meta['billing_city'] . '</td>
        <td style="text-align:right;">' . number_format($amount, 2) . '</td>
      </tr>';
    }

    echo '<div class="wrap">
      <h1>Aboon » Offene Zahlungen</h1>
      <table class="wp-list-table widefat fixed striped table-view-list payments">
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th>Kunde</th>
            <th>IBAN</th>
            <th>Adresse</th>
            <th>PLZ</th>
            <th>Ort</th>
            <th style="text-align:right;">Betrag</th>
          </tr>
        </thead>
      
        <tbody>
          ' . $html . '
        </tbody>
      </table>
    </div>';
  }
}