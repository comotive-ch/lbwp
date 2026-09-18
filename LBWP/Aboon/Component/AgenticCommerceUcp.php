<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\AgenticCommerce\Fields;
use LBWP\Aboon\AgenticCommerce\Notification\Queue;
use LBWP\Aboon\AgenticCommerce\Notification\UcpOrderEvents;
use LBWP\Aboon\AgenticCommerce\Rest\UcpController;
use LBWP\Theme\Component\ACFBase;

/**
 * Google UCP component: public business profile at /.well-known/ucp, REST endpoints and order
 * events. Registered by the theme only when "agentic-ucp-active" is checked.
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AgenticCommerceUcp extends ACFBase
{
  public const string PROFILE_PATH = '/.well-known/ucp';

  /**
   * Registers routes, the profile handler and order events.
   * @return void
   */
  public function init(): void
  {
    (new UcpController())->register();
    (new UcpOrderEvents())->register();
    (new Queue())->register();
    add_action('wp', [$this, 'handleProfileRequest'], 5);
  }

  /**
   * Serves the profile document without authentication (cacheable for an hour).
   * @return void
   */
  public function handleProfileRequest(): void
  {
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (rtrim($path, '/') !== self::PROFILE_PATH) {
      return;
    }
    $profile = (new UcpController())->buildProfile();
    status_header(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('Access-Control-Allow-Origin: *');
    echo wp_json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }

  /**
   * Registers the UCP settings group.
   * @return void
   */
  public function fields(): void
  {
    (new Fields())->registerUcp();
  }

  /**
   * No blocks.
   * @return void
   */
  public function blocks(): void
  {
  }
}
