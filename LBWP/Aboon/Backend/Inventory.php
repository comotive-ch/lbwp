<?php

namespace LBWP\Aboon\Backend;

use LBWP\Core;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\WooCommerce\Util;
use LBWP\Helper\ZipDistance;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Provide invotory functions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class Inventory extends ACFBase
{
  const INVENTORY_SLUG = 'inventory-article';
  const BOOKING_SLUG = 'inventory-booking';
  const SENDING_SLUG = 'order-sending';
  const PRODUCT_GROUP_SLUG = 'product-group';
  const LOG_HISTORY_DAYS = 270;

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    // Register a twice daily job to do bookings and warn on low stock afterwards
    add_action('cron_daily_9', array($this, 'doBookings'));
    add_action('cron_daily_9', array($this, 'warnLowStock'));
    add_action('cron_daily_20', array($this, 'doBookings'));
    add_action('cron_daily_23', array($this, 'syncInventoryProducts'));
    add_action('cron_monthly_10_23', array($this, 'logHistoryMerge'));
    add_action('admin_footer', array($this, 'printScripts'));
    add_action('add_meta_boxes_' . self::SENDING_SLUG, array($this, 'sendingListTableMetabox'));
    add_action('add_meta_boxes', array($this, 'handleMetaboxes'));
    add_action('admin_menu', array($this, 'addCustomPages'));
    add_filter('duplicateable_post_types', array($this, 'addDuplicateability'));

    // Various controllers
    $this->eventuallyDoManualOrderBooking();
    $this->eventuallyDoManualPhysicalOrderBooking();
    $this->eventuallyDoManualBooking();
    $this->eventuallyDoManualStatusChange();
    $this->eventuallyExportDirectBookings();
    $this->addTypeConfig();
    $this->setupPriceAlarm();

    add_action('acf/input/admin_head', array($this, 'addAcfMetaboxes'));
    add_action('acf/options_page/save', array($this, 'productGroupActions'));
  }

  /**
   * @return array
   */
  protected function getSkuToInventoryIdMap()
  {
    $db = WordPress::getDb();
    $map = array();
    $raw = $db->get_results('
      SELECT post_id, meta_value FROM ' . $db->postmeta . '
      WHERE meta_key = "provider-sku"
    ');

    foreach ($raw as $row) {
      $map[$row->meta_value] = $row->post_id;
    }

    return $map;
  }

  public function setup()
  {
    parent::setup();

    WordPress::registerTaxonomy(
      self::PRODUCT_GROUP_SLUG,
      'Warengruppe',
      'Warengruppen',
      '',
      array('public' => false),
      self::INVENTORY_SLUG
    );
  }

  /**
   * @param $allowedTypes
   * @return mixed
   */
  public function addDuplicateability($allowedTypes)
  {
    $allowedTypes[] = self::SENDING_SLUG;
    $allowedTypes[] = self::INVENTORY_SLUG;
    return $allowedTypes;
  }

  /**
   * @return void
   */
  public function sendingListTableMetabox()
  {
    global $post;
    $orderIds = $this->getSendingOrderIds();
    if (count($orderIds) == 0) {
      return;
    }

    add_meta_box(
      self::SENDING_SLUG . '-box-sending-list',
      'Lieferliste: ' . $post->post_title,
      array($this, 'getSendingListTable'),
      self::SENDING_SLUG,
      'normal',
      'core',
    );

    if (isset($_GET['show']) & $_GET['show'] == 'route-map') {
      add_meta_box(
        self::SENDING_SLUG . '-box-route-map',
        'Routenkarte: ' . $post->post_title,
        array($this, 'getRouteMap'),
        self::SENDING_SLUG,
        'normal',
        'core',
      );
    }
  }

  public function getRouteMap()
  {
    global $routeAddressList;
    $routeAddressList = apply_filters('lbwp_aboon_inventory_pre_build_routemap_adresses', $routeAddressList);
    ?>
    <!-- Include Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
    <!-- Include Leaflet Routing Machine CSS -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/leaflet-routing-machine/3.2.12/leaflet-routing-machine.css"/>
    <style>
        #map {
            height: 400px;
        }

        .number-icon {
            background-color: #555;
            border-radius: 50%;
            color: #fff;
            width: 22px !important;
            height: 22px !important;
            text-align: center;
            line-height: 20px;
            font-weight: bold;
        }
    </style>
    <!-- Include Leaflet JavaScript -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <!-- Include Leaflet Routing Machine JavaScript -->
    <script
      src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-routing-machine/3.2.12/leaflet-routing-machine.js"></script>
    <div id="map">Karte lädt.</div>

    <script>
      // Array of addresses
      const addresses = <?php echo json_encode($routeAddressList); ?>;
      let globalCounter = 1;

      // Function to initialize map and draw routes
      function initMap() {
        const map = L.map('map').setView([47.149080, 7.553], 9); // Centered at USA

        // Add OpenStreetMap tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Loop through addresses and create markers
        const bounds = new L.LatLngBounds();
        const waypoints = [];
        const promises = addresses.map((address, index) => {
          return fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${address}`)
            .then(response => response.json())
            .then(data => {
              if (data && data.length > 0) {
                const latlng = [parseFloat(data[0].lat), parseFloat(data[0].lon)];
                L.marker(latlng, {
                  icon: L.divIcon({
                    className: 'number-icon',
                    html: index + 1
                  })
                }).addTo(map).bindPopup(address);
                // Use indext for wayoints so they're in correct order
                waypoints[index] = latlng;
                bounds.extend(latlng);
              }
            });
        });

        // Wait for all promises to complete
        Promise.all(promises).then(() => {
          // Fit map to markers
          map.fitBounds(bounds);
          // Add route drawing
          if (waypoints.length >= 2) {
            L.Routing.control({
              waypoints: waypoints,
              routeWhileDragging: false
            }).addTo(map);
          }
        });
      }

      // Initialize the map
      setTimeout(function () {
        initMap();
      }, 3000);
    </script>
    <?php
  }

  /**
   * @return void
   */
  public function addCustomPages()
  {
    add_submenu_page(
      'edit.php?post_type=' . self::SENDING_SLUG,
      'Ungeplant',
      'Ungeplant',
      'manage_options',
      'unplanned-orders',
      array($this, 'showOpenOrdersList')
    );
  }

  /**
   * @return void
   */
  public function showOpenOrdersList()
  {
    $html = '
      <h2>Nicht eingeplante Bestellungen</h2>
    ';

    // Get all already planned orders
    $db = WordPress::getDb();
    $plannedIds = array_map('intval', $db->get_col('
      SELECT meta_value FROM ' . $db->postmeta . '
      WHERE meta_key LIKE "orders_%_id"
    '));

    // Get all open orders, not in the list of already planned ones
    $orders = wc_get_orders(array(
      'limit' => -1,
      'orderby' => 'date',
      'order' => 'ASC',
      'status' => array('on-hold', 'processing'),
      'post__not_in' => $plannedIds
    ));

    $html .= '
      <p class="container-filter-shipping"></p>
      <table class="wp-list-table widefat fixed striped table-view-list" style="width:99%">
      <thead>
      <tr>
        <td style="width:5%">ID</td>
        <td style="width:10%">Datum</td>
        <td style="width:20%">Adresse</td>
        <td style="width:15%">Versandart</td>
        <td style="width:30%">Produkte</td>
        <td style="width:10%">Versandvorschlag</td>
        <td style="width:20%">Kommentar</td>
      </tr>
      </thead>
      <tbody>
    ';

    $shippingMethods = array();
    $postCodeSendMap = $this->getPostCodeSendMap();
    $autoSuggestMethods = apply_filters('lbwp_aboon_inventory_autosuggest_shipping_methods', array());
    foreach ($orders as $order) {
      $address = Util::getDeliveryAddressWithFallback($order);
      $method = $this->translateShippingMethod($order->get_shipping_method());
      $shippingMethods[$method] = true;
      $html .= '
        <tr data-shipping="' . $method . '">
          <td>
              <strong><a href="' . $order->get_edit_order_url() . '">#' . $order->get_id() . '</a></strong>
          </td>
          <td>
              ' . date('d.m.Y', strtotime($order->get_date_created())) . '
          </td>
          <td>
            ' . $address['company'] . ' ' . $address['firstname'] . ' ' . $address['lastname'] . '<br>
            ' . $address['street'] . ' ' . $address['addition'] . '<br>
            ' . $address['postcode'] . ' ' . $address['city'] . '
          </td>
          <td>' . $method . '</td>
          <td>
            ' . $this->getOrderPositionHtmlList($order) . '
          </td>
          <td>' . $this->autoSuggestDelievery($method, $address['postcode'], $postCodeSendMap, $autoSuggestMethods) . '</td>
          <td>' . nl2br($order->get_customer_note()) . '</td>
        </tr>
      ';
    }

    $html .= '</tbody></table>';
    // Mini script to filter by method
    $html .= '
      <script>
        jQuery(function() {
          let container = jQuery(".container-filter-shipping");
          let shippingMethods = ' . json_encode(array_keys($shippingMethods)) . ';
          let html = "";
          shippingMethods.forEach(function(method) {
            html += "<a class=\"filter-shipping\" data-method=\"" + method + "\">" + method + "</a> | ";
          });
          container.append("| " + html)
          
          jQuery(".filter-shipping").on("click", function() {
            let table = jQuery(".wp-list-table tbody");
            let method = jQuery(this).data("method");
            table.find("tr").hide();
            table.find("tr[data-shipping=" + method + "]").show();
          });
        });
      </script>
    ';
    echo $html;
  }

  /**
   * @param string $method
   * @param int $postcode
   * @param array $map
   * @return void
   */
  public function autoSuggestDelievery($method, $postcode, $map, $available)
  {
    if (!in_array($method, $available)) {
      return 'N/A';
    }

    $nearest = array(
      'distance' => 1000000,
      'postcode' => 0,
      'sending' => 0
    );
    foreach ($map as $sendingId => $postcodes) {
      $nearestPostCode = 0;
      $nearestDistance = $nearest['distance'];
      foreach ($postcodes as $candidate) {
        $distance = ZipDistance::getDistance($postcode, $candidate);
        if ($distance < $nearestDistance) {
          $nearestDistance = $distance;
          $nearestPostCode = $candidate;
        }
      }

      if ($nearestDistance < $nearest['distance']) {
        $nearest['distance'] = $nearestDistance;
        $nearest['postcode'] = $nearestPostCode;
        $nearest['sending'] = $sendingId;
      }
    }

    if ($nearest['sending'] > 0) {
      return '
        <a href="/wp-admin/post.php?post=' . $nearest['sending'] . '&action=edit">#' . $nearest['sending'] . '</a>
        via PLZ ' . $nearest['postcode'] . ', ca. ' . round($nearest['distance'] / 1000, 0) . ' Km
      ';
    }

    return 'Kein Treffer';
  }

  /**
   * @return void
   */
  protected function getPostCodeSendMap()
  {
    $map = array();
    // Get every sending in the future
    $sendings = get_posts(array(
      'post_type' => self::SENDING_SLUG,
      'post_status' => 'future',
      'posts_per_page' => -1
    ));

    // Only take sammelbuchungen
    foreach ($sendings as $sending) {
      if (get_field('sending-type', $sending->ID) !== 'collective') {
        continue;
      }

      // Get connected orderIds
      $orderIds = get_field('orders', $sending->ID);
      $orderIds = ArrayManipulation::getSpecifiedKeyArray($orderIds, 'id');

      if (count($orderIds) > 0) {
        $map[$sending->ID] = array();
        foreach ($orderIds as $orderId) {
          $order = wc_get_order($orderId);
          if ($order instanceof \WC_Order) {
            if (strlen($order->get_shipping_postcode()) > 0) {
              $map[$sending->ID][] = $order->get_shipping_postcode();
            } else {
              $map[$sending->ID][] = $order->get_billing_postcode();
            }
          }
        }
      }
    }

    return $map;
  }

  /**
   * Must be overridden to individualize
   * @param $method
   * @return mixed
   */
  protected function translateShippingMethod($method)
  {
    return $method;
  }

  /**
   * @return array
   */
  protected function getSendingOrderIds()
  {
    return ArrayManipulation::forceArray(get_field('orders'));
  }

  /**
   * @return void
   */
  public function handleMetaboxes()
  {
    add_meta_box(
      'shop-order__inventory-info',
      'Inventar',
      array($this, 'showOrderInventoryBookStatus'),
      Util::isHposActive() ? wc_get_page_screen_id(str_replace('_', '-', 'shop_order')) : 'shop_order',
      'side',
      'default',
    );

    $product = wc_get_product(get_the_ID());
    if ($product instanceof \WC_Product) {
      $bookables = get_field('bookables');
      if (is_array($bookables) && count($bookables) > 0) {
        add_meta_box(
          'shop-product_margin-calculator',
          'Margenberechnung',
          array($this, 'showMarginCalculator'),
          'product',
          'side',
          'default',
        );
      }
    }
  }

  /**
   * @return void
   */
  public function showMarginCalculator()
  {
    $bookables = get_field('bookables');
    // Get invested price
    $investPrice = 0;
    foreach ($bookables as $inventory) {
      $investPrice += floatval($inventory['count']) * floatval(get_post_meta($inventory['inventory-id'], 'value-position', true));
    }
    // Get sale price excluding tax
    $salePrice = floatval(get_post_meta(get_the_ID(), '_price', true));

    // Check if invested price is set
    if ($salePrice <= 0) {
      echo '<p>Der Verkaufspreis ist nicht gesetzt. Bitte zuerst den Verkaufspreis setzen.</p>';
      return;
    }

    $taxrates = \WC_Tax::get_rates_for_tax_class('standard');
    $taxrate = array_pop($taxrates);
    $salePrice = $salePrice / (1 + (floatval($taxrate->tax_rate) / 100));
    // Calculate percent difference between sale and invest price
    $percent = 100 - (($investPrice / $salePrice) * 100);

    // Display everything
    echo '
      <p>Angaben exkl. ' . number_format($taxrate->tax_rate, 1) . '% MwSt.</p>
      <p>
        <strong>Investition:</strong> ' . number_format($investPrice, 2, ',', '.') . ' CHF<br>
        <strong>Verkaufspreis:</strong> ' . number_format($salePrice, 2, ',', '.') . ' CHF<br>
        <strong>Gewinn:</strong> ' . number_format($salePrice - $investPrice, 2, ',', '.') . ' CHF<br>
        <strong>Marge:</strong> ' . number_format($percent, 2, ',', '.') . ' %
      </p>
    ';
  }

  /**
   * @return void
   */
  protected function eventuallyExportDirectBookings()
  {
    if (isset($_GET['page']) && isset($_GET['export']) && $_GET['page'] == 'aboon-direct-book') {
      $data = array(
        array('Datum', 'Buchungstext', 'Soll', 'Haben', 'Betrag', 'MwstCode')
      );
      $table = get_field('bookings', 'option');
      foreach ($table as $row) {
        $data[] = array(
          $row['date'], $row['text'], $row['soll'], $row['haben'], $row['value'], $row['taxcode'],
        );
      }

      Csv::downloadExcel($data, 'direct-bookings');
    }
  }

  /**
   * @return void
   */
  public function eventuallyDoManualStatusChange()
  {
    $orderId = isset($_GET['id']) ? intval($_GET['id']) : intval($_GET['post']);
    if ($orderId > 0 && isset($_GET['change-status']) && $_GET['action'] == 'edit') {
      $order = wc_get_order(intval($orderId));
      $status = Strings::forceSlugString($_GET['change-status']);
      // Change status but prevent emails being triggered
      add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
      add_filter('woocommerce_email_enabled_customer_customer_on_hold_order', '__return_false');
      $order->update_status($status, '', true);
    }
  }

  /**
   * @return void
   */
  public function getSendingListTable()
  {
    $html = '<table class="sending-delivery-list">';
    $orderIds = array();
    global $routeAddressList;
    $routeAddressList = array();
    foreach ($this->getSendingOrderIds() as $order) {
      if ($order['id'] > 0) {
        $orderIds[] = $order['id'];
      }
      $textParts = array(trim($order['info']));
      $order = wc_get_order($order['id']);
      if ($order instanceof \WC_Order) {
        $customerText = $order->get_customer_note();
        $couponCodes = implode(', ', $order->get_coupon_codes());
        if (strlen($customerText) > 0) {
          $textParts[] = $customerText;
        }
        if (strlen($couponCodes) > 0) {
          $textParts[] = strtoupper($couponCodes);
        }
        $text = implode(' // ', array_filter($textParts));
        $address = Util::getDeliveryAddressWithFallback($order);
        $routeAddressList[] = trim($address['street'] . ' ' . $address['addition'] . ', ' . $address['postcode'] . ' ' . $address['city']);
        $html .= '
          <tr>
            <td width="10%">
                <strong><a style="font-size:16px;text-decoration:none;" href="' . $order->get_edit_order_url() . '">#' . $order->get_id() . '</a></strong><br>
                ' . $order->get_status() . '
            </td>
            <td width="30%">
              ' . $address['company'] . ' ' . $address['firstname'] . ' ' . $address['lastname'] . '<br>
              ' . $address['street'] . ' ' . $address['addition'] . '<br>
              ' . $address['postcode'] . ' ' . $address['city'] . '
            </td>
            <td width="30%">
              ' . $order->get_billing_phone() . '<br>
              ' . $order->get_billing_email() . '
            </td>
            <td width="30%">Unterschrift/Quittiert<br><br><br></td>
          </tr>
          <tr class="line-after">
            <td><strong>Produkte:</strong></td>
            <td colspan="2">' . $this->getOrderPositionHtmlList($order) . '</td>
            <td>' . $text . '</td>
          </tr>
        ';
      } else {
        $html .= '
          <tr>
          <tr class="line-after">
            <td><strong>Spezial:</strong></td>
            <td colspan="3"><br>' . $text . '<br><br></td>
          </tr>
        ';
      }
    }
    $html .= '</table>';

    global $post;
    $additionalInfo = get_post_meta($post->ID, 'additional-info', true);
    if (strlen($additionalInfo) > 0) {
      $html .= '<p>' . nl2br($additionalInfo) . '</p>';
    }

    // Link to the packing slip endless pdf
    $url = '/wp-admin/admin-ajax.php?action=generate_wpo_wcpdf&_wpnonce=' . wp_create_nonce('generate_wpo_wcpdf') . '&document_type=packing-slip&bulk&order_ids=' . implode('x', $orderIds);
    $html .= '<a class="invisible-print" href="' . $url . '" target="_blank">Lieferscheine herunterladen/drucken</a> | ';
    $html .= '<a class="invisible-print" href="/wp-admin/post.php?post=' . $post->ID . '&action=edit&show=route-map">Routen-Karte anzeigen</a>';

    $html .= '
      <style>
        .sending-delivery-list {
          width:100%;
          border-collapse: collapse;
        }
        .sending-delivery-list td {
          padding: 3px;
          border:1px dotted #000;
        }
        .line-after td {
          border-bottom: 4px solid #777;
        }
        @page {
          size: A4;
          margin: 0cm;
        }
        @media print {
          #postbox-container-1,
          #order-sending-box-route-map,
          #wpadminbar, #adminmenumain,
          #post-body-content,
          #screen-options-link-wrap,
          .wp-heading-inline, .error,
          .page-title-action,
          .invisible-print,
          #acf-group_659e8e26b9541, 
          #wpfooter {
            display:none !important;
          }
          #wpcontent {
            margin-left:0px !important;
          }
          #post-body {
            margin-right:0px !important;
            width:100%;
          }
          html, body {
            margin:0px !important;
            padding:0px !important;
          }
        }
      </style>
    ';

    echo $html;
  }

  /**
   * @param \WC_Order $order
   * @return void
   */
  protected function getOrderPositionHtmlList($order)
  {
    $html = '';
    foreach ($order->get_items() as $item) {
      $html .= $item->get_quantity() . 'x ' . $item->get_name() . '<br>';
    }
    return $html;
  }

  /**
   * @return void
   */
  public function logHistoryMerge()
  {
    set_time_limit(900);
    $threshold = current_time('timestamp') - (self::LOG_HISTORY_DAYS * 86400);
    $db = WordPress::getDb();
    // Must get everything, as timestamp has different formats
    $raw = $db->get_results('
      SELECT post_id, meta_key, meta_value FROM ' . $db->postmeta . '
      WHERE meta_key LIKE "log_%_timestamp"
    ');

    $resultMap = array();
    foreach ($raw as $row) {
      // Convert to timestamp, if not
      if (!is_numeric($row->meta_value)) {
        $row->meta_value = strtotime($row->meta_value);
      } else {
        $row->meta_value = intval($row->meta_value);
      }

      // Remember which logs for which posts to remove
      if ($threshold > $row->meta_value) {
        list($type, $id, $field) = explode('_', $row->meta_key);
        $resultMap[$row->post_id][$id] = $row->meta_value;
      }
    }

    $fields = array('change', 'text', 'value', 'timestamp');
    // Get actual data, whilst deleting the records
    foreach ($resultMap as $postId => $logRows) {
      $deletedKeys = array();
      $historyData = get_post_meta($postId, 'log-history', true);
      $historyData = is_string($historyData) ? $historyData : '';
      $metaData = WordPress::getAccessiblePostMeta($postId);
      // Build history data and to be deleted keys
      foreach ($logRows as $id => $timestamp) {
        $historyData .= $metaData['log_' . $id . '_change'] . ';';
        $historyData .= $metaData['log_' . $id . '_text'] . ';';
        $historyData .= $metaData['log_' . $id . '_value'] . ';';
        $historyData .= Date::getTime(Date::EU_DATETIME, $timestamp) . ';' . PHP_EOL;
        // Deletekeys building
        foreach ($fields as $field) {
          $deletedKeys[] = 'log_' . $id . '_' . $field;
          $deletedKeys[] = '_log_' . $id . '_' . $field;
        }
      }

      // Save back history of the item
      update_post_meta($postId, 'log-history', trim($historyData));
      // Delete the meta keys for this post id
      $db->query('
        DELETE FROM ' . $db->postmeta . ' WHERE post_id = ' . $postId . '
        AND meta_key IN("' . implode('","', $deletedKeys) . '")
      ');

      // Now we have to rebuild all the existing meta entries and recount the rows
      $this->recountRepeaterEntries($postId, 'log', $fields);
    }

    MemcachedAdmin::flushFullCacheHelper();;
  }

  /**
   * @return void
   */
  public function doBookings()
  {
    // Get orders from he last three days
    $ts = current_time('timestamp');
    $from = date('Y-m-d', $ts - 86400);
    $to = date('Y-m-d', $ts);
    $now = date('Y-m-d H:i:s', $ts);

    $query = new \WC_Order_Query(array(
      'limit' => -1,
      'return' => 'objects',
      'status' => array('wc-processing', 'wc-on-hold', 'wc-completed'),
      'date_created' => $from . '...' . $to
    ));
    $orders = $query->get_orders();

    /** @var \WC_Order $order Go trough orders and do the bookings */
    foreach ($orders as $order) {
      $this->doOrderBooking($order, $now, false);
    }
  }

  /**
   * @return void
   */
  protected function eventuallyExportBookings()
  {
    // Validate user input generally (to prevent fraud)
    $query = Strings::forceSlugString($_POST['export-date']);

    // Get all of the inventory, as we need to filter by meta/listing/date ACF
    $inventory = get_posts(array(
      'post_type' => self::INVENTORY_SLUG,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));

    $export = array(array(
      'id', 'artikel', 'buchungsmenge', 'kommentar', 'datum', 'wert'
    ));

    foreach ($inventory as $item) {
      $bookings = get_field('log', $item->ID);
      if (is_array($bookings)) {
        $fallbackValue = floatval(get_field('value-position', $item->ID));
        foreach ($bookings as $booking) {
          // Skip if positive change
          if ($booking['change'] >= 0) {
            continue;
          }

          $value = floatval(strlen($booking['value']) > 0 ? $booking['value'] : $fallbackValue) * abs($booking['change']);
          $bookingTime = date('Y-m-d H:i', $booking['timestamp']);
          if (substr($bookingTime, 0, 7) == $query) {
            $export[] = array(
              $item->ID,
              $item->post_title,
              $booking['change'],
              $booking['text'],
              $bookingTime,
              $value
            );
          }
        }
      }
    }

    Csv::downloadFile($export, 'booking-export-' . $query);
  }

  /**
   * @param $orderId
   * @param $force
   * @param $forceEvenWhenDid
   * @return void
   */
  public function doPhysicalOrderBooking($orderId, $force, $forceEvenWhenDid = false)
  {
    $order = wc_get_order($orderId);
    // Skip if we already did the bookings and it is not forces
    if ($order->get_meta('inventory-did-physical-bookings') == 1 && !$forceEvenWhenDid) {
      return;
    }

    // Initialize booking array
    $bookings = array();
    // Get the product positions of the order (original product)
    $items = $order->get_items();
    foreach ($items as $item) {
      $data = $item->get_data();
      $automateBookings = $force || get_post_meta($data['product_id'], 'inv-auto-booking', true) == 1;
      if (!$automateBookings) {
        continue;
      }

      // Get the booking config
      $config = get_field('bookables', $data['product_id']);
      foreach ($config as $entry) {
        if (!isset($bookings[$entry['inventory-id']])) {
          $bookings[$entry['inventory-id']] = 0;
        }
        $bookings[$entry['inventory-id']] += (floatval($data['quantity']) * floatval($entry['count']));
      }
    }

    // Put the actual bookings into our inveotory log for each item
    foreach ($bookings as $inventoryId => $quanity) {
      $physicalTotal = floatval(get_post_meta($inventoryId, 'physical-count', true));
      update_post_meta($inventoryId, 'physical-count', $physicalTotal - $quanity);
    }

    // Remember we did the bookings here
    $order->update_meta_data('inventory-did-physical-bookings', 1);
    $order->save_meta_data();
  }

  /**
   * @param \WC_Order $order
   * @param int $time
   * @return void
   */
  protected function doOrderBooking($order, $time, $force, $forceEvenWhenDid = false)
  {
    // Skip if we already did the bookings and it is not forces
    if ($order->get_meta('inventory-did-bookings') == 1 && !$forceEvenWhenDid) {
      return;
    }

    // Initialize booking array
    $bookings = array();
    // Get the product positions of the order (original product)
    $items = $order->get_items();
    foreach ($items as $item) {
      $data = $item->get_data();
      $automateBookings = $force || get_post_meta($data['product_id'], 'inv-auto-booking', true) == 1;
      if (!$automateBookings) {
        continue;
      }

      // Get the booking config
      $config = get_field('bookables', $data['product_id']);
      foreach ($config as $entry) {
        if (!isset($bookings[$entry['inventory-id']])) {
          $bookings[$entry['inventory-id']] = 0;
        }
        $bookings[$entry['inventory-id']] += (floatval($data['quantity']) * floatval($entry['count']));
      }
    }

    // Put the actual bookings into our inveotory log for each item
    $text = __('Ausbuchung Inventar gemäss Bestellung #' . $order->get_id(), 'aboon');
    foreach ($bookings as $inventoryId => $quanity) {
      $posValue = floatval(get_post_meta($inventoryId, 'value-position', true));
      self::addRepeaterEntry($inventoryId, 'field_64822ddce2685', array(
        'field_64822e09e2686' => 0 - $quanity, // negativ, as we book out
        'field_64822e1be2687' => $text,
        'field_64822e28e2688' => $time,
        'field_64822e61e2689' => $posValue > 0 ? $posValue : ''
      ));

      // Also recalculate total and total value according to that
      $totalCount = floatval(get_post_meta($inventoryId, 'bestandsmenge', true));
      $totalCount -= $quanity;
      // Set new data
      update_post_meta($inventoryId, 'bestandsmenge', round($totalCount, 2));
      update_post_meta($inventoryId, 'value-totle', round($totalCount * $posValue, 2));
    }

    // Remember we did the bookings here
    $order->update_meta_data('inventory-did-bookings', 1);
    $order->save_meta_data();
  }

  /**
   * @param \WC_Order $order
   * @param int $time
   * @return void
   */
  protected function doOrderUnBooking($order, $time)
  {
    // Initialize booking array
    $bookings = array();
    // Get the product positions of the order (original product)
    $items = $order->get_items();
    foreach ($items as $item) {
      $data = $item->get_data();
      // Get the booking config
      $config = get_field('bookables', $data['product_id']);
      foreach ($config as $entry) {
        if (!isset($bookings[$entry['inventory-id']])) {
          $bookings[$entry['inventory-id']] = 0;
        }
        $bookings[$entry['inventory-id']] += (floatval($data['quantity']) * floatval($entry['count']));
      }
    }

    // Put the actual bookings into our inveotory log for each item
    $text = __('Einbuchung Inventar durch Änderung/Stornierung Bestellung #' . $order->get_id(), 'aboon');
    foreach ($bookings as $inventoryId => $quanity) {
      self::addRepeaterEntry($inventoryId, 'field_64822ddce2685', array(
        'field_64822e09e2686' => $quanity, // positive, as we book back in
        'field_64822e1be2687' => $text,
        'field_64822e28e2688' => $time,
        'field_64822e61e2689' => ''
      ));

      // Also recalculate total and total value according to that
      $totalCount = floatval(get_post_meta($inventoryId, 'bestandsmenge', true));
      $totalCount += $quanity;
      $posValue = floatval(get_post_meta($inventoryId, 'value-position', true));
      // Set new data
      update_post_meta($inventoryId, 'bestandsmenge', round($totalCount, 2));
      update_post_meta($inventoryId, 'value-totle', round($totalCount * $posValue, 2));
    }
  }

  /**
   * @return string
   */
  protected function displayInventoryTable()
  {
    if ((!isset($_GET['page']) || $_GET['page'] != 'aboon-inventory-overview') && !isset($_POST['exportinventory'])) {
      return;
    }

    $options = array(
      '<a href="#" class="inventory-show" data-show="row-all">Alle</a>',
      '<a href="#" class="inventory-show" data-show="row-alert">Alert</a>',
      '<a href="#" class="inventory-show" data-show="row-warning">Warn</a>',
      '<a href="#" class="inventory-show" data-show="row-alert;row-warning">Alert & Warn</a>',
    );
    $html = '
      <table class="acf-inline-table full-inventory-table">
        <tr>
          <th>Inventarposition</th>
          <th>Lieferant</th>
          <th>Buch. Bestand</th>
          <th>Wenig</th>
          <th>Kritisch</th>
          <th>Buch. Wert</th>
          <th>Phys. Bestand</th>
          <th>Phys. Wert</th>
          <th></th>
        </tr>
    ';

    $export = array(
      array(
        'Inventarposition',
        'Lieferant',
        'Buch. Bestand',
        'Buch. Wert',
        'Phys. Bestand',
        'Phys. Wert',
      )
    );

    $inventory = get_posts(array(
      'post_type' => self::INVENTORY_SLUG,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'tax_query' => array(
        array(
          'taxonomy' => self::PRODUCT_GROUP_SLUG,
          'field' => 'term_id',
          'terms' => get_option('options_product-group-overview')
        )
      )
    ));

    $providers = array();
    $inventoryValue = $physicalTotal = 0;
    foreach ($inventory as $position) {
      $rowClasses = array('row-all');
      $countTotal = $countOriginal = get_field('bestandsmenge', $position->ID);
      $physicalCountTotal = get_field('physical-count', $position->ID);
      $providerName = get_field('provider-name', $position->ID);
      $providerSlug = Strings::forceSlugString($providerName);
      $countWarning = get_field('count-warning', $position->ID);
      $countAlert = get_field('count-alert', $position->ID);
      $value = floatval(get_field('value-totle', $position->ID));
      $posValue = get_field('value-position', $position->ID);
      $physicalValue = ($physicalCountTotal > 0) ? ($posValue * $physicalCountTotal) : 0;
      $physicalTotal += $physicalValue;
      $inventoryValue += $value;
      if ($countTotal <= $countAlert && $countAlert > 0) {
        $countTotal = '<span style="color:#ff0000;">' . $countTotal . '</span>';
        $rowClasses[] = 'row-alert';
      } else if ($countTotal <= $countWarning && $countWarning > 0) {
        $countTotal = '<span style="color:#ec9f27;">' . $countTotal . '</span>';
        $rowClasses[] = 'row-warning';
      }
      if ($physicalCountTotal <= $countAlert && $countAlert > 0) {
        $physicalCountTotal = '<span style="color:#ff0000;">' . $physicalCountTotal . '</span>';
        $rowClasses[] = 'row-alert';
      } else if ($physicalCountTotal <= $countWarning && $countWarning > 0) {
        $physicalCountTotal = '<span style="color:#ec9f27;">' . $physicalCountTotal . '</span>';
        $rowClasses[] = 'row-warning';
      }
      if (strlen($providerName) > 0) {
        $rowClasses[] = 'row-' . $providerSlug;
        if (!isset($providers[$providerSlug])) {
          $providers[$providerSlug] = $providerName;
        }
      }
      $html .= '
        <tr class="' . implode(' ', array_unique($rowClasses)) . '">
          <td>' . $position->post_title . '</td>
          <td>' . $providerName . '</td>
          <td><strong>' . $countTotal . '</strong></td>
          <td>' . $countWarning . '</td>
          <td>' . $countAlert . '</td>
          <td>' . number_format($value, 2) . ' CHF</td>
          <td><strong>' . $physicalCountTotal . '</strong></td>
          <td>' . number_format($physicalValue, 2) . ' CHF</td>
          <td><a href="/wp-admin/post.php?post=' . $position->ID . '&action=edit" class="dashicons dashicons-edit"></a></td>
        </tr>
      ';

      $export[] = array(
        $position->post_title,
        $providerName,
        $countOriginal,
        $value,
        $physicalCountTotal,
        $physicalValue,
      );
    }

    if (isset($_POST['exportinventory'])) {
      Csv::downloadFile($export, 'inventory-export');
      return;
    }

    // Totals row
    $html .= '
      <tr class="row-all">
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td><strong>Buch. Total</strong></td>
        <td>' . $inventoryValue . ' CHF</td>
        <td><strong>Phys. Total</strong></td>
        <td>' . $physicalTotal . ' CHF</td>
        <td></td>
      </tr>
    ';

    // Add more options for provides
    foreach ($providers as $slug => $name) {
      $options[] = '<a href="#" class="inventory-show" data-show="row-' . $slug . '">' . $name . '</a>';
    }

    $html .= '</table>';
    $html .= '<p class="show-options">Anzeige: ' . implode(' | ', $options) . '</p>';

    return $html;
  }

  public function printScripts()
  {
    if (isset($_GET['page']) && $_GET['page'] == 'aboon-inventory-overview') {
      echo '
        <script>
          jQuery(function() {
            jQuery(".show-options").insertBefore(".full-inventory-table");
            jQuery(".inventory-show").on("click", function() {
              jQuery(".row-all").hide();
              var classes = jQuery(this).data("show").split(";");
              classes.forEach(function(showClass) {
                jQuery("." + showClass).show();
              });
            });
          });
        </script>  
      ';
    }
  }

  /**
   * @return void
   */
  protected function eventuallyDoManualOrderBooking()
  {
    $orderId = isset($_GET['id']) ? intval($_GET['id']) : intval($_GET['post']);
    if (isset($_GET['inventory']) && $_GET['inventory'] == 'do-order-booking' && $orderId > 0) {
      $this->doOrderBooking(wc_get_order($orderId), current_time('timestamp'), true, isset($_GET['force-booking']));
    }
    if (isset($_GET['inventory']) && $_GET['inventory'] == 'do-order-unbooking' && $orderId > 0) {
      $this->doOrderUnBooking(wc_get_order($orderId), current_time('timestamp'));
    }
  }

  /**
   * @return void
   */
  protected function eventuallyDoManualPhysicalOrderBooking()
  {
    $orderId = isset($_GET['id']) ? intval($_GET['id']) : intval($_GET['post']);
    if (isset($_GET['inventory']) && $_GET['inventory'] == 'do-order-physical-booking' && $orderId > 0) {
      $this->doPhysicalOrderBooking($orderId, true, isset($_GET['force-booking']));
    }
  }

  /**
   * TODO BIG TIME
   * Run a manual booking from given option settings
   * @return void
   */
  protected function eventuallyDoManualBooking()
  {
    $type = '';
    if (isset($_GET['inventory']) && $_GET['inventory'] == 'do-manual-booking') {
      $type = 'valuated';
    }
    if (isset($_GET['inventory']) && $_GET['inventory'] == 'do-manual-physical-booking') {
      $type = 'physical';
    }

    if (strlen($type) > 0) {
      $postId = intval($_GET['post']);
      if ($type == 'physical') {
        $this->runManualPhysicalBooking($postId);
      } else if ($type == 'valuated') {
        $this->runManualBooking($postId);
      }
      // Change State
      $status = ArrayManipulation::forceArray(get_post_meta($postId, 'status', true));
      if (!in_array($type, $status)) $status[] = $type;
      update_post_meta($postId, 'status', $status);

      // Finally print a message after doing so
      echo '
        <div class="acf-admin-notice notice notice-success">
          <p>Die Buchung wurde ausgeführt.</p>
        </div>
      ';
    }
  }

  /**
   * @return void
   */
  protected function runManualBooking($postId)
  {
    $text = get_post($postId)->post_title;
    $entries = get_field('entries', $postId);
    $time = current_time('timestamp');
    foreach ($entries as $inventory) {
      $id = $inventory['inventory-id'];
      self::addRepeaterEntry($id, 'field_64822ddce2685', array(
        'field_64822e09e2686' => $inventory['count'], // as is, negative is book out, positive is adding material
        'field_64822e1be2687' => strlen($inventory['text']) > 0 ? $inventory['text'] : $text,
        'field_64822e28e2688' => $time,
        'field_64822e61e2689' => strlen($inventory['value']) > 0 ? $inventory['value'] : '',
      ));

      // Also recalculate total and total value according to that
      $totalCount = floatval(get_post_meta($id, 'bestandsmenge', true));
      $posValue = floatval(get_post_meta($id, 'value-position', true));
      $newTotalCount = $totalCount + floatval($inventory['count']);
      // Calculate worth of inventory, if booking is positive and has a value
      if ($inventory['count'] > 0 && $inventory['value'] > 0 && $totalCount > 0) {
        $bookInValue = ($inventory['count'] * $inventory['value']);
        $bookInValue += ($totalCount * $posValue);
        $posValue = ($bookInValue / $newTotalCount);
      }

      // Set new data
      update_post_meta($id, 'bestandsmenge', round($newTotalCount, 2));
      update_post_meta($id, 'value-position', round($posValue, 2));
      update_post_meta($id, 'value-totle', round($newTotalCount * $posValue, 2));
    }
  }

  /**
   * @return void
   */
  protected function runManualPhysicalBooking($postId)
  {
    $entries = get_field('entries', $postId);
    foreach ($entries as $inventory) {
      $id = $inventory['inventory-id'];
      $physicalTotal = floatval(get_post_meta($id, 'physical-count', true));
      update_post_meta($id, 'physical-count', $physicalTotal + $inventory['count']);
    }
  }

  /**
   * @return string
   */
  public function showOrderInventoryBookStatus()
  {
    $html = '<h4>Buchungsverlauf</h4>';
    $orderId = isset($_GET['id']) ? intval($_GET['id']) : intval($_GET['post']);
    $order = wc_get_order($orderId);
    // Skip this on new order screen
    if (!($order instanceof \WC_Order)) {
      return;
    }

    $orderUrl = $order->get_edit_order_url();
    if ($order->get_meta('inventory-did-bookings') == 1) {
      $html .= '
        Inventar für diesen Auftrag wurde bereits buchhalterisch ausgebucht.
        <a href="' . $orderUrl . '&inventory=do-order-booking&force-booking" class="confirm">Trotzdem ausbuchen</a> oder
        <a href="' . $orderUrl . '&inventory=do-order-unbooking" class="confirm">rückgängig machen</a>.
      ';
    } else {
      $html .= '
        Der Auftrag wurde noch nicht buchhalterisch ausgebucht. <a href="' . $orderUrl . '&inventory=do-order-booking" class="confirm">Jetzt ausbuchen</a>.
      ';
    }

    if ($order->get_meta('inventory-did-physical-bookings') == 1) {
      $html .= '
        <br><br>Inventar für diesen Auftrag wurde bereits physisch ausgebucht.
        <a href="' . $orderUrl . '&inventory=do-order-physical-booking&force-booking" class="confirm">Trotzdem ausbuchen</a>
      ';
    } else {
      $html .= '
        <br><br>Der Auftrag wurde noch nicht physisch ausgebucht. <a href="' . $orderUrl . '&inventory=do-order-physical-booking" class="confirm">Jetzt ausbuchen</a>.
      ';
    }

    $signature = $order->get_meta('completion-signature');
    if (strlen($signature) > 0) {
      $html .= '
        <h4>Unterschrift</h4>
        <img src="' . $signature . '" width="100%" />
      ';
    }

    $html .= '
      <h4>Status ändern</h4>
      - <a href="' . $orderUrl . '&change-status=on-hold" class="confirm">In Wartestellung</a><br>
      - <a href="' . $orderUrl . '&change-status=processing" class="confirm">In Bearbeitung</a>
    ';

    echo $html;
  }

  /**
   * @return string text
   */
  protected function showManualBookingText()
  {
    return '
      <p>Buchung erfassen und oben zwischenspeichern. Sobald fertig, kann die Buchung hier ausgelöst werden.</p>
      - <a href="/wp-admin/post.php?post=' . $_GET['post'] . '&action=edit&inventory=do-manual-booking">Buchhalterisch buchen</a><br>
      - <a href="/wp-admin/post.php?post=' . $_GET['post'] . '&action=edit&inventory=do-manual-physical-booking">Physisch buchen</a><br>
    ';
  }

  /**
   * Post type and taxonomy configuration
   * @return void
   */
  protected function addTypeConfig()
  {
    WordPress::registerType(self::INVENTORY_SLUG, 'Inventar', 'Inventar', array(
      'menu_icon' => 'dashicons-media-spreadsheet',
      'supports' => array('title'),
      'menu_position' => 57,
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'has_archive' => false
    ));
    WordPress::registerType(self::SENDING_SLUG, 'Versand', 'Versände', array(
      'menu_icon' => 'dashicons-controls-repeat',
      'supports' => array('title'),
      'menu_position' => 58,
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'has_archive' => false
    ));
    WordPress::registerType(self::BOOKING_SLUG, 'Buchung', 'Buchungen', array(
      'menu_icon' => 'dashicons-controls-repeat',
      'supports' => array('title'),
      'menu_position' => 59,
      'show_in_menu' => 'edit.php?post_type=inventory-article',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'has_archive' => false
    ));

    WordPress::addPostTableColumn(array(
      'post_type' => self::SENDING_SLUG,
      'meta_key' => 'sending-start',
      'column_key' => self::SENDING_SLUG . '_sending-start',
      'single' => true,
      'heading' => 'Abholtermin / Rüsttermin',
      'callback' => function ($value, $postId) {
        echo Date::getTime(Date::EU_DATE, strtotime($value));
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::SENDING_SLUG,
      'meta_key' => 'sending-delivery',
      'column_key' => self::SENDING_SLUG . '_sending-delivery',
      'single' => true,
      'heading' => 'Liefertermin',
      'callback' => function ($value, $postId) {
        echo Date::getTime(Date::EU_DATE, strtotime($value));
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::SENDING_SLUG,
      'meta_key' => 'deliverer',
      'column_key' => self::SENDING_SLUG . '_deliverer',
      'single' => true,
      'heading' => 'Lieferant'
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::SENDING_SLUG,
      'column_key' => self::SENDING_SLUG . '_order_ids',
      'heading' => 'Bestellnummern',
      'callback' => function ($value, $postId) {
        $orders = get_field('orders', $postId);
        $orderIds = ArrayManipulation::getSpecifiedKeyArray($orders, 'id');
        echo implode(', ', $orderIds);
      }
    ));
    WordPress::removePostTableColumns(array(
      'post_type' => self::SENDING_SLUG,
      'column_keys' => array('date')
    ));

    WordPress::addPostTableColumn(array(
      'post_type' => self::BOOKING_SLUG,
      'meta_key' => 'valuated',
      'column_key' => self::BOOKING_SLUG . 'valuated',
      'single' => true,
      'heading' => 'Buchhalterisch',
      'callback' => function ($value, $postId) {
        $value = ArrayManipulation::forceArray(get_post_meta($postId, 'status', true));
        echo in_array('valuated', $value)
          ? '<span class="dashicons dashicons-saved"></span> Gebucht'
          : '<span class="dashicons dashicons-no-alt"></span> N/A';
      }
    ));

    WordPress::addPostTableColumn(array(
      'post_type' => self::BOOKING_SLUG,
      'meta_key' => 'physical',
      'column_key' => self::BOOKING_SLUG . 'physical',
      'single' => true,
      'heading' => 'Physisch',
      'callback' => function ($value, $postId) {
        $value = ArrayManipulation::forceArray(get_post_meta($postId, 'status', true));
        echo in_array('physical', $value)
          ? '<span class="dashicons dashicons-saved"></span> Gebucht'
          : '<span class="dashicons dashicons-no-alt"></span> N/A';
      }
    ));
  }

  /**
   * Add a sub page below our custom type for inventory
   * @return void
   */
  public function acfInit()
  {
    parent::acfInit();

    acf_add_options_sub_page(array(
      'page_title' => 'Buchhaltung',
      'menu_title' => 'Buchhaltung',
      'capability' => 'administrator',
      'menu_slug' => 'aboon-direct-book',
      'parent_slug' => 'edit.php?post_type=inventory-article'
    ));

    acf_add_options_sub_page(array(
      'page_title' => 'Übersicht',
      'menu_title' => 'Übersicht',
      'capability' => 'administrator',
      'menu_slug' => 'aboon-inventory-overview',
      'parent_slug' => 'edit.php?post_type=inventory-article'
    ));
  }

  /**
   * No blocks needed here
   */
  public function blocks()
  {
    $this->registerBlock(array(
      'name' => 'aboon-order-completion',
      'icon' => 'welcome-write-blog',
      'title' => __('Auftragsabschluss & Unterschrift', 'aboon'),
      'preview' => false,
      'description' => __('Formular für den Auftragsabschluss inkl. Unterschriftsfeld', 'aboon'),
      'render_callback' => array($this, 'renderCompletionForm'),
      'post_types' => array('page'),
    ));
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_659fa4e4bad9d',
      'title' => 'Direkte Buchungen',
      'fields' => array(
        array(
          'key' => 'field_659fa4e5f7194',
          'label' => 'Buchungen',
          'name' => 'bookings',
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
          'pagination' => 1,
          'rows_per_page' => 10,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'sub_fields' => array(
            array(
              'key' => 'field_659fa525f7196',
              'label' => 'Datum',
              'name' => 'date',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa53af7197',
              'label' => 'Buchungstext',
              'name' => 'text',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa545f7198',
              'label' => 'Soll',
              'name' => 'soll',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa553f7199',
              'label' => 'Haben',
              'name' => 'haben',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa563f719a',
              'label' => 'Betrag',
              'name' => 'value',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
            array(
              'key' => 'field_659fa572f719b',
              'label' => 'MwStCode',
              'name' => 'taxcode',
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
              'parent_repeater' => 'field_659fa4e5f7194',
            ),
          ),
        ),
        array(
          'key' => 'field_659fa50ff7195',
          'label' => 'Export',
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
          'message' => '<a href="/wp-admin/edit.php?post_type=inventory-article&page=aboon-direct-book&export">CSV Export starten</a>',
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
        array(
          'key' => 'field_64811dcee2684',
          'label' => 'Notizen',
          'name' => 'direct-booking-notes',
          'aria-label' => '',
          'type' => 'textarea',
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
          'rows' => 14,
          'placeholder' => '',
          'new_lines' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-direct-book',
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
    acf_add_local_field_group(array(
      'key' => 'group_659e8e26b9541',
      'title' => 'Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_659e91b0dac5d',
          'label' => 'Liefertyp',
          'name' => 'sending-type',
          'aria-label' => '',
          'type' => 'radio',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'single' => 'Einzellieferung',
            'collective' => 'Sammellieferung',
          ),
          'default_value' => '',
          'return_format' => 'value',
          'allow_null' => 0,
          'other_choice' => 0,
          'layout' => 'vertical',
          'save_other_choice' => 0,
        ),
        array(
          'key' => 'field_659e91eddac5e',
          'label' => 'Lieferart',
          'name' => 'delivery-type',
          'aria-label' => '',
          'type' => 'radio',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_659e91b0dac5d',
                'operator' => '==',
                'value' => 'single',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'post' => 'Post / Sperrgut',
            'spedition' => 'Spedition',
          ),
          'default_value' => '',
          'return_format' => 'value',
          'allow_null' => 0,
          'other_choice' => 0,
          'layout' => 'vertical',
          'save_other_choice' => 0,
        ),
        array(
          'key' => 'field_659e93a51b989',
          'label' => 'Tracking-URL',
          'name' => 'tracking-url',
          'aria-label' => '',
          'type' => 'url',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_659e91b0dac5d',
                'operator' => '==',
                'value' => 'single',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
        ),
        array(
          'key' => 'field_659e936865875',
          'label' => 'Abholtermin',
          'name' => 'sending-start',
          'aria-label' => '',
          'type' => 'date_picker',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'display_format' => 'd.m.Y',
          'return_format' => 'd.m.Y',
          'first_day' => 1,
        ),
        array(
          'key' => 'field_659e939065876',
          'label' => 'Liefertermin',
          'name' => 'sending-delivery',
          'aria-label' => '',
          'type' => 'date_picker',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'display_format' => 'd.m.Y',
          'return_format' => 'd.m.Y',
          'first_day' => 1,
        ),
        array(
          'key' => 'field_659ee45f03b3e',
          'label' => 'Lieferant',
          'name' => 'deliverer',
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
          'key' => 'field_659ee47403b3f',
          'label' => 'Bemerkungen',
          'name' => 'additional-info',
          'aria-label' => '',
          'type' => 'textarea',
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
          'rows' => 3,
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_659ffa788c555',
          'label' => 'Avisierungung',
          'name' => 'avis-email',
          'aria-label' => '',
          'type' => 'true_false',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => 'Avisierungen per E-Mail gesendet',
          'default_value' => 0,
          'ui_on_text' => 'Ja',
          'ui_off_text' => 'Nein',
          'ui' => 1,
        ),
        array(
          'key' => 'field_6588fa788c555',
          'label' => 'Abschluss',
          'name' => 'is-completed',
          'aria-label' => '',
          'type' => 'true_false',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => 'Bestellungen Abgeschlossen & Zahlungserinnerungen versendet',
          'default_value' => 0,
          'ui_on_text' => 'Ja',
          'ui_off_text' => 'Nein',
          'ui' => 1,
        ),
        array(
          'key' => 'field_659e93ccb4922',
          'label' => 'Bestellung(en)',
          'name' => 'orders',
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
              'key' => 'field_659e93e9b4923',
              'label' => 'ID',
              'name' => 'id',
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
              'parent_repeater' => 'field_659e93ccb4922',
            ),
            array(
              'key' => 'field_659e93e9b4945',
              'label' => 'Bemerkung',
              'name' => 'info',
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
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '',
              'prepend' => '',
              'append' => '',
              'parent_repeater' => 'field_659e93ccb4922',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'order-sending',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));

    acf_add_local_field_group(array(
      'key' => 'group_649019f6c6405',
      'title' => 'Übersicht aller Inventarpositionen',
      'fields' => array(
        array(
          'key' => 'field_649019f725df8',
          'label' => '',
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
          'message' => $this->displayInventoryTable(),
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-inventory-overview',
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

    acf_add_local_field_group(array(
      'key' => 'group_65905ea13efb0',
      'title' => 'Buchung durchführen',
      'fields' => array(
        array(
          'key' => 'field_64905ef17ef19',
          'label' => '',
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
          'message' => $this->showManualBookingText(),
          'new_lines' => '',
          'esc_html' => 0,
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => self::BOOKING_SLUG,
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'side',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));

    acf_add_local_field_group(array(
      'key' => 'group_64822d4dd1453',
      'title' => 'Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_64822d4ee267f',
          'label' => 'Hersteller ArtNr',
          'name' => 'provider-sku',
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
          'key' => 'field_64833d4ee267f',
          'label' => 'Lieferant',
          'name' => 'provider-name',
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
          'key' => 'field_64822d98e2680',
          'label' => 'Buchhalterischer Bestand',
          'name' => 'bestandsmenge',
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
          'key' => 'field_64822d98e2682',
          'label' => 'Physischer Bestand',
          'name' => 'physical-count',
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
          'key' => 'field_64822da0e2681',
          'label' => 'Wert Position',
          'name' => 'value-position',
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
          'key' => 'field_64822da9e2682',
          'label' => 'Wert Total',
          'name' => 'value-totle',
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
          'key' => 'field_64822da9e2683',
          'label' => 'Wenig Bestand',
          'name' => 'count-warning',
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
          'key' => 'field_64822da9e2783',
          'label' => 'Kritischer Bestand',
          'name' => 'count-alert',
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
          'key' => 'field_64822ddce2685',
          'label' => 'Buchungs-Log',
          'name' => 'log',
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
          'pagination' => 1,
          'rows_per_page' => 10,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'sub_fields' => array(
            array(
              'key' => 'field_64822e09e2686',
              'label' => 'Veränderung +/-',
              'name' => 'change',
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
              'parent_repeater' => 'field_64822ddce2685',
            ),
            array(
              'key' => 'field_64822e1be2687',
              'label' => 'Buchungstext',
              'name' => 'text',
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
              'parent_repeater' => 'field_64822ddce2685',
            ),
            array(
              'key' => 'field_64822e28e2688',
              'label' => 'Datum/Zeit',
              'name' => 'timestamp',
              'aria-label' => '',
              'type' => 'date_time_picker',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'display_format' => 'd.m.Y H:i:s',
              'return_format' => 'U',
              'first_day' => 1,
              'parent_repeater' => 'field_64822ddce2685',
            ),
            array(
              'key' => 'field_64822e61e2689',
              'label' => 'Wert exkl. Mwst.',
              'name' => 'value',
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
              'parent_repeater' => 'field_64822ddce2685',
            ),
          ),
        ),
        array(
          'key' => 'field_64822dbfe2683',
          'label' => 'Kommentar',
          'name' => 'kommentar',
          'aria-label' => '',
          'type' => 'textarea',
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
          'rows' => 3,
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_64822dcee2684',
          'label' => 'Log History',
          'name' => 'log-history',
          'aria-label' => '',
          'type' => 'textarea',
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
          'rows' => 5,
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_66323b4a0b4d9',
          'label' => 'Einstellungen',
          'name' => 'status',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'external-warehouse' => 'Nicht im eigenen Lager geführt / externer Direktlieferant',
            'auto-shop-product' => 'Automatisch Shop-Produkt synchronisieren',
          ),
          'default_value' => array(),
          'return_format' => 'value',
          'allow_custom' => 0,
          'layout' => 'vertical',
          'toggle' => 0,
          'save_custom' => 0
        ),
        array(
          'key' => 'field_66bdf47c162ae',
          'label' => 'Synchronisation Einstellungen',
          'name' => 'sync-settings',
          'aria-label' => '',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_66323b4a0b4d9',
                'operator' => '==',
                'value' => 'auto-shop-product',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_66bdf43a162ad',
              'label' => 'Info',
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
              'message' => 'Der Abgleich findet nur einmal am Tag statt um 23 Uhr. <a href="' . get_bloginfo('url') . '/wp-content/plugins/lbwp/views/cron/daily.php?specific=23" target="_blank">Jetzt synchronisieren</a>',
              'new_lines' => 'wpautop',
              'esc_html' => 0,
            ),
            array(
              'key' => 'field_66bdf537162af',
              'label' => 'Artikelnummer (SKU)',
              'name' => 'sku',
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
              'key' => 'field_66bdf563162b0',
              'label' => 'Öffentlicher Titel',
              'name' => 'public-title',
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
              'key' => 'field_66bdf5aa162b1',
              'label' => 'Produktbild',
              'name' => 'image-id',
              'aria-label' => '',
              'type' => 'image',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'return_format' => 'id',
              'library' => 'all',
              'min_width' => '',
              'min_height' => '',
              'min_size' => '',
              'max_width' => '',
              'max_height' => '',
              'max_size' => '',
              'mime_types' => '',
              'preview_size' => 'medium',
            ),
            array(
              'key' => 'field_66bdf5be162b2',
              'label' => 'Kurzbeschreibung',
              'name' => 'short-description',
              'aria-label' => '',
              'type' => 'wysiwyg',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'tabs' => 'all',
              'toolbar' => 'full',
              'media_upload' => 1,
              'delay' => 0,
            ),
            array(
              'key' => 'field_66bdf5d0162b3',
              'label' => 'Produktbeschreibung',
              'name' => 'description',
              'aria-label' => '',
              'type' => 'wysiwyg',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'tabs' => 'all',
              'toolbar' => 'full',
              'media_upload' => 1,
              'delay' => 0,
            ),
            array(
              'key' => 'field_66bdf5ee162b4',
              'label' => 'Kategorien',
              'name' => 'categories',
              'aria-label' => '',
              'type' => 'taxonomy',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'taxonomy' => 'product_cat',
              'add_term' => 0,
              'save_terms' => 0,
              'load_terms' => 0,
              'return_format' => 'id',
              'field_type' => 'multi_select',
              'allow_null' => 1,
              'bidirectional' => 0,
              'multiple' => 0,
              'bidirectional_target' => array(),
            ),
            array(
              'key' => 'field_66bdf798162b5',
              'label' => 'Preisdefinition',
              'name' => 'pricedeinition',
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
              'choices' => array(
                'fixed-product' => 'Fixer Preis (1 zu 1 ins Produkt übernehmen)',
                'fixed' => 'Zielmarge in Fixbetrag',
                'percent' => 'Zielmarge in Prozent',
              ),
              'default_value' => false,
              'return_format' => 'value',
              'multiple' => 0,
              'allow_null' => 0,
              'ui' => 0,
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_66bdf8416a8bd',
              'label' => 'Preis',
              'name' => 'price',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'fixed-product',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '0.01',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf8c46a8be',
              'label' => 'Wunschmarge in %',
              'name' => 'percent-margin',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'percent',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '0.01',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf8f66a8bf',
              'label' => 'Runden auf Kommastellen',
              'name' => 'percent-rounding',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'percent',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => 0,
              'max' => 2,
              'placeholder' => '',
              'step' => 1,
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf9326a8c0',
              'label' => 'Zielmarge',
              'name' => 'fixed-margin',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'fixed',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => '',
              'max' => '',
              'placeholder' => '',
              'step' => '0.01',
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_66bdf9ce6a8c2',
              'label' => 'Runden auf Kommastellen',
              'name' => 'fixed-rounding',
              'aria-label' => '',
              'type' => 'number',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_66bdf798162b5',
                    'operator' => '==',
                    'value' => 'fixed',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'min' => 0,
              'max' => 2,
              'placeholder' => '',
              'step' => 1,
              'prepend' => '',
              'append' => '',
            ),
            array(
              'key' => 'field_76323b4a0b5f1',
              'label' => 'Weitere Einstellungen',
              'name' => 'additional-settings',
              'aria-label' => '',
              'type' => 'checkbox',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'manage-stock' => 'Lagerverwaltung aktivieren',
                'allow-backorders' => 'Lieferrückstand erlauben',
                'compare-stock-booking' => 'Lagerbestand mit Buchbestand abgleichen',
              ),
              'default_value' => array(),
              'return_format' => 'value',
              'allow_custom' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'inventory-article',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));

    acf_add_local_field_group(array(
      'key' => 'group_64822f8d76b0a',
      'title' => 'Inventar Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_64822f8ed20cd',
          'label' => 'Automatische Ausbuchungen',
          'name' => 'inv-auto-booking',
          'aria-label' => '',
          'type' => 'true_false',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => 'Die definierten Inventar-Artikel werden bei Bestellung automatisch ausgebucht',
          'default_value' => 0,
          'ui' => 0,
          'ui_on_text' => '',
          'ui_off_text' => '',
        ),
        array(
          'key' => 'field_64822fcdd20ce',
          'label' => 'Zuweisungen',
          'name' => 'bookables',
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
              'key' => 'field_64822fdfd20cf',
              'label' => 'Inventar-Artikel',
              'name' => 'inventory-id',
              'aria-label' => '',
              'type' => 'post_object',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'post_type' => array(
                0 => 'inventory-article',
              ),
              'post_status' => '',
              'taxonomy' => '',
              'return_format' => 'id',
              'multiple' => 0,
              'allow_null' => 0,
              'ui' => 1,
              'parent_repeater' => 'field_64822fcdd20ce',
            ),
            array(
              'key' => 'field_64822ff2d20d0',
              'label' => 'Menge',
              'name' => 'count',
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
              'parent_repeater' => 'field_64822fcdd20ce',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'product',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));

    acf_add_local_field_group(array(
      'key' => 'group_64822ba735b66',
      'title' => 'Buchungsdaten',
      'fields' => array(
        array(
          'key' => 'field_66323b4a0b4c8',
          'label' => 'Status',
          'name' => 'status',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'valuated' => 'Buchhalterisch gebucht',
            'physical' => 'Physisch gebucht',
          ),
          'default_value' => array(),
          'return_format' => 'value',
          'allow_custom' => 0,
          'layout' => 'vertical',
          'toggle' => 0,
          'save_custom' => 0
        ),
        array(
          'key' => 'field_64822bbf08522',
          'label' => 'Buchungen',
          'name' => 'entries',
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
              'key' => 'field_64822be708523',
              'label' => 'Inventar-Artikel',
              'name' => 'inventory-id',
              'aria-label' => '',
              'type' => 'post_object',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'post_type' => array(
                0 => 'inventory-article',
              ),
              'post_status' => '',
              'taxonomy' => '',
              'return_format' => 'id',
              'multiple' => 0,
              'allow_null' => 0,
              'ui' => 1,
              'parent_repeater' => 'field_64822bbf08522',
            ),
            array(
              'key' => 'field_64822c1208524',
              'label' => 'Menge',
              'name' => 'count',
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
              'parent_repeater' => 'field_64822bbf08522',
            ),
            array(
              'key' => 'field_64822c1e08525',
              'label' => 'Buchungstext',
              'name' => 'text',
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
              'parent_repeater' => 'field_64822bbf08522',
            ),
            array(
              'key' => 'field_64822c2808526',
              'label' => 'Wert exkl. MwSt.',
              'name' => 'value',
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
              'parent_repeater' => 'field_64822bbf08522',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => self::BOOKING_SLUG,
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

    acf_add_local_field_group(array(
      'key' => 'group_66cc620ba139f',
      'title' => 'Inventar Einstellungen',
      'fields' => array(
        array(
          'key' => 'field_667462681f0d4',
          'label' => 'Erste Warengruppe',
          'name' => 'product-group-overview',
          'aria-label' => '',
          'type' => 'taxonomy',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'taxonomy' => 'product-group',
          'add_term' => 0,
          'save_terms' => 0,
          'load_terms' => 0,
          'return_format' => 'id',
          'field_type' => 'select',
          'allow_null' => 0,
          'bidirectional' => 0,
          'multiple' => 0,
          'bidirectional_target' => array(),
        ),
        array(
          'key' => 'field_66cc620c4778e',
          'label' => 'Weitere Einstellungen',
          'name' => 'inventory-settings',
          'aria-label' => '',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'pricealarm-active' => 'Preisalarm Report aktivieren',
          ),
          'default_value' => array(),
          'return_format' => 'value',
          'allow_custom' => 0,
          'layout' => 'vertical',
          'toggle' => 0,
          'save_custom' => 0,
          'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
        ),
        array(
          'key' => 'field_66cc62934778f',
          'label' => 'Preisalarm Einstellungen',
          'name' => 'pricealarm-settings',
          'aria-label' => '',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_66cc620c4778e',
                'operator' => '==',
                'value' => 'pricealarm-active',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'block',
          'sub_fields' => array(
            array(
              'key' => 'field_66cc630547790',
              'label' => 'Warnwert (wenn %-Marge geringer)',
              'name' => 'warning-value',
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
              'key' => 'field_66cc637947791',
              'label' => 'Alarmwert (wenn %-Marge geringer)',
              'name' => 'alarm-value',
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
              'key' => 'field_66cc639247792',
              'label' => 'Regelmässigkeit',
              'name' => 'interval',
              'aria-label' => '',
              'type' => 'radio',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'daily' => 'Täglich',
                'weekly' => 'Wöchentlich am montag',
                'monthly' => 'Monatlich am ersten tag',
              ),
              'default_value' => '',
              'return_format' => 'value',
              'allow_null' => 0,
              'other_choice' => 0,
              'layout' => 'vertical',
              'save_other_choice' => 0,
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-display',
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

  /*
   * Warn if stock gets low
   */
  public function warnLowStock()
  {
    $items = get_posts(array(
      'post_type' => self::INVENTORY_SLUG,
      'numberposts' => -1
    ));
    $criticalTable = '';
    $count = 0;

    foreach ($items as $item) {
      $itemMeta = WordPress::getAccessiblePostMeta($item->ID);

      if (
        $itemMeta['bestandsmenge'] < $itemMeta['count-alert'] || $itemMeta['bestandsmenge'] < $itemMeta['count-warning'] ||
        $itemMeta['physical-count'] < $itemMeta['count-alert'] || $itemMeta['physical-count'] < $itemMeta['count-warning']
      ) {
        $criticalTable .= '<tr style="' . ($count % 2 === 0 ? 'background: #ddd' : '') . '">
          <td>' . $item->post_title . '</td>
          <td>' . ($itemMeta['bestandsmenge'] < $itemMeta['count-alert'] || $itemMeta['physical-count'] < $itemMeta['count-alert'] ? '<span style="color: red;">Kritisch</span>' : 'Warnung') . '</td>
          <td>' . $itemMeta['bestandsmenge'] . '</td>
          <td>' . $itemMeta['physical-count'] . '</td>
          <td>' . $itemMeta['count-alert'] . '</td>
          <td>' . $itemMeta['count-warning'] . '</td>
          <td><a href="' . get_edit_post_link($item->ID) . '" target="_blank">Item bearbeiten</a></td>
          
          </tr>';

        $count++;
      }
    }

    if ($criticalTable !== '') {
      $criticalTable = '<table>
        <tr style="font-weight: bold; text-align: left;">
          <th>Inventar Item</th>
          <th>Zustand</th>
          <th>Buch-Bestand</th>
          <th>Phys-Bestand</th>
          <th>Kritischer Wert</th>
          <th>Warnung Wert</th>
          <th>Bearbeitungs-Link</th>
        </tr>
        ' . $criticalTable . '
      </table>';

      $mail = External::PhpMailer();
      $mail->FromName = get_bloginfo('title');
      $mail->Subject = get_bloginfo('title') . ' - Inventar Alert';
      $mail->Body = $criticalTable;
      $mail->AddAddress(get_bloginfo('admin_email'));
      $mail->send();
    }
  }

  /**
   * Render completion form with signature field
   * @return void
   */
  public function renderCompletionForm()
  {
    $completed = $this->handleCompletionPost();

    wp_enqueue_script('aboon-order-completion');
    wp_enqueue_script('aboon-signature');

    $formMessage = $completed ? '<p class="order-completion__message success">Die Bestellung wurde abgeschlossen</p>' : '';
    $form = '<form method="GET">
      <input id="orderNr" name="orderNr" type="number" style="width:70%" placeholder="Bestell-Nummer z.b 10854" required>
      <button class="btn btn-primary">Suchen</button>
    </form>';

    if (intval($_GET['orderNr']) !== 0) {
      $order = wc_get_order($_GET['orderNr']);
      $signature = $order->get_meta('completion-signature');

      if ($order === false) {
        $formMessage = '<p class="order-completion__message error">Die Bestellung mit der Nummer ' . $_GET['orderNr'] . ' konnte nicht gefunden werden.</p>';
      } else {
        $data = $order->get_data();
        $form = '<div class="order-completion__data">
          <p><strong>Besteller</strong><br>' .
          $data['billing']['first_name'] . ' ' . $data['billing']['last_name'] . '<br>' .
          $data['billing']['address_1'] . '<br>' .
          $data['billing']['postcode'] . ' ' . $data['billing']['city'] .
          '</p>
          <p><strong>Status: </strong>' . wc_get_order_status_name($order->get_status()) . '</p>
          <strong>Produkte</strong>';

        foreach ($order->get_items() as $item) {
          $item = $item->get_data();

          $form .= '<div class="order-completion__item">' . $item['quantity'] . 'x ' . $item['name'] . '</div>';
        }

        if ($signature !== '') {
          $form .= '<div class="order-completion__signature">
            <strong>Unterschrift</strong>
            <div class="order-completion__signature--image">
              <img src="' . $signature . '">
            </div>';

          if ($order->get_meta('absence-delivery') == 1) {
            $form .= '<p>Unterschrift erfolgte in Abwesenheit des Kunden durch Lieferfahrer</p>';
          }

          $form .= '</div>';

          if ($order->get_meta('absence-image') !== '') {
            $form .= '<div class="order-completion__absence-image">
              <img src="' . $order->get_meta('absence-image') . '">
            </div>';
          }
          if ($order->get_meta('absence-image-2') !== '') {
            $form .= '<div class="order-completion__absence-image">
              <img src="' . $order->get_meta('absence-image-2') . '">
            </div>';
          }

          $form .= '<p class="order-completion__message">Diese Bestellung wurde bereits abgeschlossen. <a href="?orderNr">Zurück zum Formular</a></p>';
        } else {
          $form .= '</div>
          <div class="order-completion__signature">
            <strong>Unterschrift</strong>
            <canvas class="order-completion__signature--canvas signature-autosetup"></canvas>
            <div class="order-completion__signature--clear signature-clear">Löschen</div>
          </div>
          <div class="order-completion__absence-image">
            <input id="absence-image" type="file" accept=".jpg,.jpeg,.png" style="width:48%">  
            <input id="absence-image-2" type="file" accept=".jpg,.jpeg,.png" style="width:48%">  
          </div>';

          $form .= '<form method="POST" class="signature-form" action="?orderCompleted">
            <div class="signature-form__field">
              <label class="checkbox">
                <input type="checkbox" name="absence-delivery">
                Deponiert, Kunde nicht anwesend
              </label>
            </div>
            <input type="hidden" name="absence-image">   
            <input type="hidden" name="absence-image-2">   
            <input type="hidden" name="signature" value="">
            <input type="hidden" name="orderId" value="' . $_GET['orderNr'] . '">
            <button class="btn btn-primary">Bestellung abschliessen</button>
          </form>';
        }
      }
    }

    $html = '<section class="wp-block-wrapper wp-block-order-completion s03-default-grid">
      <div class="grid-container order-completion">
        <div class="grid-row">
          <div class="grid-column">
             ' . $formMessage . $form . '
          </div>
        </div>
      </div>
    </section>';

    echo $html;
  }

  /**
   * @return bool
   */
  public function handleCompletionPost()
  {
    $orderId = intval($_POST['orderId']);
    if (isset($_POST['signature']) && $orderId > 0) {
      // Save the signature, book out physically and close the order
      $order = wc_get_order($orderId);
      if ($order->get_meta('order-delivery-completed') == 1) {
        return false;
      }

      $order->update_meta_data('completion-signature', $_POST['signature']);
      $order->update_meta_data('absence-delivery', (isset($_POST['absence-delivery']) && $_POST['absence-delivery'] === 'on' ? 1 : 0));
      if (isset($_POST['absence-image'])) {
        $order->update_meta_data('absence-image', $_POST['absence-image']);
      }
      if (isset($_POST['absence-image-2'])) {
        $order->update_meta_data('absence-image-2', $_POST['absence-image-2']);
      }

      $currentStatus = $order->get_status();
      $this->doPhysicalOrderBooking($orderId, true, false);
      if ($currentStatus == 'processing') {
        $order->update_status('wc-completed', 'Per Unterschrift ausgebucht und abgeschlossen.', false);
      } else {
        $order->add_order_note('Physisch ausgebucht per Unterschrift, aber noch *nicht* bezahlt.');
      }
      // Allow stuff after delivery update, only send once
      $this->afterDeliverySignatureHook($order, $currentStatus, $_POST);
      $order->update_meta_data('order-delivery-completed', 1);
      $order->save_meta_data();

      return true;
    }

    return false;
  }

  /**
   * @param \WC_Order $order
   * @param string $status status before completion, processing, on-hold
   * @param array $data post array containing data
   * @return void
   */
  protected function afterDeliverySignatureHook($order, $status, $data)
  {

  }

  public function assets()
  {
    $base = File::getResourceUri();
    wp_register_script('aboon-order-completion', $base . '/js/aboon/OrderCompletion.js', [], Core::REVISION, true);
    wp_register_script('aboon-signature', $base . '/js/aboon/Signature.js', [], Core::REVISION, true);
  }

  public function addAcfMetaboxes()
  {
    if ($_GET['post_type'] === self::INVENTORY_SLUG && $_GET['page'] === 'aboon-inventory-overview') {
      add_meta_box(
        'product-group-filter',
        'Warengruppe Filter',
        array($this, 'renderProductGroupFilter'),
        'acf_options_page',
        'side'
      );

      add_meta_box(
        'aboon-inventory-export',
        'Export',
        array($this, 'renderInventoryExport'),
        'acf_options_page',
        'side'
      );
    }
  }

  public function renderProductGroupFilter()
  {
    $terms = get_terms(array(
      'taxonomy' => self::PRODUCT_GROUP_SLUG,
      'hide_empty' => false
    ));
    $html = '<select name="product-group">';
    $selectedGroup = get_option('options_product-group-overview');

    foreach ($terms as $term) {
      $html .= '<option value="' . $term->term_id . '" ' . ($selectedGroup == $term->term_id ? 'selected' : '') . '>' . $term->name . '</option>';
    }

    $html .= '</select>
    <button class="button">Filtern</button>';

    echo $html;
  }

  public function productGroupActions($postId)
  {
    if (isset($_POST['product-group'])) {
      update_option('options_product-group-overview', $_POST['product-group']);
    }

    if (isset($_POST['start-booking-export']) && strlen($_POST['export-date']) !== 0) {
      $this->eventuallyExportBookings();
    }

    if (isset($_POST['exportinventory'])) {
      $this->displayInventoryTable();
    }
  }

  public function renderInventoryExport()
  {
    echo '
      <br>
      <input type="submit" name="exportinventory" class="button" value="Tabelle Exportieren"/>
      <br><br>
      <hr>
      <br>
      <input type="text" name="export-date" placeholder="z.B. 2023-05">
      <br><br>
      <input type="submit" name="start-booking-export" class="button" value="Buchungen Exportieren" />';
  }

  public function syncInventoryProducts()
  {
    $toSyncProducts = get_posts(array(
      'post_type' => self::INVENTORY_SLUG,
      'posts_per_page' => -1,
      'meta_query' => array(
        array(
          'key' => 'status',
          'value' => 'auto-shop-product',
          'compare' => 'LIKE'
        )
      ),
      'date_query' => array(
        array(
          'column' => 'post_modified_gmt',
          'after' => '-24 hours'
        )
      )
    ));

    if (empty($toSyncProducts)) {
      echo 'Keine geänderten Inventar-Produkte zum synchronisieren.';
      exit;
    }

    foreach ($toSyncProducts as $inventoryProduct) {
      $settings = get_field('sync-settings', $inventoryProduct->ID);
      $internalPrice = floatval(get_field('value-position', $inventoryProduct->ID));
      $existingProduct = wc_get_product_id_by_sku($settings['sku']);

      if ($existingProduct === 0) {
        // New product
        $product = new \WC_Product();
        $product->set_sku($settings['sku']);
        $product->set_status('publish');
      } else {
        // Existing product
        $product = wc_get_product($existingProduct);
      }

      $product->set_name($settings['public-title']);
      $product->set_description($settings['description']);
      $product->set_short_description($settings['short-description']);
      $product->set_category_ids($settings['categories']);
      $product->update_meta_data('_thumbnail_id', $settings['image-id']);

      $price = false;
      $tax = new \WC_Tax();
      $taxes = $tax->get_rates($product->get_tax_class());
      $taxData = array_shift($taxes);

      switch ($settings['pricedeinition']) {
        case 'fixed-product':
          $price = $settings['price'];
          break;
        case 'fixed':
          $inventoryPrice = $internalPrice + ($internalPrice * $taxData['rate'] / 100) + floatval($settings['fixed-margin']);
          $price = number_format((float)$inventoryPrice, $settings['fixed-rounding']);
          break;
        case 'percent':
          $inventoryPrice = $internalPrice + ($internalPrice * $taxData['rate'] / 100) + $internalPrice * (floatval($settings['percent-margin']) / 100);
          $price = number_format((float)$inventoryPrice, $settings['percent-rounding']);
          break;
      }

      if ($price !== false) {
        $product->set_regular_price($price);
      }

      if (intval($product->get_meta('inv-auto-booking')) === 0 || empty(get_field('bookables', $product->get_id()))) {
        $product->update_meta_data('inv-auto-booking', 1);
        add_row('bookables', array(
          'inventory-id' => $inventoryProduct->ID,
          'count' => 1
        ), $product->get_id());
      }

      if (isset($settings['additional-settings'])) {
        foreach ($settings['additional-settings'] as $aSetting) {
          switch ($aSetting) {
            case 'manage-stock':
              $product->set_manage_stock(true);
              break;

            case 'allow-backorders':
              if (in_array('manage-stock', $settings['additional-settings'])) {
                $product->set_backorders('yes');
              } else {
                $product->set_stock_status('onbackorder');
              }
              break;

            case 'compare-stock-booking':
              $product->set_stock_quantity(get_field('bestandsmenge', $inventoryProduct->ID));
              break;
          }
        }
      }

      $product->save();

      echo 'Produkt importiert: <a href="' . get_edit_post_link($product->get_id()) . '">' . $product->get_title() . '</a><br>';
    }
  }

  private function setupPriceAlarm()
  {
    $isActive = in_array('pricealarm-active', ArrayManipulation::forceArray(get_field('inventory-settings', 'option')));

    if ($isActive) {
      $settings = get_field('pricealarm-settings', 'option');

      switch ($settings['interval']) {
        case 'daily':
          add_action('cron_daily_8', [$this, 'sendPriceAlarmNotification']);
          break;
        case 'weekly':
          add_action('cron_weekday_1', [$this, 'sendPriceAlarmNotification']);
          break;
        case 'monthly':
          add_action('cron_monthly_1', [$this, 'sendPriceAlarmNotification']);
          break;
      }
    }
  }

  public function sendPriceAlarmNotification()
  {
    $alarmSettings = get_field('pricealarm-settings', 'option');
    $alarmProducts = array();

    $products = get_posts(array(
      'post_type' => 'product',
      'posts_per_page' => -1,
      'meta_query' => array(
        array(
          'key' => 'bookables',
          'value' => 1,
          'compare' => '='
        )
      )
    ));

    foreach ($products as $product) {
      $bookables = get_field('bookables', $product->ID);
      // Get invested price
      $investPrice = 0;
      foreach ($bookables as $inventory) {
        $investPrice += floatval($inventory['count']) * floatval(get_post_meta($inventory['inventory-id'], 'value-position', true));
      }
      // Get sale price excluding tax
      $salePrice = floatval(get_post_meta($product->ID, '_price', true));

      // Skip products without a (sale) price
      if ($salePrice <= 0) {
        continue;
      }

      $taxrates = \WC_Tax::get_rates_for_tax_class('standard');
      $taxrate = array_pop($taxrates);
      $salePrice = $salePrice / (1 + (floatval($taxrate->tax_rate) / 100));
      // Calculate percent difference between sale and invest price
      $percent = 100 - (($investPrice / $salePrice) * 100);

      if ($percent < floatval($alarmSettings['alarm-value'])) {
        $alarmProducts[] = array($product, 'Kritisch', $investPrice, $salePrice, $percent);
      } else if ($percent < floatval($alarmSettings['warning-value'])) {
        $alarmProducts[] = array($product, 'Warnung', $investPrice, $salePrice, $percent);
      }
    }

    // Stop if there's nothing to warn about
    if (empty($alarmProducts)) {
      return;
    }

    $table = '<table>
      <tr style="text-align: left;">
        <th style="border-bottom: 1px solid #000">ID</th>
        <th style="border-bottom: 1px solid #000">Status</th>
        <th style="border-bottom: 1px solid #000">Artikelname</th>
        <th style="border-bottom: 1px solid #000">Einkaufspreis</th>
        <th style="border-bottom: 1px solid #000">Verkaufspreis</th>
        <th style="border-bottom: 1px solid #000">Differenz Marge</th>
      </tr>';

    foreach ($alarmProducts as $i => $product) {
      $table .= '<tr ' . ($i % 2 !== 0 ? 'style="background: #efefef"' : '') . '>
        <td style="padding: 5px 10px;"><a href="' . get_edit_post_link($product[0]->ID) . '">' . $product[0]->ID . '</a></td>
        <td style="padding: 5px 10px; color: ' . ($product[1] === 'Kritisch' ? 'red' : 'orange') . '">' . $product[1] . '</td>
        <td style="padding: 5px 10px;">' . $product[0]->post_title . '</td>
        <td style="padding: 5px 10px;">' . $product[2] . '</td>
        <td style="padding: 5px 10px;">' . number_format($product[3], 2) . '</td>
        <td style="padding: 5px 10px;">' . number_format($product[4], 2) . '</td>
      </tr>';
    }

    $table .= '</table>';

    $html = 'Folgende Artikel haben eine kritische oder warnende Marge:<br>' . $table;

    $mail = External::PhpMailer();
    $mail->Subject = get_bloginfo('title') . ' - Preisalarm Report';
    $mail->Body = $html;
    $mail->AddAddress(get_option('admin_email'));
    $mail->send();

    // echo $table; use this to debug
  }
}