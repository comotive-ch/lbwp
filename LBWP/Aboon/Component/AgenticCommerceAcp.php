<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\AgenticCommerce\Feed\AcpProductFeed;
use LBWP\Aboon\AgenticCommerce\Fields;
use LBWP\Aboon\AgenticCommerce\Notification\AcpOrderWebhook;
use LBWP\Aboon\AgenticCommerce\Notification\Queue;
use LBWP\Aboon\AgenticCommerce\Rest\AcpController;
use LBWP\Theme\Component\ACFBase;

/**
 * OpenAI ACP component: REST endpoints, order webhooks and the product feed. Registered by the
 * theme only when "agentic-acp-active" is checked.
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AgenticCommerceAcp extends ACFBase
{
  /**
   * Registers routes, webhooks and feed generation.
   * @return void
   */
  public function init(): void
  {
    (new AcpController())->register();
    (new AcpOrderWebhook())->register();
    (new AcpProductFeed())->register();
    (new Queue())->register();
  }

  /**
   * Registers the ACP settings group.
   * @return void
   */
  public function fields(): void
  {
    (new Fields())->registerAcp();
  }

  /**
   * No blocks.
   * @return void
   */
  public function blocks(): void
  {
  }
}
