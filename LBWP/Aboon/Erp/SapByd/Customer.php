<?php

namespace LBWP\Aboon\Erp\SapByd;

use Banholzer\Component\Shop;
use LBWP\Aboon\Erp\Customer as CustomerBase;
use LBWP\Aboon\Erp\Entity\User;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\LbwpData;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * ERP implementation for SapByd
 * @package LBWP\Aboon\Erp\Sage
 */
abstract class Customer extends CustomerBase
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
  /**
   * @var string
   */
  public static $DEFAULT_PRICE_LIST_NAME = 'default';
  /**
   * @var string must be defined in child class
   */
  public static $STANDARD_ASSORTMENT_GROUP = '';
  /**
   * @var int number of datasets to be imported in full sync or process queue
   */
  protected int $importsPerRun = 20;

  public function init()
  {
    parent::init();

    add_action('woocommerce_thankyou', array($this, 'eventuallyCreateSapCustomer'), 50);
    add_action('cron_job_manual_queue_sync_by_internal_id', array($this, 'queueByInternalId'));
    add_action('cron_job_manual_queue_all_logins', array($this, 'queueAllLogins'));
    add_action('cron_job_manual_enable_login', array($this, 'enableLogin'));
    add_action('cron_job_manual_disable_login', array($this, 'disableLogin'));
    add_action('cron_daily_21', array($this, 'cleanUpErpAdresses'));
    add_action('admin_footer', array($this, 'allowReEnableUser'));
  }

  /**
   * Cleanup and remove addresses from customers that have been disabled in SAP
   * @return void
   */
  public function cleanUpErpAdresses()
  {
    // Raise timeout and memory limits as we're accessing SAP
    set_time_limit(1800);
    ini_set('memory_limit', '2048M');

    // Get all customer internal IDs that are locked (inactive) with their assigned emails
    $api = $this->getApi();
    $raw = $api->get('/sap/byd/odata/cust/v1/khcustomer/CustomerCollection', array(
      '$format' => 'json',
      '$filter' => "LifeCycleStatusCode eq '3'",
      '$select' => 'InternalID',
      '$top' => defined('LOCAL_DEVELOPMENT') ? 20 : 100000,
      'sap-language' => 'DE'
    ),300);

    // Build an indexed array of all object ids
    $lockedIds = array();
    foreach ($raw['d']['results'] as $customer) {
      $lockedIds[$customer['InternalID']] = true;
    }
    unset($raw);

    // Load erp-address-list direclty from meta table
    $addressTypes = array('billing', 'shipping');
    $db = WordPress::getDb();
    $erpAddresses = $db->get_results("SELECT user_id,meta_value FROM {$db->prefix}usermeta WHERE meta_key = 'erp-address-list'");
    foreach ($erpAddresses as $address) {
      $hasChanges = false;
      $userId = $address->user_id;
      $addressList = unserialize($address->meta_value);
      foreach ($addressTypes as $type) {
        if (isset($addressList[$type])) {
          foreach ($addressList[$type] as $addressId => $addressData) {
            if (isset($addressData['customer_id']) && isset($lockedIds[$addressData['customer_id']])) {
              unset($addressList[$type][$addressId]);
              $hasChanges = true;
            }
          }
        }
      }

      if ($hasChanges) {
        // Save the adress list back to the user
        update_user_meta($userId, 'erp-address-list', $addressList);
        // If there are no addresses left, disable the user
        if (count($addressList['billing']) == 0 && count($addressList['shipping']) == 0) {
          update_user_meta($userId, 'member-disabled', 1);
        } else {
          delete_user_meta($userId, 'member-disabled');
        }
      }
    }
  }

  /**
   * Enable a login
   * @return void
   */
  public function enableLogin()
  {
    if (current_user_can('administrator')) {
      $userId = intval($_GET['data']);
      if ($userId > 0) {
        delete_user_meta($userId, 'member-disabled');
      }
      // Redirect to user profile in backend
      wp_redirect(admin_url('user-edit.php?user_id=' . $userId));
    }
  }

  /**
   * Enable a login
   * @return void
   */
  public function disableLogin()
  {
    if (current_user_can('administrator')) {
      $userId = intval($_GET['data']);
      if ($userId > 0) {
        update_user_meta($userId, 'member-disabled', 1);
      }
      // Redirect to user profile in backend
      wp_redirect(admin_url('user-edit.php?user_id=' . $userId));
    }
  }

  /**
   * @return void
   */
  public function allowReEnableUser()
  {
    $userId = $_GET['user_id'];
    if ($userId > 0) {
      echo '
        <script>
          jQuery(function() {
            // Find the checkbox and see if it is checked
            var checkbox = jQuery("#disable-member");
            // when checked, add a link to reenable the user
            if (checkbox.length > 0 && checkbox.is(":checked")) {
              jQuery("#disable-member").parent().append("<a href=\"/wp-content/plugins/lbwp/views/cron/job.php?identifier=manual_enable_login&data=' . $userId . '\">(Aktivieren)</a>");
            }
          });
        </script>
      ';
    }
  }

  /**
   * Creates a SAP private customer if logged in as private account and not connected yet
   * @return void
   */
  public function eventuallyCreateSapCustomer($orderId)
  {
    if (!Shop::wcThankyouOnce($orderId, 'customer') || defined('LOCAL_DEVELOPMENT')) {
      return;
    }

    $order = wc_get_order($orderId);
    $userId = $order->get_customer_id();

    if (apply_filters('aboon_erp_customer_is_sap_customer_creatable', !self::hasSapConnectionPrivateCustomer($userId))) {
      $meta = WordPress::getAccessibleUserMeta($userId);
      $language = strtoupper(Multilang::getAnyPluginLang());
      $language = (strlen($language) > 0) ? $language : 'DE';
      $soapAction = 'http://sap.com/xi/A1S/Global/ManageCustomerIn/MaintainBundle_V1Request';
      $email = strtolower($meta['billing_email']);
      // End in an error when the email already exists in SAP
      $customerId = $this->getCustomerIdByEmail($email);
      if (strlen($customerId) > 0) {
        SystemLog::add('SapByd::Customer', 'error', 'attention: customer existing in SAP, no account created', $meta);
        return;
      }

      $envelope = '        
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:glob="http://sap.com/xi/SAPGlobal20/Global">
        <soap:Header/>
        <soap:Body>
          <glob:CustomerBundleMaintainRequest_sync_V1>
            <BasicMessageHeader>
            </BasicMessageHeader>
            <Customer actionCode="01">
              <ObjectNodeSenderTechnicalID>1</ObjectNodeSenderTechnicalID>
              <InternalID></InternalID> 
              <CategoryCode>1</CategoryCode> 
              <CustomerIndicator>true</CustomerIndicator> 
              <LifeCycleStatusCode>2</LifeCycleStatusCode> 
              <Person>
                <GivenName>' . $meta['billing_first_name'] . '</GivenName>
                <FamilyName>' . $meta['billing_last_name'] . '</FamilyName>
                <NameFormatCountryCode>CH</NameFormatCountryCode>
                <NonVerbalCommunicationLanguageCode>' . $language . '</NonVerbalCommunicationLanguageCode>
              </Person>
              {addresses}
              <SalesArrangement actionCode="01">
                <ObjectNodeSenderTechnicalID>003</ObjectNodeSenderTechnicalID> <!-- Use Default -->
                <SalesOrganisationID>A140</SalesOrganisationID> <!-- Verkaufsorganisation (bleibt immer gleich) -->
                <DistributionChannelCode>01</DistributionChannelCode> <!-- Always use 01 (=  -->
                <Incoterms>
                  <ClassificationCode>EXW</ClassificationCode> <!-- Incoterms -->
                  <TransferLocationName>-</TransferLocationName> <!-- Incoterms-Ort -->
                </Incoterms>
                <DeliveryPriorityCode>3</DeliveryPriorityCode> <!-- 3 = Normal -->
                <CurrencyCode>CHF</CurrencyCode>
                <CustomerGroupCode></CustomerGroupCode>
                <CashDiscountTermsCode>1003</CashDiscountTermsCode><!-- Zahlungsbedingung:  1003 - 30 Tage netto-->
              </SalesArrangement>
              <PaymentData actionCode="01" paymentFormListCompleteTransmissionIndicator="true">
                <ObjectNodeSenderTechnicalID>013</ObjectNodeSenderTechnicalID> <!-- Use Default -->
                <CompanyID>U100</CompanyID> <!-- Unternehmensnummer -->
                <AccountDeterminationDebtorGroupCode>4010</AccountDeterminationDebtorGroupCode> <!-- Kontenfindungsgruppe: 4010 - Inland, nicht verbundenes Unternehmen -->
                <PaymentForm actionCode="01">
                  <PaymentFormCode>05</PaymentFormCode> <!-- Bank Transfer / Zahlweg: Überweisung-->
                </PaymentForm>
              </PaymentData>
            </Customer>
          </glob:CustomerBundleMaintainRequest_sync_V1>
        </soap:Body>
      </soap:Envelope>
      ';

      // Add addresses to it (billing, and shipping if not same as billing)
      $billing = $this->getAddressObject('billing', $meta);
      $shipping = $this->getAddressObject('shipping', $meta);
      // Add billing in any case
      $addresses = '';
      $addresses .= $this->createAddressObject('billing', $meta, $language, true);
      // Add shipping if not same
      if (md5(json_encode($billing)) != md5(json_encode($shipping))) {
        $addresses .= $this->createAddressObject('shipping', $meta, $language, false);
      }
      $envelope = str_replace('{addresses}', $addresses, $envelope);

      // Put that new customer on SAP
      $response = $this->getApi()->postXml('/sap/bc/srt/scs/sap/managecustomerin1?sap-vhost={{TenantHostname}}', $envelope, $soapAction);

      // If successfully created, attacht to our customer
      if (Strings::contains($response, '<InternalID>')) {
        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->loadXML($response);
        $internalId = (string) $dom->getElementsByTagName('InternalID')->item(0)->textContent;
        $uuid = (string) $dom->getElementsByTagName('UUID')->item(0)->textContent;
        $uuid = strtoupper(str_replace('-', '', $uuid));

        update_user_meta($userId, 'sap-customer-id', $uuid);
        update_user_meta($userId, 'sap-customer-nr', $internalId);
        SystemLog::add('SapByd::Customer', 'info', 'created new private account', htmlentities($response));
      } else {
        // Add the account data that we tried to add
        SystemLog::add('SapByd::Customer', 'fatal', 'could not create new private account', array(
          'requestXml' => htmlentities($envelope),
          'responseXml' => htmlentities($response)
        ));
      }
    }
  }

  /**
   * @return void
   */
  public function queueByInternalId()
  {
    $internalId = intval($_GET['data']);
    $objectId = $this->getCustomerObjectIdByInternalId($internalId);

    if ($objectId !== false) {
      $object['id'] = $objectId;
      $object['partner'] = false;
      $object['relationship'] = false;
      $table = new LbwpData('aboon_customer_sync');
      $table->updateRow($object['id'], $object);
      $this->registerProcessQueueJob();
    }
  }

  /**
   * @param $internalId
   * @return false|mixed
   */
  protected function getCustomerObjectIdByInternalId($internalId, $type = 'khcustomer/CustomerCollection')
  {
    $raw = $this->getApi()->get('/sap/byd/odata/cust/v1/' . $type, array(
      '$format' => 'json',
      '$filter' => "InternalID eq '$internalId'",
      '$select' => 'ObjectID'
    ));

    if (isset($raw['d']['results'][0]['ObjectID']) && strlen($raw['d']['results'][0]['ObjectID']) > 0) {
      return $raw['d']['results'][0]['ObjectID'];
    }

    return false;
  }

  /**
   * Queues all logins from SAP to be synced in background
   * @return void
   */
  public function queueAllLogins()
  {
    if (!current_user_can('administrator')) {
      return;
    }

    $api = $this->getApi();

    $raw = $api->get('/sap/byd/odata/cust/v1/khbusinesspartnerrelationship/BusinessPartnerRelationshipCollection', array(
      '$format' => 'json',
      '$filter' => "ZShopUser_KUT eq true",
      '$select' => 'FirstBusinessPartnerUUID',
      '$top' => 100000
    ));

    $table = new LbwpData('aboon_customer_sync');
    foreach ($raw['d']['results'] as $customer) {
      $object = array(
        'id' => str_replace('-', '', $customer['FirstBusinessPartnerUUID']),
        'relationship' => false
      );
      $table->updateRow($object['id'], $object);
    }

    // Register a new processing job
    $this->registerProcessQueueJob();
  }

  /**
   * @param string $type
   * @param array $meta
   * @param string $language
   * @param bool $defaut
   * @return void
   */
  protected function createAddressObject($type, $meta, $language, $defaut)
  {
    // Get street / number from address line
    $street = $houseId = '';
    $parts = explode(' ', $meta[$type . '_address_1']);
    if (count($parts) > 1) {
      $houseId = array_pop($parts);
      $street = implode(' ', $parts);
    } else {
      $street = $parts[0];
    }

    $email = '';
    // Add email if given and only for billing
    if ($type == 'billing' && strlen($meta['billing_email']) > 0) {
      $email = '<EmailURI>' . $meta['billing_email'] . '</EmailURI>';
    }

    return '      
      <AddressInformation actionCode="01">
        <AddressUsage actionCode="01">
          <ObjectNodeSenderTechnicalID>003</ObjectNodeSenderTechnicalID> 
          <AddressUsageCode>' . ($type == 'shipping' ? 'SHIP_TO' : 'XXDEFAULT') . '</AddressUsageCode>
          <DefaultIndicator>' . ($defaut ? 'true' : 'false') . '</DefaultIndicator>
        </AddressUsage>
        <Address actionCode="01">
          ' . $email . '
          <PostalAddress>
            <CountryCode>' . strtoupper($meta[$type . '_country']) . '</CountryCode>
            <CityName>' . $meta[$type . '_city'] . '</CityName>
            <StreetPostalCode>' . $meta[$type . '_postcode'] . '</StreetPostalCode>
            <StreetName>' . $street . '</StreetName>
            <HouseID>' . $houseId . '</HouseID>
          </PostalAddress>
        </Address>
      </AddressInformation>
    ';
  }

  /**
   * @param string $type billing or shipping
   * @param array $meta meta array to be used
   * @return array
   */
  protected function getAddressObject($type, $meta)
  {
    return array(
      'address' => $meta[$type . '_address_1'] ?? '',
      'postcode' => $meta[$type . '_postcode'] ?? '',
      'city' => $meta[$type . '_city'] ?? '',
      'country' => $meta[$type . '_country'] ?? ''
    );
  }

  /**
   * @param mixed $remoteId
   * @param mixed $object
   * @return User fully filled user object
   */
  public function convertCustomer($remoteId, $object = null): User
  {
    // Maybe our object is actually a relationship, if so register all of the contacts businesspartners
    if (is_array($object) && isset($object['data']['relationship']) && $object['data']['relationship']) {
      $relationship = $this->getApi()->get('/sap/byd/odata/cust/v1/khbusinesspartnerrelationship/BusinessPartnerRelationshipCollection', array(
        '$format' => 'json',
        '$filter' => "ObjectID eq '" . $object['id'] . "'",
        '$expand' => 'ContactPerson/ContactPersonBusinessAddressInformation/ContactPersonBusinessEMail',
        '$select' => 'ContactPerson/ContactPersonBusinessAddressInformation/ContactPersonBusinessEMail/URI'
      ));
      if (is_array($relationship['d']['results']) && count($relationship['d']['results']) > 0) {
        $email = $relationship['d']['results'][0]['ContactPerson']['ContactPersonBusinessAddressInformation']['ContactPersonBusinessEMail']['URI'];
        if (Strings::isEmail($email)) {
          $customerIds = $this->getBusinessPartnerRelationShipsByEmail($email);
          if (count($customerIds) > 0) {
            $table = new LbwpData('aboon_customer_sync');
            foreach ($customerIds as $customerId) {
              // Skip if we already sinced this one in the last few minutes
              if (wp_cache_get('already_synced_id_' . $customerId, 'SapByd') == 1) {
                continue;
              }
              $object = array(
                'id' => $customerId,
                'relationship' => false
              );
              $table->updateRow($object['id'], $object);
              wp_cache_set('already_synced_id_' . $customerId, 1, 'SapByd', 900);
            }

            // Register a new processing job, but return here, as we just synced all of the logins accounts
            $this->registerProcessQueueJob();
            return new User('');
          }
        }
      }
    }

    if (is_array($object) && isset($object['data']['partner']) && $object['data']['partner']) {
      $businessPartner = $this->getApi()->get('/sap/byd/odata/cust/v1/khbusinesspartner/BusinessPartnerCollection', array(
        '$format' => 'json',
        '$filter' => "ObjectID eq '" . $object['id'] . "'",
        '$select' => 'InternalID',
        '$top' => 1
      ));
      // Get the actual customerID from business partner by the internal ID, skip if not found
      $remoteId = $this->getCustomerObjectIdByInternalId($businessPartner['d']['results'][0]['InternalID']);
      // It is possible we don't find anything as triggers for GP only (with no customer role) exist. they are not synced
      if ($remoteId === false) {
        return new User('');
      }
    }

    // The Sap Remote ID is actually the ObjectID of a business partner
    // First get all infos about the business partner and relations
    $customer = $this->getCustomerByObjectId($remoteId);

    // Check if there are contacts, if not, not login needs to be synced
    if (!isset($customer['contacts']) || count($customer['contacts']) == 0) {
      return new User('');
    }

    // Add the addresses to every user/login within contacts
    foreach ($customer['contacts'] as $contact) {
      $hash = md5($contact['email']);
      $localId = $this->getUserByLoginEmail($contact['email']);
      $user = new User($hash, $localId);
      $user->setUserLogin($contact['email']);
      $user->setUserEmail($contact['email']);
      $user->setMeta('sap-pricelist', $customer['pricelist']);
      $user->setMeta('sap-pricehierarchy', $customer['pricehierarchy']);
      $user->setMeta('sap-user-assortment-groups', $customer['groups']);
      if ($customer['private']) {
        $user->setMeta('sap-customer-id', $customer['sap-id']);
        $user->setMeta('sap-customer-nr', $customer['customer-id']);
      }
      // Make sure to remove inactive flag if previously set
      if (get_user_meta($user->getId(), 'member-disabled', true) == 1) {
        $user->deleteMeta('member-disabled');
        $user->forceSaving();
      }

      // Load existing addresses if given, so we don't delete them
      $user->loadAddresses();

      // Add the addresses
      foreach (array('billing', 'shipping') as $type) {
        $isMain = true;
        foreach ($customer['addresses'][$type] as $addressId => $address) {
          $address = array_merge($address, $contact);
          $address['address_id'] = $addressId;
          $address['customer_id'] = $address['customer_id'] ?? $customer['customer-id'];
          $user->addAddress($type, $isMain, $address, true);
          $isMain = false;
        }
      }

      // Let developers add more data if needed
      do_action('aboon_erp_customer_before_update_user', $user, $customer);
      // Save the user and send email for newly created login
      if (!$user->isUpdating()) {
        $user->setSendEmail(true);
      }
      // Save the users always, as this method normally intends to only edit one login
      // But SAP can have multiple logins attached to one customer, which is a SAP special
      $user->save();
      // Log that we synced a customer
      //SystemLog::add('SapByd::Customer', 'info', 'Synced customer ID ' . $remoteId, $user->getMetaList());
    }

    // As we already maybe saved multiple users, return an empty one
    return new User('');
  }

  /**
   * @param string $email an email address
   * @return string business partner id, or empty if not found
   */
  protected function getCustomerIdByEmail(string $email) : string
  {
    $customerId = '';
    $api = $this->getApi();

    $raw = $api->get('/sap/byd/odata/cust/v1/khcustomer/AddressInformationCollection', array(
      '$format' => 'json',
      '$expand' => 'EMail',
      '$filter' => "EMail/URI eq '$email'",
      '$select' => 'ParentObjectID,EMail/URI'
    ));

    // The parent of the found email is basically the customer we're looking for
    if (isset($raw['d']['results']) && count($raw['d']['results']) > 0) {
      $customerId = $raw['d']['results'][0]['ParentObjectID'];
    }

    // Not sure why, but maybe we need to search via relationship collections
    if (strlen($customerId) == 0) {
      $customerIds = $this->getBusinessPartnerRelationShipsByEmail($email);
      if (count($customerIds) > 0) {
        $customerId = $customerIds[0];
      }
    }

    return $customerId;
  }

  /**
   * @param $email
   * @return array
   */
  protected function getBusinessPartnerRelationShipsByEmail($email)
  {
    $customerIds = array();
    $api = $this->getApi();
    $raw = $api->get('/sap/byd/odata/cust/v1/khbusinesspartnerrelationship/ContactPersonBusinessEMailCollection', array(
      '$format' => 'json',
      '$filter' => "URI eq '$email'",
      '$select' => 'ParentObjectID'
    ));
    if (isset($raw['d']['results']) && count($raw['d']['results']) > 0) {
      foreach ($raw['d']['results'] as $relationship) {
        $contactId = $relationship['ParentObjectID'];
        // When we found the email in a relation, call the contact person, then get it's first businesspartner id
        // This may not work if a business contact has multiple different customers assigned
        if (strlen($contactId) > 0) {
          $contact = $api->get('/sap/byd/odata/cust/v1/khbusinesspartnerrelationship/ContactPersonBusinessAddressInformationCollection', array(
            '$format' => 'json',
            '$expand' => 'BusinessPartnerRelationship',
            '$filter' => "ObjectID eq '$contactId'",
            '$select' => 'BusinessPartnerRelationship/FirstBusinessPartnerUUID'
          ));
          if (isset($contact['d']['results']) && count($contact['d']['results']) > 0) {
            $customerIds[] = str_replace('-', '', $contact['d']['results'][0]['BusinessPartnerRelationship']['FirstBusinessPartnerUUID']);
          }
        }
      }
    }

    return $customerIds;
  }

  /**
   * @param int $userId
   * @return bool true if the user id os connected to a private sap account
   */
  protected static function hasSapConnectionPrivateCustomer($userId) : bool
  {
    return strlen(get_user_meta($userId, 'sap-customer-id', true) > 0);
  }

  /**
   * @param int $userId
   * @return bool true if the user id os connected to a private sap account
   */
  protected static function hasSapPricelist($userId) : bool
  {
    return strlen(get_user_meta($userId, 'sap-pricelist', true) > 0);
  }

  /**
   * @param string $id
   * @return array meaningfully translated customer object
   */
  protected function getCustomerByObjectId(string $id)
  {
    $customer = array(
      'sap-id' => '', // ObjectID
      'customer-id' => '', // InternalID
      'name' => '',
      'pricelist' => '',
      'active' => false,
      'private' => false,
      'addresses' => array(),
      'contacts' => array(),
      'groups' => array(),
      'nonSynced' => array()
    );

    $api = $this->getApi();
    $raw = $api->get('/sap/byd/odata/cust/v1/khcustomer/CustomerCollection', array(
      '$format' => 'json',
      '$expand' => 'AddressInformation,AddressInformation/PostalAddress,AddressInformation/EMail,AddressInformation/AddressUsage,Relationship,CustomerCommon',
      '$filter' => "ObjectID eq '$id'",
      'sap-language' => 'DE'
    ));

    // Check if we even have data, return empty so nothing is done
    if (!isset($raw['d']['results'][0]['ObjectID'])) {
      return $customer;
    }

    // Set some basic data first
    $raw = $raw['d']['results'][0];
    $customer['sap-id'] = $raw['ObjectID'];
    $customer['customer-id'] = $raw['InternalID'];
    $customer['name'] = $customer['private'] ? $raw['BusinessPartnerFormattedName'] : $raw['OrganisationFirstLineName'];
    $customer['active'] = $raw['LifeCycleStatusCode'] == 2;
    $customer['private'] = $raw['CategoryCode'] != 2;
    $customer['pricelist'] = $raw['Kalkulationsbasis_KUTText'] ?? static::$DEFAULT_PRICE_LIST_NAME;
    $customer['pricehierarchy'] = $raw['FrachtKondition_KUT'] ?? '';
    $customer['costcenter'] = $raw['Kostenstelle_KUT'] ?? '';
    $customer['allow-def-assortment'] = $raw['GesamtsortimentimWebshopanzeigen_KUT'] == true;

    // Add the postal addresses in woocommerce format
    foreach ($raw['AddressInformation'] as $address) {
      // Add address as both
      $data = array(
        'company' => $customer['name'] ?? '',
        'address_1' => trim($address['PostalAddress']['StreetName'] . ' ' . $address['PostalAddress']['HouseID']),
        'address_2' => $raw['OrganisationSecondLineName'] ?? '',
        'postcode' => $address['PostalAddress']['StreetPostalCode'],
        'costcenter' => $customer['costcenter'],
        'city' => $address['PostalAddress']['CityName'],
        'country' => $address['PostalAddress']['CountryCode']
      );


      $id = $address['PostalAddress']['ObjectID'];
      foreach ($address['AddressUsage'] as $usage) {
        if ($usage['AddressUsageCode'] == 'BILL_TO' || $usage['AddressUsageCode'] == 'XXDEFAULT') {
          $customer['addresses']['billing'][$id] = $data;
        }
        if ($usage['AddressUsageCode'] == 'SHIP_TO' || $usage['AddressUsageCode'] == 'XXDEFAULT') {
          $customer['addresses']['shipping'][$id] = $data;
        }
      }
    }

    // Relationships don't need to be checked for private customers, leave early
    if ($customer['private']) {
      // But create a single contact from name/email/phone of the contact
      $email = $raw['AddressInformation'][0]['EMail']['URI'];
      if (Strings::checkEmail($email)) {
        $customer['contacts'][md5($email)] = array(
          'company' => !$customer['private'] ? $customer['name'] : '',
          'first_name' => $raw['PersonGivenName'] ?? '',
          'last_name' => $raw['PersonFamilyName'] ?? '',
          'email' => $email
        );
      }
      return $customer;
    }

    // For our next trick, get details of every relationship on that customer
    foreach ($raw['Relationship'] as $relationship) {
      $relId = $relationship['ObjectID'];

      // Connect ansprechpartners BUR001 as the actual login if flag is given
      if ($relationship['CategoryCode'] == 'BUR001') {
        $details = $api->get('/sap/byd/odata/cust/v1/khbusinesspartnerrelationship/BusinessPartnerRelationshipCollection', array(
          '$format' => 'json',
          '$expand' => 'ContactPerson/ContactPersonBusinessAddressInformation/ContactPersonBusinessEMail',
          '$filter' => "ObjectID eq '$relId'",
          'sap-language' => 'DE'
        ));
        $details = $details['d']['results'][0];
        $email = $details['ContactPerson']['ContactPersonBusinessAddressInformation']['ContactPersonBusinessEMail']['URI'];
        // Skip if not a shop login
        if ($details['ZShopUser_KUT'] != true) {
          continue;
        }
        $nameParts = explode(' ', $details['SecondBusinessPartnerFormattedName']);
        if (Strings::checkEmail($email)) {
          $customer['contacts'][md5($email)] = array(
            'first_name' => array_shift($nameParts),
            'last_name' => implode(' ', $nameParts),
            'email' => $email
          );
        }
      }

      // Connect another GPs addresses if he is rechnungsempfänger
      if ($relationship['CategoryCode'] == 'CRMH04') {
        $relatedBusinessPartnerId = $relationship['InternalID2'];

        if (strlen($relatedBusinessPartnerId) > 0) {
          $relationRaw = $api->get('/sap/byd/odata/cust/v1/khcustomer/CustomerCollection', array(
            '$format' => 'json',
            '$expand' => 'AddressInformation,AddressInformation/PostalAddress,AddressInformation/AddressUsage',
            '$filter' => "InternalID eq '$relatedBusinessPartnerId'",
            'sap-language' => 'DE'
          ));

          $relationRaw = $relationRaw['d']['results'][0];
          foreach ($relationRaw['AddressInformation'] as $address) {
            $addressLine2 = '';
            if (isset($address['PostalAddress']['POBoxID']) && strlen($address['PostalAddress']['POBoxID']) > 0) {
              $addressLine2 = $address['PostalAddress']['POBoxID'];
            } else if (isset($relationRaw['OrganisationSecondLineName']) && strlen($relationRaw['OrganisationSecondLineName']) > 0) {
              $addressLine2 = $relationRaw['OrganisationSecondLineName'];
            }
            // Add address as both
            $data = array(
              'company' => $relationRaw['BusinessPartnerFormattedName'] ?? '',
              'address_1' => trim($address['PostalAddress']['StreetName'] . ' ' . $address['PostalAddress']['HouseID']),
              'address_2' => $addressLine2,
              'postcode' => $address['PostalAddress']['StreetPostalCode'],
              'costcenter' => $relationRaw['Kostenstelle_KUT'] ?? '',
              'city' => $address['PostalAddress']['CityName'],
              'country' => $address['PostalAddress']['CountryCode'],
              'customer_id' => $relatedBusinessPartnerId
            );

            if (strlen($data['address_1']) == 0 && strlen($data['address_2']) > 0) {
              $data['address_1'] = $data['address_2'];
              $data['address_2'] = '';
            }

            $id = $address['PostalAddress']['ObjectID'];
            foreach ($address['AddressUsage'] as $usage) {
              if ($usage['AddressUsageCode'] == 'BILL_TO' || $usage['AddressUsageCode'] == 'XXDEFAULT') {
                $customer['addresses']['billing'][$id] = $data;
              }
              if ($usage['AddressUsageCode'] == 'SHIP_TO' || $usage['AddressUsageCode'] == 'XXDEFAULT') {
                $customer['addresses']['shipping'][$id] = $data;
              }
            }
          }
        }
      }
    }

    // Read in the groups if given by ObjectID
    $groups = $api->get('/sap/byd/odata/cust/v1/kundensortiment/BO_CA_Helper_CustomerSalesOrderWhiteListCollection', array(
      '$format' => 'json',
      '$select' => 'customerUUID,id',
      'customerUUID' => $raw['ObjectID']
    ));

    // Match groups, but never add the standard assortment group as of now
    if (isset($groups['d']['results']) && count($groups['d']['results']) > 0) {
      foreach ($groups['d']['results'] as $group) {
        $assortmentId = strtoupper(str_replace('-', '', $group['id']));
        // Skip to next if standard assortment is not allowed for customer
        if (!$customer['allow-def-assortment'] && $assortmentId == static::$STANDARD_ASSORTMENT_GROUP) {
          continue;
        }
        if ($group['customerUUID'] == $raw['UUID']) {
          $customer['groups'][] = $assortmentId;
        }
      }
    }

    // Add default assortment if *none* was given (makes is easier for banholzer to not set the flag, if only default assortment is given
    if (count($customer['groups']) == 0) {
      $customer['groups'][] = static::$STANDARD_ASSORTMENT_GROUP;
    }

    return $customer;
  }

  /**
   * @return ApiHelper
   */
  protected function getApi() : ApiHelper
  {
    return new ApiHelper(static::$sapHostName, static::$sapUserName, static::$sapPassword);
  }

  /**
   * @return array
   */
  protected function getQueueTriggerObject() : array
  {
    $object = json_decode(file_get_contents('php://input'), true);
    /*
    $object = json_decode('{
      "type": "sap.byd.Customer.Root.Updated.v1",
      "data": {
        "entity-id": "FA163ED93AF31EDDA0C10950ED5CE93F"
      }
    }', true);
    $object = json_decode('{
      "type": "sap.byd.BusinessPartner.Root.Updated.v1",
      "data": {
        "entity-id": "FA163EDCA3541EDDA9F7461C986EAA83"
      }
    }', true);
    */
    // this object comes when an address or contact changes
    /*
    $object = json_decode('{
      "type": "sap.byd.BusinessPartnerRelationship.Root.Updated.v1",
      "data": {
        "entity-id": "FA163EB66B721EDD9F8B1639224C4554"
      }
    }', true);
    */
    /*
    // Customer with login for 5 business accounts
    $object = json_decode('{
      "type": "sap.byd.BusinessPartnerRelationship.Root.Updated.v1",
      "data": {
        "entity-id": "FA163ED93AF31EEDA7CA4A64A1432368"
      }
    }', true);
    */
    /*
    $object = json_decode('{
      "type": "sap.byd.Customer.Root.Updated.v1",
      "data": {
        "entity-id": "FA163ED93AF31EDDA0D897979C07B0EC"
      }
    }', true);
    */
    /*
    $object = json_decode('{
      "type": "sap.byd.BusinessPartner.Root.Updated.v1",
      "data": {
        "entity-id": "47138BB3F8FA1EDDB8EC82B26A33C13E"
      }
    }', true);
    */


    // Leave early, request is invalid, don't bother SAP for that
    // Check for BusinessPartner handles "BusinessPartner" and "BusinessPartnerRelationship" validation
    if ((!Strings::contains($object['type'], 'Customer')  && !Strings::contains($object['type'], 'BusinessPartner')) || !isset($object['data']['entity-id'])) {
      SystemLog::add('SabByd', 'debug', 'invalid customer trigger, full data dump', $object);
      return array('success' => false, 'message' => 'invalid request');
    }

    // Temporary log (always disabled when not needed on prod)
    // SystemLog::add('SapByd::BPTrigger', 'debug', 'valid trigger executing: ' . $object['type'], $object);

    $object['id'] = $object['data']['entity-id'];
    $object['partner'] = Strings::contains($object['type'], 'BusinessPartner.');
    $object['relationship'] = Strings::contains($object['type'], 'BusinessPartnerRelationship');
    return $object;
  }

  /**
   * Get the next n-IDs from the file
   * @param int $page
   * @return array
   */
  protected function getPagedCustomerIds($page): array
  {
    // TODO implement from SAP
    return array();
    /*
    // First open file and get all distinct ids
    $file = $this->loadRemoteFile();
    $remoteIds = array_keys($file['logins']);
    // Do not send user email change mails
    add_filter('send_email_change_email', '__return_false', 100);
    // Now return the page slice of that
    $offset = ($page - 1) * $this->importsPerRun;
    return array_slice($remoteIds, $offset, $this->importsPerRun);
    */
  }

  /** TODO maybe implement
   * @param mixed $remoteId
   * @return mixed $remoteId is int in every case
   */
  protected function validateRemoteId($remoteId)
  {
    return $remoteId;
  }
}