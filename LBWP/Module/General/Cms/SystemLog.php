<?php

namespace LBWP\Module\General\Cms;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\LbwpData;
use LBWP\Util\Strings;
use LBWP\Module\BaseSingleton;

/**
 * Allows for developers to add log messages that are displayed for admins
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class SystemLog extends BaseSingleton
{
  /**
   * @var string identifier of the DB option
   */
  const ROW_KEY = 'lbwpSystemLog';

  /**
   * Called at admin menu, allows us to add a submenu for admins
   */
  public function run()
  {
    add_action('admin_menu', array($this, 'addAdminMenu'));
    add_action('cron_weekday_6', array($this, 'removeOldData'));
  }

  /**
   * Adds the admin menu entry for the system log
   */
  public function addAdminMenu()
  {
    if (function_exists('add_submenu_page')) {
      add_submenu_page(
        'tools.php',
        'System-Log',
        'System-Log',
        'administrator',
        'systemlog',
        array($this, 'displayLog')
      );
    }
  }

  /**
   * @return void
   */
  public function removeOldData()
  {
    $lbwpData = new LbwpData(self::ROW_KEY);
    // Remove all rows older than 5 days
    $lbwpData->deleteOldRows(5);
  }

  /**
   * Displays the log information
   */
  public function displayLog()
  {
    echo '
      <div class="wrap">
        <h2>System-Log</h2>
        <table class="wp-list-table widefat fixed striped">
          <thead>
            <tr>
              <td>Datum/Uhrzeit</td>
              <td>Fehler-Typ</td>
              <td>Komponente</td>
              <td>Nachricht</td>
              <td>Weitere Daten</td>
            </tr>
          </thead>
          <tbody>
            ' . $this->getLogEntryHtml() . '
          </tbody>
        </table>
        <script>
          jQuery(function() {
            jQuery(".show-more").click(function() {
              jQuery(this).next().toggle();
            });
          });
        </script>
      </div>
    ';
  }

  /**
   * @return string html for the log entry rows
   */
  protected function getLogEntryHtml()
  {
    $html = '';
    $log = array();

    $lbwpData = new LbwpData(self::ROW_KEY);
    $raw = $lbwpData->getRows('row_created', 'DESC');
    foreach ($raw as $entry) {
      unset($entry['data']['_id']);
      $component = $entry['data']['_component']; unset($entry['data']['_component']);
      $type = $entry['data']['_type']; unset($entry['data']['_type']);
      $message = $entry['data']['_message']; unset($entry['data']['_message']);
      $log[] = array(
        'component' => $component,
        'date' => $entry['created'],
        'type' => $type,
        'message' => $message,
        'data' => $entry['data']
      );
    }


    foreach ($log as $entry) {
      $html .= '
        <tr class="log-' . $entry['type'] . '">
          <td>' . $entry['date'] . '</td>
          <td class="log-entry-' . $entry['type'] . '">' . $entry['type'] . '</td>
          <td>' . $entry['component'] . '</td>
          <td>' . $entry['message'] . '</td>
          <td>
            <a href="javascript:void(0)" class="show-more">Anzeigen</a>
            <pre class="show-more-content" style="display:none;">' . print_r($entry['data'], true) . '</pre>
          </td>
        </tr>
      ';
    }

    // Message, if there are no logs at all
    if (count($log) == 0) {
      $html .= '
        <tr class="no-logs">
          <td colspan="5">Bisher gibt es noch keine Log-Einträge.</td>
        </tr>
      ';
    }

    return $html;
  }

  /**
   * @param string $subject
   * @param string $email
   * @param string $body
   * @return bool true if logged and locally, false if not locally
   */
  public static function logMailLocally($subject, $email, $body)
  {
    if (defined('LOCAL_DEVELOPMENT')) {
      $filename = get_temp_dir() . Strings::forceSlugString($subject . '-' . $email) . '.html';
      file_put_contents($filename, $body);
      return true;
    }

    return false;
  }

  /**
   * Allows to add a log entry
   * @param string $component erroring component
   * @param string $type info, debug, error, critical
   * @param string $message error message
   * @param array $data additional error data
   */
  public static function add($component, $type, $message, $data = array())
  {
    // Handle if data is a string
    if (is_string($data)) {
      $data = array('_data_string' => $data);
    }
    // add main infos into the log data
    $data['_component'] = $component;
    $data['_type'] = $type;
    $data['_message'] = trim(strip_tags($message));
    $date = Date::getTime(Date::SQL_DATETIME, current_time('timestamp'));
    $data['_id'] = str_replace(array(' ', ':'), '-', $date) . '--' . uniqid();

    $lbwpData = new LbwpData(self::ROW_KEY);
    $lbwpData->updateRow($data['_id'], $data);

    // Send mail if critical status
    if ($type == 'critical') {
      $mail = External::PhpMailer();
      $mail->addAddress(MONITORING_EMAIL);
      $mail->Subject = 'SystemLog/' . $type . ':' . $message;
      $mail->Body = 'Domain: ' . LBWP_HOST . '<br>';
      foreach ($data as $key => $value) {
        $mail->Body .= $key . ': ' . $value . '<br>';
      }
      $mail->send();
    }
  }

  /**
   * Mirko's debug function (which is basically a shortcut for the log function)
   * @param $text string the debug message
   * @param ...$data mixed the data to pass to the debug function
   * @return void
   */
  public static function mDebug($text, ...$data){
    $trace = debug_backtrace();

    if(is_array($trace)){
      $trace = substr($trace[0]['file'], strrpos($trace[0]['file'], '/') + 1);
    }else{
      $trace = 'SystemLog';
    }

    if(!is_array($data)){
      $data = [$data];
    }

    self::add($trace, 'debug', $text, $data);
  }
}