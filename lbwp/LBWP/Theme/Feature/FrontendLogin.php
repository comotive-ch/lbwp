<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\Forms\Core as FormCore;

/**
 * Class FrontendLogin
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class FrontendLogin
{
  /**
   * Logouts the user
   */
  public function logoutUser()
  {
    wp_logout();
    unset($_SESSION['gcb_pokershop_mode']);
    wp_safe_redirect(get_permalink());
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
        if (isset($_POST['redirect_to']) && $_POST['redirect_to'] !== '') {
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
          $errors[] = '<strong>Fehler</strong>: Bitte geben Sie Ihren Benutzernamen ein.';
        } else if ($code === 'empty_password') {
          $errors[] = '<strong>Fehler</strong>: Bitte geben Sie Ihr Passwort ein.';
        } else if ($code === 'invalid_username') {
          $errors[] = '<strong>Fehler</strong>: Der eingegebene Benutzername ist ungültig.';
        } else if ($code === 'incorrect_password') {
          $errors[] = '<strong>Fehler</strong>: Das eingegebene Passwort ist nicht korrekt.';
        }
      }

      $html .= implode(PHP_EOL, $errors);
    }

    $html .= $this->displayLoginForm();
    return $html;
  }

  /**
   * Displays the login form
   */
  public function displayLoginForm()
  {
    $shortcode = '
      [lbwp:form button="Login" id="login" weiterleitung="' . get_permalink() . '" action="' . get_permalink() . '"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="log" feldname="Benutzername" type="text"]
        [lbwp:formItem key="textfield" pflichtfeld="ja" id="pwd" feldname="Passwort" type="password"]
      [/lbwp:form]
    ';

    $formHtml =
      '<div id="respond">' . PHP_EOL
      . apply_filters('FrontendLogin_heading', '<h3>Login</h3>') . PHP_EOL
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
} 