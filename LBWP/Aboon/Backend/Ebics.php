<?php

namespace LBWP\Aboon\Backend;

use LBWP\Aboon\Base\Component;
use LBWP\Core as LbwpCore;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use Genkgo\Camt\Config;
use Genkgo\Camt\Reader;
use LBWP\Util\Strings;

/**
 * Handles EBICS file import and camt53 reader to automatically mark woocommerce orders as paid
 * @package LBWP\Aboon\Backend
 * @author Michael Sebel <michael@comotive.ch
 */
class Ebics extends Component
{
  /**
   * backend only filters, called on admin_init(10)
   */
  public function adminInit()
  {
    $this->enqueueAssets();
    add_action('wp_ajax_deletePaymentRow', array($this, 'deletePaymentRow'));
    add_action('wp_ajax_getOrders', array($this, 'getOrders'));
    add_action('wp_ajax_assignPayment', array($this, 'assignPayment'));
  }

  /**
   * Deletes a payment row, set matched to -1
   */
  public function deletePaymentRow()
  {
    // get the payment
    $paymentId = $_POST['pid'];
    $payments = ArrayManipulation::forceArray(get_option('aboon_camtimports'));

    // "delete" the payment
    $payments[$paymentId]['matched'] = -1;

    // save it back to the db
    update_option('aboon_camtimports', $payments);

    wp_send_json(true);
    wp_die();
  }

  /**
   * Get order row to assign to payments
   */
  public function getOrders()
  {
    $amount = floatval($_POST['amount']);
    // Get all orders in betwen 90% and 110% of $amount
    $allOrders = wc_get_orders(array(
      'limit' => -1,
      'post_status' => array('pending', 'on-hold'),
      'meta_query' => array(
        array(
          'key' => '_order_total',
          'value' => array($amount * 0.9, $amount * 1.1),
          'compare' => 'BETWEEN'
        )
      )
    ));

    // Build the "table" rows
    $rowHtml = '';
    foreach ($allOrders as $order) {
      $rowHtml .= '
        <div class="modal-order-row">
          <p><a href="' . get_edit_post_link($order->ID) . '" target="_blank">Bestellung #' . $order->ID . '</a></p>
          <p>' . $order->get_total() . ' ' . $order->get_currency() . '</p>
          <div class="asign-button button button-primary" data-assign="' . $order->ID . '">Zuweisen</div>
        </div>
      ';
    }

    // If not empty return the rows
    if (Strings::isEmpty($rowHtml)) {
      echo '<p>Es wurde keine Zahlung in der Höhe von ' . $amount . ' gefunden.</p>';
    } else {
      echo $rowHtml;
    }
    wp_die();
  }

  /**
   * Assign an order to a payment
   */
  public function assignPayment()
  {
    $orderId = $_POST['oId'];
    $paymentId = $_POST['pId'];
    $payments = ArrayManipulation::forceArray(get_option('aboon_camtimports'));

    // update the payment and the payment status
    $payments[$paymentId]['matched'] = $orderId;
    $order = wc_get_order($orderId);
    $order->payment_complete();

    // save it back to the db and return the link
    update_option('aboon_camtimports', $payments);
    echo '<a href="' . $order->get_edit_order_url() . '">Bestellung #' . $orderId . '</a>';
    wp_die();
  }

  /**
   * Displays payment infos from ebics or manual imports
   */
  public function displayPaymentReport()
  {
    echo '
      <div class="wrap">
        ' . $this->handleCamt53FileUpload() . '
        <h1>Abgleich von Bankzahlungs-Dateien</h1>
        <p>Der automatische Abgleich Ihrer Bank (EBICS) ist nicht aktiv. Sie können die Zahlungsdatei (camt.53) hochladen um Zahlungseingänge abzugleichen.</p>
        <form method="post" enctype="multipart/form-data">
          <input type="file" name="ebics_camt53" />
          <input type="submit" value="Bankdatei hochladen" name="camt53_upload" class="button-primary" />
        </form>
        ' . $this->getPaymentTable() . '
        <div id="modal-assignable-orders">
          <div class="close-button"><span>schliessen</span></div>
          <div class="modal-orders"></div>
        </div>
      </div>
    ';
  }

  protected function getPaymentTable()
  {
    $payments = ArrayManipulation::forceArray(get_option('aboon_camtimports'));
    if (count($payments) == 0) {
      return '';
    }

    $html = '
      <table class="wp-list-table widefat fixed striped table-view-list posts">
        <thead>
        <tr>
          <th scope="col" class="manage-column">Zahlungseingang</th>
          <th scope="col" class="manage-column">von Konto</th>
          <th scope="col" class="manage-column">Betrag</th>
          <th scope="col" class="manage-column">Referenz</th>
          <th scope="col" class="manage-column">Info</th>
          <th scope="col" class="manage-column">Zuweisung</th>
        </tr>
        </thead>
        <tbody>
    ';

    // TODO Maybe sort payments in a way that unmatched ones are first, then the matched ones

    // Display all the imported payments
    foreach ($payments as $id => $payment) {
      if ($payment['matched'] >= 0) {
        $html .= '
          <tr data-id="' . $id . '">
            <td>' . $payment['date'] . '</td>
            <td>' . $payment['iban'] . '</td>
            <td>' . number_format(($payment['amount'] / 100), 2) . ' ' . $payment['currency'] . '</td>
            <td>' . $payment['reference'] . '</td>
            <td>' . $payment['info'] . '</td>
            <td>' . $this->getAttachingLink($id, $payment) . '</td>
          </tr>
        ';
      }
    }

    $html .= '</tbody></table>';
    return $html;
  }

