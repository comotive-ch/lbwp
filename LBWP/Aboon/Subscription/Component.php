<?php

namespace LBWP\Aboon\Subscription;

use LBWP\Aboon\Subscription\Account\AccountActions;
use LBWP\Aboon\Subscription\Account\AccountEndpoint;
use LBWP\Aboon\Subscription\Admin\AdminActions;
use LBWP\Aboon\Subscription\Admin\AdminScreen;
use LBWP\Aboon\Subscription\Api\ChargeLog;
use LBWP\Aboon\Subscription\Cron\SyncCron;
use LBWP\Aboon\Subscription\Email\CancellationEmail;
use LBWP\Aboon\Subscription\Gateway\CartGuard;
use LBWP\Aboon\Subscription\Gateway\PayrexxSubscriptionGateway;
use LBWP\Aboon\Subscription\Webhook\WebhookController;
use LBWP\Theme\Component\ACFBase;

/**
 * Orchestrates the Payrexx recurring payment subscription feature. Intentionally thin:
 * every piece of actual behaviour lives in its own focused class under this namespace.
 * @package LBWP\Aboon\Subscription
 * @author Michael Sebel <michael@comotive.ch>
 */
class Component extends ACFBase
{
  /**
   * Registers post type, charge log table, cart/gateway guards, webhook route, cron sync,
   * my-account endpoint and order linking. Runs on WordPress "init".
   * @return void
   */
  public function init(): void
  {
    (new PostType())->register();
    (new ChargeLog())->register();
    (new CartGuard())->register();
    (new WebhookController())->register();
    (new SyncCron())->register();
    (new AccountEndpoint())->register();
    (new AccountActions())->register();

    add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
    add_filter('woocommerce_email_classes', [CancellationEmail::class, 'register']);
  }

  /**
   * Wires the admin-only screens. Runs on "admin_init".
   * @return void
   */
  public function admin(): void
  {
    (new AdminScreen())->register();
    (new AdminActions())->register();
  }

  /**
   * Registers the ACF field groups. Runs on "acf/init".
   * @return void
   */
  public function fields(): void
  {
    (new Fields())->register();
  }

  /**
   * No Gutenberg blocks are needed for this feature.
   * @return void
   */
  public function blocks(): void
  {
  }

  /**
   * Appends the Payrexx subscription gateway to the list of available WooCommerce gateways.
   * @param array $gateways registered gateway class names/instances
   * @return array the updated list
   */
  public function registerGateway(array $gateways): array
  {
    $gateways[] = PayrexxSubscriptionGateway::class;
    return $gateways;
  }
}
