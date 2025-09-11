<?php

namespace LBWP\Aboon\Erp\SapByd;

use LBWP\Aboon\Erp\Entity\Order as ImportOrder;
use LBWP\Aboon\Erp\Order as OrderBase;
use LBWP\Module\General\Cms\SystemLog;

/**
 * ERP implementation for SapByd
 * @package LBWP\Aboon\Erp\Sage
 */
abstract class Order extends OrderBase
{
  /**
   * @var string
   */
  public static $sapHostName = 'myXXXXXX.sap.com';
  /**
   * @var string
   */
  public static $sapUserName = '_WOOCOMMERCE';
  /**
   * @var string
   */
  public static $sapPassword = '**********';

  /** TODO
   * @param int $page the page to load
   * @return array a list of order ids on that page
   */
  protected function getPagedOrderIds(int $page): array
  {
    return array();
  }

  /** TODO
   * @param mixed $remoteId remote id given from external system
   * @return mixed the validated remote od
   */
  protected function validateRemoteId($remoteId)
  {
    return $remoteId;
  }

  /** TODO
   * @param mixed $remoteId the id of the order in the remote system
   * @return ImportOrder predefined order object that can be imported or updated
   */
  protected function convertOrder($remoteId): ImportOrder
  {
    /**
    {
      "specversion": "1.0",
      "source": "/FR4/sap.byd/000000000742188036",
      "type": "sap.byd.SalesOrder.Root.Updated.v1", // or ".Created" when new
      "id": "fa163e16-4431-1edd-8eb8-351cf99ab8c7",
      "time": "2022-09-21T15:25:38Z",
      "datacontenttype": "application/json",
      "data": {
        "root-entity-id": "FA163E1644311EED85DD46607F250319",
        "entity-id": "FA163E1644311EED85DD46607F250319"
      }
    }*/
    $data = array_merge($_GET, $_POST);
    $data['inputBody'] = file_get_contents('php://input');
    //SystemLog::add('SapByd::Order', 'debug', 'order trigger', $data);
    $order = new ImportOrder('', '');
    return $order;
  }
}