  protected function getAttachingLink($id, $payment)
  {
    if ($payment['matched'] == 0) {
      return '
        <div class="attaching-actions">
          <a href="#link_' . $id . '" class="aboon-match-payment" data-id="' . $id . '" data-amount="' . number_format(($payment['amount'] / 100), 2) . '">zuweisen</a> | 
          <a href="#delete_' . $id . '" class="delete-row">löschen</a>
        </div>';
    }

    return '<a href="' . get_edit_post_link($payment['matched']) . '">Bestellung #' . $payment['matched'] . '</a>';
  }

  /**
   * Handle the file upload of a camt53 file
   */
  protected function handleCamt53FileUpload()
  {
    if (!isset($_POST['camt53_upload']) && !isset($_FILES['ebics_camt53'])) {
      return '';
    }

    if ($_FILES['ebics_camt53']['error'] == 0) {
      $file = File::getNewUploadFolder() . $_FILES['ebics_camt53']['name'];
      move_uploaded_file($_FILES['ebics_camt53']['tmp_name'], $file);
      $message = $this->importCamt53File($file);
      // Display a message if given
      if (isset($message['msg']) && isset($message['type'])) {
        return '
          <div id="message" class="' . $message['type'] . ' notice">
            <p>' . $message['msg'] . '</p>
          </div>
        ';
      }
    }
  }

  /**
   * @param string $file path to a camt53 file
   */
  protected function importCamt53File($file)
  {
    $message['msg'] = 'Die Verarbeitung wurde durchgeführt.';
    $message['type'] = 'updated';

    // Try reading the file with the external library
    require_once File::getResourcePath() . '/libraries/camtreader/autoload.php';
    $reader = new Reader(Config::getDefault());
    $handler = $reader->readFile($file);
    $payments = ArrayManipulation::forceArray(get_option('aboon_camtimports'));

    // Get the actual entries from the file and try matching them
    $statements = $handler->getRecords();
    foreach ($statements as $statement) {
      $entries = $statement->getEntries();
      if (is_array($entries)) {
        foreach ($entries as $entry) {
          // newly add with open status, if not yet in payments
          $id = strtolower($entry->getAccountServicerReference());
          if (!isset($payments[$id])) {
            $amount = intval($entry->getAmount()->getAmount());
            if ($amount > 0) {
              $payments[$id] = array(
                'date' => $entry->getValueDate()->format('d.m.Y'),
                'iban' => $entry->getRecord()->getAccount()->getIdentification(),
                'amount' => $entry->getAmount()->getAmount(),
                'currency' => $entry->getAmount()->getCurrency()->getCode(),
                'reference' => $entry->getTransactionDetail()->getRemittanceInformation()->getCreditorReferenceInformation()->getRef(),
                'info' => $entry->getAdditionalInfo(),
                'matched' => 0
              );
            }

            // Try matching the payment with order waiting for payment (by price and reference)
            if ($payments[$id]['matched'] == 0 && strlen($payments[$id]['reference']) > 0) {
              // Get the last 12 chars from reference
              $reference = substr($payments[$id]['reference'], -12);
              $reference = substr($reference, 0, 11);
              $order = wc_get_order(intval($reference));
              // If the order is valid and price is matching, set status to paid immediately
              if ($order->get_id() > 0 && floatval($order->get_total()) == floatval($payments[$id]['amount'] / 100)) {
                $payments[$id]['matched'] = $order->get_id();
                $order->payment_complete();
              }
            }
          }
        }
      }
    }

    // TODO Remove payments older than 30 days

    // Save back unmatches to our option
    update_option('aboon_camtimports', $payments);

    return $message;
  }

  /**
   * Add general purpose filters, called on init(10)
   */
  public function init()
  {
    add_action('admin_menu', array($this, 'addWooCommerceMenuPage'), 999);
  }

  /**
   * Adds the main page where payments are shown and assigned
   */
  public function addWooCommerceMenuPage()
  {
    add_submenu_page(
      'woocommerce',
      'Zahlungsabgleich',
      'Zahlungsabgleich',
      'administrator',
      'auto-payment-info',
      array($this, 'displayPaymentReport')
    );
  }

  /**
   * frontend only filters, called on wp(10)
   */
  public function frontInit()
  {

  }

  /**
   * Enqueue the js file
   */
  public function enqueueAssets()
  {
    $base = File::getResourceUri();
    wp_enqueue_script('auto-payment-info-js', $base . '/js/auto-payment-info.js', array('jquery'), LbwpCore::REVISION, true);
    wp_localize_script('auto-payment-info-js', 'ajaxData', array('url' => admin_url('admin-ajax.php')));
  }
}