<?php

namespace LBWP\Aboon\Erp\FtpCsv;

use LBWP\Aboon\Erp\Customer as CustomerBase;
use LBWP\Aboon\Erp\Entity\User;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Import\Ftp;

/**
 * ERP implementation of a statically synced CSV erp, with prefined formats
 * @package LBWP\Aboon\Erp\FtpCsv
 */
abstract class Customer extends CustomerBase
{
  /**
   * Variables for FTP connection and file access, can and should be overridden in customer class
   */
  protected string $ftpServer = '__override_plz';
  protected string $ftpUser = '__override_plz';
  protected string $ftpPass = '__override_plz_maybe_encrypt';
  protected int $ftpPort = 21;
  protected string $sourceFile = 'erp-import-customers.csv';
  protected bool $cacheFile = true;
  protected int $cacheFileTime = 14400;

  /**
   * Index helpers for the CSV file
   */
  const REMOTE_ID = 0;
  const LOCAL_ID = 1;
  const USER_LOGIN = 2;
  const USER_EMAIL = 3;
  const ADDRESS_TYPE = 4;
  const MAIN_ADDRESS = 5;
  const FIRSTNAME = 6;
  const LASTNAME = 7;
  const COMPANY = 8;
  const ADDRESS_1 = 9;
  const ADDRESS_2 = 10;
  const CITY = 11;
  const POSTCODE = 12;
  const COUNTRY = 13;
  const PHONE = 14;
  const EMAIL = 15;

  /**
   * Loads the while file in to cache and retursn it
   * @return array
   */
  protected function loadRemoteFile(): array
  {
    // Try getting raw file from cache eventually
    $file = wp_cache_get('customerImportFile', 'ftpCsv');
    // If not available, load from FTP server
    if ($file === false) {
      $ftp = new Ftp($this->ftpServer, $this->ftpUser, $this->ftpPass, $this->ftpPort);
      $path = $ftp->getFile($this->sourceFile);
      $ftp->close();
      $file = Csv::getArray($path);
      // Maybe cache if allowed to do so
      if ($this->cacheFile) {
        wp_cache_set('customerImportFile', $file, 'ftpCsv', $this->cacheFileTime);
      }
    }

    return $file;
  }

  /**
   * Get the next n-IDs from the file
   * @param int $page
   * @return array
   */
  protected function getPagedCustomerIds($page): array
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
   * @param mixed $object
   * @return User fully filled user object
   */
  public function convertCustomer($remoteId, $object = null): User
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
      return new User('');
    }

    $user = new User($lines[0][self::REMOTE_ID]);
    // Assume that at least the first line has main user data
    $user->setUserLogin($lines[0][self::USER_LOGIN]);
    $user->setUserEmail($lines[0][self::USER_EMAIL]);
    // And add the addresses
    foreach ($lines as $line) {
      $user->addAddress($line[self::ADDRESS_TYPE], $line[self::MAIN_ADDRESS], array(
        'first_name' => $line[self::FIRSTNAME],
        'last_name' => $line[self::LASTNAME],
        'company' => $line[self::COMPANY],
        'address_1' => $line[self::ADDRESS_1],
        'address_2' => $line[self::ADDRESS_2],
        'postcode' => $line[self::POSTCODE],
        'city' => $line[self::CITY],
        'country' => $line[self::COUNTRY],
        'phone' => $line[self::PHONE],
        'email' => $line[self::EMAIL]
      ));
    }

    return $user;
  }

  /**
   * triggering of single dataset update is not possible with FTP
   * @return array empty
   */
  public function getQueueTriggerObject() : array
  {
    return array();
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