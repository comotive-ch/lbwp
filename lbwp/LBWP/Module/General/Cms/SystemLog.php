<?php

namespace LBWP\Module\General\Cms;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\External;
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
  const OPTION_NAME = 'lbwpSystemLog';
  /**
   * @var int the maximum number of entries
   */
  const MAX_ENTRIES = 100;

  /**
   * Called at admin menu, allows us to add a submenu for admins
   */
  public function run()
  {
    add_submenu_page(
      'tools.php',
      'System-Log',
      'System-Log',
      'administrator',
      'systemlog',
      array($this, 'displayLog')
    );
  }

  /**
   * Displays the log information
   */
  public function displayLog()
  {
    echo '
      <div class="wrap">
        <h2>System-Log</h2>
        <p>Diese Seite zeigt die letzten ' . self::MAX_ENTRIES . ' Log-Einträge des LBWP-Plugins an.</p>
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
    $log = ArrayManipulation::forceArray(get_option(self::OPTION_NAME));
    $log = array_reverse($log);

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
   * Allows to add a log entry
   * @param string $component erroring component
   * @param string $type info, debug, error, critical
   * @param string $message error message
   * @param array $data additional error data
   */
  public static function add($component, $type, $message, $data = array())
  {
    $log = ArrayManipulation::forceArray(get_option(self::OPTION_NAME));
    $type = Strings::forceSlugString($type);

    // See if we already reached the maximum entries in our log, remove oldest
    if (count($log) >= self::MAX_ENTRIES) {
      array_shift($log);
    }

    // Log the new information
    $log[] = array(
      'component' => $component,
      'date' => Date::getTime(Date::SQL_DATETIME, current_time('timestamp')),
      'type' => $type,
      'message' => trim(strip_tags($message)),
      'data' => $data
    );

    // Save back to the option
    update_option(self::OPTION_NAME, $log);

    // Send mail if critical status
    if ($type == 'critical') {
      $mail = External::PhpMailer();
      $mail->addAddress('it+monitoring@comotive.ch');
      $mail->Subject = 'SystemLog/' . $type . ':' . $message;
      $mail->Body = 'Domain: ' . LBWP_HOST . '<br>';
      foreach ($data as $key => $value) {
        $mail->Body .= $key . ': ' . $value . '<br>';
      }
      $mail->send();
    }
  }
}