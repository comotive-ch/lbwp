<?php

namespace LBWP\Aboon\Erp;

use LBWP\Aboon\Erp\Entity\Order as ImportOrder;
use LBWP\Helper\Cronjob;
use LBWP\Theme\Base\Component;

/**
 * Base class to sync order data with an ERP system
 * @package LBWP\Aboon\Erp
 */
abstract class Order extends Component
{
  /**
   * @var int number of datasets to be imported in full sync per minutely run
   */
  protected int $importsPerRun = 10;
  /**
   * Main starting point of the component
   */
  public function init()
  {
    add_action('rest_api_init', array($this, 'registerApiEndpoints'));
    add_action('cron_job_manual_aboon_erp_order_register_full_sync', array($this, 'registerFullSync'));
    add_action('cron_job_aboon_erp_order_sync_page', array($this, 'runBulkImportCron'));
  }

  /**
   * @param ImportOrder $order a full order object that should be imported or updated
   * @return bool save status
   */
  protected function updateOrder(ImportOrder $order): bool
  {
    // Validate the order and save it
    if ($order->validate()) {
      return $order->save();
    }

    return false;
  }

  /**
   * Starts running a paged cron for bulk importing/syncing all customers from remote system
   */
  public function registerFullSync()
  {
    // This basically creates a cron trigger with the first page of mass import (which will then contain itself until finished)
    Cronjob::register(array(
      current_time('timestamp') => 'aboon_erp_order_sync_page::1'
    ));
  }

  /**
   * Runs one limited cron of a paged full import / sync from ERP
   */
  public function runBulkImportCron()
  {
    // Get the current page of the cron, exiting when not valid
    $syncResults = array();
    $page = intval($_GET['data']);
    if ($page == 0) return;
    set_time_limit(1800);
    // Before starting, remove the job on master so it's not called twice fo sho
    Cronjob::confirm($_GET['jobId']);

    foreach ($this->getPagedOrderIds($page) as $remoteId) {
      $order = $this->convertOrder($remoteId);
      // And import or sync the provided order
      $syncResults[] = $this->updateOrder($order);
    }

    // Register another cron with next page if data was synced
    if (count($syncResults) > 0) {
      Cronjob::register(array(
        current_time('timestamp') => 'aboon_erp_order_sync_page::' . (++$page)
      ));
    }
  }

  /**
   * Register the api trigger endpoint
   */
  public function registerApiEndpoints()
  {
    register_rest_route('aboon/erp/order', 'trigger', array(
      'methods' => \WP_REST_Server::ALLMETHODS,
      'callback' => array($this, 'provideExternalTrigger')
    ));
  }

  /**
   * Provides rest api endpoint
   */
  public function provideExternalTrigger()
  {
    $remoteId = $this->validateRemoteId($_GET['order_id']);
    $order = $this->convertOrder($remoteId);
    // And import or sync the provided order
    return array('success' => $this->updateOrder($order));
  }

  /**
   * @param int $page the page to load
   * @return array a list of order ids on that page
   */
  abstract protected function getPagedOrderIds(int $page): array;

  /**
   * @param mixed $remoteId remote id given from external system
   * @return mixed the validated remote od
   */
  abstract protected function validateRemoteId($remoteId);

  /**
   * Actual function to be implemented to convert remote order to local order to be able to import
   * @param mixed $remoteId the id of the order in the remote system
   * @return ImportOrder predefined order object that can be imported or updated
   */
  abstract protected function convertOrder($remoteId): ImportOrder;
}