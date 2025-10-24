<?php

namespace LBWP\Module\Backend;

use LBWP\Helper\Location;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\External;
use LBWP\Util\Templating;

/**
 * Monitor Logins and send email if logged in from new device
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class MonitorLogins extends \LBWP\Module\Base
{
  /**
   * Meta key of the devices list
   */
  const USER_DEVICES_META_KEY = 'lbwp_login_devices_v2';
  /**
   * User roles to monitor
   * TODO: maybe add e setting/filter for this?
   * @var string[]
   */
  private $roles = array('administrator');

  public function __construct()
  {
    parent::__construct();
    $this->initialize();
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_action('wp_login', array($this, 'logLoginDevices'), 10, 2);
  }

  /**
   * Monitor the login device
   * @return void
   */
  public function logLoginDevices($login, $user)
  {
    if (in_array($user->roles[0], $this->roles) && !defined('LBWP_DISABLE_LOGIN_MONITORING')) {
      $loginDevices = get_user_meta($user->data->ID, self::USER_DEVICES_META_KEY, true);
      $browser = get_browser($_SERVER['HTTP_USER_AGENT']);
      // Do nothing if browscap not active / not working
      if ($browser === false) {
        return;
      }

      $device = $browser->platform . ', ' . $browser->device_name . ', ' . $browser->browser;

      // Don't send warning mail on first login
      if (!is_array($loginDevices)) {
        $loginDevices = array($device);
      } else if (!in_array($device, $loginDevices)) {
        $loginDevices[] = $device;

        $ip = $_SERVER['X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'];
        $subject = '[' . LBWP_HOST . '] Login von unbekanntem Gerät';
        $location = json_decode(file_get_contents('https://ipinfo.io/' . $ip . '/json'));
        $team = defined('LBWP_WESIGN_INSTANCE') && LBWP_WESIGN_INSTANCE === true ? 'Das wesign Team' : 'Das comotive.ch Team';
        $content = Templating::getEmailTemplate('Login von unbekanntem Gerät',
          'Hallo ' . $user->data->user_nicename . '<br/><br/>
          Wir haben einen Login mit deinem Account auf ' . LBWP_HOST . ' mit einem bisher unbekannten Gerät oder Browser festgestellt.' . '<br/><br/>' .
          'Benutzername: ' . $user->data->user_login . '<br/>' .
          'Datum und Zeit: ' . date('d.m.y H:i:s', $_SERVER['REQUEST_TIME'] + get_option('gmt_offset') * HOUR_IN_SECONDS) . '<br/>' .
          'IP: ' . $ip . '<br/>' .
          'Gerät: ' . $browser->platform . ', ' . $browser->device_name . '<br/>' .
          'Browser: ' . $browser->browser . '<br/>' .
          'Ungefährer Ort: ' . (isset($location->bogon) && $location->bogon === true ? 'Unbekannt' : $location->city . ', ' . $location->region . ' (' . $location->country . ')') . '
          <br/><br/>
          Sofern du dich gerade eingeloggt hast, ist alles in Ordnung. Wenn nicht, empfehlen wir, möglichst schnell <a href="https://' . LBWP_HOST . '/wp-admin/profile.php">dein Passwort zu ändern</a>.<br>
          <br>
          Freundliche Grüsse<br>' .
          $team
        );

        $mail = External::PhpMailer();
        $mail->addAddress($user->data->user_email);
        $mail->isHTML();
        $mail->Subject = $subject;
        $mail->Body = $content;
        $mail->send();
      }

      update_user_meta($user->data->ID, self::USER_DEVICES_META_KEY, $loginDevices);
    }
  }
}