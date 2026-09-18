<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\AgenticCommerce\Admin\KeyActions;
use LBWP\Aboon\AgenticCommerce\Admin\OrderMeta;
use LBWP\Aboon\AgenticCommerce\Fields;
use LBWP\Aboon\AgenticCommerce\SessionStore;
use LBWP\Aboon\AgenticCommerce\Settings;
use LBWP\Theme\Component\ACFBase;

/**
 * Shared settings component for agentic commerce (OpenAI ACP and Google UCP). Always loaded with
 * the Aboon components; the protocol components AgenticCommerceAcp / AgenticCommerceUcp are
 * registered by the theme only when their checkbox is active.
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AgenticCommerce extends ACFBase
{
  /**
   * Registers the key generation actions and the draft session cleanup.
   * @return void
   */
  public function init(): void
  {
    (new KeyActions())->register();
    add_action('cron_daily_4', [SessionStore::class, 'cleanupExpired']);
  }

  /**
   * Wires the order meta box and list column for agent orders.
   * @return void
   */
  public function admin(): void
  {
    (new OrderMeta())->register();
  }

  /**
   * Adds the options page below the Aboon menu (admin only).
   * @return void
   */
  public function acfInit(): void
  {
    if (!is_admin()) {
      return;
    }
    $this->addOptionsPage('Agentic Commerce', Fields::PAGE, 'aboon-settings', ['capability' => 'administrator']);
  }

  /**
   * Registers the general and product field groups.
   * @return void
   */
  public function fields(): void
  {
    $fields = new Fields();
    $fields->registerGeneral();
    $fields->registerProduct();
  }

  /**
   * No blocks.
   * @return void
   */
  public function blocks(): void
  {
  }

  /**
   * @return bool whether the ACP component should be loaded
   */
  public static function isAcpActive(): bool
  {
    return Settings::isAcpActive();
  }

  /**
   * @return bool whether the UCP component should be loaded
   */
  public static function isUcpActive(): bool
  {
    return Settings::isUcpActive();
  }
}
