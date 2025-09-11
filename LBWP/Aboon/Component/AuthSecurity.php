<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Component\ACFBase;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Core;
use LBWP\Module\Forms\Action\SendMail;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\External;
use LBWP\Util\Templating;
use WP_Error;

/**
 * Provide functions for woocommerce users to use 2FA via email
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class AuthSecurity extends ACFBase
{
  /**
   * User meta key
   */
  const AUTH_META = 'aboon-two-factor-auth';

  /**
	 * The auth input name
   */
	const AUTH_NAME = 'aboon-2f-auth';

	/**
	 * Meta name for trusted devices
	 */
	const TRUSTED_DEVICES_META = 'aboon-2f-auth-devices';

	/**
	 * Salt used for generating the device hashes
	 */
	const TRUSTED_DEVICES_SALT = 
		'HFP4sdKIi(*_~^Eq|i>QZ(ez)7A<aF +TrAfxbi7:dQ5!N}r9IybAE)mo>)]]=,
		9r9X^R=WL#YYK|z,*-72:~/%o-.]hg+@)i%)}jvu;TSZdV4H3{jyl+(VT]kx&1F9
		3_(|F!_BwBdS<Qw@17*_&CAsmySt$K:VkQpcm9623eQIPTko@F_t:dXgx<?Q5<d1
		S/*)+s;hAnEvr)Ubd5i??.2x#l~?1fB4!^#t|t|9]($W9@;a{=WtyeYtc`=bE_,p
		k3oLbwp2AVq9p|B^=|kgg[uG`>g[dh_(e~-,;Jjw[km2OQ(,4G|;dw6~?l*(4+TO
		PXf-4s-_-Rc!g6R^h{$^O-(%I1JE-7v!{233C|{mfgN#&Mo*C +P>dd5i2z~R&e~
		FD=),`L;kU2zZ,a=vmH.DP6f t/dn`R%M5#1K4n^${]mgTf+*|+O&|U3 `1*.j1@
		b?h+nqVN`p- ~L`M1RHszhI;-tIg)7G:HJEJ$xe+}twOO ,`iWb:Hg%Ja=<gP09>';

  /**
   * If the authentication is active
   */
  private $isActive = false;

  /**
   * The authentication code
   */
  private $authCode;

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    $this->isActive = !empty(get_option('options_force-auth-security'));

    if ($this->isActive) {
      // Add ajax hooks
      add_action('wp_ajax_checkUserAuth', array($this, 'checkUserAuth'));
      add_action('wp_ajax_nopriv_checkUserAuth', array($this, 'checkUserAuth'));
      // Add custom authentications
      add_filter('authenticate', array($this, 'addLoginCheck'), 99, 3);
      add_filter('wp_login', array($this, 'updateAuthDuration'), 99, 2);
    }
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    // Hook into main settings page
    add_action('aboon_general_settings_page', array($this, 'addSettingsFields'));
  }

  public function assets()
  {
    // If is login page add js and pass variables to it
    if ($this->isActive) {
      $base = File::getResourceUri();
      wp_enqueue_script('aboon-auth-js', $base . '/js/aboon/authentication.js', array('jquery'), Core::REVISION, true);
      wp_localize_script('aboon-auth-js', 'aboonAjax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'inputName' => self::AUTH_NAME
      ));
    }
  }

  /**
   * Adds settings for the given features
   */
  public function addSettingsFields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_5feabda083732',
      'title' => 'Einstellungen Konto-Sicherheit',
      'fields' => array(
        array(
          'key' => 'field_5fcf6938d3117',
          'label' => 'Verbesserte Konto-Sicherheit aktivieren',
          'name' => 'force-auth-security',
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
            1 => 'Wenn Käufer sich anmelden müssen sie Periodisch einen Code per E-Mail eingeben',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_5f197b9b28071',
          'label' => 'Anzahl Tage bis Code erneut nötig wird',
          'name' => 'auth-security-duration',
          'type' => 'number',
          'instructions' => 'Beim Wert 0 (oder leer) ist bei jedem Login die Eingabe des Code nötig. Ansonsten nur alle n-Tage gemäss einstellung.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        )
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
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }

  /**
   * No blocks needed here
   */
  public function blocks()
  {
  }

  /**
   * Check the user two factor authentication
   */
  public function checkUserAuth()
  {
    $email = $_POST['data']['email'];

    // Return if it's not an email
    if (!Strings::isEmail($email)) {
      wp_send_json(false);
    }

    $user = get_user_by('email', $email);

    // Return if user is not found
    if ($user === false) {
      wp_send_json(false);
    }

    // Start authentication process and return true to display the auth field
    if ($this->authIsOutDated($user->ID) || !$this->isTrustedDevice($user->ID, $email)) {
      // Generate code
      $code = Strings::getRandom(8);
      update_user_meta($user->ID, self::AUTH_NAME, $code);

      // send mail
      $firstname = get_user_meta($user->ID, 'billing_first_name', true) === '' ? $user->display_name : get_user_meta($user->ID, 'billing_first_name', true);
      $lastname = get_user_meta($user->ID, 'billing_last_name', true);
      $emailHtml = Templating::getEmailTemplate(
        apply_filters('aboon_auth_email_header', 'Ihr Authentifizierungs-Code'),
        apply_filters('aboon_auth_email_content', 'Hallo ' . trim($firstname . ' ' . $lastname) . '<br><br>Dein Autherifizierungscode lautet: <strong>' . $code . '</strong>'),
        apply_filters('aboon_auth_email_args', array())
      );

      $mail = External::PhpMailer();
      $mail->Subject = apply_filters('aboon_auth_email_subject', sprintf(__('%s - Ihr Authentifizierungs-Code', 'lbwp'), LBWP_HOST));
      $mail->Body = apply_filters('aboon_auth_email_body', $emailHtml);
      $mail->addAddress($email);
      $mail->send();

      wp_send_json(true);
    }

    wp_send_json(false);
    wp_die();
  }

  /**
   * Check if the authentication time is outdated
   *
   * @param int $userId
   * @return bool true if it is outdated. False otherwise
   */
  public function authIsOutDated($userId)
  {
    $getAuthDuration = apply_filters('aboon_auth_security_duration', get_option('options_auth-security-duration'), $userId);
    $authDuration = intval($getAuthDuration) * 24 * 60 * 60;
    $userAuth = intval(get_user_meta($userId, self::AUTH_META, true));

    if ($userAuth + $authDuration < time()) {
      return true;
    }

    return false;
  }
	
	/**
	 * Check if the current device is a trusted device
	 *
	 * @param  int $userId the user id
	 * @param  string $username the username (email)
	 * @return bool 
	 */
	public function isTrustedDevice($userId, $username){
		$tDeviceHash = $this->getDeviceHash($username);
		$tDevices = get_user_meta($userId, self::TRUSTED_DEVICES_META, true);
		$tDevices = is_array($tDevices) ? $tDevices : array();

		return in_array($tDeviceHash, $tDevices);
	}

  /**
   * Implement custom auth field
   *
   * @param WP_User $user
   * @param string $username
   * @param string $password
   * @return WP_User|null|WP_Error
   */
  public function addLoginCheck($user, $username, $password)
  {
    if ($this->authIsOutDated($user->ID) && is_account_page() && !is_user_logged_in()) {
      // Get the auth code and delete it afterwards
      $authCode = get_user_meta($user->ID, self::AUTH_NAME, true);
      update_user_meta($user->ID, self::AUTH_NAME, null);

      // Check the auth code with the input
      if ($_POST[self::AUTH_NAME] !== $authCode) {
        // TODO: add custom login error
        return new WP_Error(__('Der Authentifizierungs-Code ist nicht korrekt.', 'lbwp'));
      }
    }

    return $user;
  }

  /**
   * Updated the authentication date to current date
   *
   * @param mixed $login
   * @param WP_User $user
   * @return void
   */
  public function updateAuthDuration($login, $user)
  {
		// If is auth is outdated update the userlogin date
    if ($this->authIsOutDated($user->ID)) {
      update_user_meta($user->ID, self::AUTH_META, time());
    }

		// Get the current device hash and check if this hash is in the trusted devices hash array
		$trustedDeviceHash = ArrayManipulation::forceArray($this->getDeviceHash($user->get('user_email')));
		$trustedDevices = get_user_meta($user->ID, self::TRUSTED_DEVICES_META, true);
		$trustedDevices = is_array($trustedDevices) ? array_unique(array_merge($trustedDevices, $trustedDeviceHash)) : array($trustedDeviceHash);

		update_user_meta($user->ID, self::TRUSTED_DEVICES_META, $trustedDevices);
  }
	
	/**
	 * Get/generate the device hash
	 *
	 * @param  string $user the username (email)
	 * @return string the hash for the device
	 */
	public function getDeviceHash($user){
		// Get the useragent without any version number and generate the hash
		$userAgent = preg_replace('/(\d|[:;,.\/])/', '', $_SERVER['HTTP_USER_AGENT']);
		return password_hash('secret-aboon-string' . $userAgent . $user,  PASSWORD_BCRYPT, array('salt' => self::TRUSTED_DEVICES_SALT));
	}
} 