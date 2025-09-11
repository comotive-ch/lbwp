<?php

namespace LBWP\Aboon\Backend;

/**
 * Handles EBICS file uploads and payment matching also allows user to update order status
 * @package LBWP\Aboon\Backend
 * @author Mirko Baffa <michael@comotive.ch
 */
class EbicsV2
{
  private $orderStatuses;
  private $changeToStatus;

  /**
   * @param $orderStatuses array status(es) of the order to filter
   * @param $changeToStatus string the status to change the orders to
   */
  public function __construct($orderStatuses, $changeToStatus){
    $this->orderStatuses = $orderStatuses;
    $this->changeToStatus = $changeToStatus;

    add_action('init', array($this, 'init'));
  }

  /**
   * Called on wordpress init
   * @return void
   */
  public function init(){
    add_action('admin_menu', array($this, 'addMenuPage'));

    $this->updateOrderStatus();
  }

  /**
   * Add wp-menu page
   * @return void
   */
  public function addMenuPage(){
    add_submenu_page(
      'woocommerce',
      'Abgleich von Bankzahlungs-Dateien',
      'Bankdaten abgleichen',
      'manage_woocommerce',
      'aboon-ebics',
      array($this, 'renderEbicsPage'),
    );
  }

  /**
   * Render the EBICS page
   * @return void
   */
  public function renderEbicsPage(){
    echo '
      <div class="wrap">
        <h1>Abgleich von Bankzahlungs-Dateien</h1>
        <p>Sie können die Zahlungsdatei (camt.53/54) hochladen um Zahlungseingänge abzugleichen.</p>
        <form method="post" enctype="multipart/form-data">
          <label for="camt_version">
            EBICS Version
            <select name="camt_version">
              <option value="53">camt.053</option>
              <option value="54">camt.054</option>
            </select>
          </label>
          <input type="file" name="ebics_camt53" />
          <input type="submit" value="Bankdatei hochladen" name="camt53_upload" class="button-primary" />
        </form>
        ' . $this->getPaymentTable() . '
      </div>
    ';
  }

  /**
   * Get payment table from uploaded camt53 file
   * @return string
   */
  private function getPaymentTable(){
    if(isset($_FILES['ebics_camt53'])){
      $file = $_FILES['ebics_camt53'];
      $fileContent = file_get_contents($file['tmp_name']);
      $xml = simplexml_load_string($fileContent);
      $payments = array();

      switch($_POST['camt_version']){
        case 53:
          $entries = $xml->BkToCstmrStmt->Stmt->Ntry;
          break;

        case 54:
        default:
          $entries = $xml->BkToCstmrDbtCdtNtfctn->Ntfctn->Ntry;
          break;
      }

      foreach($entries as $entry){
        $payment = array(
          'amount' => floatval((string)$entry->Amt),
          'reference' => intval(substr((string)$entry->NtryDtls->TxDtls->RmtInf->Strd->CdtrRefInf->Ref, 0, -1)),
          //'name' => (string)$entry->NtryDtls->TxDtls->RltdPties->Dbtr->Nm,
          //'iban' => (string)$entry->Acct->Id->IBAN,
          //'currency' => (string)$entry->Amt['Ccy'],
        );
        $payments[] = $payment;
      }

      $getOrders = $this->matchPayments($payments);
      return $this->displayOrdersTable($getOrders);
    }

    return '';
  }

  /**
   * Match payments with orders
   * @param $payments
   * @return array
   */
  private function matchPayments($payments){
    $orders = array();

    foreach($payments as $payment){
      $order = wc_get_order($payment['reference']);

      if(is_object($order)){
        if($order->get_total() == $payment['amount'] && in_array($order->get_status(), $this->orderStatuses)){
          $orders[] = $order;
        }
      }
    }
    return $orders;
  }

  /**
   * Display orders in a table
   * @param array $orders
   * @return string
   */
  private function displayOrdersTable(array $orders){
    if(empty($orders)){
      return '<p>Keine passenden Bestellungen gefunden.</p>';
    }

    $html = '<form method="post">
      <table class="wp-list-table widefat fixed striped table-view-list posts" style="margin: 1rem 0;">
        <thead>
        <tr>
          <th scope="col" class="manage-column" style="width: 20px;"></th>
          <th scope="col" class="manage-column" style="width: 50px">ID</th>
          <th scope="col" class="manage-column">Betrag</th>
          <th scope="col" class="manage-column">Nachname</th>
          <th scope="col" class="manage-column">Ort</th>
          <th scope="col" class="manage-column">Status</th>
        </tr>
        </thead>
        <tbody>
    ';

    /** @var \WC_Order $order */
    foreach($orders as $order){
      $html .= '
        <tr>
          <td><input type="checkbox" name="order_ids[]" value="' . $order->get_id() . '" checked /></td>
          <td><a href="/wp-admin/post.php?post=' . $order->get_id() . '&amp;action=edit" target="_blank" />' . $order->get_id() . '</a></td>
          <td>' . $order->get_total() . '</td>
          <td>' . $order->get_billing_last_name() . '</td>
          <td>' . $order->get_billing_city() . '</td>
          <td>' . $order->get_status() . '</td>
        </tr>
      ';
    }

    $html .= '</tbody></table>';
    $html .= '<input type="submit" value="Zahlungseingänge bestätigen" name="confirm_payments" class="button-primary" /></form>';

    return $html;
  }

  /**
   * Update order status on submit
   * @return void
   */
  private function updateOrderStatus(){
    if(isset($_POST['confirm_payments'])){
      $orderIds = $_POST['order_ids'];

      foreach($orderIds as $orderId){
        $order = wc_get_order(intval($orderId));

        if(is_object($order)) {
          // Change status but prevent emails being triggered
          add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
          add_filter('woocommerce_email_enabled_customer_customer_on_hold_order', '__return_false');
          $order->update_status($this->changeToStatus, '', true);
        }
      }

      echo '<div class="notice notice-success is-dismissible"><p>' . __('Bestellungen wurden erfolgreich aktualisiert.', 'aboon') . '</p></div>';
    }
  }
}