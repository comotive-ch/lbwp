<?php

namespace LBWP\Aboon\Erp\Entity;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Importable user data object
 * @package LBWP\Aboon\Erp\Entity
 */
class User
{
  /**
   * @var int ID of the local version, if already given and updating
   */
  protected $id = 0;
  /**
   * @var mixed ID of the remote system version, can be anything
   */
  protected $remoteId = null;
  /**
   * @var string the user login
   */
  protected $userLogin = '';
  /**
   * @var string the user email (can also be used for logging in)
   */
  protected $userEmail = '';
  /**
   * @var array the address array
   */
  protected $addresses = array();
  /**
   * @var array main address (max one of type)
   */
  protected $mainAddresses = array();
  /**
   * @var array meta information about the user
   */
  protected $meta = array();
  /**
   * @var array meta to be deleted
   */
  protected $delMeta = array();
  /**
   * @var bool tells if the current objects is updating or new
   */
  protected $updating = false;
  /**
   * @var bool send email for newly created users
   */
  protected $sendEmail = false;
  /**
   * @var bool
   */
  protected $forceSave = false;
  /**
   * @var string
   */
  protected $hash = '';
  /**
   * @var \wpdb the database reference
   */
  protected $db = null;

  /**
   * Create a user from remoteID (maybe even set local id so it mustn't be searched)
   * @param $remoteId
   * @param int $localId
   */
  public function __construct($remoteId, $localId = 0)
  {
    $this->remoteId = $remoteId;
    $this->id = $localId;
    $this->db = WordPress::getDb();

    // Maybe load the local id from meta
    if ($this->id === 0) {
      $id = $this->searchLocalIdConnection($remoteId);
      if ($id > 0) {
        $this->id = $id;
        $this->updating = true;
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
    return Strings::checkEmail($this->userEmail) && $this->getAnyMainAddress() !== false;
  }

  /**
   * Hash that tells if the object changed
   * @return string
   */
  protected function generateHash() : string
  {
    $this->hash = md5(
      $this->userEmail .
      $this->userLogin .
      json_encode($this->meta) .
      json_encode($this->addresses) .
      json_encode($this->mainAddresses) .
      json_encode($this->delMeta)
    );

    return $this->hash;
  }

  /**
   * Forces saving by hashing randomly
   * @return void
   */
  public function forceSaving() : void
  {
    $this->forceSave = true;
  }

  /**
   * Adds or updates the user object
   * @return bool true if all went well
   */
  public function save(): bool
  {
    // Generate hash before saving
    $this->generateHash();
    // When updating, check if the object changed, only procees if there are changes
    if ($this->updating) {
      $lastHash = get_user_meta($this->id, 'last-sync-hash', true);
      if (strlen($lastHash) > 0 && $lastHash === $this->hash && !$this->forceSave) {
        return true;
      }
    }

    if (!$this->updating) {
      // Get any mainAddress that is the most valid
      $address = $this->getAnyMainAddress();
      $password = Strings::getRandom(14);
      // Create the user first to get the local id
      $this->id = wp_insert_user(array(
        'user_login' => $this->userLogin,
        'user_email' => $this->userEmail,
        'user_password' => $password,
        'display_name' => trim($address['first_name'] . ' ' . $address['last_name']),
        'first_name' => $address['first_name'],
        'last_name' => $address['last_name'],
        'role' => 'customer'
      ));

      // If already existing, get his ID orrectly
      if ($this->id instanceof \WP_Error) {
        $error = array_keys($this->id->errors)[0];
        if ($error == 'existing_user_login') {
          $user = get_user_by('login', $this->userLogin);
          $this->id = $user->ID;
        }
        if ($error == 'existing_user_email') {
          $user = get_user_by('email', $this->userEmail);
          $this->id = $user->ID;
        }
      }

      // Trigger newly created user email
      if ($this->sendEmail && defined('LBWP_ABOON_ERP_PRODUCTIVE') && LBWP_ABOON_ERP_PRODUCTIVE) {
        $email = WC()->mailer()->emails['WC_Email_Customer_New_Account'];
        $email->trigger($this->id, $password, true);
      }

      // See if its a WP error
      if (is_wp_error($this->id)) {
        SystemLog::add('AboonErpCustomer', 'debug', 'WP Error on importing remote id ' . $this->remoteId, array(
          'message' => $this->id->get_error_message(),
          'error_id' => $this->id->get_error_code(),
          'error_data' => $this->id->get_all_error_data()
        ));
        return false;
      }

      // Also, connect this user to his remote erp id
      update_user_meta($this->id, 'erp-remote-id', $this->remoteId);
      update_user_meta($this->id, 'last-sync-hash', $this->hash);
    } else {
      // Get any mainAddress that is the most valid
      $address = $this->getAnyMainAddress();
      // Just update the base user
      wp_update_user(array(
        'ID' => $this->id,
        'user_login' => $this->userLogin,
        'user_email' => $this->userEmail,
        'display_name' => trim($address['first_name'] . ' ' . $address['last_name']),
      ));

      // Update various display names in meta data
      update_user_meta($this->id, 'first_name', $address['first_name']);
      update_user_meta($this->id, 'last_name', $address['last_name']);
      update_user_meta($this->id, 'last-sync-hash', $this->hash);
    }

    // Update all addresses as full object in meta
    update_user_meta($this->id, 'erp-address-list', $this->addresses);

    // Update main addresses to woocommerce fields
    foreach ($this->mainAddresses as $type => $address) {
      foreach ($address as $key => $value) {
        update_user_meta($this->id, $type . '_' . $key, $value);
      }
    }

    // Update other meta data
    foreach ($this->meta as $key => $value) {
      update_user_meta($this->id, $key, $value);
    }

    // Delete meta data if needed
    if (count($this->delMeta) > 0) {
      foreach ($this->delMeta as $key) {
        delete_user_meta($this->id, $key);
      }
    }

    return true;
  }

  /**
   * @param mixed $remoteId the remote system id
   * @return int the id or 0
   */
  protected function searchLocalIdConnection($remoteId): int
  {
    return intval($this->db->get_var('
      SELECT user_id FROM ' . $this->db->usermeta . ' 
      WHERE meta_key = "erp-remote-id" AND meta_value = "' . $remoteId . '"
    '));
  }

  /**
   * @param string $email
   * @return in
   */
  protected function getIdByEmail(string $email): int
  {
    $user = get_user_by('email', $email);
    return ($user !== false) ? $user->ID : 0;
  }

  /**
   * @param string $type
   * @param bool $isMain
   * @param array $address
   */
  public function addAddress(string $type, bool $isMain, array $address, bool $check = false)
  {
    $id = -1;
    if ($check) {
      $id = $this->getExistingAddressId($type, $address);
    }

    if ($id === -1) {
      // Add new at the end
      $this->addresses[$type][] = $address;
    } else {
      // Override existing address
      $this->addresses[$type][$id] = $address;
    }
    if ($isMain) $this->mainAddresses[$type] = $address;
  }

  /**
   * @param string $type
   * @param array $address
   * @return int index of existing address
   */
  protected function getExistingAddressId(string $type, array $address) : int
  {
    // Assume we can't add it if it doesn't have an ID for checks
    if (!isset($address['address_id'])) {
      return 0;
    }

    // check if existing by ID
    foreach ($this->addresses[$type] as $id => $candidate) {
      if ($candidate['address_id'] == $address['address_id']) {
        return $id;
      }
    }

    // If we come here, assume we can add it, as not found by ID yet
    return -1;
  }

  /**
   * @return void
   */
  public function loadAddresses()
  {
    $addresses = get_user_meta($this->id, 'erp-address-list', true);
    if (is_array($addresses) && count($addresses) > 0) {
      $this->addresses = $addresses;
    }
  }

  /**
   * @return array all addresses by type
   */
  public function getAddresses(): array
  {
    return $this->addresses;
  }

  /**
   * @param string $type address type of billing, shipping, generic
   * @return array|null
   */
  public function getMainAddress($type)
  {
    return $this->mainAddresses[$type];
  }

  /**
   * @return array|false
   */
  public function getAnyMainAddress()
  {
    foreach ($this->mainAddresses as $address) {
      return $address;
    }

    return false;
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
   * @param string $key key of meta data
   */
  public function deleteMeta(string $key)
  {
    $this->delMeta[] = $key;
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
   * @return string
   */
  public function getUserLogin(): string
  {
    return $this->userLogin;
  }

  /**
   * @param string $userLogin
   */
  public function setUserLogin(string $userLogin)
  {
    $this->userLogin = $userLogin;
  }

  /**
   * @param bool $send
   */
  public function setSendEmail(bool $send)
  {
    $this->sendEmail = $send;
  }

  /**
   * @return bool
   */
  public function isUpdating(): bool
  {
    return $this->updating;
  }

  /**
   * @param mixed $remoteId
   */
  public function connectUser($remoteId)
  {
    $this->updating = true;
    $this->id = $this->getIdByEmail($this->getUserEmail());
    $this->setMeta('erp-remote-id', $remoteId);
  }

  /**
   * @return string
   */
  public function getUserEmail(): string
  {
    return $this->userEmail;
  }

  /**
   * @param string $userEmail
   */
  public function setUserEmail(string $userEmail)
  {
    $this->userEmail = $userEmail;
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
}