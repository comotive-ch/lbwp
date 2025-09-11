<?php

namespace LBWP\Module\General\Cms;

use PHPMailer\PHPMailer\PHPMailer;
use LBWP\Util\LbwpData;

class MailLogger
{
  /**
   * @var bool
   */
  protected $debuggingMode = false;

  /**
   * Set debugging mode and init all needed actions
   */
  public function __construct()
  {
    $this->debuggingMode = get_option('lbwp_mail_log_debugging_mode') == 1;
    // Hooks into our own filterable php mailer sends
    add_action('phpmailer_custom_before_send', array($this, 'logMail'));
    // Hooks deep into phpmailer used via wp_mail
    add_action('phpmailer_init', array($this, 'logMail'));
    // Register admin backend
    add_action('admin_menu', array($this, 'registerAdminMenu'), 1, 20);
    // Register retention cron, default ist 30 days, make it overridable by a constant at least for apps.comotive.ch
    add_action('cron_weekday_5', [$this, 'deleteOldEntries']);
  }

  /**
   * @param PHPMailer $mail
   * @return void
   */
  public function logMail($mail)
  {
    $table = new LbwpData('lbwp_mail_log');
    $date = current_time('timestamp');
    $id = uniqid(true) . '-' . $date;
    $table->updateRow($id, array(
      'date' => $date,
      'from' => $mail->From,
      'to' => $this->getAddressesString($mail->getToAddresses()),
      'cc' => $this->getAddressesString($mail->getCcAddresses()),
      'bcc' => $this->getAddressesString($mail->getBccAddresses()),
      'reply' => $this->getAddressesString($mail->getReplyToAddresses()),
      'subject' => $mail->Subject,
      'body' => $this->debuggingMode ? $mail->Body : 'Nicht verfügbar: Debugging OFF',
    ));
  }

  /**
   * Converts address arrays of phpmailer to a string
   * @param $addresses
   * @return string
   */
  protected function getAddressesString($addresses)
  {
    $string = '';
    $addresses = array_filter($addresses);
    // Add first index of each address
    foreach ($addresses as $address) {
      $string .= $address[0] . ';';
    }
    // Remove last semicolon
    return rtrim($string, ';');
  }

  public function registerAdminMenu(){
    add_management_page(
      'Mail-Log',
      'Mail-Log',
      'administrator',
      'lbwp-mail-log',
      array($this, 'renderAdminPage')
    );
  }

