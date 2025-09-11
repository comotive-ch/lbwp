<?php

namespace LBWP\Aboon\Erp\FtpCsv;

use LBWP\Aboon\Erp\Entity\Order as ImportOrder;
use LBWP\Aboon\Erp\Order as OrderBase;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Import\Ftp;

/**
 * ERP implementation of a statically synced CSV erp, with prefined formats
 * @package LBWP\Aboon\Erp\FtpCsv
 */
abstract class Order extends OrderBase
{

  /**
   * Variables for FTP connection and file access, can and should be overridden in customer class
   */
  protected string $ftpServer = '__override_plz';
  protected string $ftpUser = '__override_plz';
  protected string $ftpPass = '__override_plz_maybe_encrypt';
  protected int $ftpPort = 21;
  protected string $sourceFile = 'erp-import-order.csv';
  protected bool $cacheFile = true;
  protected int $cacheFileTime = 14400;

  /**
   * Index helpers for the CSV file
   */
  const REMOTE_ID = 0;
  const LOCAL_ID = 1;
  const REMOTE_USER_ID = 2;
  const LOCAL_USER_ID = 3;
  const LINE_TYPE = 4;
  const ADDR_SOURCE = 5;
  const BILLING_ADDR_ID = 6;
  const SHIPPING_ADDR_ID = 7;
  const ORDER_STATUS = 8;
  const PAYMENT_METHOD = 9;
  const CURRENCY = 10;
  const TAX_IN_TOTAL_INCL = 11;
  const PRODUCT_SOURCE = 12;
  const PRODUCT_SKU = 13;
  const ITEM_NAME = 14;
  const ITEM_QUANTITY = 15;
  const ITEM_TAX = 16;
  const ITEM_TOTAL = 17;

  /**
   * Loads the while file in to cache and retursn it
   * @return array
   */
  protected function loadRemoteFile(): array
  {
    // Try getting raw file from cache eventually
    $file = wp_cache_get('orderImportFile', 'ftpCsv');
    // If not available, load from FTP server
    if ($file === false) {
      $ftp = new Ftp($this->ftpServer, $this->ftpUser, $this->ftpPass, $this->ftpPort);
      $path = $ftp->getFile($this->sourceFile);
      $ftp->close();
      $file = Csv::getArray($path);
      // Maybe cache if allowed to do so
      if ($this->cacheFile) {
        wp_cache_set('orderImportFile', $file, 'ftpCsv', $this->cacheFileTime);
      }
    }

    return $file;
  }

  /**
   * Get the next n-IDs from the file
   * @param int $page
   * @return array
   */
  protected function getPagedOrderIds($page): array
  {
    // First open file and get all distinct ids
    $remoteIds = array();
    $file = $this->loadRemoteFile();
    // Remove heading line before reading
    unset($file[0]);
    foreach ($file as $line) {
      if (!in_array($line[self::REMOTE_ID], $remoteIds)) {
        $remoteIds[] = $line[self::REMOTE_ID];
      }
    }

    // Now return the page slice of that
    $offset = ($page - 1) * $this->importsPerRun;
    return array_slice($remoteIds, $offset, $this->importsPerRun);
  }

  /**
   * @param mixed $remoteId
   * @return ImportOrder fully filled order object
   */
  public function convertOrder($remoteId): ImportOrder
  {
    // Search for lines with the corresponding remote id
    $lines = array();
    foreach($this->loadRemoteFile() as $line) {
      if ($line[self::REMOTE_ID] == $remoteId) {
        $lines[] = $line;
      }
    }

    // Return invalid user, if no lines are found
    if (count($lines) == 0) {
      return new ImportOrder('', '');
    }

    // Create order and set basics
    $orderline = array_shift($lines);
    $order = new ImportOrder($orderline[self::REMOTE_ID], $orderline[self::REMOTE_USER_ID]);
    $order->setStatus($orderline[self::ORDER_STATUS]);

    // Add correct address (only remote addresses supported as of now
    switch ($orderline[self::ADDR_SOURCE]) {
      case'remote':
        $billingAddrId = $shippingAddrId = $orderline[self::BILLING_ADDR_ID];
        if (strlen($orderline[self::SHIPPING_ADDR_ID]) > 0) {
          $shippingAddrId = $orderline[self::SHIPPING_ADDR_ID];
        }
        // Add the addresses from remote user
        $order->addRemoteAddress('billing', $billingAddrId);
        $order->addRemoteAddress('shipping', $shippingAddrId);
        break;
    }

    // Now add the positions that are left
    foreach ($lines as $position) {
      $order->addPosition(
        $position[self::PRODUCT_SKU],
        $position[self::ITEM_NAME],
        intval($position[self::ITEM_QUANTITY]),
        floatval($position[self::ITEM_TAX]),
        floatval($position[self::ITEM_TOTAL]),
        $position[self::TAX_IN_TOTAL_INCL] == 'true'
      );
    }

    return $order;
  }

  /**
   * @param mixed $remoteId
   * @return mixed $remoteId is int in every case
   */
  protected function validateRemoteId($remoteId)
  {
    return intval($remoteId);
  }
}