<?php

namespace LBWP\Aboon\AgenticCommerce\Admin;

use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Aboon\AgenticCommerce\Signature;

/**
 * Admin-post handlers that generate the ACP bearer key and the UCP EC signing key pair.
 * Values are written directly to the ACF option rows so they are usable without a form save.
 * @package LBWP\Aboon\AgenticCommerce\Admin
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class KeyActions
{
  /**
   * @var string admin_post action for the ACP api key
   */
  public const string ACTION_API_KEY = 'agentic_generate_api_key';
  /**
   * @var string admin_post action for the UCP key pair
   */
  public const string ACTION_KEYS = 'agentic_generate_keys';

  /**
   * Registers the admin_post hooks.
   * @return void
   */
  public function register(): void
  {
    add_action('admin_post_' . self::ACTION_API_KEY, [$this, 'generateApiKey']);
    add_action('admin_post_' . self::ACTION_KEYS, [$this, 'generateKeys']);
  }

  /**
   * Generates a new ACP bearer key and redirects back to the options page.
   * @return void
   */
  public function generateApiKey(): void
  {
    $this->guard(self::ACTION_API_KEY);
    $this->writeOption('acp-api-key', 'field_agentic_acp_api_key', bin2hex(random_bytes(24)));
    Log::info('ACP api key regenerated', ['user' => get_current_user_id()]);
    $this->redirect('api-key');
  }

  /**
   * Generates a new EC P-256 key pair for UCP signing and redirects back.
   * @return void
   */
  public function generateKeys(): void
  {
    $this->guard(self::ACTION_KEYS);
    [$pem, $jwk, $kid] = Signature::generateKeyPair();
    if ($pem === '' || $kid === '') {
      wp_die(__('Schlüsselpaar konnte nicht erzeugt werden (OpenSSL).', 'lbwp'));
    }
    $this->writeOption('ucp-signing-key', 'field_agentic_ucp_signing_key', $pem);
    $this->writeOption('ucp-signing-kid', 'field_agentic_ucp_signing_kid', $kid);
    Log::info('UCP signing key regenerated', ['user' => get_current_user_id(), 'kid' => $kid]);
    $this->redirect('keys');
  }

  /**
   * Verifies nonce and capability, dies otherwise.
   * @param string $action the action name used as nonce action
   * @return void
   */
  protected function guard(string $action): void
  {
    if (!current_user_can('administrator') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), $action)) {
      wp_die(__('Keine Berechtigung.', 'lbwp'), '', ['response' => 403]);
    }
  }

  /**
   * Writes an ACF option including its field reference row.
   * @param string $name option name
   * @param string $fieldKey ACF field key
   * @param string $value the value
   * @return void
   */
  protected function writeOption(string $name, string $fieldKey, string $value): void
  {
    update_option('options_' . $name, $value, false);
    update_option('_options_' . $name, $fieldKey, false);
  }

  /**
   * Redirects back to the options page with a marker.
   * @param string $done marker for the notice
   * @return void
   */
  protected function redirect(string $done): void
  {
    wp_safe_redirect(admin_url('admin.php?page=aboon-agentic-commerce&agentic-generated=' . $done));
    exit;
  }
}
