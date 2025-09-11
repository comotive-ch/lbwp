<?php

namespace LBWP\Aboon\Erp\Entity;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\WordPress;

/**
 * Importable order data object
 * @package LBWP\Aboon\Erp\Entity
 */
class Order
{
  /**
   * @var int ID of the local version, if already given and updating
   */
  protected int $id = 0;
  /**
   * @var mixed ID of the remote system version, can be anything
   */
  protected $remoteId = null;
  /**
   * @var int the owning local user id
   */
  protected int $userId = 0;
  /**
   * @var mixed the owning remote user id
   */
  protected $remoteUserId = null;
  /**
   * @var string
   */
  protected string $status = 'wc-pending';
  /**
   * @var string
   */
  protected string $title = '';
  /**
   * @var Position[] the address array
   */
  protected $positions = array();
  /**
   * @var array meta information about the user
   */
  protected $meta = array();
  /**
   * @var bool tells if the current objects is updating or new
   */
  protected $updating = false;
  /**
   * @var \wpdb the database reference
   */
  protected $db = null;

  /**
   * Create an order from remoteID
   * @param mixed $remoteId
   * @param mixed $remoteUserId
   */
  public function __construct($remoteId, $remoteUserId)
  {
    $this->remoteId = $remoteId;
    $this->remoteUserId = $remoteUserId;
    $this->db = WordPress::getDb();
    $this->title = __('Importierte Bestellung', 'lbwp') . ' R#' . $this->remoteId;

    // Maybe load the local id from meta
    if ($this->id === 0) {
      $id = $this->searchLocalIdConnection($remoteId);
      if ($id > 0) {
        $this->id = $id;
        $this->updating = true;
        $this->userId = $this->searchLocalUserConnection($remoteUserId);
      }
    } else {
      $this->updating = true;
    }
  }

  /**
   * @return bool
   */
  public function validate(): bool
  {
    return true;
  }

  /**
   * TODO needs hpos compatible implementation
   * Adds or updates the user object
   * @return bool true if all went well
   */
  public function save(): bool
  {
    if (!$this->updating) {

    } else {

    }

    // Update other meta data (also contains addresses)
    foreach ($this->meta as $key => $value) {
      //update_user_meta($this->id, $key, $value);
    }

    return true;
  }

  /**
   * @param string $type
   * @param string $syntax
   * @return bool true if the address has been found and loaded
   */
  public function addRemoteAddress(string $type, string $syntax): bool
  {
    list($remoteUserId, $index) = explode('-', $syntax);
    $userId = $this->searchLocalUserConnection($remoteUserId);
    if ($userId > 0) {
      $addresses = get_user_meta($userId, 'erp-address-list', true);
      // See if the address was found
      if (isset($addresses[$type][$index])) {
        // Add is to meta, to be later saved
        foreach ($addresses[$type][$index] as $key => $value) {
          $this->setMeta($type . '_' . $key, $value);
        }

        return true;
      }
    }

    return false;
  }

  /**
   * @param mixed $remoteId the remote system id
   * @return int the id or 0
   */
  protected function searchLocalIdConnection($remoteId): int
  {
    return intval($this->db->get_var('
      SELECT post_id FROM ' . $this->db->postmeta . ' 
      WHERE meta_key = "erp-remote-order-id" AND meta_value = "' . $remoteId . '"
    '));
  }

  /**
   * @param mixed $remoteId the remote system id of the user
   * @return int the id or 0
   */
  protected function searchLocalUserConnection($remoteId): int
  {
    return intval($this->db->get_var('
      SELECT user_id FROM ' . $this->db->usermeta . ' 
      WHERE meta_key = "erp-remote-id" AND meta_value = "' . $remoteId . '"
    '));
  }

  /**
   * @return Position[]
   */
  public function getPositions(): array
  {
    return $this->positions;
  }

  /**
   * @param array $positions
   */
  public function setPositions(array $positions): void
  {
    $this->positions = $positions;
  }

  /**
   * @param string $sku utitlizes woocommerce _sku to get the actual product
   * @param string $line the line to be printed, when empty, uses skus product name if available
   * @param int $qty purchase quantity
   * @param float $tax tax part of line
   * @param float $total total of line
   * @param bool $taxIncl true = total includes tax, false = total doesn't include tax
   */
  public function addPosition(string $sku, string $line, int $qty, float $tax, float $total, bool $taxIncl)
  {
    $this->positions[] = new Position($sku, $line, $qty, $tax, $total, $taxIncl);
  }

  /**
   * @param string $key key of meta data
   * @param mixed $value can be any object that is serializable
   */
  public function setMeta(string $key, $value)
  {
    $this->meta[$key] = $value;
  }

  /**
   * @param string $key the key
   * @return mixed any object stored in that key
   */
  public function getMeta(string $key)
  {
    return $this->meta[$key];
  }

  /**
   * @return array full meta list
   */
  public function getMetaList():array
  {
    return $this->meta;
  }

  /**
   * @return bool
   */
  public function isUpdating(): bool
  {
    return $this->updating;
  }

  /**
   * @return int
   */
  public function getId(): int
  {
    return $this->id;
  }

  /**
   * @return mixed
   */
  public function getRemoteId()
  {
    return $this->remoteId;
  }

  /**
   * @return mixed
   */
  public function getRemoteUserId()
  {
    return $this->remoteUserId;
  }

  /**
   * @return int
   */
  public function getUserId(): int
  {
    return $this->userId;
  }

  /**
   * @return string
   */
  public function getStatus(): string
  {
    return $this->status;
  }

  /**
   * @param string $status
   */
  public function setStatus(string $status): void
  {
    $this->status = $status;
  }

  /**
   * @return string
   */
  public function getTitle(): string
  {
    return $this->title;
  }

  /**
   * @param string $title
   */
  public function setTitle(string $title): void
  {
    $this->title = $title;
  }
}