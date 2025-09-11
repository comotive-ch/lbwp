<?php

namespace LBWP\Aboon\Component;

use Automattic\WooCommerce\Admin\Overrides\OrderRefund;
use LBWP\Core as LbwpCore;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\WooCommerce\Util;
use LBWP\Theme\Base\Component;
use LBWP\Util\Date;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Export functionality for woocommerce orders
 * @package Export\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Export extends Component
{
  const DEFAULT_STATUS = array('wc-pending', 'wc-processing', 'wc-on-hold', 'wc-partial-refunded');
  const DEFAULT_STATUS_SUBS = array('wc-active');

  /**
   * @return void
   */
  public function init()
  {
    add_action('admin_menu', array($this, 'addSubmenuPage'));
    add_action('admin_init', array($this, 'maybeRunExport'));
    add_action('wp', array($this, 'maybeRunExportExternal'));

    if(is_user_logged_in() && user_can(get_current_user_id(), 'manage_woocommerce')){
      add_filter('woocommerce_rest_check_permissions', '__return_true');
    }
  }

  /**
   * @return void
   */
  public function maybeRunExportExternal()
  {
    if (isset($_REQUEST['shop-export']) && $_REQUEST['allowExternal'] == '739ztuhgoroz8053z3qhigrw08z53ihgo') {
      if (isset($_POST['postType']) && $_POST['postType'] == 'shop_subscription') {
        $this->exportCSVSubscriptions();
      } else {
        $this->exportCSVOrders();
      }
    }
  }

  /**
   * @return void
   */
  public function maybeRunExport()
  {
    if (isset($_REQUEST['shop-export']) && current_user_can('administrator')) {
      if (isset($_POST['postType']) && $_POST['postType'] == 'shop_subscription') {
        $this->exportCSVSubscriptions();
      } else {
        $this->exportCSVOrders();
      }
    }

    if(isset($_POST['template-save']) && current_user_can('administrator')){
      $templates = get_option('aboon-shop-export-templates');
      $templates = !is_array($templates) ? [] : $templates;
      $_POST['template-name'] = !isset($_POST['template-name']) ? 'Volage ' . (count($templates) + 1) : $_POST['template-name'];

      unset($_POST['template-save']);

      $templates[Strings::forceSlugString($_POST['template-name']) . time()] = $_POST;
      update_option('aboon-shop-export-templates', $templates);
    }

    if(isset($_POST['template-delete']) && !empty($_POST['template-selected']) && current_user_can('administrator')){
      $templates = get_option('aboon-shop-export-templates');
      unset($templates[$_POST['template-selected']]);

      update_option('aboon-shop-export-templates', $templates);
    }
  }

  /**
   * @return void
   */
  public function addSubmenuPage()
  {
    add_submenu_page(
      'woocommerce',
      'Export',
      'Export',
      'manage_woocommerce',
      'aboon-shop-export',
      array($this, 'exportPageContent')
    );
  }

  /**
   * @return string
   */
  protected function getOrderStatuses()
  {
    $current = isset($_POST['postStatus']) ? $_POST['postStatus'] : apply_filters('aboon_export_post_status_list', self::DEFAULT_STATUS);

    if(is_array($_POST['template']['postStatus'])){
      $current = $_POST['template']['postStatus'];
    }

    $html = '';
    foreach (wc_get_order_statuses() as $key => $status) {
      $checked = selected(true, in_array($key, $current), false);
      $html .= '<option value="' . $key . '"' . $checked . '>' . $status . '</option>';
    }

    return $html;
  }

  /**
   * @return string
   */
  protected function getSubscriptionStatuses()
  {
    $current = isset($_POST['postStatusSubs']) ? $_POST['postStatusSubs'] : self::DEFAULT_STATUS_SUBS;

    if(is_array($_POST['template']['postStatusSubs'])){
      $current = $_POST['template']['postStatusSubs'];
    }

    $html = '';
    foreach (wcs_get_subscription_statuses() as $key => $status) {
      $checked = selected(true, in_array($key, $current), false);
      $html .= '<option value="' . $key . '"' . $checked . '>' . $status . '</option>';
    }

    return $html;
  }

  /**
   * @return void
   */
  public function exportPageContent()
  {
    $isSubscriptions = function_exists('wcs_get_subscription_statuses');
    wp_enqueue_script('jquery-ui-datepicker');
    wp_register_style('jquery-ui', '/wp-content/plugins/lbwp/resources/css/jquery.ui.theme.min.css');
    wp_enqueue_style('jquery-ui');
    $base = File::getResourceUri();
    wp_enqueue_script('select2-js', $base . '/js/select2/select2.min.js', array('jquery'), LbwpCore::REVISION);
    wp_enqueue_style('select2-css', $base . '/js/select2/select2.min.css', array(), LbwpCore::REVISION);

    $templates = get_option('aboon-shop-export-templates');

    if(isset($_POST['template-load'])){
      $_POST['template'] = $templates[$_POST['template-selected']];
    }

    echo '
      <div class="wrap">
        <h1>Export</h1>';

    if(is_array($templates) && !empty($templates)){
      echo '<h2>Vorlagen</h2>
      <form method="post"><select name="template-selected" required><option disabled selected>Vorlage wählen...</option>';

      foreach ($templates as $key => $template){
        echo '<option value="' . $key . '">' . $template['template-name'] . '</option>';
      }

      echo '</select>
        <input type="submit" class="button button-primary" name="template-load" value="Vorlage Laden">
        <input type="submit" class="button button-secondary" name="template-delete" value="Volage Löschen" style="background: #b32d2e; color: #531111; border-color: #531111">
      </form><br>';
    }

    if(isset($_POST['template'])){
      echo '<p><b><i>Vorlage «' . $_POST['template']['template-name'] . '» geladen.</i></b></p>';
    }

    if(isset($_POST['template-delete'])){
      echo '<p><b><i>Vorlage wurde gelöscht.</i></b></p>';
    }

    $filterProducts = '';
    if(is_array($_POST['template']['productList'])){
      foreach($_POST['template']['productList'] as $id){
        $filterProducts .= '<option value="' . $id .'" selected>' . wc_get_product($id)->get_name() . '</option>';
      }
    }

    echo '
        <form method="POST">
          <h2>Datentyp</h2>
          <p><label><input type="radio" name="postType" value="shop_order"' .
          (!isset($_POST['template']) || $_POST['template']['postType'] === 'shop_order' ? ' checked' : '') . '> Bestellungen</label></p>
          ' . (($isSubscriptions) ? '<p><label><input type="radio" name="postType" value="shop_subscription"' .
          ($_POST['template']['postType'] === 'shop_subscription' ? ' checked' : '') . '> Abonnemente</label></p>' : '') . '
  
          <div class="status-selector status-order">
            <h2>Bestellstatus</h2>
            <p>
              <select multiple="multiple" name="postStatus[]">
                ' . $this->getOrderStatuses() . '
              </select>
            </p>
          </div>
          
          ' . (($isSubscriptions) ? '
            <div class="status-selector status-subscription" style="display:none;">
            <h2>Abonnementstatus</h2>
            <p>
              <select multiple="multiple" name="postStatusSubs[]">
                ' . $this->getSubscriptionStatuses() . '
              </select>
            </p>
          </div>
          ' : '') . '
  
          <h2>Optionen</h2>
          <p><label><input type="checkbox" name="addPositions" value="1"' .
            (!isset($_POST['template']) || $_POST['template']['addPositions'] === '1' ? ' checked' : '') .'> Positionen & Mengen exportieren</label></p>
          <p>&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="addPositionVariant" value="1"' .
            ($_POST['template']['addPositionVariant'] === '1' ? ' checked' : '') .'> Variante der Position in gleicher Zeile exportieren</label></p>
          <p>&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="addPositionMeta" value="1"' .
            ($_POST['template']['addPositionMeta'] === '1' ? ' checked' : '') .'> Metadaten aller Positionen exportieren</label></p>
          <p>&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="posQuantityColumn" value="1"' .
            ($_POST['template']['posQuantityColumn'] === '1' ? ' checked' : '') .'> Einzelne Spalte für Quantität pro Position</label></p>
          <p>&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="onePosPerLine" value="1"' .
            ($_POST['template']['onePosPerLine'] === '1' ? ' checked' : '') .'> Eine Zeile pro Position</label></p>
          <p><label><input type="checkbox" name="addPositionCoupons" value="1"' .
            ($_POST['template']['addPositionCoupons'] === '1' ? ' checked' : '') .'> angewendete Gutscheine exportieren</label></p>
          <p><label><input type="checkbox" name="addPositionSales" value="1"' .
            ($_POST['template']['addPositionSales'] === '1' ? ' checked' : '') .'> Umsatz anzeigen</label></p>
          
          <h2>Adressen</h2>
          <p>
            <select name="addressExportType">
              <option value="none"' .
                ($_POST['template']['addressExportType'] === 'none' ? ' selected' : '') .'>Keine Adressen exportieren</option>
              <option value="shipping"' .
                ($_POST['template']['addressExportType'] === 'shipping' ? ' selected' : '') .'>Liefer-Adressen (Fallback Rechnungs-Adresse) exportieren</option>
              <option value="billing"' .
                ($_POST['template']['addressExportType'] === 'billing' ? ' selected' : '') .'>Rechnungs-Adressen exportieren</option>
              <option value="both"' .
                ($_POST['template']['addressExportType'] === 'both' ? ' selected' : '') .'>Rechnungs- und Liefer-Adressen exportieren</option>
            </select>
          </p>
          
          <h2>Datumsbereich</h2>
          <p>Leer lassen um gesamte Datenbank zu exportieren.</p>
          <p>
            <label><input type="text" name="dateFrom" class="jq-date" value="' . ($_POST['template']['dateFrom'] ?? '') . '" placeholder="Datum von"></label>
            <label> <input type="text" name="dateTo" class="jq-date" value="' . ($_POST['template']['dateTo'] ?? '') . '" placeholder="Datum bis"></label>
          </p>
          
          <h2>Produktfilter</h2>
          <p><label><select name="productList[]" multiple placeholder="Produkte suchen..." style="min-width: 250px">
          ' . $filterProducts . '
          </select></label></p>
          
          <h2>Exportformat</h2>
          <p><label><input type="radio" name="export-type" value="csv"' .
            (!isset($_POST['template']['export-type']) || $_POST['template']['export-type'] === 'csv' ? ' checked' : '') .'>CSV</label></p>
          <p><label><input type="radio" name="export-type" value="excel"' .
            ($_POST['template']['export-type'] === 'excel' ? ' checked' : '') .'>Excel</label></p>
          
          <br>
          <p>
            <label><b>Als Vorlage speichern</b><br>
              <input type="text" name="template-name" placeholder="Name der Vorlage">
            </label>
            <input type="submit" class="button button-primary" name="template-save" value="Vorlage Speichern">
          </p>
          <input type="submit" class="button button-primary" name="shop-export" value="Exportieren">
        </form>
      </div>
    ';

    echo "
      <script>
        jQuery(function() {
          jQuery('[name=postType]').on('change', function() {
            let elem = jQuery(this);
            let type = elem.val().split('_')[1];
            jQuery('.status-selector').hide();
            jQuery('.status-' + type).show();
          });
          jQuery('[name=postType]:checked').trigger('change');
          
          jQuery('.jq-date').datepicker({
            dateFormat: 'yy-mm-dd'
          });
          
          jQuery('[name=\"productList[]\"]').select2({
            placeholder: 'Produkte suchen...',
            multiple: true,
            minimumInputLength: 4,
            closeOnSelect: false,
            ajax: {
              url: LbwpBackend.globals.rest_route + 'wc/v3/products',
              dataType: 'json',
              type: 'GET',
              data : function(params){
                return {
                  search : params.term
                }
              },
              processResults : function(response){
                let data = [];
                
                response.forEach((item) => {
                  data.push({
                    id : item.id,
                    text : item.name
                  });
                })
                
                return{
                  results: data
                };
              }
            }
          });
        });
      </script>
    ";
  }

  /**
   * @return void
   */
  protected function exportCSVSubscriptions()
  {
    $this->raiseLimits();

    $addPositions = intval($_POST['addPositions']) === 1;
    $addQuantityCol = intval($_POST['posQuantityColumn']) === 1;
    $addPositionSales = intval($_POST['addPositionSales']) === 1;
    $linePerPosition = intval($_POST['onePosPerLine']) === 1;
    $cols = array('ID', 'Status', 'Abo Start', 'Nächste Zahlung');

    $customFields = apply_filters('aboon_export_additional_columns_list_sub', array());
    $useAddressDefaults = apply_filters('aboon_export_use_simple_address_defaults_sub', true);
    $usePaymentDefaults = apply_filters('aboon_export_use_payment_defaults_sub', true);
    if ($useAddressDefaults) {
      $this->addAddressCols($cols);
    }
    if ($usePaymentDefaults) {
      $cols = array_merge($cols, array('Zahlungsart', 'Versandoption', 'Bemerkung'));
    }
    if ($addPositions) {
      if ($addQuantityCol) {
        $cols[] = 'Pos. Quantität';
      }
      $cols[] = 'Positionen';
    }
    if ($addPositionSales) {
      $cols[] = 'Pos. Preis';
      $cols[] = 'Pos. Total';
      $cols[] = 'Umsatz';
      $cols[] = 'Versandkosten';
      $cols[] = 'Total';
    }

    if (count($customFields) > 0) {
      $cols = array_merge($cols, $customFields);
    }

    $csvName = 'export-' . Strings::forceSlugString(get_bloginfo('title')) . date('d-m-Y', time());
    $csvContent = array($cols);
    $queryArgs = array(
      'subscriptions_per_page' => -1,
      'orderby' => 'start_date',
      'order' => 'DESC',
      'subscription_status' => $_POST['postStatusSubs']
    );

    // Subscriptions cannot query by date, count how many and override the limit to exactly that number
    // That is ignored with HPOS as it just accounts in performance, not functionality
    if (strlen($_POST['dateFrom']) > 0 && strlen($_POST['dateTo']) > 0) {
      $db = WordPress::getDb();
      if (!Util::isHposActive()) {
        $queryArgs['subscriptions_per_page'] = intval($db->get_var('
          SELECT COUNT(ID) FROM ' . $db->posts . ' WHERE post_type = "shop_subscription"
          AND post_date BETWEEN "' . $_POST['dateFrom'] . '" AND "' . $_POST['dateTo'] . '"
        '));
      }
    }

    $subscriptions = wcs_get_subscriptions($queryArgs);

    /** @var \WC_Subscription $order */
    foreach ($subscriptions as $order) {
      // Skip if refund order
      if ($order instanceof OrderRefund) {
        continue;
      }

      $row = array(
        $order->get_id(),
        $order->get_status(),
        Date::getTime(Date::EU_DATETIME, $order->get_time('date_created')),
        Date::getTime(Date::EU_DATETIME, $order->get_time('next_payment'))
      );
      $email = $order->get_billing_email();
      $phone = $order->get_billing_phone();

      if ($useAddressDefaults && $_POST['addressExportType'] != 'none') {
        $this->addAddressData($row, $order, $email, $phone);
      }

      if ($usePaymentDefaults) {
        $row[] = $order->get_payment_method_title();
        $row[] = $order->get_shipping_method();
        $row[] = $order->get_customer_note();
      }

      if ($addPositions || is_array($_POST['productList'])) {
        $posQty = '';
        $positions = '';

        if(is_array($_POST['productList'])){
          $productIsInList = in_array(true, array_map(function($item){
            return in_array($item->get_data()['product_id'], $_POST['productList']);
          }, $order->get_items()));

          if(!$productIsInList){
            continue;
          }
        }

        foreach ($order->get_items() as $item) {
          $posQty .= $item->get_quantity() . PHP_EOL;
          $positions .= $item->get_quantity() . 'x ' . $item->get_name() . PHP_EOL;
        }

        if($positions === ''){
          continue;
        }

        // Remove the last PHP_EOL
        if ($addQuantityCol) {
          $posQty = substr($posQty, 0, strlen($posQty) - strlen(PHP_EOL));
          $row[] = $posQty;
        }
        $positions = substr($positions, 0, strlen($positions) - strlen(PHP_EOL));
        $row[] = $positions;
      }

      if ($addPositionSales) {
        $possingle = $postotal = '';
        $orderItemTotal = 0;
        foreach ($order->get_items() as $item) {
          $total = round($item->get_total() + $item->get_total_tax(), 2);
          $postotal .= $total . PHP_EOL;
          $possingle .= round($total / $item->get_quantity(), 2) . PHP_EOL;
          $orderItemTotal += $total;
        }
        // Remove the last PHP_EOL
        $row[] = substr($possingle, 0, strlen($possingle) - strlen(PHP_EOL));
        $row[] = substr($postotal, 0, strlen($postotal) - strlen(PHP_EOL));
        $row[] = round($orderItemTotal, 2);
        $row[] = round($order->get_shipping_total() + $order->get_shipping_tax(), 2);
        $row[] = $order->get_total();
      }

      if (count($customFields) > 0) {
        $row = apply_filters('aboon_export_add_custom_row_data', $row, $customFields, $order);
      }

      $csvContent[] = $row;
    }

    // Maybe rework and have a line per PHP_EOL instead
    if ($linePerPosition) {
      $csvContent = $this->linePerPositonRework($csvContent, $cols);
    }

    switch ($_POST['export-type']) {
      case 'excel':
        Csv::downloadExcel($csvContent, $csvName);
        break;

      default:
        Csv::downloadFile($csvContent, $csvName, ',');
    }
  }

  /**
   * This reworks the array into single lines, when cells contain PHP_EOL
   * @return array
   */
  protected function linePerPositonRework($old, $cols)
  {
    $new = array();
    $blacklistIndex[] = array_search('Bemerkung', $cols);
    foreach ($old as $line) {
      $countEol = 0;
      $indexWithEol = array();
      foreach ($line as $index => $cell) {
        if (str_contains($cell, PHP_EOL) && !in_array($index, $blacklistIndex)) {
          $indexWithEol[] = $index;
          if ($countEol == 0) {
            $countEol = substr_count($cell, PHP_EOL);
          }
        }
      }
      // Now rework if there are eols
      if ($countEol > 0) {
        for ($i = 0; $i <= $countEol; ++$i) {
          $newLine = $line;
          foreach ($indexWithEol as $index) {
            $content = explode(PHP_EOL, $newLine[$index]);
            $newLine[$index] = $content[$i];
          }
          $new[] = $newLine;
        }
      } else {
        // If no eol, just re-add the single line
        $new[] = $line;
      }
    }

    return $new;
  }

  /**
   * @param $cols
   * @return void
   */
  protected function addAddressCols(&$cols)
  {
    if ($_POST['addressExportType'] == 'billing' || $_POST['addressExportType'] == 'both') {
      $cols = array_merge($cols, array('Rechn. Vorname', 'Rechn. Nachname', 'Rechn. Firma', 'Rechn. Email', 'Rechn. Telefon', 'Rechn. Adresse', 'Rechn. PLZ', 'Rechn. Ort'));
    }
    if ($_POST['addressExportType'] == 'shipping' || $_POST['addressExportType'] == 'both') {
      $cols = array_merge($cols, array('Lief. Vorname', 'Lief. Nachname', 'Lief. Firma', 'Lief. Email', 'Lief. Telefon', 'Lief. Adresse', 'Lief. PLZ', 'Lief. Ort'));
    }
  }

  /**
   * @param array $row
   * @param \WC_Order $order
   * @param string $email
   * @param string $phone
   * @return void
   */
  protected function addAddressData(&$row, $order, $email, $phone)
  {
    switch ($_POST['addressExportType']) {
      case 'shipping':
        if ($order->get_billing_address_1() !== $order->get_shipping_address_1() && !empty($order->get_shipping_address_1())) {
          // Use shipping if given
          $row[] = $order->get_shipping_first_name();
          $row[] = $order->get_shipping_last_name();
          $row[] = $order->get_shipping_company();
          $row[] = $email;
          $row[] = $phone;
          $row[] = $order->get_shipping_address_1();
          $row[] = $order->get_shipping_postcode();
          $row[] = $order->get_shipping_city();
        } else {
          $row[] = $order->get_billing_first_name();
          $row[] = $order->get_billing_last_name();
          $row[] = $order->get_billing_company();
          $row[] = $email;
          $row[] = $phone;
          $row[] = $order->get_billing_address_1();
          $row[] = $order->get_billing_postcode();
          $row[] = $order->get_billing_city();
        }
        break;
      case 'both':
      case 'billing':
        $row[] = $order->get_billing_first_name();
        $row[] = $order->get_billing_last_name();
        $row[] = $order->get_billing_company();
        $row[] = $email;
        $row[] = $phone;
        $row[] = $order->get_billing_address_1();
        $row[] = $order->get_billing_postcode();
        $row[] = $order->get_billing_city();
        if ($_POST['addressExportType'] == 'both') {
          $row[] = $order->get_shipping_first_name();
          $row[] = $order->get_shipping_last_name();
          $row[] = $order->get_shipping_company();
          $row[] = '';
          $row[] = '';
          $row[] = $order->get_shipping_address_1();
          $row[] = $order->get_shipping_postcode();
          $row[] = $order->get_shipping_city();
        }
        break;
    }
  }

  protected function raiseLimits()
  {
    global $wp_object_cache;
    $wp_object_cache->can_write = false;
    ini_set('memory_limit', '2048M');
  }

  /**
   * @return void
   */
  protected function exportCSVOrders()
  {
    $this->raiseLimits();
    $addPositions = intval($_POST['addPositions']) === 1;
    $addPositionMeta = intval($_POST['addPositionMeta']) === 1;
    $addPositionCoupons = intval($_POST['addPositionCoupons']) === 1;
    $addPositionSales = intval($_POST['addPositionSales']) === 1;
    $linePerPosition = intval($_POST['onePosPerLine']) === 1;
    $addQuantityCol = intval($_POST['posQuantityColumn']) === 1;
    $addPositionVariant = intval($_POST['addPositionVariant']) === 1;
    $cols = array('ID', 'Bestelldatum', 'Bestellstatus');

    $customFields = apply_filters('aboon_export_additional_columns_list', array());
    $useAddressDefaults = apply_filters('aboon_export_use_simple_address_defaults', true);
    $usePaymentDefaults = apply_filters('aboon_export_use_payment_defaults', true);
    if ($useAddressDefaults) {
      $this->addAddressCols($cols);
    }
    if ($usePaymentDefaults) {
      $cols = array_merge($cols, array('Zahlungsart', 'Versandoption', 'Bemerkung'));
    }
    if ($addPositions) {
      if ($addQuantityCol) {
        $cols[] = 'Pos. Quantität';
      }
      $cols[] = 'Positionen';
    }
    if ($addPositionCoupons) $cols[] = 'Gutscheine';
    if ($addPositionSales) {
      $cols[] = 'Pos. Preis';
      $cols[] = 'Pos. Total';
      $cols[] = 'Umsatz';
      $cols[] = 'Versandkosten';
      $cols[] = 'Total';
    }

    if (count($customFields) > 0) {
      $cols = array_merge($cols, $customFields);
    }

    $csvName = 'export-' . Strings::forceSlugString(get_bloginfo('title')) . date('d-m-Y', time());
    $csvContent = array($cols);
    $queryArgs = array(
      'limit' => -1,
      'status' => $_POST['postStatus']
    );

    if (strlen($_POST['dateFrom']) > 0 && strlen($_POST['dateTo']) > 0) {
      $queryArgs['date_created'] = $_POST['dateFrom'] . '...' . $_POST['dateTo'];
    }

    $orders = wc_get_orders($queryArgs);

    foreach ($orders as $order) {
      // Skip if refund order
      if ($order instanceof \WC_Order_Refund) {
        continue;
      }

      $row = array(
        $order->get_id(),
        (string)$order->get_date_created(),
        $order->get_status()
      );
      $email = $order->get_billing_email();
      $phone = $order->get_billing_phone();

      if ($useAddressDefaults && $_POST['addressExportType'] != 'none') {
        $this->addAddressData($row, $order, $email, $phone);
      }

      if ($usePaymentDefaults) {
        $row[] = $order->get_payment_method_title();
        $row[] = $order->get_shipping_method();
        $row[] = $order->get_customer_note();
      }

      if ($addPositions || is_array($_POST['productList'])) {
        $posQty = '';
        $positions = '';

        if(is_array($_POST['productList'])){
          $productIsInList = in_array(true, array_map(function($item){
            return in_array($item->get_data()['product_id'], $_POST['productList']);
          }, $order->get_items()));

          if(!$productIsInList){
            continue;
          }
        }

        foreach ($order->get_items() as $item) {
          $posQty .= $item->get_quantity() . PHP_EOL;
          $positions .= $item->get_quantity() . 'x ' . $item->get_name();
          if ($addPositionVariant) {
            foreach ($item->get_meta_data() as $meta) {
              if ($meta->key == 'Variante') {
                $positions .= ' (' . $meta->value . ')';
              }
            }
          }
          $positions .= PHP_EOL;
          if ($addPositionMeta) {
            foreach ($item->get_meta_data() as $meta) {
              if (!str_starts_with($meta->key, '_') && !str_contains($meta->value, '{')) {
                $positions .= $meta->key . ': ' . $meta->value . PHP_EOL;
              }
            }
          }
        }

        if($positions === ''){
          continue;
        }

        // Remove the last PHP_EOL
        if ($addQuantityCol) {
          $posQty = substr($posQty, 0, strlen($posQty) - strlen(PHP_EOL));
          $row[] = $posQty;
        }
        $positions = substr($positions, 0, strlen($positions) - strlen(PHP_EOL));
        $row[] = $positions;
      }

      if ($addPositionCoupons) {
        $row[] = implode(', ', $order->get_coupon_codes());
      }

      if ($addPositionSales) {
        $possingle = $postotal = '';
        $orderItemTotal = 0;
        foreach ($order->get_items() as $item) {
          $total = round($item->get_total() + $item->get_total_tax(), 2);
          $postotal .= $total . PHP_EOL;
          $possingle .= round($total / $item->get_quantity(), 2) . PHP_EOL;
          $orderItemTotal += $total;
        }
        // Remove the last PHP_EOL
        $row[] = substr($possingle, 0, strlen($possingle) - strlen(PHP_EOL));
        $row[] = substr($postotal, 0, strlen($postotal) - strlen(PHP_EOL));
        $row[] = round($orderItemTotal, 2);
        $row[] = round($order->get_shipping_total() + $order->get_shipping_tax(), 2);
        $row[] = $order->get_total();
      }

      if (count($customFields) > 0) {
        $row = apply_filters('aboon_export_add_custom_row_data', $row, $customFields, $order);
      }

      $csvContent[] = $row;
    }

    // Maybe rework and have a line per PHP_EOL instead
    if ($linePerPosition) {
      $csvContent = $this->linePerPositonRework($csvContent,$cols);
    }

    switch ($_POST['export-type']) {
      case 'excel':
        Csv::downloadExcel($csvContent, $csvName);
        break;

      default:
        Csv::downloadFile($csvContent, $csvName, ',');
    }
  }
}