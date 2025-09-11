<?php

namespace LBWP\Aboon\Erp;

use LBWP\Aboon\Erp\Entity\User;
use LBWP\Helper\Cronjob;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component;
use LBWP\Util\Date;
use LBWP\Util\LbwpData;
use LBWP\Util\Strings;

/**
 * Base class to sync customer data with an ERP system
 * @package LBWP\Aboon\Erp
 */
abstract class Customer extends Component
{
  /**
   * @var int number of datasets to be imported in full sync per minutely run
   */
  protected int $importsPerRun = 50;
  /**
   * @var bool Basically don't send emails on new users, but
   */
  protected bool $sendEmailForNewlyImportedUsers = false;
  /**
   * Main starting point of the component
   */
  public function init()
  {
    add_action('rest_api_init', array($this, 'registerApiEndpoints'));
    add_action('cron_job_manual_aboon_erp_customer_register_full_sync', array($this, 'registerFullSync'));
    add_action('cron_job_aboon_erp_customer_sync_page', array($this, 'runBulkImportCron'));
    add_action('cron_job_aboon_customer_sync_process_queue', array($this, 'processCustomerQueue'));
    add_action('woocommerce_before_checkout_form', array($this, 'displayAddressSwitcher'));
    // Controller for address switching in checkout site
    $this->addressChangeController();
  }

  /**
   * @param User $user a full user object that should be imported or updated
   * @return bool save status
   */
  protected function updateCustomer(User $user): bool
  {
    // Validate the user and save it
    if ($user->validate()) {
      // Check if the user is new and the email is already existing
      if (!$user->isUpdating() && $this->emailExists($user->getUserEmail())) {
        // If so, connect the user and let him update from ERP (as he was local only until not
        $user->connectUser($user->getRemoteId());
        SystemLog::add('AboonErpCustomer', 'error', 'Connected local user with remote ERP user id ' . $user->getRemoteId());
      } else if (!$user->isUpdating()) {
        // If new user but not existing yet, eventually send welcome mail on import
        $user->setSendEmail($this->sendEmailForNewlyImportedUsers);
      }

      return $user->save();
    }

    return false;
  }

  /**
   * @param string $email
   * @return bool
   */
  protected function emailExists(string $email): bool
  {
    return !(get_user_by('email', $email) === false);
  }

