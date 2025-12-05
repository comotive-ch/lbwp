<?php

namespace LBWP\ERP\Inventory;

use LBWP\Helper\WooCommerce\Util;
use LBWP\Helper\ZipDistance;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\WordPress;

/**
 * Provide inventory functions
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class SendingsPlanner extends ACFBase
{
  const SENDING_SLUG = 'order-sending';

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    // Register a twice daily job to do bookings and warn on low stock afterwards
    add_action('add_meta_boxes_' . self::SENDING_SLUG, array($this, 'sendingListTableMetabox'));
    add_action('admin_menu', array($this, 'addCustomPages'));
    add_filter('duplicateable_post_types', array($this, 'addDuplicateability'));
    // Post type and column config
    $this->addTypeConfig();
  }


  /**
   * @param $allowedTypes
   * @return mixed
   */
  public function addDuplicateability($allowedTypes)
  {
    $allowedTypes[] = self::SENDING_SLUG;
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
    return apply_filters('lbwp_erp_inventory_translate_shipping_method', $method);
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
   * Post type and taxonomy configuration
   * @return void
   */
  protected function addTypeConfig()
  {
    WordPress::registerType(self::SENDING_SLUG, 'Versand', 'Versände', array(
      'menu_icon' => 'dashicons-controls-repeat',
      'supports' => array('title'),
      'menu_position' => 58,
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
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
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
  }

  public function blocks()
  {

  }
}