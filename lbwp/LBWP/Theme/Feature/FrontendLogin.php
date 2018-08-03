<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\Forms\Core as FormCore;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;

/**
 * Class FrontendLogin
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class FrontendLogin
{
  /**
   * @var array custom fields after the default fields for register/change process
   * Example structure
   * array(
   *   array(
   *     'id' => 'myFieldId1',
   *     'label' => __('myLabel1', 'lbwp'),
   *     'type' => 'text', // any allowed lbwp form text field
   *     'required' => true
   *   ),
   *   array(
   *     'id' => 'myFieldId2',
   *     'label' => __('myLabel2', 'lbwp'),
   *     'type' => 'text', // any allowed lbwp form text field
   *     'required' => false
   *    ),
   * )
   */
  protected $customFields = array();

  /**
   * @param array $fields the custom fields for registration/datachange
   */
  public function setCustomFields(array $fields)
  {
    $this->customFields = $fields;
  }

  /**
   * Logouts the user
   */
  public function logoutUser()
  {
    wp_logout();
    $url = get_permalink();
    $url = Strings::attachParam('message', 'logout', $url);
    wp_safe_redirect($url);
  }

  /**
   * Logs the user in or displays the login form
   */
  public function processLogin()
  {
    $user = null;
    $html = '';

    if ('POST' == $_SERVER['REQUEST_METHOD']) {
      $user = wp_signon('', false);
      if (!is_wp_error($user)) {
        // Let user decide with a filter what happens
        do_action('FrontendLogin_after_successful_login', $user);
        if (isset($_POST['redirect_to']) && Strings::isURL($_POST['redirect_to'])) {
          wp_safe_redirect($_POST['redirect_to']);
        } else {
          wp_safe_redirect(get_permalink());
        }
        exit;
      }
    }

    if (is_wp_error($user)) {
      $errors = array();
      foreach ($user->get_error_codes() as $code) {
        if ($code === 'empty_username') {
          $errors[] = __('<strong>Fehler</strong>: Bitte geben Sie Ihren Benutzernamen ein.', 'lbwp');
        } else if ($code === 'empty_password') {
          $errors[] = __('<strong>Fehler</strong>: Bitte geben Sie Ihr Passwort ein.', 'lbwp');
        } else if ($code === 'invalid_username') {
          $errors[] = __('<strong>Fehler</strong>: Der eingegebene Benutzername ist ungültig.', 'lbwp');
        } else if ($code === 'incorrect_password') {
          $errors[] = __('<strong>Fehler</strong>: Das eingegebene Passwort ist nicht korrekt.', 'lbwp');
        }
      }

      $html .= '<p class="lbwp-message message-error">' . implode(PHP_EOL, $errors) . '</p>';
    }

    // Display a logout message
    if (isset($_GET['message']) && $_GET['message'] == 'logout') {
      $html .= apply_filters('FrontendLogin_success', '<p class="lbwp-message">' . __('Sie wurden erfolgreich ausgeloggt.', 'lbwp') . '</p>');
    }

    $html .= $this->displayLoginForm();
    return $html;
  }

  /**
   * Displays the login form
   */
  public function displayLoginForm()
  {
    HTMLCache::avoidCache();
    $shortcode = '
      [lbwp:form button="' . __('Login', 'lbwp') . '" id="login" weiterleitung="' . get_permalink() . '" action="' . get_permalink() . '" skip_execution="1"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="log" feldname="' . __('E-Mail-Adresse', 'lbwp') . '" type="text"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="pwd" feldname="' . __('Passwort', 'lbwp') . '" type="password"]
        [lbwp:formItem key="hiddenfield" id="redirect_to" vorgabewert="' . apply_filters('FrontendLogin_redirect_to', $this->getTarget(), 'login') . '"]
      [/lbwp:form]
    ';

    $formHtml =
      '<div id="login-form">' . PHP_EOL
      . apply_filters('FrontendLogin_heading', '<h3>' . __('Login', 'lbwp') . '</h3>') . PHP_EOL
      . apply_filters('FrontendLogin_content_before_form', '') . PHP_EOL
      . do_shortcode($shortcode) . PHP_EOL
      . apply_filters('FrontendLogin_content_after_form', '') . PHP_EOL
      . '<script type="text/javascript">'
        . 'jQuery(document).ready(function () {'
        . '  jQuery("#log").attr("tabindex", 1);'
        . '  jQuery("#pwd").attr("tabindex", 2);'
        . '  jQuery("input[name=\'lbwpFormSend\']").attr("tabindex", 3);'
        . '});'
      . '</script>'
    . '</div>';

    // Add validation and css
    $core = FormCore::getInstance();
    if ($core instanceof FormCore) {
      FormCore::getInstance()->getFormHandler()->addFormAssets();
    }

    return $formHtml;
  }

  /**
   * Registers the user, logs him and and displays the registration form
   */
  public function processRegistration()
  {
    $user = null;
    $html = '';

    if ('POST' == $_SERVER['REQUEST_METHOD'] && $_POST['sentForm'] == 'register') {
      // Let developers to custom validation and return an error
      $userId = apply_filters('FrontendRegistration_custom_validation', false);

      // Only proceed if custom validation threw no errors
      if ($userId === false) {
        $diplayName = trim($_POST['firstname'] . ' ' . $_POST['lastname']);
        $userId = wp_insert_user(array(
          'user_login' => $_POST['email'],
          'user_pass' => $_POST['password'],
          'user_nicename' => Strings::forceSlugString($diplayName),
          'user_email' => $_POST['email'],
          'display_name' => $diplayName,
        ));
      }

      // Proceed to add data, if a user was created
      if (intval($userId) > 0) {
        // Set first and lastname meta data, also set a role
        update_user_meta($userId, 'first_name', $_POST['firstname']);
        update_user_meta($userId, 'last_name', $_POST['lastname']);
        update_user_meta($userId, 'salutation', $this->getSalutation($_POST['gender']));
        // Process further custom fields, if given
        foreach ($this->customFields as $field) {
          update_user_meta($userId, $field['id'], $_POST[$field['id']]);
        }
        // Make a user instance and make sure to set the subscriber role
        $user = new \WP_User($userId);
        $user->set_role('subscriber');
        // Success filter to make further changes to the user
        do_action('FrontendRegistration_after_user_creation', $user);
        // Log the user in after redirecting by setting the login cookie
        wp_signon(array(
          'user_login' => $user->user_email,
          'user_password' => $_POST['password'],
          'remember' => true
        ), false);
      }

      // No errors happened, redirect to success page
      if (!is_wp_error($userId)) {
        if (isset($_POST['redirect_to']) && Strings::isURL($_POST['redirect_to'])) {
          wp_safe_redirect($_POST['redirect_to']);
        } else {
          $url = get_permalink();
          $url = Strings::attachParam('message', 'registered', $url);
          wp_safe_redirect($url);
        }
        exit;
      }
    }

    if (is_wp_error($userId)) {
      $errors = array();
      foreach ($userId->get_error_codes() as $code) {
        $errors[] = sprintf(__('<strong>Fehler</strong>: %s', 'lbwp'), $userId->get_error_message($code));
      }
      $html .= '<p class="message message-error">' . implode('<br />', $errors) . '</p>';
    }

    // Display a registration success message
    if (isset($_GET['message']) && $_GET['message'] == 'registered') {
      $html .= apply_filters('FrontendRegistration_success', '<p class="message">' . __('Ihr Account wurde erstellt. Sie sind nun eingeloggt.', 'lbwp') . '</p>');
    }

    $html .= $this->displayRegistrationForm();
    return $html;
  }

  /**
   * Displays the registration form
   */
  public function displayRegistrationForm()
  {
    HTMLCache::avoidCache();
    $language = Multilang::getCurrentLang('slug', 'de');

    $shortcode = '
      [lbwp:form button="' . __('Registrieren', 'lbwp') . '" id="register" weiterleitung="' . get_permalink() . '" action="' . get_permalink() . '" skip_execution="1"]
        [lbwp:formContentItem id="gender" key="dropdown" pflichtfeld="ja" feldname="' . __('Anrede', 'lbwp') . '" id="gender"]
          male_' . $language . '==' . __('Herr', 'lbwp') . '$$
          female_' . $language . '==' . __('Frau', 'lbwp') . '
        [/lbwp:formContentItem]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="email" feldname="' . __('E-Mail-Adresse', 'lbwp') . '" type="email"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="firstname" feldname="' . __('Vorname', 'lbwp') . '" type="text"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="lastname" feldname="' . __('Nachname', 'lbwp') . '" type="text"]
        ' . $this->getCustomFieldShortcodes() . '
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="password" feldname="' . __('Passwort', 'lbwp') . '" type="password"]
        [lbwp:formItem key="hiddenfield" id="redirect_to" vorgabewert="' . apply_filters('FrontendRegistration_redirect_to', $this->getTarget(), 'registration') . '"]
      [/lbwp:form]
    ';

    $formHtml =
      '<div id="registration-form">' . PHP_EOL
      . apply_filters('FrontendRegistration_heading', '<h3>' . __('Registrieren', 'lbwp') . '</h3>') . PHP_EOL
      . apply_filters('FrontendRegistration_content_before_form', '') . PHP_EOL
      . do_shortcode($shortcode) . PHP_EOL
      . apply_filters('FrontendRegistration_content_after_form', '') . PHP_EOL
    . '</div>';

    // Add validation and css
    $core = FormCore::getInstance();
    if ($core instanceof FormCore) {
      FormCore::getInstance()->getFormHandler()->addFormAssets();
    }

    return $formHtml;
  }

  /**
   * @param string $tag the salutation tag from reg form
   * @return string the actual saveable salutation
   */
  protected function getSalutation($tag)
  {
    switch ($tag) {
      case 'male_de': return 'Sehr geehrter Herr';
      case 'male_fr': return 'Cher monsieur';
      case 'male_en': return 'Dear Mr';
      case 'male_it': return 'Caro signore';
      case 'female_de': return 'Sehr geehrte Frau';
      case 'female_fr': return 'Chère madame';
      case 'female_en': return 'Dear Ms';
      case 'female_it': return 'Cara signorina';
    }

    return '';
  }

  /**
   * @return string
   */
  protected function getTarget()
  {
    if (isset($_GET['target']) && Strings::isURL($_GET['target'])) {
      if ($_GET['target'] != get_permalink()) {
        return $_GET['target'];
      }
    }

    return '';
  }

  /**
   * Edits settings of an existing user
   */
  public function processDataChange($fallbackPageId)
  {
    $user = null;
    $html = '';

    // If the user is not logged in, redirect him to a fallback page
    if (!is_user_logged_in()) {
      wp_safe_redirect(get_permalink($fallbackPageId));
      exit;
    }

    if ('POST' == $_SERVER['REQUEST_METHOD'] && $_POST['sentForm'] == 'data-change') {
      // Prepare update array with new user data and meta
      $user = wp_get_current_user();
      $updateData = array(
        'ID' => $user->ID,
        'display_name' => trim($_POST['firstname'] . ' ' . $_POST['lastname'])
      );

      // Save new first and last name to user meta
      update_user_meta($user->ID, 'first_name', $_POST['firstname']);
      update_user_meta($user->ID, 'last_name', $_POST['lastname']);

      // Process further custom fields, if given
      foreach ($this->customFields as $field) {
        update_user_meta($user->ID, $field['id'], $_POST[$field['id']]);
      }

      // See if the email changed
      if ($user->user_email != $_POST['email'] && Strings::checkEmail($_POST['email'])) {
        // Change the email in our update object
        $updateData['user_email'] = $_POST['email'];
        // If the login was the email address before, also change the login name
        if (Strings::checkEmail($user->user_login)) {
          $updateData['user_login'] = $_POST['email'];
        }
      }

      // Is there a new and valid password
      if (strlen($_POST['new-password']) > 0 && $_POST['new-password'] == $_POST['password-confirm']) {
        $updateData['user_pass'] = $_POST['new-password'];
      }

      // Finally update the user
      $result = wp_update_user($updateData);
      do_action('FrontendLogin_after_user_data_change', $user, $updateData, $result);

      // No errors happened, redirect to success page
      if (!is_wp_error($result)) {
        $url = get_permalink();
        $url = Strings::attachParam('message', 'data-saved', $url);
        wp_safe_redirect($url);
        exit;
      }
    }

    // Display errors if given
    if (!empty($result) && is_wp_error($result)) {
      $errors = array();
      foreach ($result->get_error_codes() as $code) {
        $errors[] = sprintf(__('<strong>Fehler</strong>: %s', 'lbwp'), $result->get_error_message($code));
      }
      $html .= '<p class="message message-error">' . implode('<br />', $errors) . '</p>';
    }

    // Display a registration success message
    if (isset($_GET['message']) && $_GET['message'] == 'data-saved') {
      $html .= apply_filters('FrontendDataChange_success', '<p class="message">' . __('Ihre Profildaten wurden gespeichert.', 'lbwp') . '</p>');
    }

    $html .= $this->displayDataChangeForm();
    return $html;
  }

  /**
   * Displays the data change form
   */
  public function displayDataChangeForm()
  {
    HTMLCache::avoidCache();
    // Get the currently logged in user and its data
    $user = wp_get_current_user();
    $email = esc_attr($user->user_email);
    $firstname = esc_attr(trim(get_user_meta($user->ID, 'first_name', true)));
    $lastname = esc_attr(trim(get_user_meta($user->ID, 'last_name', true)));

    $shortcode = '
      [lbwp:form button="' . __('Save') . '" id="data-change" weiterleitung="' . get_permalink() . '" action="' . get_permalink() . '" skip_execution="1"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="email" feldname="' . __('E-Mail-Adresse', 'lbwp') . '" type="email" vorgabewert="' . $email . '"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="firstname" feldname="' . __('Vorname', 'lbwp') . '" type="text" vorgabewert="' . $firstname . '"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="lastname" feldname="' . __('Nachname', 'lbwp') . '" type="text" vorgabewert="' . $lastname . '"]
        ' . $this->getCustomFieldShortcodes($user->ID) . '
        [lbwp:formItem key="textfield" pflichtfeld="nein" id="new-password" feldname="' . __('Neues Passwort', 'lbwp') . '" type="password"]
        [lbwp:formItem key="textfield" pflichtfeld="nein" id="password-confirm" feldname="' . __('Passwort bestätigen', 'lbwp') . '" type="password"]
      [/lbwp:form]
    ';

    $formHtml =
      '<div id="registration-form">' . PHP_EOL
      . apply_filters('FrontendDataChange_heading', '<h3>' . __('Daten ändern', 'lbwp') . '</h3>') . PHP_EOL
      . apply_filters('FrontendDataChange_content_before_form', '') . PHP_EOL
      . do_shortcode($shortcode) . PHP_EOL
      . apply_filters('FrontendDataChange_content_after_form', '') . PHP_EOL
    . '</div>';

    // Add validation and css
    $core = FormCore::getInstance();
    if ($core instanceof FormCore) {
      FormCore::getInstance()->getFormHandler()->addFormAssets();
    }

    return $formHtml;
  }

  /**
   * @param int $userId if given, we prefill data fields
   * @return string the shortcodes to display the custom fields
   */
  protected function getCustomFieldShortcodes($userId = 0)
  {
    $shortcodes = '';
    foreach ($this->customFields as $field) {
      $required = ($field['required']) ? 'ja' : 'nein';

      // if user, prefill data
      $value = '';
      if ($userId > 0) {
        $value = esc_attr(trim(get_user_meta($userId, $field['id'], true)));
      }

      // Create shortcode
      $shortcodes .= '[lbwp:formItem key="textfield" pflichtfeld="' . $required . '" id="' . $field['id'] . '" feldname="' . $field['label'] . '" type="' . $field['type'] . '" vorgabewert="' . $value . '"]';
    }

    return $shortcodes;
  }

  /**
   * @return string very simple password forgot link with standard WP process
   */
  public static function getForgotPasswordLink()
  {
    // Build the url and return a link
    $resetUrl = get_bloginfo('url') . '/wp-login.php?action=lostpassword';
    return '<a class="lost-password" href="' . $resetUrl . '">' . __('Lost your password?') . '</a>';
  }
} 