  /**
   * Starts running a paged cron for bulk importing/syncing all customers from remote system
   */
  public function registerFullSync()
  {
    // This basically creates a cron trigger with the first page of mass import (which will then contain itself until finished)
    Cronjob::register(array(
      current_time('timestamp') => 'aboon_erp_customer_sync_page::1'
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

    foreach ($this->getPagedCustomerIds($page) as $remoteId) {
      $user = $this->convertCustomer($remoteId);
      // And import or sync the provided user
      $syncResults[] = $this->updateCustomer($user);
    }

    // Register another cron with next page if data was synced
    if (count($syncResults) > 0) {
      Cronjob::register(array(
        current_time('timestamp') => 'aboon_erp_customer_sync_page::' . (++$page)
      ));
    }
  }

  /**
   * @return void
   */
  public function processCustomerQueue()
  {
    set_time_limit(600);
    $table = new LbwpData('aboon_customer_sync');
    foreach ($table->getRows('pid', 'DESC', $this->importsPerRun) as $customer) {
      $user = $this->convertCustomer($customer['id'], $customer);
      $this->updateCustomer($user);
      $table->deleteRowByPid($customer['pid']);
    }

    if (count($table->getRows()) > 0) {
      $this->registerProcessQueueJob();
    }
  }

  /**
   * Register the api trigger endpoint
   */
  public function registerApiEndpoints()
  {
    register_rest_route('aboon/erp/customer', 'trigger', array(
      'methods' => \WP_REST_Server::ALLMETHODS,
      'callback' => array($this, 'queueExternalTrigger')
    ));
  }

  /**
   * Displays an address switcher if there are addresses to switch
   * @param \WC_Checkout $checkout
   */
  public function displayAddressSwitcher(\WC_Checkout $checkout)
  {
    $user = wp_get_current_user();
    if ($user === false || $user->ID === 0) {
      return;
    }

    // Get the addresses
    $html = ''; $htmlIndex = 1;
    $addresses = get_user_meta($user->ID, 'erp-address-list', true);

    // Display dropdowns by type
    foreach (array('billing', 'shipping') as $type) {
      // Skip if not given or just one address
      if (!isset($addresses[$type]) || count($addresses[$type]) <= 1) {
        continue;
      }

      $addressId = -1;
      if (isset($_POST[$type . '-address-selection']) && is_numeric($_POST[$type . '-address-selection'])) {
        $addressId = intval($_POST[$type . '-address-selection']);
      }

      // Create a label and the dropdown
      $html .= '
        <div class="address-selector col-' . $htmlIndex . '">
          <select id="' . $type . '-address-selection" name="' . $type . '-address-selection">
            <option></option>
      ';

      foreach ($addresses[$type] as $key => $address) {
        $html .= '<option value="' . $key . '"' . selected($addressId, $key, false) . '>' . $this->getAddressString($address) . '</option>';
      }
      
      $html .= '</select></div>';
      $htmlIndex++;
    }

    // Add some JS to make the selects intelligent and build a form around it
    echo '
      <form id="address-selection-list" method="POST" action="">
        <input type="hidden" name="change-address-action" value="1" />
        <input type="hidden" name="change-address-type" value="" />
        <div class="col2-set col-compact">' . $html . '</div>
      </form>
      <script type="text/javascript">
        jQuery(function() {
          jQuery("#billing-address-selection").select2({ 
            placeholder : "' . __('Rechnungsadresse ändern', 'lbwp') . '"
          });
          jQuery("#shipping-address-selection").select2({
            placeholder : "' . __('Lieferadresse ändern', 'lbwp') . '"
          });
          // Also, on change, send the form to save the selection
          jQuery("#billing-address-selection, #shipping-address-selection").on("change", function() {
            var element = jQuery(this);
            var type = element.attr("id");
            type = type.substring(0, type.indexOf("-"));
            jQuery("input[name=change-address-type").val(type);
            jQuery("#address-selection-list").submit();
          });
        });
      </script>
    ';
  }

  /**
   * @param string $email
   * @return int the user id or 0 if not found
   */
  protected function getUserByLoginEmail($email)
  {
    $user = get_user_by('email', $email);
    if ($user === false) {
      $user = get_user_by('login', $email);
    }

    return ($user !== false) ? $user->ID : 0;
  }

  /**
   * Save an address to native woocommerce field upon selection
   */
  protected function addressChangeController()
  {
    if (!isset($_POST['address-change-action']) && $_POST['address-change-action'] == 1) {
      return;
    }

    $user = wp_get_current_user();
    $addresses = get_user_meta($user->ID, 'erp-address-list', true);
    $type = Strings::forceSlugString($_POST['change-address-type']);
    $addressId = intval($_POST[$type . '-address-selection']);
    // See if that address exists
    if (isset($addresses[$type][$addressId])) {
      // Save that down to according meta fields
      foreach ($addresses[$type][$addressId] as $key => $value) {
        update_user_meta($user->ID, $type . '_' . $key, $value);
      }
    }
  }

  /**
   * @param array $address
   * @return string a representation of the address
   */
  protected function getAddressString(array $address) : string
  {
    $parts = array();
    if (isset($address['company']) && strlen($address['company']) > 0) {
      $parts[] = $address['company'];
    }
    if (isset($address['address_1']) && strlen($address['address_1']) > 0) {
      $parts[] = trim($address['address_1'] . ' ' . $address['address_2']);
    }
    if (isset($address['postcode']) && strlen($address['postcode']) > 0) {
      $parts[] = trim($address['postcode'] . ' ' . $address['city']);
    }
    if (isset($address['customer_id']) && strlen($address['customer_id']) > 0) {
      $parts[] = 'KdNr. ' . $address['customer_id'];
    }
    if (isset($address['costcenter']) && strlen($address['costcenter']) > 0) {
      $parts[] = 'Kostenstelle ' . $address['costcenter'];
    }

    return implode(', ', $parts);
  }

  /**
   * @return void
   */
  public function queueExternalTrigger()
  {
    $table = new LbwpData('aboon_customer_sync');
    $object = $this->getQueueTriggerObject();
    $validId = isset($object['id']) && strlen($object['id']) > 0;
    if ($validId) {
      $table->updateRow($object['id'], $object);
      $this->registerProcessQueueJob();
    }
    return array('success' => $validId);
  }

  /**
   * Register with delay and as single, multiple triggers therefore mostly trigger the last change
   * if many changes are coming without importing multiple times
   * @return void
   */
  protected function registerProcessQueueJob($delay = 90)
  {
    Cronjob::register(array(
      current_time('timestamp') + $delay => 'aboon_customer_sync_process_queue'
    ), 1);
  }

  /**
   * Retrieves data sent by a webhook/rigger and converts it
   * @return array with at least an id and additional data
   */
  abstract protected function getQueueTriggerObject(): array;

  /**
   * @param int $page the page to load
   * @return array a list of userids on that page
   */
  abstract protected function getPagedCustomerIds(int $page): array;

  /**
   * @param mixed $remoteId remote id given from external system
   * @return mixed the validated remote od
   */
  abstract protected function validateRemoteId($remoteId);

  /**
   * Actual function to be implemented to convert remote user to local user to be able to import
   * @param mixed $remoteId the id of the user in the remote system
   * @param mixed $object an optional full object to work with
   * @return User predefined user object that can be imported or updated
   */
  abstract protected function convertCustomer($remoteId, $object = null): User;
}