  public function renderAdminPage(){
    if(isset($_POST['set_debugging_mode'])){
      $this->debuggingMode = isset($_POST['lbwp_mail_log_debugging_mode']) && $_POST['lbwp_mail_log_debugging_mode'] == 1;
      update_option('lbwp_mail_log_debugging_mode', $this->debuggingMode ? 1 : 0);
    }

    $table = new LbwpData('lbwp_mail_log');

    // Parameters
    $page = intval($_GET['paged']) ?? 0;
    $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

    if(isset($_GET['s']) && $_GET['s'] !== ''){
      $rows = $table->searchRows($_GET['s'], 'row_created', $order, 50, $page);
    }else{
      $rows = $table->getRows('row_created', 'DESC', 50, $page);
    }

    $rows = array_combine(array_column($rows, 'id'), array_column($rows, 'data'));
    $head = '';
    $body = '';

    if(isset($_GET['email-html'])){
      $row = $rows[$_GET['email-html']];

      echo '<h1>' . __('Mail Log', 'lbwp') . '</h1>
      <div class="wrap">
        <a href="#back" onclick="history.back()">' . __('< Zurück', 'lbwp') . '</a>
        <h2>' . date('d.m.Y H:i:s', $row['date']) . ' - ' . $row['subject'] . '</h2>
        ' . $row['body'] . '        
      </div>';

      return;
    }

    foreach($rows as $rowId => $row){
      if($head === ''){
        $keys = array_keys($row);
        $head .= '<thead><tr>';

        foreach($keys as $key){
          if($key === 'body' && !$this->debuggingMode){
            continue;
          }

          $classes = 'manage-column column-' . $key;
          if(isset($_GET['orderby']) && $_GET['orderby'] === $key){
            $classes .= ' sorted ' . ($_GET['order'] === 'asc' ? 'asc' : 'desc');
          }else{
            $classes .= ' sortable';
          }

          $head .= '<th class="' . $classes . '">
            <a href="#">
              <span>' . ucfirst($key) . '</span>
            </a>
          </th>';

          // Hotfix for new key "reply" (added later), add empty heading col
          if($key === 'bcc' && !in_array('reply', $keys)){
            $head .= '<th class="' . $classes . '">
            <a href="#">
              <span>Reply</span>
            </a>
          </th>';;
          }
        }

        $head .= '</tr></thead>';
      }

      $body .= '<tr>';
      foreach($row as $key => $value){
        if($key === 'body'){
          if(!$this->debuggingMode){
            continue;
          }else{
            $value = '<a href="' . $_SERVER['REQUEST_URI'] . '&email-html=' . $rowId . '">' . __('HTML Ansehen') . '</a>';
          }
        }

        if($key === 'date'){
          $value = date('d.m.Y H:i:s', $value);
        }

        $body .= '<td>' . $value . '</td>';

        // Hotfix for new key "reply" (added later), add empty col
        if($key === 'bcc' && !isset($row['reply'])){
          $body .= '<td></td>';
        }
      }
      $body .= '</tr>';
    }

    $resultsNum = $table->countRows($_GET['s'] ?? '');
    $currentPage = $page > 0 ? $page : 1;
    $totalPages = ceil($resultsNum / 50);

    $pagingNav = '<div class="tablenav-pages">
      <span class="displaying-num">' . sprintf(__('%s Einträge', 'lbwp'), $resultsNum) . '</span>
      <span class="pagination-links">
        ' . ($currentPage === 1 ? '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>' : '<a class="first-page button" href="' . $_SERVER['REQUEST_URI'] . '&amp;paged=1"><span aria-hidden="true">«</span></a>') .
        ($currentPage === 1 ? '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>' : '<a class="prev-page button" href="' . $_SERVER['REQUEST_URI'] . '&amp;paged=' . ($currentPage - 1) . '"><span aria-hidden="true">‹</span></a>') . '
                  
        <span id="table-paging" class="paging-input">
          <span class="tablenav-paging-text">' . $currentPage . ' von <span class="total-pages">' . $totalPages . '</span></span>
        </span>
        
        ' . ($currentPage >= $totalPages ? '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>' : '<a class="next-page button" href="' . $_SERVER['REQUEST_URI'] . '&amp;paged=' . ($currentPage + 1) . '"><span aria-hidden="true">›</span></a>') .
        ($currentPage >= $totalPages ? '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>' : '<a class="last-page button" href="' . $_SERVER['REQUEST_URI'] . '&amp;paged=' . $totalPages . '"><span aria-hidden="true">»</span></a>') . '
      </span>
    </div>';

    echo '<div class="wrap">
    <h1>' . __('Mail-Log', 'lbwp') . '</h1>
    <div class="tablenav top">
      <div class="search-box">
        <form method="get" action="">
          <input type="hidden" name="page" value="lbwp-mail-log">
          <input type="text" name="s" value="' . $_GET['s'] . '" placeholder="' . __('Suchbegriff eingeben', 'lbwp') . '" class="search-input">
          <input type="submit" class="button" value="' . __('Suchen', 'lbwp') . '">
        </form>
      </div>
      ' . $pagingNav . '
    </div>
    
    <table class="wp-list-table widefat fixed striped table-view-list pages">
      ' . $head . '
      <tbody>
      ' . $body . '
      </tbody>
    </table>
    
    <div class="tablenav bottom">
      <div class="alignleft settings">
        <form method="post" action="">
          <input type="hidden" name="page" value="lbwp-mail-log">
          <label for="lbwp_mail_log_debugging_mode">
            <input type="checkbox" id="lbwp_mail_log_debugging_mode" name="lbwp_mail_log_debugging_mode" value="1"' . ($this->debuggingMode ? ' checked' : '') . '>
            ' . __('Debugging aktivieren / Mail Inhalt speichern', 'lbwp') . '
          </label>
          <p><input type="submit" class="button button-primary" name="set_debugging_mode" value="' . __('speichern', 'lbwp') . '"></p>
        </form>
      </div>
      ' . $pagingNav . '
      <br class="clear">
    </div>
    </div>';
  }

  /**
   * Deletes old entries from the mail log
   */
  public function deleteOldEntries(){
    $maxDays = apply_filters('lbwp_mail_log_max_days', 90);
    $table = new LbwpData('lbwp_mail_log');
    $table->deleteOldRows($maxDays);
  }
}