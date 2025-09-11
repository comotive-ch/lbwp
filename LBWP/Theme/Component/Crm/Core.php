<?php

namespace LBWP\Theme\Component\Crm;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Metabox;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\Forms\Action\Crm\WriteData;
use LBWP\Module\Forms\Action\Crm\WriteContact;
use LBWP\Theme\Base\Component;
use LBWP\Theme\Feature\BetterTables\BetterUserTables;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Theme\Feature\SortableTypes;
use LBWP\Helper\WooCommerce\Util as WCUtil;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\Templating;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\WordPress;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Component to provide vast backend features for members
 * @package LBWP\Theme\Component\Crm
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends Component
{
  /**
   * @var string slug for profile / member categories
   */
  const TYPE_PROFILE_CAT = 'crm-profile-category';
  /**
   * @var string slug for contact categories
   */
  const TYPE_CONTACT_CAT = 'crm-contact-category';
  /**
   * @var string slug for custom fields
   */
  const TYPE_FIELD = 'crm-custom-field';
  /**
   * @var string dynamic key for crm segments
   */
  const FILTER_REF_KEY = 'crm-segment';
  /**
   * The list history meta key
   */
  const LIST_HISTORY_META = 'mailing-list-history';
  /**
   * @var array the configuration for the component
   */
  protected $configuration = array();
  /**
   * @var array the user admin data object
   */
  protected $userAdminData = array();
  /**
   * @var array of inactive user ids, if given
   */
  protected $inactiveUserIds = null;
  /**
   * @var int the edited user id
   */
  protected $editedUserId = 0;
  /**
   * @var \WP_User
   */
  protected $editingUser = null;
  /**
   * @var \WP_User
   */
  protected $editedUser = null;
  /**
   * @var bool
   */
  protected $hasWooCommerce = false;
  /**
   * @var string the field template
   */
  protected $fieldTemplate = '
    <table class="form-table custom-field-table" data-target-tab="{tabName}">
      <tbody>
        <tr>
          <th><label for="crmcf-{fieldName}">{fieldLabel}{fieldRequired}</label></th>
          <td>
            {fieldContent}
            <span class="description crmcf-description">
              <span class="dashicons dashicons-editor-help"></span>
              <label for="crmcf-{fieldName}">{fieldDescription}</label>
              <span class="crmcf-last-changed">{fieldChangedDate}</span>
            </span>
            <span class="history crmcf-history">
              <span class="dashicons dashicons-share-alt2"></span>
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  ';
  /**
   * @var array fields that can't yet be exported properly
   */
  protected $unexportableFields = array('table');
  /**
   * @var Core the instance reference for static access from outside
   */
  public static $instance = null;

  /**
   * Few things need to be registered pretty early
   */
  public function setup()
  {
    self::$instance = $this;
    $this->hasWooCommerce = LbwpCore::hasWooCommerce();
    parent::setup();
    // Handle special cases for add new users easier
    if (is_admin()) {
      $this->handleNewUserCreateUI();
    }
    // Filter virtual capabilities for users
    add_filter('user_has_cap', array($this, 'filterVirtualCapabilities'), 10, 4);
    // Tell disabled users that they're not allowed anymore
    add_filter('authenticate', array($this, 'preventDisabledUserLogin'), 100, 1);
    add_filter('wp', array($this, 'logoutDisabledUsers'), 100, 1);
    // Set a global to access configurations from outside easily
    global $crmCoreSettings;
    $crmCoreSettings = $this->configuration;
  }

  /**
   * Initialize the component
   */
  public function init()
  {
    // Create the data object that is used multiple times
    $this->setEditedUserId();
    $this->userAdminData = $this->getUserAdminData();
    // Register categorization post types and connections
    $this->addCategorizationPostTypes();

    // Invoke member admin scripts and menu stuff
    if (is_admin()) {
      // Various actions sorted by run time
      add_action('current_screen', array($this, 'preventUserOnDashboard'), 10);
      add_action('current_screen', array($this, 'maybeSetDefaultDisplayRole'));
      add_action('admin_init', array($this, 'checkBackendAccess'), 40);
      add_action('admin_init', array($this, 'addCategorizationMetaboxes'), 50);
      add_action('admin_menu', array($this, 'hideMenusFromMembers'), 50);
      add_action('admin_menu', array($this, 'addExportView'), 100);
      add_action('admin_head', array($this, 'invokeMemberAdminScripts'));
      add_action('admin_footer', array($this, 'invokeFooterScripts'));
      add_action('pre_get_users', array($this, 'invokeUserTableQuery'));
      add_filter('sortabletype_' . self::TYPE_FIELD . '_item_title', array($this, 'extendSortableFieldTitle'), 10, 2);
      // Save user data (hook even called for non crm roles)
      add_action('profile_update', array($this, 'saveMemberData'));
      add_action('user_register', array($this, 'syncCoreToCustomFields'));
      add_action('save_post_' . self::TYPE_FIELD, array($this, 'invalidateCaches'));
      add_action('save_post_' . self::TYPE_FIELD, array($this, 'invalidateSegmentCache'));
      // XHR actions
      add_action('wp_ajax_getCrmFieldHistory', array($this, 'getCrmFieldHistory'));
      // On user deletion, maybe delete sub accounts if given
      add_action('delete_user', array($this, 'forceSubAccountDelete'));
      // When woocommerce is active, allow access for our roles
      add_filter('woocommerce_prevent_admin_access', array($this, 'allowShopCrmGroups'));
      $this->addAdminTableColumns();
      // Refresh the cache if the list changes
      add_action('save_post_' . LocalMailService::LIST_TYPE_NAME, array($this, 'invalidateListCache'));

      if(isset($_POST['exportData'])){
        $this->exportChartData();
      }
    }

    if (isset($this->configuration['betterTables']) && $this->configuration['betterTables']['active']) {
      new BetterUserTables($this->configuration['betterTables']['config']);
    }

    // Support for flat tables?
    if (isset($this->configuration['betterTables']) && $this->configuration['betterTables']['config']['useFlatTable']) {
      add_action('cron_daily_22', array($this, 'syncUserFlatTable'));
      add_action('cron_daily_23', array($this, 'runUserFlatPerformanceCompare'));
    }

    // Filters to add segments containing of profile- and contact categories
    add_action('Lbwp_LMS_Metabox_' . self::FILTER_REF_KEY, array($this, 'addMemberMetabox'), 10, 3);
    add_filter('Lbwp_LMS_Data_' . self::FILTER_REF_KEY, array($this, 'getSegmentData'), 10, 2);
    add_filter('Lbwp_LMS_handle_list_unsubscribe', array($this, 'handleCrmFieldListUnsubscribe'), 20, 3);
    add_filter('Lbwp_LMS_oneclick_subscribe_vars', array($this, 'handleCrmFieldOneclickSubscribe'), 20, 1);
    add_filter('Lbwp_Autologin_Link_Validity', array($this, 'configureAutoLoginLink'));
    add_action('LBWP_SortableTypes_after_saving', array($this, 'invalidateCaches'));
    // Cron to automatically send changes from the last 24h
    add_action('cron_daily_7', array($this, 'sendTrackedUserChangeReport'));
    add_action('cron_hourly', array($this, 'checkForHangingSendings'));
    // Add the lbwp-form-to-crm field action
    add_filter('lbwpFormActions', array($this, 'addCrmFormAction'));
    // When new user is added (by admin or by for example woocommerce, add profile categories
    add_filter('user_register', array($this, 'maybeAddProfileCategories'), 20, 1);
    add_filter('user_register', array($this, 'maybeAddDefaultContact'), 30, 1);
    // Allow usage of CRM fields in the checkout
    add_filter('woocommerce_checkout_fields', array($this, 'addCrmCheckoutFields'));
    add_filter('woocommerce_checkout_update_user_meta', array($this, 'saveCrmCheckoutFields'), 10, 2);
    // Generally do not send those emails as they might be triggered inadvertedly
    add_filter('send_email_change_email', '__return_false');
    add_filter('send_password_change_email', '__return_false');

    if ($this->userAdminData['editedIsMember']) {
      // Set the editing user object
      $this->editingUser = wp_get_current_user();
      // Include the tabs navigation and empty containers
      add_action('show_user_profile', array($this, 'addTabContainers'));
      add_action('edit_user_profile', array($this, 'addTabContainers'));
      // Include custom fields as of configuration and callbacks
      add_action('show_user_profile', array($this, 'addCustomUserFields'));
      add_action('edit_user_profile', array($this, 'addCustomUserFields'));
      // Custom save functions
      add_action('profile_update', array($this, 'onMemberProfileUpdate'));
    }

    // Keep history of the lists if setting is active
    if(isset($this->configuration['misc']['trackListsHistory']) && $this->configuration['misc']['trackListsHistory'] === true){
      // Add the notification settings
      add_action('admin_init', array($this, 'addNotificationSettings'));

      // Add the chart
      add_action('cron_daily_7', array($this, 'trackMailingLists'));
      add_action('add_meta_boxes', array($this, 'addHistoryChart'));
      add_action('admin_enqueue_scripts', array($this, 'enqueueChartJS'));
    }
  }

  /**
   * @return void
   */
  public function syncUserFlatTable()
  {
    // Allow more RAM and time for this
    ini_set('memory_limit', '2048M');
    set_time_limit(1800);
    // Get raw data we need to sync down
    $raw = $this->getContactsByCategory(-1, true);
    // First create the table or delta the fields in it
    $this->handleDeltaFlatTable($raw);
    // Sync data with the table
    $this->syncUserFlatTableData($raw);
  }

  /**
   * @param $raw
   * @return void
   */
  protected function handleDeltaFlatTable(&$raw)
  {
    // Create the table with dbDelta
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $db = WordPress::getDb();

    // Get cached full data list
    $example = $raw[0];
    // Iterate trough raw until we have an example with no NULL values
    foreach ($raw as $row) {
      // Merge in all non-null fields from $row that are NULL in $example
      foreach ($row as $key => $value) {
        if ($value !== null && $example[$key] === null) {
          $example[$key] = $value;
        }
      }
      // check if there are still null values in $example
      $hasNull = false;
      foreach ($example as $value) {
        if ($value === null) {
          $hasNull = true;
          break;
        }
      }
      if (!$hasNull) {
        break;
      }
    }

    // Create actual SQL from the example
    $innerSql = '';
    unset($example['userid']);
    foreach ($example as $key => $value) {
      $type = 'varchar(255) COLLATE utf8mb4_unicode_520_ci';
      if (is_array($value)) {
        continue; // TODO MAYBE add support if viable
      }
      if (is_numeric($value)) {
        $type = 'bigint UNSIGNED';
      }
      $innerSql .= str_replace('-', '_', $key) . ' ' . $type . ',' . PHP_EOL;
    }
    if (strlen($innerSql) > 0) {
      // Remove the last comma and PHP_EOL from $innerSql
      $innerSql = substr($innerSql, 0, -2);
    }
    $table = $db->prefix . 'userflat';

    // Check if our table already exists
    if ($db->get_var("SHOW TABLES LIKE '$table'") != $table) {
      $db->query("CREATE TABLE $table (
          userid bigint NOT NULL,
          changehash char(32) NOT NULL,
          $innerSql
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
      ");
      $db->query("ALTER TABLE $table ADD PRIMARY KEY (userid)");
    } else {
      // BIG TODO, make a delta remove and add fields as needed

    }
  }

  /**
   * @return void
   */
  protected function syncUserFlatTableData(&$raw)
  {
    // TODO do this nicely with changehashing
    // Just simply add all raw inefficiently for testing
    // BEWARE works only once! FOR TESTING
    $db = WordPress::getDb();

    $arrayKeys = array_map(function ($key) {
      return str_replace('-', '_', $key);
    }, array_keys($raw[0]));
    // Remove the keys userid and profile_categories
    $arrayKeys = array_diff($arrayKeys, array('userid', 'profile_categories'));

    $sqlTemplate = '
      INSERT INTO ' . $db->prefix . 'userflat (userid, changehash, ' . implode(', ', $arrayKeys) . ')
      VALUES ({values})
    ';

    foreach ($raw as $row) {
      // Add the values to our sql template
      $values = array($row['userid'], "'" . md5(json_encode($row)) . "'");
      foreach ($row as $key => $value) {
        // also skip userid and profile_categories
        if ($key == 'userid' || $key == 'profile-categories') {
          continue;
        }
        $values[] = $db->prepare('%s', $value);
      }

      // Replace empty strings in values with NULL
      foreach ($values as $key => $value) {
        if ($value == "''") {
          $values[$key] = 'NULL';
        }
      }
      $sql = str_replace('{values}', implode(', ', $values), $sqlTemplate);
      $db->query($sql);
    }
  }

  /**
   * Some performance comparisons
   * @return void
   */
  public function runUserFlatPerformanceCompare()
  {
    // Allow more RAM and time for this
    ini_set('memory_limit', '2048M');
    set_time_limit(1800);
    // Get raw data we need to sync down
    $timeToGetCached = microtime(true);
    $raw = $this->getContactsByCategory(-1, true);
    $timeToGetCached = microtime(true) - $timeToGetCached;
    var_dump('time to solely get cached data array: ' . $timeToGetCached);
    var_dump('-----');

    $db = WordPress::getDb();
    $sql = 'SELECT * FROM ' . $db->prefix . 'userflat WHERE LENGTH(email) > 0';
    $timeToGetFlat = microtime(true);
    $rows = $db->get_results($sql, ARRAY_A);
    $timeToGetFlat = microtime(true) - $timeToGetFlat;
    var_dump('time to get all users with email (' . count($rows) . ', db): ' . $timeToGetFlat);

    // Do the same comparison with cached data
    $timeToGetCachedPlus = microtime(true);
    // Get a diff arraw of $raw with only records that have en email in them
    $cachedData = array_filter($raw, function($row){
      return strlen($row['email']) > 0;
    });
    $timeToGetCachedPlus = microtime(true) - $timeToGetCachedPlus;
    var_dump('time to get all users with email (' . count($cachedData) . ', cache): ' . $timeToGetCached+$timeToGetCachedPlus);
    var_dump('-----');


    $sql = 'SELECT * FROM ' . $db->prefix . 'userflat WHERE LENGTH(email) > 0 AND plz >= 8000 AND plz < 9000 ORDER BY plz ASC';
    $timeToGetFlat = microtime(true);
    $rows = $db->get_results($sql, ARRAY_A);
    $timeToGetFlat = microtime(true) - $timeToGetFlat;
    var_dump('time to get users with email and plz between 8000-8999, sorted (' . count($rows) . ', db): ' . $timeToGetFlat);

    // Do the same comparison with cached data
    $timeToGetCachedPlus = microtime(true);
    // Get a diff arraw of $raw with only records that have en email in them
    $cachedData = array_filter($raw, function($row){
      return strlen($row['email']) > 0 && $row['plz'] >= 8000 && $row['plz'] < 9000;
    });
    // Sort them ascending by the plz field
    usort($cachedData, function($a, $b){
      return $a['plz'] - $b['plz'];
    });
    $timeToGetCachedPlus = microtime(true) - $timeToGetCachedPlus;
    var_dump('time to get all users with email and plz between 8000-8999, sorted (' . count($cachedData) . ', cache): ' . $timeToGetCached+$timeToGetCachedPlus);
    var_dump('-----');

    $sql = 'SELECT * FROM ' . $db->prefix . 'userflat ORDER BY firstname ASC';
    $timeToGetFlat = microtime(true);
    $rows = $db->get_results($sql, ARRAY_A);
    $timeToGetFlat = microtime(true) - $timeToGetFlat;
    var_dump('time to get all users sorted by firstname (' . count($rows) . ', db): ' . $timeToGetFlat);

    // Do the same comparison with cached data
    $timeToGetCachedPlus = microtime(true);
    // Sort them ascending by the firstname field
    usort($raw, function($a, $b){
      return strcmp($a['firstname'], $b['firstname']);
    });
    $timeToGetCachedPlus = microtime(true) - $timeToGetCachedPlus;
    var_dump('time to get all users sorted by firstname (' . count($raw) . ', cache): ' . $timeToGetCached+$timeToGetCachedPlus);
    var_dump('-----');

    // paged select of page 10, 20 items
    $sql = 'SELECT * FROM ' . $db->prefix . 'userflat LIMIT 100,20';
    $timeToGetFlat = microtime(true);
    $rows = $db->get_results($sql, ARRAY_A);
    $timeToGetFlat = microtime(true) - $timeToGetFlat;
    var_dump('time to get 20 users from paging, page 10 (' . count($rows) . ', db): ' . $timeToGetFlat);

    // Do the same comparison with cached data
    $timeToGetCachedPlus = microtime(true);
    // From $raw get the 20 users from paging, page 10
    $cachedData = array_slice($raw, 100, 20);
    $timeToGetCachedPlus = microtime(true) - $timeToGetCachedPlus;
    var_dump('time to get 20 users from paging, page 10 (' . count($rows) . ', cache): ' . $timeToGetCached+$timeToGetCachedPlus);
  }

  /**
   * @return bool tells if there is an instance
   */
  public static function hasInstance()
  {
    return self::$instance !== null;
  }

  /**
   * @return Core provides access to the component instance if active
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param bool $doUnsub initially true, set to false if the core unsubscribe should not happen
   * @param string $recordId md5 of the unsubscribing email address
   * @param int $listId the list, the user wants to unsubscribe from
   * @return bool $doUnsub
   */
  public function handleCrmFieldListUnsubscribe($doUnsub, $recordId, $listId)
  {
    // Only do custom unsubscribe with CRM checkbox fields, if config for that is given for the specific list
    if (isset($this->configuration['crmListUnsubscribe']) && isset($this->configuration['crmListUnsubscribe'][$listId])) {
      // Get the full list to see which record (userid) we need to update
      $lms = LocalMailService::getInstance();
      $list = $lms->getListData($listId);
      // See if the record exists
      if (isset($list[$recordId])) {
        // Get user id and delete the according crm field
        $field = $this->configuration['crmListUnsubscribe'][$listId];
        $userId = intval($list[$recordId]['userid']);
        if (strlen($field) > 0 && $userId > 0) {
          $time = current_time('timestamp');
          delete_user_meta($userId, $field);
          update_user_meta($userId, $field . '-changed', $time);
          update_user_meta($userId, $field . '-optout', $time);
          // Do not unsubscribe via core functions
          $doUnsub = false;
        }
      }
    }

    return $doUnsub;
  }

  /**
   * @param $data
   * @return void
   */
  public function handleCrmFieldOneclickSubscribe($data)
  {
    $listId = intval($data['listId']);
    // Use the unsub config to actually to the reverse: a subscription on a checkbox
    if ($listId > 0 && isset($this->configuration['crmListUnsubscribe']) && isset($this->configuration['crmListUnsubscribe'][$listId])) {
      // Directly return an array with only the necessary data, so we don't overtransmit data on the url
      return array(
        'type' => 'crm',
        'user' => isset($data['userid']) ? $data['userid'] : $data['id'],
        'field' => str_replace('crmcf-', '', $this->configuration['crmListUnsubscribe'][$listId])
      );
    }

    return $data;
  }

  /**
   * Add superglobals for new user creation to work properly
   */
  protected function handleNewUserCreateUI()
  {
    if (empty($_POST['email']) && isset($this->configuration['newUserUI']) && $this->configuration['newUserUI']['unrequireEmail']) {
      // Generate internal email as not required and we need one
      $_POST['email'] = 'it+crmuser.' . current_time('timestamp') . '@comotive.ch';
    }
    if (empty($_POST['user_login']) && isset($this->configuration['newUserUI']) && $this->configuration['newUserUI']['unrequireLogin']) {
      // Generate internal email as not required and we need one
      $_POST['user_login'] = str_replace(array('it-', 'comotivech'), '', Strings::forceSlugString($_POST['email']));
    }
    // Add wp_redirect fix, so we can redirect directly to the new user
    if (
      isset($this->configuration['newUserUI']) && $this->configuration['newUserUI']['redirectAfterCreate'] &&
      isset($_POST['_wpnonce_create-user']) && $_POST['action'] == 'createuser'
    ) {
      add_filter('wp_redirect', function ($url) {
        $tag = 'users.php?update=add&id=';
        if (Strings::startsWith($url, $tag)) {
          $id = intval(str_replace($tag, '', $url));
          if ($id > 0) {
            $url = '/wp-admin/user-edit.php?user_id=' . $id;
          }
        }

        return $url;
      });
    }
  }

  /**
   * Sends monitoring email when emails sendings are potentially hanging too long
   */
  public function checkForHangingSendings()
  {
    $threshold = Date::getTime(Date::SQL_DATETIME, current_time('timestamp') - 2400);
    $db = WordPress::getDb();
    $count = intval($db->get_var('
      SELECT COUNT(pid) FROM ' . $db->prefix . 'lbwp_data
      WHERE row_key LIKE "localmail_stats_%"
      AND row_data LIKE \'%"sent":0,"opens"%\'
      AND row_modified < "' . $threshold . '"
    '));

    if ($count > 0) {
      $mail = External::PhpMailer();
      $mail->Subject = '[' . LBWP_HOST . '] ' . $count . ' unsent mails are still queued';
      $mail->Body = '<a href="https://' . LBWP_HOST . '/wp-admin/>' . $count . 'Mails im Backend überprüfen</a>';
      $mail->send();
    }
  }

  /**
   * This will add the form to crm action
   * @param array $actions list of current actions
   * @return array altered $actions array with new actions
   */
  public function addCrmFormAction($actions)
  {
    // Add the two actions and return
    $actions['form-to-crm'] = '\LBWP\Module\Forms\Action\Crm\WriteData';
    $actions['write-crm-contact'] = '\LBWP\Module\Forms\Action\Crm\WriteContact';
    // Also, add the crm instance statically to the action
    WriteData::setCrmComponent($this);
    WriteContact::setCrmComponent($this);
    return $actions;
  }

  /**
   * Checks if the logged in crm role can access the backend/profile at all
   */
  public function checkBackendAccess()
  {
    if (defined('DOING_AJAX') && DOING_AJAX) {
      return true;
    }

    // Special case for full admins and people that have write access
    if (current_user_can('administrator') || in_array('shop_manager', wp_get_current_user()->roles)) {
      return true;
    }

    if (isset($this->configuration['disallowedBackendRoles']) && is_array($this->configuration['disallowedBackendRoles'])) {
      foreach ($this->configuration['disallowedBackendRoles'] as $disallowedRole) {
        // If disallowed, go to the home site on frontend
        if (current_user_can($disallowedRole)) {
          header('Location: ' . get_bloginfo('url'), null, 307);
          exit;
        }
      }
    }
  }

  /**
   * @param bool $prevent
   * @return mixed
   */
  public function allowShopCrmGroups($prevent)
  {
    if ($this->currentIsMember() || $this->currentIsSubAccount()) {
      return false;
    }
    return $prevent;
  }

  /**
   * @param int $userId the newly created user
   */
  public function maybeAddProfileCategories($userId)
  {
    // If there is only one category and setting is active, set this one category
    if (isset($this->configuration['misc']['autoAddProfileCategory']) && $this->configuration['misc']['autoAddProfileCategory']) {
      if (isset($this->configuration['misc']['autoAddProfileCategoryId'])) {
        update_user_meta($userId, 'profile-categories', array($this->configuration['misc']['autoAddProfileCategoryId']));
      } else {
        // Set category automatically if there is only one
        $categories = $this->getSelectableProfileCategories();
        if (count($categories) == 1) {
          update_user_meta($userId, 'profile-categories', array_keys($categories));
        }
      }
    }
  }

  /**
   * @param int $userId
   */
  public function maybeAddDefaultContact($userId)
  {
    if (isset($this->configuration['newUserUI']['createDefaultContact'])) {
      $contacts = array(array(
        'salutation' => $this->configuration['newUserUI']['createDefaultContact']['salutation'],
        'firstname' => $_POST['first_name'],
        'lastname' => $_POST['last_name'],
        'email' => stristr($_POST['email'], 'it+crmuser') === false ? $_POST['email'] : ''
      ));
      // Save into the according group
      update_user_meta($userId, 'crm-contacts-' . $this->configuration['newUserUI']['createDefaultContact']['contactId'], $contacts);
    }
  }

  /**
   * When a member profile is saved / updated
   */
  public function onMemberProfileUpdate()
  {
    if (wp_verify_nonce($_POST['_wp_nonce_crm_data'], 'save') === false) {
      return;
    }
    // Save custom fields and contact data
    $this->saveCustomFieldData();
    $this->saveContactData();
    $this->saveUserLogins();
    // If configured, override user email with main contact
    if (isset($this->configuration['mainContactMap'])) {
      $this->syncMainContactEmail();
    }
    // If configured, merge a specified custom field into the display_name
    if (isset($this->configuration['misc']['syncDisplayNameField'])) {
      $this->syncDisplayName();
    }

    // Let developers add their own shit
    do_action('after_crm_member_save', $this->editedUser);
    // At last, make sure to flush user cache, as we may do database edits
    clean_user_cache($this->editedUserId);
  }

  /**
   * Sets the current edit user id
   */
  protected function setEditedUserId()
  {
    $this->editedUserId = intval($_REQUEST['user_id']);
    if ($this->editedUserId == 0) {
      $this->editedUserId = intval(get_current_user_id());
    }
    $this->editedUser = get_user_by('id', $this->editedUserId);
    if ($this->editedUser->ID > 0) {
      $this->editedUser->profileCategories = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, 'profile-categories', true));
    }
  }

  /**
   * @return bool
   */
  protected function getSubAccountConfig()
  {
    return
      isset($this->configuration['allowSubAccounts'][$this->editedUser->roles[0]])
        ? $this->configuration['allowSubAccounts'][$this->editedUser->roles[0]]
        : false;
  }

  /**
   * @return bool
   */
  protected function canManageSubAccounts()
  {
    $subconfig = $this->getSubAccountConfig();
    return (
      is_array($subconfig) &&
      $subconfig['active'] === true &&
      ArrayManipulation::anyValueMatch($this->editedUser->profileCategories, $subconfig['categories']));
  }

  /**
   * @param int $userId the user that will be deleted
   */
  public function forceSubAccountDelete($userId)
  {
    $user = get_user_by('id', $userId);

    // Only continue if the deleted user can attach subaccounts
    if (isset($this->configuration['allowSubAccounts'][$user->roles[0]])) {
      // Delete all users where this user is the main account of
      $db = WordPress::getDb();
      $sql = 'SELECT user_id FROM {sql:userMeta} WHERE meta_key = "crm-main-account-id" AND meta_value = {mainUserId}';
      $users = $db->get_col(Strings::prepareSql($sql, array(
        'userMeta' => $db->usermeta,
        'mainUserId' => $user->ID
      )));
      // Delete all attached users
      foreach ($users as $deleteUserId) {
        wp_delete_user($deleteUserId, 0);
      }
    }
  }

  /**
   * Add the tab containers as of config
   */
  public function addTabContainers()
  {
    // See if we need to add the user login tab
    if ($this->canManageSubAccounts()) {
      $this->configuration['tabs']['subaccounts'] = __('Loginverwaltung', 'lbwp');
    }
    if (isset($this->configuration['misc']['customerStats']) && $this->configuration['misc']['customerStats']) {
      $this->configuration['tabs']['wc-stats'] = __('Statistik', 'lbwp');
    }
    if (isset($this->configuration['misc']['segmentListing']) && $this->configuration['misc']['segmentListing']) {
      $this->configuration['tabs']['segment-list'] = __('Versandlisten', 'lbwp');
    }

    $html = '<nav class="nav-tab-wrapper crm-navigation wp-clearfix">';
    // Open all the corresponding tabs and the navigation
    $activeClass = false;
    foreach ($this->configuration['tabs'] as $key => $name) {
      $classes = 'nav-tab crm-tab';
      if (!$activeClass) {
        $classes .= ' nav-tab-active';
        $activeClass = true;
      }
      // Add this to the navigation
      $html .= '<a href="javascript:void(0)" class="' . $classes . '" data-tab="' . $key . '">' . $name . '</a>';
    }
    // Close navigation
    $html .= '</nav>';

    // Print empty containers to be filled with JS
    foreach ($this->configuration['tabs'] as $key => $name) {
      $html .= '<div class="tab-container container-' . $key . '" data-tab-id="' . $key . '">'
       . apply_filters('lbwp_crm_tab_' . $key . '_content', '', $this->userAdminData) .
      '</div>';
    }

    // Allow changes or additional scripts from outside
    $html = apply_filters('lbwp_crm_add_tab_containers_html', $html, $this->userAdminData);

    echo $html;
  }

  /**
   * Adds the output for custom user fields to be tabbed by JS
   */
  public function addCustomUserFields()
  {
    // Add disabling checkbox and profile categories view or edit field
    if (apply_filters('lbwp_crm_allow_member_disable', $this->userAdminData['userIsAdmin'])) {
      echo $this->getDisableMemberEditor();
    }
    if (apply_filters('lbwp_crm_allow_change_automation_optout', $this->userAdminData['userIsAdmin'] && isset($this->configuration['misc']['disabledMarketingOptin']))) {
      echo $this->getAutomationOptoutEditor();
    }
    // Show sub account editor if managing them is allowed
    if ($this->canManageSubAccounts()) {
      echo $this->getSubAccountEditor();
    }
    if (isset($this->configuration['misc']['customerStats']) && $this->configuration['misc']['customerStats']) {
      echo $this->getStatisticsTab();
    }
    if (isset($this->configuration['misc']['segmentListing']) && is_array($this->configuration['misc']['segmentListing'])) {
      echo $this->getSegmentListTab();
    }
    echo $this->getProfileCategoriesEditor();

    // Get all Custom fields to print their html
    $customFields = $this->getCustomFields($this->editedUser->profileCategories);
    // Print the fields
    foreach ($customFields as $field) {
      $title = $field['title'];
      // If there are versions, add the version to the title
      if ($field['history-active'] && count($field['versions']) > 0) {
        $version = array_values(array_slice($field['versions'], -1))[0];
        $title .= ' ' . $version;
        $field['title'] .= ' ' . $version;
      }
      // If checkbox, do not show the checkbox name as title (its shown in a label)
      if ($field['type'] == 'checkbox') {
        $title = '';
      }

      $changed = '';
      if ($field['track-changes']) {
        $lastChange = intval(get_user_meta($this->editedUserId, 'crmcf-' . $field['id'] . '-changed', true));
        if ($lastChange > 0) {
          $changed = 'Geändert am ' . Date::getTime('d.m.Y, H:i', $lastChange);
        }
      }

      echo Templating::getBlock($this->fieldTemplate, array(
        '{fieldName}' => $field['id'],
        '{fieldLabel}' => $title,
        '{tabName}' => $field['tab'],
        '{fieldDescription}' => $field['description'],
        '{fieldChangedDate}' => $changed,
        '{fieldRequired}' => ($field['required']) ? ' <span class="required">*</span>' : '',
        '{fieldContent}' => $this->getCustomFieldContent($field),
      ));
    }

    // Add the contact UIs
    echo $this->getProfileContactsEditor();
    // Print an nonce field for saving
    wp_nonce_field('save', '_wp_nonce_crm_data');
  }

  /**
   * @param string $title
   * @param \WP_Post $item
   * @return string
   */
  public function extendSortableFieldTitle($title, $item)
  {
    $tabId = get_post_meta($item->ID, 'tab', true);
    return '<strong>' . $this->configuration['tabs'][$tabId] . '</strong>: ' . $title;
  }

  /**
   * Shows some user statistics
   * @return string
   */
  protected function getStatisticsTab()
  {
    date_default_timezone_set('Europe/Zurich');

    // Get all orders
    $orders = wc_get_orders(array(
      'meta_key' => '_customer_user',
      'meta_value' => $this->editedUser->ID,
      'numberposts' => -1
    ));

    // Setup tables
    $tableHtml = '';
    $tables = array(
      'Bestellung' => array(
        'title' => '<h4>Kauf-Historie</h4>',
        'items' => $orders,
        'emptyText' => '<p>Dieser Benutzer hat noch keine Käufe</p>',
        'completed' => array('completed', 'processing'),
        'statuses' => wc_get_order_statuses()
      )
    );

    // Only add subscription table if subscriptions are active
    if (class_exists('\WC_Subscriptions')) {
      $tables['Abonnement'] = array(
        'title' => '<h4>Aktive Abonnemente</h4>',
        'items' => wcs_get_users_subscriptions($this->editedUser->ID),
        'emptyText' => '<p>Dieser Benutzer hat noch keine Abos</p>',
        'completed' => array('active', 'on-hold'),
        'statuses' => wcs_get_subscription_statuses()
      );
    }

    foreach ($tables as $tableType => $table) {
      $tableContent = '';
      $total = 0;

      if (empty($table['items'])) {
        $tableHtml .= $table['title'] . $table['emptyText'];
        continue;
      }

      foreach ($table['items'] as $item) {
        $isActive = in_array($item->get_status(), $table['completed']);

        $productNames = '';

        foreach ($item->get_items() as $product) {
          $productNames .= $product->get_name() . '<br>';
        }

        $tableContent .= '
          <tr>
            <td><a href="' . get_edit_post_link($item->get_id()) . '">#' . $item->get_id() . '</a></td>
            <td>' . $productNames . '</td>
            <td>' . $table['statuses']['wc-' . $item->get_status()] . '</td>
            <td>' . date('d.m.Y - H:i', strtotime($item->get_date_created())) . '</td>
            <td>' . $item->get_total() . '</td>
          </tr>';

        if ($isActive) {
          $total += $item->get_total();
        }
      }

      $tableHtml .= $table['title'] . '
      <table class="wp-list-table widefat striped">
        <thead>
          <tr>
            <th>' . $tableType . ' ID</th>
            <th>Produkte</th>
            <th>Status</th>
            <th>Kaufdatum</th>
            <th>Preis</th>
          </tr>
        </thead>
        <tbody>
          ' . $tableContent . '
        </tbody>
        ' .
        (is_array($table['items']) && count($table['items']) > 1 ?
          '<tfoot>
              <tr>
                <td><b>Total</b></td>          
                <td></td>          
                <td></td>          
                <td></td>          
                <td>' . number_format($total, 2) . '</td>          
              </tr>
            </tfoot>'
          : ''
        ) .
        '</table>';
    }

    return '<div data-target-tab="wc-stats">' . $tableHtml . '</div>';
  }

  /**
   * Get the segment list tab
   * @return string
   */
  protected function getSegmentListTab(){
    $tableContent = '';

    $userId = $this->editedUser->ID;
    $lms = LocalMailService::getInstance();
    $lists = $lms->getLists();
    $setting = $this->configuration['misc']['segmentListing'];
    $statuses = ArrayManipulation::forceArray(wp_cache_get('lbwp-mailing-lists-statuses_' . $userId, 'Crm'));

    // Get search key from contact and specific row/key or a custom field
    if (isset($_GET['segment-list-check'])) {
      if (Strings::contains($setting['search'], 'crm-contacts')) {
        list($field,$index,$key) = explode(':', $setting['search']);
        $uMeta = get_user_meta($this->editedUser->ID, $field, true);
        $uMeta = $uMeta[$index][$key];
      } else {
        $uMeta = get_user_meta($this->editedUser->ID, $setting['search'], true);
      }
      $listId = intval($_GET['segment-list-check']);
      $data = $lms->getListData($listId);
      $searchFields = array_column($data, $setting['column']);
      $inList = !empty($uMeta) && in_array($uMeta, $searchFields);
      $statuses[$listId] = $inList;
      wp_cache_set('lbwp-mailing-lists-statuses_' . $userId, $statuses, 'Crm', 40000);
    }

    foreach($lists as $listId => $list){
      if (isset($statuses[$listId])) {
        $statusHtml = $statuses[$listId] ? '<span class="dashicons dashicons-yes" style="color: #39a014"></span>' : '<span class="dashicons dashicons-no" style="color: #c24747"></span>';
      } else {
        $statusHtml = '<a href="/wp-admin/user-edit.php?user_id=' . $userId . '&segment-list-check=' . $listId . '" class="load-list-info" title="Status laden"><span class="dashicons dashicons-info-outline"></span></a>';
      }

      $tableContent .= '<tr>
        <td>' . $statusHtml . '</td>
        <td><a href="/wp-admin/post.php?post=' . $listId .'&action=edit">' . $list . '</a></td>
      </tr>';
    }

    $tableHtml = '
      <p><a href="javascript:void(0);" class="button button-secondary load-all-status">Alle Status laden (ca. 2-3 Minuten)</a> </p>
      <table class="wp-list-table widefat striped">
        <thead>
          <tr>
            <th style="width: 50px;">' . __('Status', 'lbwp') . '</th>
            <th>' . __('Liste', 'lbwp') . '</th>
          </tr>
        </thead>
        <tbody>
        ' . $tableContent . '
        </tbody>
      </table>
    ';

    // Script to load the status of the lists
    $script = '
      <script>
        jQuery(document).ready(function(){
          jQuery(".load-all-status").on("click", function() {
            jQuery(this).text("Lade Status aller Listen, bitte warten, die Seite ladet neu, wenn alle Daten geladen sind");
            // Get all links to update statuses from the list
            let urlList = [];
            let timeoutTotal = 0;
            jQuery(".load-list-info").each(function(){
              urlList.push(jQuery(this).attr("href"));
            });
            // Call the urls each with 3s more timeout
            urlList.forEach(function ()  {
              setTimeout(function(){
                jQuery.ajax({
                  url: urlList.shift()
                });
              }, timeoutTotal+=1500);
            });
            
            // Reload the page after timeoutTotal + 10 seconds
            setTimeout(function(){
              location.reload();
            }, timeoutTotal + 10000);
          });
        });
      </script>
    ';

    return '<div data-target-tab="segment-list">' . $tableHtml . $script . '</div>';
  }

  /**
   * Empty the mailing list cache
   * @return void
   */
  public function invalidateListCache($postId)
  {
    $lms = LocalMailService::getInstance();
    wp_cache_set('lbwp-mailing-lists-' . $postId, $lms->getListData($postId), '', 2592000);
  }

  /**
   * @return string the sub account editor html
   */
  protected function getSubAccountEditor()
  {
    $subconfig = $this->getSubAccountConfig();
    $html = '<p>' . __('Sie können für weitere Personen ein Login erstellen und diesen Personen Rechte zuweisen. Diese haben keinen Zugriff auf die Profildaten, sondern nur auf die eingestellten Teilbereiche.', 'lbwp') . '</p>';

    // Get all users that are currently assigned
    $users = $this->getSubAccountUsers($this->editedUser->ID);

    // Print the table of the users
    $html .= '
      <table class="widefat subaccount-table">
        <thead>
          <tr>
            <th class="th-email">E-Mail-Adresse</th>
            <th class="th-firstname">Vorname</th>
            <th class="th-lastname">Nachname</th>
            <th class="th-password">Passwort ändern</th>
            <th class="th-capabilities">Rechte ändern</th>
            <th class="th-buttons">&nbsp;</th>
          </tr>
        </thead>
        <tbody>
    ';

    // Display users or a message
    if (count($users) > 0) {
      foreach ($users as $user) {
        // Build capabilities
        $capabilities = ArrayManipulation::forceArray(get_user_meta($user->ID, 'crm-capabilities', true));
        $capHtml = '';
        foreach ($subconfig['capabilities'] as $key => $name) {
          $checked = checked(true, in_array($key, $capabilities), false);
          $capHtml .= '
            <li>
              <label><input type="checkbox" name="subaccs[' . $user->ID . '][capabilities][]" value="' . $key . '" ' . $checked . '>' . $name . '</label>
            </li>
          ';
        }
        // Build the according html
        $html .= '
          <tr>
            <td>
              <input type="hidden" name="subaccs[' . $user->ID . ']" value="' . $user->ID . '">
              <input type="hidden" name="subaccs[' . $user->ID . '][delete]" value="0" class="delete-subacc-tick">
              <input type="text" name="subaccs[' . $user->ID . '][email]" value="' . $user->user_email . '">
            </td>
            <td><input type="text" name="subaccs[' . $user->ID . '][firstname]" value="' . $user->get('first_name') . '"></td>
            <td><input type="text" name="subaccs[' . $user->ID . '][lastname]" value="' . $user->get('last_name') . '"></td>
            <td>
              <input type="password" name="subaccs[' . $user->ID . '][password]" value="" style="display:none">
              <a href="javascript:void(0)" class="crm-show-prev crm-toggle-remove">' . __('Passwort ändern', 'lbwp') . '</a>
            </td>
            <td>
              <ul class="capabilities-selector" style="display:none">' . $capHtml . '</ul>
              <a href="javascript:void(0)" class="crm-show-prev crm-toggle-remove">' . __('Rechte ändern', 'lbwp') . '</a>
            </td>
            <td><a href="javascript:void(0);" class="dashicons dashicons-trash delete-subaccount"></a></td>
          </tr>
        ';
      }
    } else {
      $html .= '
        <tr>
          <td colspan="6">' . __('Sie haben bisher noch keine weiteren Logins erfasst', 'lbwp') . '</td>
        </tr>
      ';
    }

    // Close the table
    $html .= '</tbody></table>';

    // Provide UI to create a new user
    $html .= '
      <div class="crm-new-user-forms">
        <input type="text" name="subaccount[email]" placeholder="' . __('E-MailAdresse', 'lbwp') . '" />
        <input type="text" name="subaccount[firstname]" placeholder="' . __('Vorname', 'lbwp') . '" />
        <input type="text" name="subaccount[lastname]" placeholder="' . __('Nachname', 'lbwp') . '" />
        <input type="password" name="subaccount[password]" placeholder="' . __('Passwort eingeben', 'lbwp') . '" />
        <a href="javascript:void(0)" class="button crm-add-user-button" data-state="closed" data-save="' . __('Login speichern', 'lbwp') . '">' . __('Login hinzufügen', 'lbwp') . '</a>
      </div>
    ';

    return '<div data-target-tab="subaccounts">' . $html . '</div>';
  }

  /**
   * @return array the configuration
   */
  public function getConfiguration()
  {
    return $this->configuration;
  }

  /**
   * @param array $field
   * @param string $key
   * @param bool $forceReadonly
   * @param bool $forceDisabled
   * @return string
   */
  protected function getCustomFieldContent($field, $key = '', $forceReadonly = false, $forceDisabled = false, $asHistory = false)
  {
    // Get the current field content, if given
    $html = '';
    if (strlen($key) == 0) $key = 'crmcf-' . $field['id'];
    $value = get_user_meta($this->editedUserId, $key, true);

    // Define attributes for the input field
    $classes = 'crmcf-input regular-text';
    $attr = 'id="' . $key . '" name="' . $key . '" data-field-key="' . $key . '"';
    if ($field['required'])
      $attr .= ' required="required"';
    if (
      ($field['readonly'] && $this->userAdminData['userIsMember']) ||
      ($field['admin-readonly'] && $this->userAdminData['userIsAdmin']) ||
      $forceReadonly
    )
      $attr .= ' readonly="readonly"';
    if ($forceDisabled)
      $attr .= ' disabled="disabled"';
    if ($field['history-active'])
      $attr .= ' data-history="1"';

    // Add visual classes for admins
    if ($this->userAdminData['userIsAdmin']) {
      if ($field['readonly'])
        $classes .= ' crmcf-visualize-readonly';
      if ($field['invisible'])
        $classes .= ' crmcf-visualize-disabled';
    }

    // Add the actual classes to the attributes
    $attr .= ' class="' . $classes . ' type-' . $field['type'] . '"';
    // Set a helping readonly flag that's only active if really readonly
    $readonly = ($field['readonly'] && $this->userAdminData['userIsMember']) || $forceReadonly;

    // Display html for the field
    switch ($field['type']) {
      case 'textfield':
        $html .= '<input type="text" ' . $attr . ' value="' . esc_attr($value) . '" />';
        break;
      case 'datefield':
        $html .= '<input type="text" ' . $attr . ' data-max-days="' . $field['max-future-days'] . '" value="' . esc_attr($value) . '" />';
        break;
      case 'textarea':
        $html .= '<textarea ' . $attr . '>' . $value . '</textarea>';
        break;
      case 'checkbox':
        $checked = checked($value, 1, false);
        $html .= '
          <label>
            <input type="checkbox" ' . $attr . ' value="1" ' . $checked . ' />
            ' . $field['title'] . '
          </label>
        ';
        break;
      case 'checkbox-multi':
        $multiAttr = str_replace($key . '"', $key . '[]"', $attr);
        foreach ($field['field-values'] as $option) {
          $checkValue = str_replace('--', '-', Strings::forceSlugString(trim($option)));
          $value = is_array($value) ? $value : array();
          $checked = checked(true, in_array($checkValue, $value), false);
          $html .= '<label><input type="checkbox" ' . $multiAttr . ' value="' . esc_attr($checkValue) . '" ' . $checked . ' /> ' . $option . '</label><br />';
        }
        break;
      case 'dropdown':
        $html .= '<select ' . $attr . '>';
        foreach ($field['field-values'] as $option) {
          $selected = selected($value, $option, false);
          $html .= '<option value="' . $option . '" ' . $selected . '>' . $option . '</option>';
        }
        $html .= '</select>';
        break;
      case 'table':
        $html .= $this->getCustomFieldTableHtml($field, $key, $value, $readonly, $forceDisabled);
        break;
      case 'file':
        // Display upload field only if not readonly
        if (!$readonly && strlen($value) == 0) {
          $html .= '<input type="file" ' . $attr . ' />';
        }
        // Display the download link, if the file available
        if (strlen($value) > 0) {
          $url = '/wp-file-proxy.php?type=inline&key=' . $value;
          if ($this->isCrmFieldUploadPublic()) {
            $url = 'https://' . LBWP_HOST . '/assets/' . CDN_BUCKET_NAME . '/' . ASSET_KEY . '/files/' . $value;
          }
          $html .= '
            <p>
              <span>' . sprintf(__('Datei &laquo;%s&raquo; herunterladen.', 'lbwp'), '<a href="' . $url . '" target="_blank">' . File::getFileOnly($value) . '</a>') . '</span>
              <input type="hidden" name="crmcf-' . $field['id'] . '-remove" value="0" ' . $attr . ' />
              <a href="javascript:void(0);" class="dashicons dashicons-trash delete-crm-upload-file"></a>
            </p>
          ';
        }
        if (strlen($html) == 0) {
          $html .= __('Es wurde noch keine Datei hochgeladen.', 'lbwp');
        }
        // Special output for history (read only in any case)
        if ($asHistory) {
          if (strlen($value) > 0) {
            $html = '<a href="/wp-file-proxy.php?type=inline&key=' . $value . '" target="_blank">' . File::getFileOnly($value) . '</a>';
          } else {
            $html = '<p>' . __('Keine Datei hochgeladen.', 'lbwp') . '</p>';
          }
          $html = '<label class="history-type-file">' . $html . '</label>';
        }
        break;
    }

    return $html;
  }

  /**
   * @param array $field
   * @param string $key
   * @param bool $forceReadonly
   * @param bool $forceDisabled
   * @return string
   */
  protected function getCustomFieldTableHtml($field, $key, $value, $forceReadonly, $forceDisabled)
  {
    $html = '<table class="crmcf-table" data-key="' . $key . '" data-readonly="' . ($forceReadonly ? 1 : 0) . '" data-disabled="' . ($forceDisabled ? 1 : 0) . '">';

    // Get the field configuration of that table and print the columns
    $fields = $this->getTableColumnConfiguration($field);
    $html .= '<thead><tr>';
    foreach ($fields as $slug => $name) {
      $html .= '<td class="crmcf-head" data-slug="' . $slug . '">' . $name . '</td>';
    }
    $button = (!$forceReadonly) ? '<span class="dashicons dashicons-plus add-crmcf-row"></span>' : '';
    $delete = (!$forceReadonly) ? '<span class="dashicons dashicons-trash delete-crmcf-row"></span>' : '';
    $html .= '<td class="crmcf-head">' . $button . '</td>';
    $html .= '</tr></thead><tbody>';

    // Print the current data if given
    if (is_array($value)) {
      reset($value);
      $first = key($value);
      for ($i = 0; $i < count($value[$first]); ++$i) {
        $html .= '<tr>';
        foreach ($fields as $slug => $name) {
          $attr = '';
          $attr .= $forceReadonly ? ' readonly="readonly"' : '';
          $attr .= $forceDisabled ? ' disabled="disabled"' : '';
          $html .= '<td><input type="text" name="' . $key . '[' . $slug . '][]" value="' . esc_attr($value[$slug][$i]) . '" ' . $attr . ' /></td>';
        }
        $html .= '<td class="crmcf-head">' . $delete . '</td></tr>';
      }
    }

    $html .= '</tbody></table>';
    return $html;
  }

  /**
   * @param $field
   * @return array
   */
  protected function getTableColumnConfiguration($field)
  {
    $config = array();
    foreach ($field['field-values'] as $colName) {
      $config[Strings::forceSlugString($colName)] = $colName;
    }
    return $config;
  }

  /**
   * Saves the custom user fields with loose validation
   */
  protected function saveCustomFieldData()
  {
    // Save the custom fields as given
    $now = current_time('timestamp');
    $customFields = $this->getCustomFields($this->editedUser->profileCategories);
    do_action('crm_before_save_custom_fields');
    // Print the fields
    foreach ($customFields as $field) {
      $key = 'crmcf-' . $field['id'];
      // Need to be admin or have access to the field
      if ($this->userAdminData['userIsAdmin'] || (!$field['invisible'] && !$field['readonly'])) {
        // Get the previous value for determining a change
        $before = get_user_meta($this->editedUserId, $key, true);
        if ($field['type'] == 'file') {
          // As all files are saved, see after as same, if there is no change
          // otherwise it takes the "after" of the last iteration which breaks largely
          $after = $before;
          $this->saveFileUploadField($key, $field, $after);
        } else {
          $after = $_POST[$key];
          if (isset($_POST[$key])) {
            update_user_meta($this->editedUserId, $key, $after);
          } else {
            delete_user_meta($this->editedUserId, $key);
          }
        }

        // Track changes, when needed
        if ($field['track-changes']) {
          if ($before != $after) {
            update_user_meta($this->editedUserId, $key . '-changed', $now);
          }
        }

        // See if it changed
        $changed = $before !== $after;
        if ($field['type'] == 'table') {
          $after = empty($after) ? '' : $after;
          $changed = json_encode($before) != json_encode($after);
        }

        // If the value changed, track it
        if ($changed) {
          $tab = $this->configuration['tabs'][$field['tab']];
          $this->trackUserDataChange($field['title'], $tab, $before, $after);
          do_action('crm_after_changed_field_' . $field['id'], $field, $this->editedUserId);
        }
      }

      // Only admins can change older versions of fields
      if ($this->userAdminData['userIsAdmin'] && $field['history-active'] && count($field['versions']) > 1) {
        // Get updateable versions (all but the current)
        $updateVersions = array_slice($field['versions'], 0, count($field['versions']) - 1);
        foreach ($updateVersions as $version) {
          if (isset($_POST['update_' . $key . '_' . $version])) {
            if (isset($_POST[$key . '_' . $version])) {
              update_user_meta($this->editedUserId, $key . '_' . $version, $_POST[$key . '_' . $version]);
            } else {
              delete_user_meta($this->editedUserId, $key . '_' . $version);
            }
          }
        }
      }
    }

    // Let developers hook in right here
    do_action('crm_after_save_custom_fields');

    // Only when an admin with full access to fields is here
    if ($this->editingUser->has_cap('administrator')) {
      // Delete "dead" fields that shouldnt be here anymore, start by getting all field keys of the member natively
      $db = WordPress::getDb();
      $allowedKeys = array();
      $fieldKeys = $db->get_col('SELECT meta_key FROM ' . $db->prefix . 'usermeta WHERE meta_key LIKE "crmcf-%" AND user_id = ' . $this->editedUserId);
      foreach ($fieldKeys as $key) {
        foreach ($customFields as $field) {
          $compare = 'crmcf-' . $field['id'];
          if (Strings::contains($key, $compare)) {
            $allowedKeys[] = $key;
          }
        }
      }

      // Diff between the arrays and remove all disallowed fields
      $disallowedFields = array_diff($fieldKeys, $allowedKeys);
      foreach ($disallowedFields as $key) {
        delete_user_meta($this->editedUserId, $key);
      }
    }
  }

  /**
   * @param $key
   * @param $field
   * @param $after
   */
  protected function saveFileUploadField($key, $field, &$after)
  {
    // Upload a newly added file
    if (isset($_FILES[$key]) && $_FILES[$key]['error'] == 0) {
      /** @var S3Upload $upload */
      $upload = LbwpCore::getModule('S3Upload');
      $url = $upload->uploadLocalFile($_FILES[$key], true);
      $file = $upload->getKeyFromUrl($url);
      // But actually save only the file name without asset key
      $after = str_replace(ASSET_KEY . '/files/', '', $file);
      update_user_meta($this->editedUserId, $key, $after);
      // Make file inaccessible if configured (default)
      if (!$this->isCrmFieldUploadPublic()) {
        $upload->setAccessControl($file, S3Upload::ACL_PRIVATE);
      }
    }

    // Remove the existing file of the field if needed
    if ($_POST[$key . '-remove'] == 1) {
      $file = ASSET_KEY . '/files/' . get_user_meta($this->editedUserId, $key, true);
      /** @var S3Upload $uploader */
      $uploader = LbwpCore::getModule('S3Upload');
      $uploader->deleteFile($file);
      // Reset the upload file
      delete_user_meta($this->editedUserId, $key);
      $after = '';
    }
  }

  /**
   * @return bool
   */
  protected function isCrmFieldUploadPublic()
  {
    return isset($this->configuration['misc']['publicFileUploads']) && $this->configuration['misc']['publicFileUploads'];
  }

  /**
   * Saves all the contacts of the profile
   */
  protected function saveContactData()
  {
    // No need to do anything, when there are no concats
    if (!isset($_POST['crm-contact-categories'])) {
      return;
    }

    // Save all given contacts
    $categories = array_map('intval', $_POST['crm-contact-categories']);

    // Go trough each category, validate inputs and save them
    foreach ($categories as $categoryId) {
      $key = 'crm-contacts-' . $categoryId;
      $oldContacts = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, $key, true));
      $newContacts = $this->validateInputContacts($_POST[$key]);
      if (count($newContacts) > 0) {
        update_user_meta($this->editedUserId, $key, $newContacts);
      } else {
        delete_user_meta($this->editedUserId, $key);
      }

      // Now that they are saved, compare differences and track them
      $this->compareContactBlocks($categoryId, $oldContacts, $newContacts);
    }
  }

  /**
   * Saves all user login data, if given
   */
  protected function saveUserLogins()
  {
    // Get the config to do everything correctly
    $db = WordPress::getDb();
    $subconfig = $this->getSubAccountConfig();

    // Check for a new user to be added
    if (isset($_POST['subaccount']) && ArrayManipulation::valuesNonEmptyStrings($_POST['subaccount'])) {
      // Create the new user and get his ID
      $id = wp_insert_user(array(
        'role' => $subconfig['role'],
        'user_pass' => $_POST['subaccount']['password'],
        'user_login' => $_POST['subaccount']['email'],
        'user_email' => $_POST['subaccount']['email'],
        'first_name' => $_POST['subaccount']['firstname'],
        'last_name' => $_POST['subaccount']['lastname']
      ));

      // Associate the actual main user
      if (intval($id) > 0) {
        update_user_meta($id, 'crm-main-account-id', $this->editedUser->ID);
      }
    }

    // Delete or edit users
    if (isset($_POST['subaccs']) && is_array($_POST['subaccs'])) {
      foreach ($_POST['subaccs'] as $userId => $subaccount) {
        if ($subaccount['delete'] == 0) {
          $displayName = $subaccount['firstname'] . ' ' . $subaccount['lastname'];
          // Edit the account manually in DB to prevent endless looping
          $db->update(
            $db->users,
            array(
              'user_login' => $subaccount['email'],
              'user_email' => $subaccount['email'],
              'display_name' => $displayName
            ),
            array(
              'ID' => $userId
            )
          );

          // Set basic meta
          update_user_meta($userId, 'first_name', $subaccount['firstname']);
          update_user_meta($userId, 'last_name', $subaccount['lastname']);
          update_user_meta($userId, 'display_name', $displayName);

          // Set or override capabilities meta
          if (isset($subaccount['capabilities']) && is_array($subaccount['capabilities'])) {
            update_user_meta($userId, 'crm-capabilities', $subaccount['capabilities']);
          }

          // Change the password if requested
          if (strlen($subaccount['password']) > 0) {
            wp_set_password($subaccount['password'], $userId);
          }

          // Clean cache of user as manual DB changes took place
          clean_user_cache($userId);
        } else {
          // Delete the account
          wp_delete_user($userId, 0);
        }
      }
    }
  }

  /**
   * Syncs the user_email field with the email of the roles respective main contact
   */
  protected function syncMainContactEmail()
  {
    $role = $this->editedUser->roles[0];
    $key = 'crm-contacts-' . $this->configuration['mainContactMap'][$role];
    $contacts = get_user_meta($this->editedUserId, $key, true);

    // If there is an email, override the user object
    if (isset($contacts[0]) && Strings::checkEmail($contacts[0]['email'])) {
      $fields = array('user_email' => $contacts[0]['email']);
      if (isset($this->configuration['syncUserCoreEmail']) && $this->configuration['syncUserCoreEmail']) {
        $fields['user_login'] = $contacts[0]['email'];
      }
      // Need to update with DB, as we would create an endless loop with update_user functions
      $db = WordPress::getDb();
      $db->update(
        $db->users,
        $fields,
        array('ID' => $this->editedUserId)
      );
    } else if (isset($this->configuration['syncUserCoreEmailFallback']) && $this->configuration['syncUserCoreEmailFallback']) {
      // If not already a fallback email, override with one
      if (!Strings::contains($this->editedUser->user_email, 'it+crmuser')) {
        $email = 'it+crmuser.' . current_time('timestamp') . '@comotive.ch';
        $login = 'crmuser' . current_time('timestamp');
        $db = WordPress::getDb();
        $db->update(
          $db->users,
          array(
            'user_login' => $login,
            'user_nicename' => $login,
            'user_email' => $email
          ),
          array('ID' => $this->editedUserId)
        );
      }
    }
  }

  /**
   * Sync a custom field with the user->display_name field
   */
  protected function syncDisplayName()
  {
    $key = $this->configuration['misc']['syncDisplayNameField'];

    if (strlen($key) > 0 && in_array($this->editedUser->roles[0], $this->configuration['roles'])) {
      $db = WordPress::getDb();
      $db->update(
        $db->users,
        array('display_name' => get_user_meta($this->editedUserId, $key, true)),
        array('ID' => $this->editedUserId)
      );
    }
    // Try using a contact as source, if needed
    if (strlen($key) > 0 && Strings::startsWith($key, 'crm-contacts-')) {
      list($field, $index) = explode('_', $key);
      $contacts = $_POST[$field];
      if (is_array($contacts) && isset($contacts['firstname'][$index]) && isset($contacts['lastname'][$index])) {
        $db = WordPress::getDb();
        $db->update(
          $db->users,
          array('display_name' => $contacts['firstname'][$index] . ' ' . $contacts['lastname'][$index]),
          array('ID' => $this->editedUserId)
        );
      }
    }
  }

  /**
   * Syncs some core fields to custom fields
   * @param int $userId
   */
  public function syncCoreToCustomFields($userId)
  {
    $user = get_user_by('id', $userId);
    // Skip, if not an actual crm role
    if (!in_array($user->roles[0], $this->configuration['roles'])) {
      return;
    }

    if (isset($this->configuration['syncCoreFields'])) {
      // Map the post keys into the corresponding crm fields
      foreach ($this->configuration['syncCoreFields'] as $key => $field) {
        // Special case, when user_email in core user needs to be synced
        if (($key === 'user_email' || $key === 'user_login' || $key === 'display_name') && $_POST['action'] != 'createuser') {
          $coreValue = $_POST[$field];
          if (Strings::startsWith($field, 'crm-contacts-')) {
            list($postfield, $index, $syncwith) = explode('_', $field);
            if (isset($_POST[$postfield][$syncwith][$index])) {
              $coreValue = $_POST[$postfield][$syncwith][$index];
            }
          }
          $db = WordPress::getDb();
          $db->update(
            $db->users,
            array($key => $coreValue),
            array('ID' => $user->ID)
          );
          clean_user_cache($user->ID);
          continue;
        }

        if (Strings::startsWith($field, 'crm-contacts-')) {
          list($postfield, $index, $syncwith) = explode('_', $field);
          if (isset($_POST[$postfield][$syncwith][$index])) {
            $value = $_POST[$postfield][$syncwith][$index];
          }
        } else {
          $value = is_callable($field) ? call_user_func($field) : $_POST[$field];
        }

        update_user_meta($userId, $key, $value);
      }
    }
  }

  /**
   * @paran int $category the category id
   * @param array $before contacts before
   * @param array $after contacts after save
   */
  protected function compareContactBlocks($category, $before, $after)
  {
    $category = self::getContactCategory($category);
    // Decide which array has more entries
    $c1 = count($before);
    $c2 = count($after);
    $max = ($c1 > $c2) ? $c1 : $c2;
    // Loop trough and compare each contact by stringifying them
    for ($i = 0; $i < $max; $i++) {
      $oldContact = $this->stringifyContact($before[$i]);
      $newContact = $this->stringifyContact($after[$i]);
      // If not the same, track the change (do compare without html)
      if (strip_tags($oldContact) != strip_tags($newContact)) {
        $this->trackUserDataChange($category['title'], __('Kontakte', 'lbwp'), $oldContact, $newContact);
      }
    }
  }

  /**
   * @param array $contact the contact information
   * @return string representation of the contact
   */
  protected function stringifyContact($contact)
  {
    // If the contact is invalid, return an empty string
    if (!is_array($contact) || count($contact) == 0) {
      return '';
    }

    // Translate the salutation if there is
    if (isset($contact['salutation'])) {
      $contact['salutation'] = $this->getSalutationByKey($contact['salutation']);
    }

    return implode('<br />', $contact);
  }

  /**
   * @param string $title the field/content that is being changed
   * @param string $category the category where data was saved
   * @param mixed $before the previous value before the change
   * @param mixed $after the new value after the change
   */
  protected function trackUserDataChange($title, $category, $before, $after)
  {
    $changes = ArrayManipulation::forceArray(get_option('crmLatestUserDataChanges'));

    // Create e new changes array for the user, if not given
    if (!isset($changes[$this->editedUserId])) {
      $changes[$this->editedUserId] = array();
    }

    // Handle tables cheaply as of now
    if (is_array($before) || is_array($after)) {
      $before = '';
      $after = 'Änderungen in Tabellen können<br>im Report nicht dargestellt werden';
    }

    // Add the change to the array
    $changes[$this->editedUserId][] = array(
      'field' => $title,
      'category' => $category,
      'time' => date('H:i', current_time('timestamp')),
      'before' => $before,
      'after' => $after,
      'author' => $this->editingUser->user_email
    );

    // Save back to our changes array
    update_option('crmLatestUserDataChanges', $changes, false);
  }

  /**
   * Send the changes report, if configured to do so
   */
  public function sendTrackedUserChangeReport()
  {
    $changes = ArrayManipulation::forceArray(get_option('crmLatestUserDataChanges'));
    $company = $this->configuration['misc']['titleOverrideField'];

    // If there's no report email to send to, just reset the option and leave
    if (count($this->configuration['misc']['dataReportEmails']) == 0) {
      update_option('crmLatestUserDataChanges', array(), false);
      return false;
    }

    // Prepare the html for the report
    $html = '';
    foreach ($changes as $id => $items) {
      if (count($items) > 0) {
        // Print the member name
        $name = get_user_meta($id, $company, true);
        $html .= '<h4>' . sprintf(__('Änderungen bei %s', 'lbwp'), $name) . '</h4>';
        $html .= '
          <table style="width:100%;" width="100%">
            <tr>
              <td style="width:5%;border-bottom:2px solid #bbb" width="5%"><strong>' . __('Uhrzeit', 'lbwp') . '</strong></td>
              <td style="width:20%;border-bottom:2px solid #bbb" width="10%"><strong>' . __('Änderung in', 'lbwp') . '</strong></td>
              <td style="width:30%;border-bottom:2px solid #bbb" width="10%"><strong>' . __('Bisher', 'lbwp') . '</strong></td>
              <td style="width:30%;border-bottom:2px solid #bbb" width="10%"><strong>' . __('Neu', 'lbwp') . '</strong></td>
              <td style="width:15%;border-bottom:2px solid #bbb" width="15%"><strong>' . __('Autor', 'lbwp') . '</strong></td>
            </tr>
        ';
        // Print all the changes to the member
        foreach ($items as $change) {
          $html .= '
            <tr>
              <td style="border-bottom:1px solid #999">' . $change['time'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['category'] . ' > ' . $change['field'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['before'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['after'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['author'] . '</td>
            </tr>
          ';
        }
        $html .= '</table><br>';
      }
    }

    // Send the email
    if (strlen($html) > 0) {
      $mail = External::PhpMailer();
      $mail->Subject = __('Änderungen von Mitglieder in den letzten 24h - ' . LBWP_HOST, 'lbwp');
      $mail->Body = $html;
      // Add recipients
      foreach ($this->configuration['misc']['dataReportEmails'] as $email) {
        $mail->addAddress($email);
      }
      // Send the mail
      $mail->send();
    }

    // After sending, reset the array with en empty one
    update_option('crmLatestUserDataChanges', array(), false);
  }

  /**
   * @param array $candidates list of contact candidates to be saved from POST
   * @return array validated (hence maybe empty) list of contacts
   */
  protected function validateInputContacts($candidates)
  {
    // Check if there even are candidates
    if (!is_array($candidates) || count($candidates) == 0) {
      return array();
    }

    // Validate the input contact
    $contacts = array();
    $countKey = array_keys($candidates)[0];
    for ($i = 0; $i < count($candidates[$countKey]); ++$i) {
      $contact = array();
      foreach (array_keys($candidates) as $key) {
        $contact[$key] = $candidates[$key][$i];
      }
      $contacts[] = $contact;
    }

    return $contacts;
  }

  /**
   * @return string html for the profile categories editor
   */
  protected function getProfileCategoriesEditor()
  {
    // Get current profile categories
    $categories = self::getProfileCategoryList();
    $current = $this->editedUser->profileCategories;

    // Edit or readonly screens for members
    if (apply_filters('lbwp_crm_show_profile_categories_changer', $this->userAdminData['userIsAdmin'])) {
      $html = '<select name="profileCategories[]" id="profileCategories" multiple="multiple">';
      foreach ($categories as $category) {
        $selected = in_array($category->ID, $current) ? ' selected="selected"' : '';
        $html .= '<option value="' . $category->ID . '"' . $selected . '>' . $category->post_title . '</option>';
      }
      $html .= '</selected>';
    } else {
      $html = '<ul class="profile-category-list">';
      foreach ($categories as $category) {
        if (in_array($category->ID, $current)) {
          $html .= '<li>' . $category->post_title . '</li>';
        }
      }
      $html .= '</ul>';
    }

    // Print the output and UI
    return '
      <table class="form-table" data-target-tab="main">
	      <tbody>
	        <tr class="profile-categories-wrap">
            <th><label for="profile_categories">Zugewiesene Kategorien</label></th>
            <td>' . $html . '</td>
	        </tr>
        </tbody>
      </table>
    ';
  }

  /**
   * @return string html to disable a member
   */
  protected function getDisableMemberEditor()
  {
    $checked = checked(get_user_meta($this->editedUserId, 'member-disabled', true), 1, false);
    // Print the output and UI
    return '
      <table class="form-table" data-target-tab="main">
	      <tbody>
	        <tr class="disable-member-wrap">
            <th><label for="disable-member">Status</label></th>
            <td>
              <label>
                <input type="checkbox" id="disable-member" name="disableMember" value="1" ' . $checked . ' /> ' . $this->configuration['misc']['disableUserString'] . '
              </label>
            </td>
	        </tr>
        </tbody>
      </table>
    ';
  }

  protected function getAutomationOptoutEditor()
  {
    $checked = checked(get_user_meta($this->editedUserId, 'lbwp-automation-optout', true), 1, false);
    // Print the output and UI
    return '
      <table class="form-table" data-target-tab="main">
	      <tbody>
	        <tr class="automation-optout-wrap">
            <th><label for="automation-optout"></label></th>
            <td>
              <label>
                <input type="checkbox" id="automation-optout" name="lbwpAutomationOptout" value="1" ' . $checked . ' /> ' . $this->configuration['misc']['disabledMarketingOptin'] . '
              </label>
            </td>
	        </tr>
        </tbody>
      </table>
    ';
  }

  /**
   * @return string the contacts editor html output
   */
  protected function getProfileContactsEditor()
  {
    $html = '';
    $contactCategories = array();
    $current = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, 'profile-categories', true));
    foreach ($current as $profileCategoryId) {
      $categories = get_post_meta($profileCategoryId, 'contact-categories');
      $contactCategories = array_unique(array_merge($contactCategories, $categories));
    }

    // Make sure there are no empty values in it after merging
    $contactCategories = array_filter($contactCategories);
    // Sort by number so that topmost IDs are top
    sort($contactCategories, SORT_NUMERIC);

    $sortedCategories = array();
    foreach ($contactCategories as $categoryId) {
      $category = $this->getContactCategory($categoryId);
      if ($category['visible'] && $category['status'] == 'publish') {
        $sortedCategories[] = $category;
      }
    }

    // Order by sort
    usort($sortedCategories, function ($a, $b) {
      if ($a['sort'] > $b['sort']) {
        return 1;
      } else if ($a['sort'] < $b['sort']) {
        return -1;
      }
      return 0;
    });

    // Get the contact editing screen for all the categories
    $index = 0;
    foreach ($sortedCategories as $category) {
      $html .= $this->getContactsEditorHtml($category, ++$index);
    }

    return $html;
  }

  /**
   * @param array $category the category object array
   * @return string the html container for the contacts editor
   */
  protected function getContactsEditorHtml($category, $index)
  {
    $html = '';
    $key = 'crm-contacts-' . $category['id'];
    $contacts = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, $key, true));

    // If no contacts and the user can't add any, don't show the editor at all
    if (count($contacts) == 0 && !$category['add']) {
      return '';
    }

    // Display the add button only, if adding is allowed
    $addBtn = $category['add'] ?
      '<a href="javascript:void(0)" class="button add-contact">Kontakt hinzufügen</a>' : '';
    $mainContactBtn = $this->userAdminData['userIsAdmin'] && $index > 1 ?
      '<a href="javascript:void(0)" class="button copy-main-contact">' . $this->configuration['misc']['copyMainContact'] . '</a>' : '';
    $delBtn = $category['delete'] ?
      '<a href="javascript:void(0)" class="dashicons dashicons-trash delete-contact"></a>' : '';
    // Some fields are only required if neutral is not allowed
    $required = $category['allow-neutral'] ? '' : ' required="required"';
    $emailRequired = $category['optional-email'] ? '' : ' required="required"';

    $cfHeadings = '';
    if (!in_array('salutation', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-salutation">Anrede</th>';
    if (!in_array('firstname', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-firstname">Vorname</th>';
    if (!in_array('lastname', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-lastname">Nachname</th>';
    if (!in_array('email', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-email">E-Mail-Adresse</th>';
    foreach ($category['fields'] as $field) {
      $cfKey = Strings::forceSlugString($field);
      $cfHeadings .= '<th class="contact-custom-field th-' . $cfKey . '" data-cfkey="' . $cfKey . '">' . $field . '</th>';
    }

    // Display available contacts
    if (count($contacts) > 0) {
      foreach ($contacts as $contact) {
        $html .= '<tr>';
        // See what core fields we actually need
        if (!in_array('salutation', $category['hiddenfields'])) {
          $html .= '<td><select name="' . $key . '[salutation][]">' . $this->getSalutationOptions($category['allow-neutral'], $contact['salutation']) . '</select></td>';
        }
        if (!in_array('firstname', $category['hiddenfields'])) {
          $html .= '<td><input type="text" name="' . $key . '[firstname][]" ' . $required . ' value="' . esc_attr($contact['firstname']) . '" /></td>';
        }
        if (!in_array('lastname', $category['hiddenfields'])) {
          $html .= '<td><input type="text" name="' . $key . '[lastname][]" ' . $required . ' value="' . esc_attr($contact['lastname']) . '" /></td>';
        }
        if (!in_array('email', $category['hiddenfields'])) {
          $html .= '<td><input type="email" name="' . $key . '[email][]" ' . $emailRequired . ' value="' . esc_attr($contact['email']) . '" /></td>';
        }

        // Additional fields if available
        foreach ($category['fields'] as $field) {
          $cfKey = Strings::forceSlugString($field);
          $html .= '<td><input type="text" name="' . $key . '[' . $cfKey . '][]" value="' . esc_attr($contact[$cfKey]) . '" /></td>';
        }

        // Delete button and close row
        $html .= '
            <td>' . $delBtn . '</td>
          </tr>
        ';
      }
    } else {
      // If no contacts yet, provide fields to add one directly without clicking
      $html .= '
        <tr class="no-contacts">
          <td colspan="5">' . __('Es sind noch keine Kontakte in dieser Kategorie vorhanden.', 'lbwp') . '</td>
        </tr>
      ';
    }

    // Return this within a little container and template
    return '
      <div class="contact-editor-container" 
        data-target-tab="' . $category['tab'] . '"
        data-input-key="' . $key . '"
        data-hidden-fields="' . esc_attr(json_encode($category['hiddenfields'])) . '"
        data-max-contacts="' . intval($category['max-contacts']) . '"
        data-min-contacts="' . intval($category['min-contacts']) . '"
        data-allow-delete="' . ($category['delete'] ? '1' : '0') . '"
        data-allow-neutral="' . ($category['allow-neutral'] ? '1' : '0') . '"
        data-optional-email="' . ($category['optional-email'] ? '1' : '0') . '"
        >
        <h4>
          ' . $category['title'] . '
          <span class="description contact-help">
            <span class="dashicons dashicons-editor-help"></span>
            <label>' . $category['description'] . '</label>
          </span>
        </h4>
        
        <div class="contact-table-container">
          <table class="widefat contact-table ' . $category['view-class'] . '">
            <thead>
              <tr>
                ' . $cfHeadings . '
                <th class="th-buttons">&nbsp;</th>
              </tr>
            </thead>
            <tbody>
              ' . $html . '
            </tbody>
          </table>
          ' . $addBtn . ' ' . $mainContactBtn . '
          <input type="hidden" name="crm-contact-categories[]" value="' . $category['id'] . '" />
        </div>
      </div>
    ';
  }

  /**
   * Provides HTML block for the crm field history
   */
  public function getCrmFieldHistory()
  {
    $id = intval(str_replace('crmcf-', '', $_POST['key']));
    $field = $this->getCustomFieldById($id);
    // Check if the history can be displayed
    if (!$this->userAdminData['userIsAdmin'] || !$this->isHistoryField($field)) {
      WordPress::sendJsonResponse(array(
        'success' => false
      ));
    }

    // Initialize the html for the field
    $html = '<table class="crmcf-history-table">';
    // First, make sure the history is in reverse order (Starting with the latest
    $versions = array_reverse($field['versions']);
    $html .= '<thead><tr>';
    foreach ($versions as $version) {
      $html .= '<th>' . $version . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($versions as $i => $version) {
      $key = 'crmcf-' . $id;
      $readonly = false;
      if ($i > 0) {
        $key .= '_' . $version;
        $readonly = !$this->userAdminData['userIsAdmin'];
      }
      $html .= '<td>
        ' . $this->getCustomFieldContent($field, $key, $readonly, $readonly, true) . '
        <input type="hidden" name="update_' . $key . '" value="1" />
      </td>';
    }

    $html .= '</tbody></table>';


    // Send the generated html that represents all versions of the field
    WordPress::sendJsonResponse(array(
      'success' => true,
      'html' => $html
    ));
  }

  /**
   * @param array $field the custom field
   * @return bool true if the field is a history field
   */
  public function isHistoryField($field)
  {
    return (is_array($field) && $field['history-active']);
  }

  /**
   * @param int $categoryId a category id
   * @return array the contact object
   */
  public function getContactCategory($categoryId)
  {
    $raw = get_post($categoryId);
    $admin = $this->userAdminData['userIsAdmin'];

    // Set the capability vars
    $read = get_post_meta($categoryId, 'cap-read', true) == 'on';
    $edit = get_post_meta($categoryId, 'cap-edit', true) == 'on';
    $delete = get_post_meta($categoryId, 'cap-delete', true) == 'on';
    $add = get_post_meta($categoryId, 'cap-add', true) == 'on';

    // Set backend view class for admins
    $viewClass = '';
    if ($admin) {
      if ($read) {
        $viewClass = 'crm-read-only';
        if ($edit) {
          $viewClass = 'crm-change-only';
        }
        if ($edit && $add && $delete) {
          $viewClass = 'crm-full-access';
        }
      } else {
        $viewClass = 'crm-admin-only';
      }
    }

    return array(
      'id' => $categoryId,
      'title' => $raw->post_title,
      'status' => $raw->post_status,
      'description' => get_post_meta($categoryId, 'description', true),
      'sort' => intval(get_post_meta($categoryId, 'sort', true)),
      'fields' => array_filter(get_post_meta($categoryId, 'custom-fields')),
      'tab' => get_post_meta($categoryId, 'tab', true),
      'visible' => $admin || $read,
      'edit' => $admin || $edit,
      'delete' => $admin || $delete,
      'add' => $admin || $add,
      'view-class' => $viewClass,
      'allow-neutral' => get_post_meta($categoryId, 'neutral-salutation', true) == 'on',
      'optional-email' => get_post_meta($categoryId, 'optional-email', true) == 'on',
      'hiddenfields' => array_filter(get_post_meta($categoryId, 'hidden-fields')),
      'max-contacts' => intval(get_post_meta($categoryId, 'max-contacts', true)),
      'min-contacts' => intval(get_post_meta($categoryId, 'min-contacts', true))
    );
  }

  /**
   * @param bool $allowNeutral allows neutral salutations
   * @param string $value preselect this value, if given
   * @return string html options
   */
  protected function getSalutationOptions($allowNeutral, $value)
  {
    $html = '';
    $options = array(
      'female' => __('Frau', 'lbwp'),
      'male' => __('Herr', 'lbwp')
    );
    // Add neutral option, if needed
    if ($allowNeutral) {
      $options['neutral'] = __('Neutral', 'lbwp');
    }

    // Produce html dom elements
    foreach ($options as $key => $name) {
      $selected = selected($key, $value, false);
      $html .= '<option value="' . $key . '"' . $selected . '>' . $name . '</option>';
    }

    return $html;
  }

  /**
   * Save the member main data, contacts and custom fields
   */
  public function saveMemberData($userId)
  {
    if (wp_verify_nonce($_POST['_wp_nonce_crm_data'], 'save') === false) {
      return;
    }

    // Save the user profile categories
    if (is_array($_POST['profileCategories'])) {
      $categories = array_map('intval', $_POST['profileCategories']);
      update_user_meta($userId, 'profile-categories', $categories);
    }

    // Member disablement
    if (isset($_POST['disableMember']) && $_POST['disableMember'] == 1) {
      update_user_meta($userId, 'member-disabled', 1);
    } else {
      delete_user_meta($userId, 'member-disabled');
    }

    // Marketing email optout
    if (isset($_POST['lbwpAutomationOptout']) && $_POST['lbwpAutomationOptout'] == 1) {
      update_user_meta($userId, 'lbwp-automation-optout', 1);
    } else {
      delete_user_meta($userId, 'lbwp-automation-optout');
    }

    // Sync core fields
    $this->syncCoreToCustomFields($userId);
    // Allow devs to do stuff after the update
    do_action('lbwp_crm_after_member_data_save', $userId);
    // Make sure to flush segment caching
    self::flushContactCache();
  }

  /**
   * Flush segmenting cache, if segmenting on a saved field is active
   * @param int $fieldId id of the field being saved
   */
  public function invalidateSegmentCache($fieldId)
  {
    if (isset($_POST[$fieldId . '_segmenting-active']) && $_POST[$fieldId . '_segmenting-active'] == 0) {
      self::flushContactCache();
    }
  }

  /**
   * Flushes the full contact cache list (esp. on saving contacts or their profile categories)
   */
  public static function flushContactCache()
  {
    wp_cache_delete('fullContactList', 'CrmCore');
    wp_cache_delete('fullContactListIgnoredCaps', 'CrmCore');
  }

  /**
   * @return void
   */
  public function invokeFooterScripts()
  {
    if (isset($_SESSION['crmEvalLastError'])) {
      echo '<div id="message" class="error notice is-dismissible"><p>Beim Ausführen des Suchsyntax Code ist ein Fehler aufgetreten: ' . $_SESSION['crmEvalLastError'] . '</p></div>';
      unset($_SESSION['crmEvalLastError']);
    }
  }

  /**
   * Invoke the scripts and data provision for member admin
   */
  public function invokeMemberAdminScripts()
  {
    $screen = get_current_screen();
    if ($screen->base == 'user-edit' || ($screen->base == 'user' && $screen->action = 'add') || $screen->base == 'users' || $screen->base == 'profile' || $screen->base == 'users_page_crm-export' || $screen->base == 'profile_page_crm-export') {
      $uri = File::getResourceUri();
      // Include usage of chosen
      wp_enqueue_script('jquery-cookie');
      wp_enqueue_script('chosen-js');
      wp_enqueue_style('chosen-css');
      wp_enqueue_style('jquery-ui-theme-lbwp');
      wp_enqueue_script('jquery-ui-datepicker');
      // And some custom library outputs
      echo '
        <script type="text/javascript">
          var crmAdminData = ' . json_encode($this->userAdminData) . ';
        </script>
        <script src="' . $uri . '/js/lbwp-crm-backend.js?v=' . LbwpCore::REVISION . '" type="text/javascript"></script>
        <link rel="stylesheet" href="' . $uri . '/css/lbwp-crm-backend.css?v' . LbwpCore::REVISION . '">
      ';
      do_action('lbwp_crm_invoke_assets');
    }
  }

  /**
   * @param \WP_User_Query $query
   */
  public function invokeUserTableQuery($query)
  {
    if (!isset($_GET['order']) && !isset($_GET['orderby'])) {
      $query->set('orderby', 'display_name');
      $query->set('order', 'ASC');
    }
  }

  /**
   * Adds various columns to custom types tables
   */
  protected function addAdminTableColumns()
  {
    // Remove core columns if configured
    if (isset($this->configuration['removeCoreColumns']) && count($this->configuration['removeCoreColumns'])) {
      add_filter('manage_users_columns', array($this, 'removeCoreColumns'));
    }

    // Add some more filters to add new columns
    add_filter('manage_users_columns', array($this, 'addUserTableColumnHeader'));
    add_action('manage_users_custom_column', array($this, 'addUserTableColumnCell'), 10, 3);
    add_action('restrict_manage_users', array($this, 'restrictUserTableFilter'));
    add_filter('users_list_table_query_args', array($this, 'userTableFilterByStatus'));

    // For profile categories
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_PROFILE_CAT,
      'meta_key' => 'contact-categories',
      'column_key' => self::TYPE_PROFILE_CAT . '_contact-categories',
      'single' => false,
      'heading' => __('Verknüpfte Kontaktarten', 'lbwp'),
      'callback' => function ($value, $postId) {
        $categories = array();
        foreach ($value as $categoryId) {
          $categories[] = get_post($categoryId)->post_title;
        }
        echo implode(', ', $categories);
      }
    ));

    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_PROFILE_CAT,
      'column_key' => self::TYPE_PROFILE_CAT . '_id',
      'single' => true,
      'heading' => __('ID', 'lbwp'),
      'callback' => function ($key, $postId) {
        echo $postId;
      }
    ));

    // For contact categories
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'meta_key' => 'max-contacts',
      'column_key' => self::TYPE_CONTACT_CAT . '_max-contacts',
      'single' => true,
      'heading' => __('Max. Anz. Kontakte', 'lbwp')
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'meta_key' => 'neutral-salutation',
      'column_key' => self::TYPE_CONTACT_CAT . '_neutral-salutation',
      'single' => true,
      'heading' => __('Neutr. Anrede', 'lbwp'),
      'callback' => function ($value, $postId) {
        echo ($value == 'on') ? __('Erlaubt', 'lbwp') : __('Nicht erlaubt', 'lbwp');
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'column_key' => self::TYPE_CONTACT_CAT . '_id',
      'single' => true,
      'heading' => __('ID', 'lbwp'),
      'callback' => function ($key, $postId) {
        echo $postId;
      }
    ));

    // For custom fields
    $types = $this->getCustomFieldTypes();
    $categories = $this->getSelectableProfileCategories();
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'meta_key' => 'type',
      'column_key' => self::TYPE_FIELD . '_type',
      'single' => true,
      'heading' => __('Feld-Typ', 'lbwp'),
      'callback' => function ($value, $postId) use ($types) {
        echo $types[$value];
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'meta_key' => 'profiles',
      'column_key' => self::TYPE_FIELD . '_profiles',
      'single' => false,
      'heading' => __('Verfügbar für', 'lbwp'),
      'callback' => function ($values, $postId) use ($categories) {
        $display = array();
        foreach ($values as $key) {
          $display[] = $categories[$key];
        }
        echo implode('<br>', $display);
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'meta_key' => 'tab',
      'column_key' => self::TYPE_FIELD . '_tab',
      'single' => true,
      'heading' => __('Anzeige im Tab', 'lbwp'),
      'callback' => function ($key, $postId) {
        echo $this->configuration['tabs'][$key];
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'column_key' => self::TYPE_FIELD . '_id',
      'single' => true,
      'heading' => __('ID', 'lbwp'),
      'callback' => function ($key, $postId) {
        echo $postId;
      }
    ));
  }

  /**
   * @param $columns
   * @return mixed
   */
  public function removeCoreColumns($columns)
  {
    foreach ($this->configuration['removeCoreColumns'] as $removed) {
      unset($columns[$removed]);
    }

    return $columns;
  }

  /**
   * @param $columns
   * @return mixed
   */
  public function addUserTableColumnHeader($columns)
  {
    // Add custom configured custom fields
    if (isset($this->configuration['customUserColumns'])) {
      foreach ($this->configuration['customUserColumns'] as $field => $name) {
        $columns[$field] = $name;
      }
    }

    // Also, add the status, and remove the count posts
    $columns['crm-status'] = 'Status';
    unset($columns['posts']);

    // If we're showing subaccounts, show assignment
    if (isset($_GET['role']) && isset($this->configuration['subaccountRoles']) && in_array($_GET['role'], $this->configuration['subaccountRoles'])) {
      $columns['assignment'] = __('Zugehörigkeit', 'lbwp');
    }

    return $columns;
  }

  /**
   * @param mixed $value
   * @param string $field
   * @param int $userId
   */
  public function addUserTableColumnCell($value, $field, $userId)
  {
    // Check for a custom field
    if (Strings::startsWith($field, 'crmcf-')) {
      $value = get_user_meta($userId, $field, true);
    }

    // If it is the status
    if ($field == 'crm-status') {
      $internalState = get_user_meta($userId, 'member-disabled', true);
      $value = __('Aktiv', 'lbwp');
      if ($internalState == 1) {
        $value = '<em>' . __('Inaktiv', 'lbwp') . '<em>';
      }
      $value = apply_filters('lbwp_crm_override_status_backend_field', $value, $internalState, $userId);
    }

    // Show an assignment to the main user
    if ($field == 'assignment') {
      $parentId = get_user_meta($userId, 'crm-main-account-id', true);
      if ($parentId > 0) {
        $url = '/wp-admin/user-edit.php?user_id=' . $parentId;
        $value = '<a href="' . $url . '">' . get_user_by('id', $parentId)->display_name . '</a>';
      }
    }

    return $value;
  }

  /**
   * Make the user able to filter by active state
   */
  public function restrictUserTableFilter()
  {
    $current = $_GET['status-filter'];
    echo '
      <select name="status-filter" id="status-filter" style="display:none;margin:0px 15px 4px 0px;">
        <option value="">Aktive & Inaktive anzeigen</option>
        <option value="active" ' . selected($current, 'active', false) . '>Nur Aktive anzeigen</option>
        <option value="inactive" ' . selected($current, 'inactive', false) . '>Nur Inaktive anzeigen</option>
      </select>
    ';
  }

  /**
   * Add query arguments if needed
   */
  public function userTableFilterByStatus($args)
  {
    if (isset($_GET['status-filter']) && strlen($_GET['status-filter']) > 0) {
      $args['meta_query'] = array();
      if ($_GET['status-filter'] == 'active') {
        $args['meta_query'][] = array(
          'key' => 'member-disabled',
          'compare' => 'NOT EXISTS'
        );
      } else if ($_GET['status-filter'] == 'inactive') {
        $args['meta_query'][] = array(
          'key' => 'member-disabled',
          'value' => 1,
          'compare' => '='
        );
      }
    }

    return $args;
  }

  /**
   * There are some menus that can be hidden by caps, so we need to
   * Remove them from the menu global before they are shown
   */
  public function hideMenusFromMembers()
  {
    if (($this->currentIsMember() || $this->currentIsSubAccount()) && !current_user_can('administrator')) {
      global $menu;
      foreach ($menu as $key => $item) {
        if ($item[2] == 'index.php' || $item[2] == 'comotive-newsletter') {
          unset($menu[$key]);
        }
        if ($item[4] == 'wp-menu-separator') {
          unset($menu[$key]);
        }
      }
    }
  }

  /**
   * @param int $userId
   * @return bool true if the user is a member
   */
  public function isMember($userId)
  {
    if ($userId == $this->editedUserId) {
      return in_array(
        $this->editedUser->roles[0],
        $this->configuration['roles']
      );
    } else {
      return in_array(
        get_user_by('id', $userId)->roles[0],
        $this->configuration['roles']
      );
    }
  }

  /**
   * @return bool tells if the user is a backend member
   */
  public function currentIsMember()
  {
    $userId = get_current_user_id();
    return $this->isMember($userId);
  }

  /**
   * @return bool tells if the user is a subaccount of a crm member
   */
  public function currentIsSubAccount()
  {
    // Can't be a sub account if the feature is not active
    if (!isset($this->configuration['subaccountRoles'])) {
      return false;
    }

    // Get the user and check the role
    $userId = get_current_user_id();
    $user = get_user_by('id', $userId);
    return in_array($user->roles[0], $this->configuration['subaccountRoles']);
  }

  /**
   * If a user goes to his dashboard (which shouldn't happen) redirect to profile
   */
  public function preventUserOnDashboard()
  {
    if ($this->userAdminData['userIsMember'] || $this->currentIsSubAccount()) {
      $screen = get_current_screen();
      if ($screen->base == 'dashboard') {
        header('Location: ' . get_admin_url() . 'profile.php', null, 301);
        exit;
      }
    }
  }

  /**
   * Let the user select a group source for that segment
   * @param Metabox $helper the meta box helper object
   * @param string $boxId the box id tu use
   * @param int $postId the post id, if needed
   */
  public function addMemberMetabox($helper, $boxId, $postId)
  {
    $selection = array(0 => 'Alle Profilkategorien');
    $profileCategories = self::getProfileCategoryList();
    foreach ($profileCategories as $category) {
      $selection[$category->ID] = $category->post_title;
    }
    $helper->addDropdown('profile-category', $boxId, 'Profilkategorie', array(
      'items' => $selection,
      'multiple' => true
    ));
    $helper->addDropdown('profile-category-method', $boxId, 'Vorgehen bei mehreren Kategorien', array(
      'items' => array(
        'and' => 'Kontakte müssen allen gewählten Kategorien zugewiesen sein (AND)',
        'or' => 'Kontakte müssen einer der gewählten Kategorien zugewiesen sein (OR)'
      )
    ));
    $selection[0] = 'Keine';
    $helper->addDropdown('reduce-category', $boxId, 'Ausschlusskategorie', array(
      'items' => $selection
    ));

    $selection = array();
    $contactCategories = self::getContactCategoryList();
    foreach ($contactCategories as $category) {
      $selection[$category->ID] = $category->post_title;
    }
    // Add woocommerce contact segments if needed
    if ($this->hasWooCommerce) {
      $selection['woocommerce-all'] = __('WooCommerce: Alle Kunden & Abonnenten', 'lbwp');
      $selection['woocommerce-customer'] = __('WooCommerce: Kunden ohne Abonnemente', 'lbwp');
      if (class_exists('\WC_Subscriptions')) {
        $selection['woocommerce-subscriber'] = __('WooCommerce: Aktive Abonnenten', 'lbwp');
      }
    }
    $helper->addDropdown('contact-category', $boxId, 'Kontaktart', array(
      'items' => $selection
    ));

    $helper->addTextarea('syntax', $boxId, 'Suchsyntax', 90, array(
      'description' => '
        <p>
          Mittels Feldnamen (in der Tabelle unten) kann mit Bedingungen definiert werden, Datensätze
          in die Segmentierung aufgenommen werden.<br>
          <br>
          feldname=1 (oder mit Leerschlägen)<br>
          feldname=\'zeichen kette\' (Anführungszeichen nötig)<br>
          & = AND Logik anwenden auf darauffolgende Anweisung<br>
          | = OR Logik anwenden auf darauffolgende Anweisung<br>
          ! = Negierung einer Bedingung (z.b. nötzlich für Bedingungen in Klammern)<br>
          () = Um Bedingungen zusammenzufassen, darin sind & und | möglich)<br>
          < und > = Um Zeit oder Zahlenfelder anhang Grösser oder z.b. Zeitfelder mit NOW() zu vergleichen<br>
          NOW() = Verwenden um Zeitfelder mit der aktuellen Uhrzeit zu vergleichen<br>
          empty(feld) = Verwenden um zu prüfen ob ein Feld leer ist. Bei Zeitfeldern mit < > nötig, wenn sie leer sein können<br>
        </p>
        ' . ($this->hasWooCommerce ? '<p>Die Liste kann auf auch Kunden eingeschränkt werden die ein aktives Abo besitzen oder ein Produkt kauften mit wc-has-subscription=345;346 oder wc-has-product (Produkte ID(s) mit Semikolon angeben).</p>' : '') . '
      '
    ));

    $helper->addCheckbox('skip-email-validation', $boxId, 'E-Mail-Adressen', array(
      'description' => 'Datensätze ohne E-Mail-Adresse zulassen'
    ));

    $helper->addCheckbox('allow-duplicate-email', $boxId, 'Duplikate', array(
      'description' => 'Duplikate in E-Mail-Adresse zulassen'
    ));
  }

  /**
   * @param array $data the initially empty array to be filled
   * @param int $listId the list id
   * @return array a possibly filled data array
   */
  public function getSegmentData($data, $listId)
  {
    $contactCategoryId = get_post_meta($listId, 'contact-category', true);
    $profileCategoryIds = array_filter(get_post_meta($listId, 'profile-category'));
    $filterMethod = get_post_meta($listId, 'profile-category-method', true);
    $reduceCategoryId = intval(get_post_meta($listId, 'reduce-category', true));
    $syntaxString = get_post_meta($listId, 'syntax', true);
    // Set default filter method to AND
    $filterMethod = (strlen($filterMethod) == 0) ? 'and' : $filterMethod;

    if (Strings::startsWith($contactCategoryId, 'woocommerce')) {
      $data = $this->getContactsFromWoocommerce($contactCategoryId);
    } else {
      // Get the contact lists, already broken down to a category
      $data = $this->getContactsByCategory(intval($contactCategoryId), true);
    }

    // Filter by profile category, if configured so
    if (count($profileCategoryIds) > 0) {
      if ($filterMethod == 'and') {
        $data = array_filter($data, function ($contact) use ($profileCategoryIds) {
          return ArrayManipulation::allValueMatch($profileCategoryIds, $contact['profile-categories']);
        });
      } else if ($filterMethod == 'or') {
        $data = array_filter($data, function ($contact) use ($profileCategoryIds) {
          return ArrayManipulation::anyValueMatch($profileCategoryIds, $contact['profile-categories']);
        });
      }
    }

    // Reduce the set by category, if configured so
    if ($reduceCategoryId > 0) {
      $data = array_filter($data, function ($contact) use ($reduceCategoryId) {
        return !is_array($contact['profile-categories']) || !in_array($reduceCategoryId, $contact['profile-categories']);
      });
    }

    // Remove the profile-categories element from the arrays (as not needed in segment)
    foreach ($data as $key => $contact) {
      unset($data[$key]['profile-categories']);
    }

    // Maybe add more data automagically (before using syntax so the added fields can be used
    $data = apply_filters('lbwp_crm_after_get_segment_data', $data);

    // Maybe reduce by syntax
    if (strlen($syntaxString) > 0) {
      if (strpbrk($syntaxString, '()<>|&"')) {
        $this->applyEvaluatedSyntaxString($data, $syntaxString);
      } else {
        $this->applyDeprecatedSyntaxString($data, $syntaxString);
      }
    }

    $skipEmailValidate = get_post_meta($listId, 'skip-email-validation', true) == 'on';

    // Remove $data entries that have no actual or an invalid email
    if (!$skipEmailValidate) {
      foreach ($data as $key => $contact) {
        if (!isset($contact['email']) || !Strings::checkEmail($contact['email'])) {
          unset($data[$key]);
        }
      }
    }

    return $data;
  }

  /**
   * @param array $data
   * @param string $syntax
   * @return void
   */
  public function applyEvaluatedSyntaxString(&$data, $syntax)
  {
    // First, double some characters to use them correctly
    $now = current_time('timestamp');
    // Remove every line of the syntax starting with a php comment
    $split = explode(PHP_EOL, $syntax);
    $syntax = '';
    foreach ($split as $line) {
      if (!Strings::startsWith(trim($line), '//') && strlen(trim($line)) > 0) {
        $syntax .= $line . PHP_EOL;
      }
    }

    $syntax = trim(str_replace(
      array(PHP_EOL, '|', '=', '&', 'NOW()'),
      array(' ', '||', '==', '&&', $now),
      $syntax
    ));

    if (is_array($data) && count($data) > 0) {
      $firstKey = array_keys($data)[0];
      $usedKeys = array();
      foreach (array_keys($data[$firstKey]) as $candidate) {
        if (str_contains($syntax, $candidate)) $usedKeys[] = $candidate;
      }
      $keys = array_map('strlen', array_keys($data[$firstKey]));
    }

    $code = '';
    foreach ($data as $key => $row) {
      $if = $syntax;
      // Sort by length of key, replace longest first
      $sortCopy = $keys;
      array_multisort($sortCopy, SORT_DESC, $row);
      foreach ($row as $id => $value) {
        if (!in_array($id, $usedKeys)) {
          continue;
        }
        if (
          stristr($if, $id.'==') !== false ||
          stristr($if, $id.'>') !== false ||
          stristr($if, $id.'<') !== false
        ) {
          $value = str_replace("'", "\'", $value);
          $if = str_replace($id, "'$value'", $if);
        } else {
          $if = str_replace($id, "'$value'", $if);
        }
      }
      $code .= 'if (!(' . $if . ')) unset($data['.$key.']);' . PHP_EOL;
    }

    // Run the code that removes lines with the syntax
    try {
      @eval($code);
    } catch (\Throwable $e) {
      $_SESSION['crmEvalLastError'] = $e->getMessage() . ' on line ' . $e->getLine();
    }
  }

  /**
   * @param array $data data that will be reduced
   * @param string $syntax the actual syntax string
   * @return void
   */
  public function applyDeprecatedSyntaxString(&$data, $syntax)
  {
    $definitions = array_map('trim', explode(',', $syntax));
    $keyMap = array();
    // Loop trough the definitions to get a key map
    foreach ($definitions as $definition) {
      if (Strings::contains($definition, '!=')) {
        list($field, $value) = array_map('trim', explode('!=', $definition));
        $keyMap[$field] = array('!=', strtolower($value));
      } else if (Strings::contains($definition, '=')) {
        list($field, $value) = array_map('trim', explode('=', $definition));
        $keyMap[$field] = array('==', strtolower($value));
      }
    }
    $keys = count($keyMap);

    // Search for products or subscriptions, if given
    if (isset($keyMap['wc-has-subscription']) || isset($keyMap['wc-has-product'])) {
      if ($this->hasWooCommerce) {
        // Check if the user has bought specified products
        if (isset($keyMap['wc-has-product'])) {
          $productIds = array_filter(explode(';', $keyMap['wc-has-product'][1]));
          foreach ($data as $key => $contact) {
            $userId = intval($contact['userid']);
            if (!WCUtil::hasBoughtProducts($userId, $productIds)) {
              unset($data[$key]);
            }
          }
        }

        // Check if user has active subscription
        if (isset($keyMap['wc-has-subscription'])) {
          $productIds = array_filter(explode(';', $keyMap['wc-has-subscription'][1]));
          foreach ($data as $key => $contact) {
            $userId = intval($contact['userid']);
            if ($userId > 0) {
              $hasProduct = false;
              foreach ($productIds as $productId) {
                if (wcs_user_has_subscription($userId, $productId, 'active')) {
                  $hasProduct = true;
                  break;
                }
              }
              if (!$hasProduct) {
                unset($data[$key]);
              }
            }
          }
        }
      }

      // Remove from keymap as to not actually search in fields below
      unset($keyMap['wc-has-subscription']);
      unset($keyMap['wc-has-product']);
      --$keys;
    }

    // Now reduce the data set, if the map doesn't fully match
    foreach ($data as $key => $contact) {
      $matches = 0;
      foreach ($contact as $field => $value) {
        if (isset($keyMap[$field])) {
          // Create evaluable code to do the match
          $test = false;
          eval('$test = "' . strtolower($value) . '" ' . $keyMap[$field][0] . ' "' . $keyMap[$field][1] . '";');
          if ($test) ++$matches;
        }
      }
      // If it doesn't match all of the syntax, remove the contact
      if ($matches < $keys) {
        unset($data[$key]);
      }
    }
  }

  /**
   * @return void check if user is logged in, then log him out if in disallowed id array
   */
  public function logoutDisabledUsers()
  {
    $user = wp_get_current_user();
    if ($user->exists() && in_array($user->ID, $this->getInactiveUserIds())) {
      wp_logout();
    }
  }

  /**
   * @param $user
   * @return mixed
   */
  public function preventDisabledUserLogin($user)
  {
    if (in_array($user->ID, $this->getInactiveUserIds())) {
      // This has a comment in it so that CleanUp module can display the message
      $user = new \WP_Error(
        'authentication_prevented',
        __('Ihr Benutzerkonto ist im Moment deaktiviert. <!--authentication-prevented-->', 'lbwp')
      );
    }

    return $user;
  }

  /**
   * @param int $userId the user id
   * @return \WP_User[] list of users associated with the account
   */
  protected function getSubAccountUsers($userId)
  {
    return get_users(array(
      'meta_key' => 'crm-main-account-id',
      'meta_value' => $userId
    ));
  }

  protected function getContactsFromWoocommerce($type)
  {
    $roles = array();
    if ($type == 'woocommerce-subscriber') {
      $roles = array('subscriber');
    } else if ($type == 'woocommerce-customer') {
      $roles = array('customer');
    } else if ($type == 'woocommerce-all') {
      $roles = array('customer', 'subscriber');
    }
    // Disable cache for this methos
    global $wp_object_cache;
    $wp_object_cache->can_write = false;
    // Also still raise memory limit
    ini_set('memory_limit', '1024M');

    // Get native users
    $users = get_users(array(
      'role__in' => $roles
    ));

    if (count($users) > 0) {
      $fields = $this->getCustomFields(false);
      // Reduce this to segmentation fields
      $segmentFields = array();
      foreach ($fields as $field) {
        if ($field['segmenting-active'] && strlen($field['segmenting-slug']) > 0) {
          $segmentFields['crmcf-' . $field['id']] = Strings::forceSlugString($field['segmenting-slug']);
        }
      }
    }

    // Build same array is getContactsByCategory
    $contacts = array();
    foreach ($users as $user) {
      $contact = array(
        'userid' => $user->ID,
        'profile-categories' => get_user_meta($user->ID, 'profile-categories', true),
        'email' => get_user_meta($user->ID, 'billing_email', true),
        'firstname' => get_user_meta($user->ID, 'billing_first_name', true),
        'lastname' => get_user_meta($user->ID, 'billing_last_name', true),
        'state' => get_user_meta($user->ID, 'billing_state', true),
        'country' => get_user_meta($user->ID, 'billing_country', true),
      );

      // Get the custom fields
      foreach ($segmentFields as $metaKey => $segmentKey) {
        $contact[$segmentKey] = get_user_meta($user->ID, $metaKey, true);
      }
      // Add that contact
      if ($contact['userid'] > 0 && Strings::checkEmail($contact['email'])) {
        $contacts[] = $contact;
      }
    }

    // Re-enable cache
    $wp_object_cache->can_write = true;
    return $contacts;
  }

  /**
   * @return array list of contacts of a specified category, contains profile categories of the assigned members
   */
  public function getContactsByCategory($categoryId, $ignorecaps = false)
  {
    // Allow using more RAM, as large segments can be loaded to be reduced
    ini_set('memory_limit', '2048M');
    $cacheKey = ($ignorecaps) ? 'fullContactList_c' : 'fullContactListIgnoredCaps_c';
    $contacts = wp_cache_get($cacheKey, 'CrmCore');
    if (is_string($contacts) && strlen($contacts) > 0) {
      // Uncompress the cached large variable
      $contacts = json_decode(gzuncompress($contacts), true);
    }

    // Build a new list, if not in cache
    if (!is_array($contacts)) {
      $db = WordPress::getDb();
      $contacts = array();
      $fields = $this->getCustomFields(false, $ignorecaps);
      $disabledUsers = $this->getInactiveUserIds();

      // Get raw meta data (so we omit the meta cache in this request)
      $raw = $db->get_results('SELECT user_id, meta_key, meta_value FROM ' . $db->usermeta . ' WHERE meta_key LIKE "crmcf-%"');
      $meta = array();
      foreach ($raw as $key => $item) {
        $meta[$item->user_id][$item->meta_key] = $item->meta_value;
        unset($item, $raw[$key]);
      }

      $raw = $db->get_results('SELECT user_id, meta_value FROM ' . $db->usermeta . ' WHERE meta_key = "profile-categories"');
      foreach ($raw as $item) {
        $meta[$item->user_id]['profile-categories'] = maybe_unserialize($item->meta_value);
      }

      $automationOptouts = array();
      $showAutomationOptouts = isset($this->configuration['misc']['disabledMarketingOptin']);
      if ($showAutomationOptouts) {
        $raw = $db->get_results('SELECT user_id, meta_value FROM ' . $db->usermeta . ' WHERE meta_key = "lbwp-automation-optout"');
        foreach ($raw as $item) {
          if ($item->meta_value == 1) {
            $automationOptouts[$item->user_id] = true;
          }
        }
      }

      $optinTimeByUser = array();
      $raw = $db->get_results('
        SELECT ID, user_registered, meta_value FROM ' . $db->users . '
        LEFT JOIN ' . $db->usermeta . ' ON meta_key = "users-last-optin" AND ' . $db->users . '.ID = ' . $db->usermeta . '.user_id
      ');
      foreach ($raw as $user) {
        if ($user->meta_value != NULL) {
          $optinTimeByUser[$user->ID] = intval($user->meta_value);
        } else {
          $optinTimeByUser[$user->ID] = strtotime($user->user_registered);
        }
      }

      // Reduce this to segmentation fields
      $segmentFields = array();
      foreach ($fields as $field) {
        if ($field['segmenting-active'] && strlen($field['segmenting-slug']) > 0) {
          $segmentFields['crmcf-' . $field['id']] = Strings::forceSlugString($field['segmenting-slug']);
        }
      }

      // Get all the contact categories to access user meta fields
      foreach (self::getContactCategoryList() as $category) {
        $contacts[$category->ID] = array();
        // Get all Contacts of that category
        $sql = 'SELECT user_id, meta_value FROM {sql:userMeta} WHERE meta_key = {contactKey}';
        $raw = $db->get_results(Strings::prepareSql($sql, array(
          'userMeta' => $db->usermeta,
          'contactKey' => 'crm-contacts-' . $category->ID
        )));

        // Attach member profile categories to each contact for later filtering
        foreach ($raw as $result) {
          // If the user is disabled, skip it
          if (in_array($result->user_id, $disabledUsers)) {
            continue;
          }

          // Unserialize and merge
          $userContacts = maybe_unserialize($result->meta_value);
          $metaFields = array(
            'userid' => $result->user_id,
            'profile-categories' => $meta[$result->user_id]['profile-categories'],
          );
          // Get user fields, that are configured to be accessed in mail segments
          foreach ($segmentFields as $metaKey => $segmentKey) {
            $metaFields[$segmentKey] = $meta[$result->user_id][$metaKey];
          }
          // Integrate all contacts into the main array
          foreach ($userContacts as $contact) {
            $contact['salutation'] = $this->getSalutationByKey($contact['salutation']);
            foreach ($metaFields as $key => $value) {
              $contact[$key] = $value;
            }
            if ($showAutomationOptouts) {
              $contact['global-automation-optout'] = isset($automationOptouts[$result->user_id]) ? 1 : 0;
            }
            if (isset($optinTimeByUser[$result->user_id])) {
              $contact['optin'] = $optinTimeByUser[$result->user_id];
            }
            $contacts[$category->ID][] = $contact;
          }
        }
      }

      // Save to cache for next time fast use
      $compressed = gzcompress(json_encode($contacts), 9);
      wp_cache_set($cacheKey, $compressed, 'CrmCore');
    }

    // Reduce the array to the desired group
    if (isset($contacts[$categoryId])) {
      return $contacts[$categoryId];
    }
    if ($categoryId === -1) {
      // Merge all subkeys in to one array
      $allContacts = array();
      foreach ($contacts as $value) {
        $allContacts = array_merge($allContacts, $value);
      }
      return $allContacts;
    }

    // If category doesn't exist, return an empty array
    return array();
  }

  /**
   * @param $fields
   * @return mixed
   */
  public function addCrmCheckoutFields($fields)
  {
    $crmFields = $this->getCustomFields(false);

    // Add those who are activated for it
    foreach ($crmFields as $crmField) {
      if ($crmField['checkout-active']) {
        $key = 'crmcf-' . $crmField['id'];
        $fields['billing'][$key] = array(
          'type' => 'text',
          'class' => array('crm-checkout-field form-row-wide'),
          'label' => $crmField['title'],
          'required' => $crmField['checkout-required'],
        );
      }
    }

    return $fields;
  }

  /**
   * @param $userId
   * @param $data
   */
  public function saveCrmCheckoutFields($userId, $data)
  {
    $crmFields = $this->getCustomFields(false);
    // Save whatever is coming from $data
    foreach ($crmFields as $crmField) {
      if ($crmField['checkout-active']) {
        $key = 'crmcf-' . $crmField['id'];
        update_user_meta($userId, $key, $data[$key]);
      }
    }
  }

  /**
   * @param array $capabilities
   * @param $unused1
   * @param $unused2
   * @param $user
   * @return array new capabilities
   */
  public function filterVirtualCapabilities($capabilities, $unused1, $unused2, $user)
  {
    $additional = ArrayManipulation::forceArray(
      get_user_meta($user->ID, 'crm-capabilities', true)
    );

    foreach ($additional as $capability) {
      $capabilities[$capability] = true;
    }

    return $capabilities;
  }

  /**
   * Add Submenu to users to add a crm export feature
   */
  public function addExportView()
  {
    add_submenu_page(
      'users.php',
      'CRM Export',
      'CRM Export',
      'administrator',
      'crm-export',
      array($this, 'displayExportView')
    );
  }

  /**
   * Display the export UI (which is simple for the moment
   */
  public function displayExportView()
  {
    $html = '';
    // Maybe export data, if needed
    $this->runExportView();
    // Get role names to map
    $roles = WordPress::getRoles();
    $roles = $roles->get_names();

    // Functions / UI to create data exports for members
    $html .= '<div class="container-main-exports"><div><label class="field-description">Rolle:</label>  <select name="field-role">';
    foreach ($this->configuration['roles'] as $key) {
      $html .= '<option value="' . $key . '">' . $roles[$key] . '</option>"';
    }
    $html .= '</select></div>';

    $html .= '<div>
        <label class="field-description">Status:</label>
        <span>
          <label>
            <input type="radio" name="member-status" value="active" checked="checked"> Nur Aktive
          </label>
          <label>
            <input type="radio" name="member-status" value="inactive"> Nur Inaktive
          </label>
          <label>
            <input type="radio" name="member-status" value="all"> Alle
          </label>
        </span>
      </div>
    ';

    // Exportable tabs, make a chosen of them for multiselection
    $html .= '<div><label class="field-description">Exportiere Tabs:</label>  <select class="exported-tabs" name="exported-tabs[]" multiple="true">';
    foreach ($this->configuration['tabs'] as $key => $name) {
      // Skip contacts and make main tab always preselected
      if ($key == 'contacts') continue;
      $selected = ($key == 'main') ? ' selected="selected"' : '';
      // Print the option entry
      $html .= '<option value="' . $key . '"' . $selected . '>' . $name . '</option>';
    }
    $html .= '</select>
    </div>';

    $html .= '<div>
        <label class="field-description">Versionierte Felder:</label>
        <label>
          <input type="checkbox" name="use-history" value="1"> Versionen exportieren 
        </label>
      </div>
      <div>
        <label class="field-description">Anzahl Versionen:</label>
        <label>
          <input type="number" name="version-count" style="width:50px;" value="0" min="0"> (0 = Alle) 
        </label>
      </div>
      <div>
        <label class="field-description">Export als:</label>
        <label><input type="radio" name="export-type" value="csv" checked="checked">CSV</label>
        <label><input type="radio" name="export-type" value="excel">Excel</label>
      </div>
      <input type="submit" class="button-primary" name="field-export" value="Daten-Export starten" />
      </div>
    ';

    if (!isset($this->configuration['misc']['hideContactExport']) || !$this->configuration['misc']['hideContactExport']) {
      // Functions / UI to create contact exports for members
      $html .= '<hr><div id="lbwp-crm-export-role" class="field"><label class="field-description">Rolle:</label>  <select name="contact-role">';
      foreach ($this->configuration['roles'] as $key) {
        $html .= '<option value="' . $key . '">' . $roles[$key] . '</option>';
      }

      $html .= '</select></div>
        <div id="lbwp-crm-export-status" class="field">
          <label class="field-description">Status:</label>
          <span>
            <label>
              <input type="radio" name="member-status-contact" value="active" checked="checked"> Nur Aktive
            </label>
            <label>
              <input type="radio" name="member-status-contact" value="inactive"> Nur Inaktive
            </label>
            <label>
              <input type="radio" name="member-status-contact" value="all"> Alle
            </label>
          </span>
        </div>
        <div id="lbwp-crm-export-profile-cat" class="field"><label class="field-description">Profilkategorie:</label>  <select name="profile-cat-id">
        <option value="0">Alle</option>
      ';
      foreach (self::getProfileCategoryList() as $item) {
        $html .= '<option value="' . $item->ID . '">' . $item->post_title . '</option>';
      }
      $html .= '</select></div>
      <div id="lbwp-crm-export-contact-cat" class="field">
        <label class="field-description">Kontaktart:</label>
          <select class="contact-category" name="contact-category[]" multiple="true">
      ';
      foreach (self::getContactCategoryList() as $index => $category) {
        $html .= '<option value="' . $category->ID . '" ' . selected($index, 0, false) . '>' . $category->post_title . '</option>';
      }
      $html .= '
        </select></div>
        <div id="lbwp-crm-export-duplicates" class="field">
          <label class="field-description">Duplikate:</label>
          <label>
            <input type="checkbox" name="remove-duplicates" value="1"> Doppelte Datensätze aussortieren
          </label>
        </div>
        <div class="field">
          <label class="field-description">Export als:</label>
          <label><input type="radio" name="contact-export-type" value="csv" checked="checked">CSV</label>
          <label><input type="radio" name="contact-export-type" value="excel">Excel</label>
        </div>
        ' . apply_filters('lbwp_crm_after_contact_export_html', '') . '
        <input type="submit" class="button-primary" name="contact-export" value="Kontakte-Export starten" />
      ';
    }

    // Let developers add their own export scripts
    $html = apply_filters('lbwp_crm_export_view_html', $html);

    // Print the wrapper and html
    echo '
      <div class="wrap">
        <h1 class="wp-heading-inline">CRM Export</h1>
        <hr class="wp-header-end">
        <form method="post">
          ' . $html . '
          <script type="text/javascript">
            jQuery(function() {
              jQuery(".exported-tabs").chosen();
              jQuery(".contact-category").chosen();
            });
          </script>
        </form>
        <br class="clear">
      </div>
    ';
  }

  /**
   * Run export if desired
   */
  protected function runExportView()
  {
    // Do a full data export
    if (isset($_POST['field-export']) && isset($_POST['field-role'])) {
      $role = Strings::forceSlugString($_POST['field-role']);
      $this->downloadFieldExport($role);
    }

    // Do a contact list export
    if (isset($_POST['contact-export']) && isset($_POST['contact-role'])) {
      $role = Strings::forceSlugString($_POST['contact-role']);
      $profileCatId = intval($_POST['profile-cat-id']);
      $categories = array_map('intval', $_POST['contact-category']);
      $status = Strings::forceSlugString($_POST['member-status-contact']);
      $this->downloadContactExport($role, $categories, $profileCatId, $status);
    }
  }

  /**
   * @param string $role the role to export field data from
   */
  protected function downloadFieldExport($role)
  {
    // Allow more memory for this call
    ini_set('memory_limit', '512M');
    // Disable writing to cache to make this way faster
    global $wp_object_cache;
    $wp_object_cache->can_write = false;
    $isLarge = defined('LBWP_CRM_LARGE_DATABASE');

    // Are we exporting history
    $history = intval($_POST['use-history']) == 1;
    $versionMax = intval($_POST['version-count']);
    // See if we need to get inactives
    $inactives = false;
    if ($_POST['member-status'] != 'active') {
      $inactives = true;
    }

    // Validate and create exporting tabs
    $tabs = array_map('strtolower', $_POST['exported-tabs']);

    // Get all members and all fields to prepare for the export
    if ($isLarge) {
      $members = $this->getMemberIdsByRole($role, $inactives);
    } else {
      $members = array();
      $temp = $this->getMembersByRole($role, 'display_name', 'ASC', $inactives);
      foreach ($temp as $member) {
        $members[] = $member->ID;
      }
      unset($temp);
    }
    $inactives = $this->getInactiveUserIds();
    $categories = is_array($this->configuration['categoryByRole']) ? $this->configuration['categoryByRole'][$role] : false;
    $fields = $this->getCustomFields($categories);

    // If we only show inactives, sort out all active members
    if ($_POST['member-status'] == 'inactive') {
      foreach ($members as $key => $memberId) {
        if (!in_array($memberId, $inactives)) {
          unset($members[$key]);
        }
      }
    }

    // Begin output data array
    $data = array('columns' => array('Status'));

    // Prepare woocommerce fields that may be used
    $wcBilling = isset($this->configuration['export']['wc-billing']);
    $wcFields = array(
      'company' => 'Firma',
      'first_name' => 'Vorname',
      'last_name' => 'Nachname',
      'email' => 'E-Mail',
      'postcode' => 'PLZ',
      'city' => 'Ort',
      'address_1' => 'Strasse / Nr',
    );

    // Add wocommerce fields very first, if given
    if ($wcBilling) {
      foreach ($wcFields as $name) {
        $data['columns'][] = $name;
      }
    }

    // Create a heading column
    foreach ($fields as $field) {
      // Skip if not desired tab or unexportable
      if (
        !in_array($field['tab'], $tabs) ||
        in_array($field['type'], $this->unexportableFields)) {
        continue;
      }
      if ($history && $field['history-active']) {
        $versions = -1;
        foreach (array_reverse($field['versions']) as $version) {
          $data['columns'][] = $field['title'] . ' ' . $version;
          // Break if max versions is reached
          if ($versionMax > 0 && ++$versions == $versionMax) {
            break;
          }
        }
      } else {
        $data['columns'][] = $field['title'];
      }
    }

    // Maybe export the first of a specific contact group
    $mainContactKey = '';
    if (isset($this->configuration['export']['mainContactExport'][$role])) {
      $mainContactKey = 'crm-contacts-' . $this->configuration['export']['mainContactExport'][$role];
      $data['columns'][] = 'Anrede Hauptkontakt';
      $data['columns'][] = 'Vorname Hauptkontakt';
      $data['columns'][] = 'Nachname Hauptkontakt';
      $data['columns'][] = 'E-Mail Hauptkontakt';
    }

    // Get *all* CRM meta to be added later eventually as this takes the least amount of RAM
    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT user_id, meta_key, meta_value FROM ' . $db->usermeta . ' WHERE meta_key LIKE "crmcf-%"');
    $meta = array();
    foreach ($raw as $item) {
      $meta[$item->user_id][$item->meta_key] = $item->meta_value;
      unset($item);
    }

    // Now for each member, create a new row
    foreach ($members as $memberId) {
      $row = array();
      $row[] = in_array($memberId, $inactives) ? 'Inaktiv' : 'Aktiv';
      // Add wocommerce fields very first, if given
      if ($wcBilling) {
        $prefix = $this->configuration['export']['wc-billing'];
        foreach ($wcFields as $key => $name) {
          $row[] = get_user_meta($memberId, $prefix . $key, true);
        }
      }
      foreach ($fields as $field) {
        // Skip if not desired tab or unexportable
        if (
          !in_array($field['tab'], $tabs) ||
          in_array($field['type'], $this->unexportableFields)) {
          continue;
        }
        if ($history && $field['history-active']) {
          $versions = -1;
          foreach (array_reverse($field['versions']) as $id => $version) {
            // If the version is not the newest, add the suffix to our key
            $key = 'crmcf-' . $field['id'];
            if ($id > 0) $key .= '_' . $version;
            $row[] = get_user_meta($memberId, $key, true);
            // Break if max versions is reached
            if ($versionMax > 0 && ++$versions == $versionMax) {
              break;
            }
          }
        } else {
          if (!$isLarge && is_serialized($meta[$memberId]['crmcf-' . $field['id']])) {
            $row[] = implode(', ', maybe_unserialize($meta[$memberId]['crmcf-' . $field['id']]));
          } else {
            $row[] = $meta[$memberId]['crmcf-' . $field['id']];
          }
        }
      }

      // Add contact info if given
      if (strlen($mainContactKey)) {
        $contacts = get_user_meta($memberId, $mainContactKey, true);
        if (is_array($contacts) && count($contacts) > 0) {
          $contact = $contacts[0];
          $row[] = $this->getSalutationByKey($contact['salutation']);
          $row[] = $contact['firstname'];
          $row[] = $contact['lastname'];
          $row[] = $contact['email'];
        }
      }

      $data[] = $row;
      // Remove from meta to save RAM as we fill $data simultaneously
      unset($meta[$memberId]);
    }

    // Handle export of numeric fields if configured
    if (isset($this->configuration['misc']['exportConvertNumericFields']) && $this->configuration['misc']['exportConvertNumericFields']) {
      foreach ($data as $il => $line) {
        foreach ($line as $ic => $cell) {
          $cell = str_replace(array("'", '`'), '', $cell);
          if (is_numeric($cell)) {
            $data[$il][$ic] = $cell;
          }
        }
      }
    }

    $file = 'daten-export-' . date('Y-m-d');
    switch($_POST['export-type']){
      case 'excel':
        Csv::downloadExcel($data, $file);
        break;

      default:
        Csv::downloadFile($data, $file);
    }
  }

  /**
   * @param string $role the role to export contacts of
   * @param int[] $categories the contact category we need to get
   * @param int $profileCatId eventual profile cat to restrict query
   * @param string $status a status of active,inactive,all
   */
  protected function downloadContactExport($role, $categories, $profileCatId = 0, $status = 'all')
  {
    // Allow more memory for this call
    ini_set('memory_limit', '512M');
    // Disable writing to cache to make this way faster
    global $wp_object_cache;
    $wp_object_cache->can_write = false;
    $inactiveIds = $this->getInactiveUserIds();
    $memberIds = $this->getMemberIdsByRole($role, $status != 'active');
    // When querying inactive users, get members from inactive ids (as it contains all roles and we need to exclude)
    if ($status == 'inactive') {
      $memberIds = array_intersect($memberIds, $inactiveIds);
    }

    // Begin output data array
    $data = array('columns' => array());
    $addresses = $columns = array();
    $removeDuplicates = intval($_POST['remove-duplicates']) == 1;

    // Remove members not matching the profile category, if given
    if ($profileCatId > 0) {
      foreach ($memberIds as $key => $memberId) {
        $checks = get_user_meta($memberId, 'profile-categories', true);
        if (is_array($checks) && !in_array($profileCatId, $checks)) {
          unset($memberIds[$key]);
        }
      }
    }

    // Now add the basic core fields
    $fields = array(
      'firstname' => 'Vorname',
      'lastname' => 'Nachname',
      'email' => 'E-Mail',
      'salutation' => 'Anrede',
    );

    // Let developers change order and remove core fields
    $fields = apply_filters('lbwp_crm_contact_export_columns', $fields);

    // Put those fields in front
    foreach ($fields as $key => $value) {
      if (!in_array($key, $columns)) {
        $columns[] = $key;
        $data['columns'][] = $value;
      }
    }

    // In a first loop, define the fields/columns that can exist over all categories
    foreach ($categories as $categoryId) {
      $category = self::getContactCategory($categoryId);
      // Finally, add the custom columns
      foreach ($category['fields'] as $field) {
        $key = Strings::forceSlugString($field);
        if (!in_array($key, $columns)) {
          $columns[] = $key;
          $data['columns'][] = $field;
        }
      }
    }

    $includeContactType = apply_filters('lbwp_crm_contact_export_include_contact_type', true);

    // Add the category type as another column after base fields
    if ($includeContactType) {
      $data['columns'][] = 'Kontaktart';
    }
    // Add the custom fields after all other fields
    foreach ($this->configuration['export']['contact-fields'] as $key => $value) {
      $data['columns'][] = $value;
    }

    // Loop again to fill actual data
    foreach ($categories as $categoryId) {
      $category = self::getContactCategory($categoryId);

      foreach ($memberIds as $memberId) {
        // Create the basic row (custom fields)
        $base = array();
        if ($includeContactType) {
          $base[] = $category['title'];
        }
        foreach ($this->configuration['export']['contact-fields'] as $key => $value) {
          $base[] = get_user_meta($memberId, $key, true);
        }

        // Get all contacts for that member
        $contacts = ArrayManipulation::forceArray(
          get_user_meta($memberId, 'crm-contacts-' . $category['id'], true)
        );
        // Create a row per contact
        foreach ($contacts as $contact) {
          // Skip, if already added
          if ($removeDuplicates && in_array($contact['email'], $addresses)) {
            continue;
          }
          // Fill empty cells with key unknown
          $row = array();
          // Maintain correct sort order
          foreach ($columns as $column) {
            if (!isset($contact[$column])) {
              $row[] = '';
            } else {
              if ($column == 'salutation') {
                $row[] = $this->getSalutationByKey($contact[$column]);
              } else {
                $row[] = $contact[$column];
              }
            }
          }
          // Finally pull it into our data stream
          foreach ($base as $value) {
            $row[] = $value;
          }
          $data[] = $row;
          $addresses[] = $contact['email'];
        }
      }
    }

    // Maybe change data completely if customer desires
    $data = apply_filters('lbwp_crm_change_contact_export_data', $data);
    // Maybe do something else than downloading the file
    do_action('lbwp_crm_before_contact_export_csv', $data);

    $file = 'kontakt-export-' . date('Y-m-d');
    switch($_POST['contact-export-type']){
      case 'excel':
        Csv::downloadExcel($data, $file);
        break;

      default:
        Csv::downloadFile($data, $file);
    }
  }

  /**
   * @param $html
   * @param $filename
   */
  protected function downloadPdfExport($html, $filename)
  {
    // Include the doc raptor autoloader to gain access to the classes
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/docraptor/1.3.0/autoload.php';

    // Get the PDF stream from doc raptor
    $filename = Strings::forceSlugString($filename) . '.pdf';
    $config = \DocRaptor\Configuration::getDefaultConfiguration();
    $config->setUsername('H3CM2Yff0XwryukWJdB');
    $docraptor = new \DocRaptor\DocApi();
    $document = new \DocRaptor\Doc();

    // Configure the document
    $document->setTest(defined('LOCAL_DEVELOPMENT'));
    $document->setJavascript(true);
    $document->setName($filename);
    $document->setIgnoreConsoleMessages(true);
    $document->setDocumentType('pdf');
    $document->setStrict('none');
    $document->setPipeline(7);

    // Set the document content by locally grabbing the full url
    $document->setDocumentContent($html);
    $stream = $docraptor->createDoc($document);

    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);
    $outstream = fopen("php://output", 'w');
    fputs($outstream, $stream);
    fclose($outstream);
    exit;
  }

  /**
   * Downloads a custom configured export of CSV data
   * @param string $file the filename for the export
   * @param array $config the export configuration
   */
  public function downloadCustomExport($file, $config)
  {
    $data = $this->getCustomExportData($config);
    Csv::downloadFile($data, $file);
  }

  /**
   * @param array $config the export configuration
   * @return array custom export data as of config
   */
  public function getCustomExportData($config)
  {
    $data = $columns = array();
    // Get all members from the configured role
    $members = $this->getMembersByRole($config['role']);
    // Make the first columns line, contact fields are added later
    foreach ($config['fields'] as $key => $name)
      $columns[] = $name;
    foreach ($config['categories'] as $key => $name)
      $columns[] = $name;
    foreach ($config['contactfields'] as $key => $name)
      $columns[] = $name;
    foreach ($config['contacts'] as $key => $name)
      $columns[] = $name;
    // Add the columns to data
    $data[] = $columns;

    // Gather data for each member
    foreach ($members as $member) {
      // Get the set of fields
      $base = array();
      foreach ($config['fields'] as $key => $name) {
        $base[] = get_user_meta($member->ID, $key, true);
      }
      // Match every category of the member
      $categories = get_user_meta($member->ID, 'profile-categories', true);
      foreach ($config['categories'] as $key => $name) {
        $base[] = is_array($categories) && in_array($key, $categories) ? '1' : '';
      }

      // Now get every desired contact and build a row out of it
      $categories = array_keys($config['contacts']);
      foreach ($config['contacts'] as $categoryId => $name) {
        $contacts = ArrayManipulation::forceArray(get_user_meta($member->ID, 'crm-contacts-' . $categoryId, true));
        if (count($contacts) > 0) {
          foreach ($contacts as $contact) {
            $person = array();
            foreach ($config['contactfields'] as $key => $name) {
              if (Strings::startsWith($key, 'salutation')) {
                list($key, $type) = explode(':', $key);
                switch ($contact[$key]) {
                  case 'male':
                    $person[] = ($type == 'short') ? 'Herr' : 'Sehr geehrter Herr';
                    break;
                  case 'female':
                    $person[] = ($type == 'short') ? 'Frau' : 'Sehr geehrte Frau';
                    break;
                  default:
                    $person[] = 'Sehr geehrte Damen und Herren';
                }
              } else {
                $person[] = strlen($contact[$key]) > 0 ? $contact[$key] : '';
              }
            }
            foreach ($categories as $id) {
              $person[] = ($id == $categoryId) ? '1' : '';
            }
            // Add a row to our data array
            $data[] = array_merge($base, $person);
          }
        } else {
          $data[] = $base;
        }
      }
    }

    return $data;
  }

  /**
   * @param $value
   * @return mixed
   */
  public function configureAutoLoginLink($value)
  {
    $value = $this->configuration['misc']['autologinValidity'];
    return $value;
  }

  /**
   * @param string $key
   * @return string
   */
  protected function getSalutationByKey($key)
  {
    switch ($key) {
      case 'male':
        return 'Sehr geehrter Herr';
      case 'female':
        return 'Sehr geehrte Frau';
      default:
        return 'Sehr geehrte Damen und Herren';
    }
  }

  /**
   * Saves versions, if a new one is added, the previous one is archived
   * @param int $postId
   * @param array $field
   * @param string $boxId
   * @return array|string
   */
  public function saveCustomFieldVersion($postId, $field, $boxId)
  {
    // Validate and save the field
    $new = $_POST[$postId . '_' . $field['key']];
    $prev = get_post_meta($postId, $field['key']);
    $adoptValue = intval($_POST['history-keep-previous-value']) == 1;

    // See if the tables have turned (contents have changed)
    if (serialize($new) == serialize($prev) || !is_array($new)) {
      // Return, nothing to save
      return $new;
    }

    // Add new version if there is more versions than before
    if (count($new) > count($prev)) {
      // Build an array of key (version) and change (same, archive, new)
      $newVersionId = count(array_keys($new)) - 1;
      $archiveVersionId = ($newVersionId - 1);
      $version = $new[$archiveVersionId];

      // Archive the old version by renaming the meta fields in db
      if (count($new) > 1 && strlen($version) > 0) {
        $this->historizeVersion($version, $postId, $adoptValue);
        // Flush all user-like caches asynchronously
        MemcachedAdmin::flushByKeyword('user_meta_');
        MemcachedAdmin::flushByKeyword('users_');
      }
    } else {
      // Less versions, means a version was deleted, get difference
      $difference = array_diff($prev, $new);
      foreach ($difference as $version) {
        $this->removeVersion($version, $postId);
      }
    }

    // Save the new version config
    delete_post_meta($postId, $field['key']);
    foreach ($new as $version) {
      add_post_meta($postId, $field['key'], $version, false);
    }

    return $new;
  }

  /**
   * @param string $version
   * @param int $fieldId
   */
  protected function removeVersion($version, $fieldId)
  {
    $db = WordPress::getDb();
    // Remove all keys with that version, no need to flush the cache after this
    $sql = 'DELETE FROM {sql:userMeta} WHERE meta_key = {deletedKey}';
    $db->query(Strings::prepareSql($sql, array(
      'userMeta' => $db->usermeta,
      'deletedKey' => 'crmcf-' . $fieldId . '_' . $version
    )));
  }

  /**
   * @param string $version
   * @param int $fieldId
   * @param bool $adoptValue
   */
  public function historizeVersion($version, $fieldId, $adoptValue)
  {
    $db = WordPress::getDb();

    // Change the current live key to a versioned key
    $sql = 'UPDATE {sql:userMeta} SET meta_key = {versionedKey} WHERE meta_key = {currentKey}';
    $db->query(Strings::prepareSql($sql, array(
      'userMeta' => $db->usermeta,
      'currentKey' => 'crmcf-' . $fieldId,
      'versionedKey' => 'crmcf-' . $fieldId . '_' . $version
    )));

    // Add new live key with previous value if desired
    if ($adoptValue) {
      // Read information from jsut created version
      $sql = 'SELECT user_id, meta_value FROM {sql:userMeta} WHERE meta_key = {metaKey}';
      $data = $db->get_results(Strings::prepareSql($sql, array(
        'userMeta' => $db->usermeta,
        'metaKey' => 'crmcf-' . $fieldId . '_' . $version,
      )), ARRAY_A);
      // Add the latest version as new live field
      $sql = 'INSERT INTO {sql:userMeta} (user_id, meta_key, meta_value) VALUES ({userId}, {metaKey}, {metaValue})';
      foreach ($data as $row) {
        $data = $db->query(Strings::prepareSql($sql, array(
          'userMeta' => $db->usermeta,
          'userId' => intval($row['user_id']),
          'metaKey' => 'crmcf-' . $fieldId,
          'metaValue' => $row['meta_value'],
        )));
      }
    }
  }

  /**
   * Set a backend display role if non is set, to prevent users from pressing "alle"
   */
  public function maybeSetDefaultDisplayRole()
  {
    if (get_current_screen()->base === 'users' && !isset($_GET['role']) && isset($this->configuration['misc']['defaultDisplayRole'])) {
      $_GET['role'] = $_REQUEST['role'] = $this->configuration['misc']['defaultDisplayRole'];
    }
  }

  /**
   * Adds post types for member and contact categorization
   */
  protected function addCategorizationPostTypes()
  {
    WordPress::registerType(self::TYPE_PROFILE_CAT, 'Profilkategorie', 'Profilkategorien', array(
      'show_in_menu' => 'users.php',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ), '');

    WordPress::registerType(self::TYPE_CONTACT_CAT, 'Kontaktart', 'Kontaktarten', array(
      'show_in_menu' => 'users.php',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ), '');

    WordPress::registerType(self::TYPE_FIELD, 'Feld', 'Felder', array(
      'show_in_menu' => 'users.php',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ), 's');

    // Register sortable types
    SortableTypes::init(array(
      self::TYPE_FIELD => array(
        'type' => self::TYPE_FIELD,
        'field' => 'menu_order',
        'noImages' => true,
        'mode' => 'list',
        'custom-menu' => array(
          'slug' => 'users.php',
          'name' => 'Felder sortieren'
        )
      )
    ));
  }

  /**
   * Adds meta fields for the categorization types
   */
  public function addCategorizationMetaboxes()
  {
    $helper = Metabox::get(self::TYPE_PROFILE_CAT);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addPostTypeDropdown('contact-categories', 'settings', 'Kontaktarten', self::TYPE_CONTACT_CAT, array(
      'multiple' => true,
      'sortable' => true,
      'no_delete_callback' => true
    ));

    // Configuration for contact categories
    $helper = Metabox::get(self::TYPE_CONTACT_CAT);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addInputText('max-contacts', 'settings', 'Max. Anzahl Kontakte', array(
      'description' => 'Wählen Sie z.B. "1" sofern in dieser Gruppe nur ein Kontakt erstellt werden darf.'
    ));
    $helper->addInputText('min-contacts', 'settings', 'Min. Anzahl Kontakte', array(
      'description' => 'Geben Sie hier eine Zahl ein, wenn die Kontaktart eine Mindestzahl an Kontakten voraussetzt.'
    ));
    $helper->addDropdown('tab', 'settings', 'Anzeigen in', array(
      'items' => $this->configuration['tabs']
    ));
    $helper->addTextarea('description', 'settings', 'Beschreibung', 70, array(
      'description' => 'Eine optionale Feldbeschreibung (wie diese hier).'
    ));
    $helper->addInputText('sort', 'settings', 'Sortiernummer', array(
      'description' => 'Eine optionale Sortiernummer, damit die Reihenfolge der Kontaktarten in jeder Kombination stimmt.'
    ));
    $helper->addDropdown('custom-fields', 'settings', 'Zusätzliche Felder', array(
      'multiple' => true,
      'sortable' => true,
      'items' => 'self',
      'add_new_values' => true
    ));
    $helper->addMetabox('settings-cap', 'Zugriffsrechte');
    $helper->addCheckbox('cap-read', 'settings-cap', 'Rechte für Benutzer', array(
      'description' => 'Kann Kontakte sehen (Muss aktiv sein, damit weitere Rechte greifen)'
    ));
    $helper->addCheckbox('cap-edit', 'settings-cap', '&nbsp;', array(
      'description' => 'Kann Kontakte bearbeiten'
    ));
    $helper->addCheckbox('cap-delete', 'settings-cap', '&nbsp;', array(
      'description' => 'Kann Kontakte löschen'
    ));
    $helper->addCheckbox('cap-add', 'settings-cap', '&nbsp;', array(
      'description' => 'Kann Kontakte hinzufügen'
    ));
    $helper->addMetabox('settings-fields', 'Pflichtfelder');
    $helper->addCheckbox('neutral-salutation', 'settings-fields', 'Adressdaten', array(
      'description' => 'Neutrale Anrede ermöglichen (Felder für Anrede, Vorname, Nachname sind optional)'
    ));
    $helper->addCheckbox('optional-email', 'settings-fields', 'E-Mail-Feld', array(
      'description' => 'Das E-Mail Feld ist optional'
    ));
    $helper->addDropdown('hidden-fields', 'settings-fields', 'Felder ausblenden', array(
      'multiple' => true,
      'items' => array(
        'salutation' => 'Anrede',
        'firstname' => 'Vorname',
        'lastname' => 'Nachname',
        'email' => 'E-Mail-Adresse'
      )
    ));

    $helper = Metabox::get(self::TYPE_FIELD);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addHtml('field-scripts', 'settings', $this->getFieldUiScripts());
    $helper->addDropdown('type', 'settings', 'Feld-Typ', array(
      'items' => $this->getCustomFieldTypes()
    ));
    $helper->addDropdown('profiles', 'settings', 'Verfügbar für', array(
      'items' => $this->getSelectableProfileCategories(),
      'multiple' => true
    ));
    $helper->addDropdown('tab', 'settings', 'Anzeigen in', array(
      'items' => $this->configuration['tabs']
    ));
    $helper->addTextarea('description', 'settings', 'Beschreibung', 70, array(
      'description' => 'Eine optionale Feldbeschreibung (wie dieser hier).'
    ));
    $helper->addCheckbox('track-changes', 'settings', 'Änderungen aufzeichnen', array(
      'description' => 'Das letzte Änderungsdatum und Uhrzeit dieses Feldes aufzeichnen'
    ));

    $helper->addMetabox('capabilities', 'Berechtigungen');
    $helper->addCheckbox('cap-invisible', 'capabilities', 'Sichtbarkeit', array(
      'description' => 'Dieses Feld können nur Administratoren sehen'
    ));
    $helper->addCheckbox('cap-readonly', 'capabilities', 'Schreibrechte', array(
      'description' => 'Das Feld kann vom Benutzer nicht geändert werden'
    ));
    $helper->addCheckbox('cap-admin-readonly', 'capabilities', 'Schreibrechte', array(
      'description' => 'Das Feld kann von Administratoren nicht geändert werden'
    ));
    $helper->addCheckbox('cap-required', 'capabilities', 'Pflichtfeld', array(
      'description' => 'Das Feld muss zwingend ausgefüllt werden'
    ));
    $helper->addCheckbox('cap-not-required-admin', 'capabilities', '&nbsp;', array(
      'description' => 'Administratoren können das Feld leer lassen'
    ));

    $helper->addMetabox('segments', 'Segmentierung');
    $helper->addCheckbox('segmenting-active', 'segments', 'Anwendung', array(
      'description' => 'Dieses Feld als Datenfeld in der Segmentierung nutzen'
    ));
    $helper->addInputText('segmenting-slug', 'segments', 'Feldname für E-Mails', array(
      'description' => 'Sollte möglichst nur Kleinbuchstaben und Bindestriche verwenden.'
    ));

    $helper->addMetabox('data-history', 'Historisierung');
    $helper->addCheckbox('history-active', 'data-history', 'Aktivieren', array(
      'description' => 'Historisierung der Feld-Daten aktivieren'
    ));
    $helper->addDropdown('versions', 'data-history', 'Versionen', array(
      'multiple' => true,
      'sortable' => false,
      'add_new_values' => true,
      'items' => 'self',
      'saveCallback' => array($this, 'saveCustomFieldVersion'),
      'description' => 'Beim Hinzufügen einer neuen Version werden die aktuelle Feld-Daten archiviert, sobald die Feld-Einstellungen gespeichert werden.'
    ));
    $helper->addHtml('versions-script', 'data-history', $this->getVersionConfirmationScript());

    $helper->addMetabox('multi-values', 'Feldinformationen für Tabellen / Dropdowns');
    $helper->addParagraph('multi-values', 'Hier können Sie die Vorgabewerte für Dropdowns bzw. die Spaltenwerte für Tabellen angeben.');
    $helper->addDropdown('field-values', 'multi-values', 'Vorgabewerte', array(
      'multiple' => true,
      'items' => 'self',
      'add_new_values' => true
    ));
    $helper->addMetabox('date-values', 'Einstellungen für Datumsfelder');
    $helper->addInputText('max-future-days', 'date-values', 'Einschränkung in Tagen', array(
      'description' => 'Die Datumsauswahl kann damit auf n-Tage in der Zukunft eingeschränkt werden.'
    ));

    // Woocommerce integration
    if ($this->hasWooCommerce) {
      $helper->addMetabox('woocommerce', 'Integration in den Onlineshop');
      $helper->addCheckbox('checkout-active', 'woocommerce', 'Aktivieren', array(
        'description' => 'Dieses Feld auf der Kassen-Seite anzeigen'
      ));
      $helper->addCheckbox('checkout-required', 'woocommerce', 'Pflichtfeld', array(
        'description' => 'Dieses Feld muss auf der Kassen-Seite ausgefüllt werden'
      ));
    }
  }

  /**
   * @return string script that does confirmation messages when adding a new field version
   */
  protected function getVersionConfirmationScript()
  {
    return '
      <label class="container-history-keep-previous-value">
        <input type="checkbox" name="history-keep-previous-value" value="1" />
        Bisherigen Wert in neue Version übernehmen
      </label>
      <script type="text/javascript">
        jQuery(function() {
          var button = jQuery(".versions input[type=button]");
          button.on("click", function(e) {
            var message = "";
            var select = jQuery(".versions select");
            var versions = jQuery.map(select.find("option") ,function(option) {
              return option.value;
            });
            // Also get the newly added version
            versions.push(jQuery(".mbh-add-dropdown-value input[type=text]").val());
            // If there is at least an old and a new version, make a confirm message
            if (versions.length >= 2) {
              var index = (versions.length - 1);
              message = "Dadurch wird die neue Version *" + versions[index] + "* hinzugefügt und die Version *" + versions[index-1] + "* archiviert. Wenn Sie dies tun wollen, bitte Bestätigen Sie den Dialog mit OK. Die Aktion wird unwiederruflich durchgeführt, sobald das Feld mittels *Aktualisieren* gespeichert wird.";
            }
            
            // If there is a confirm message, ask for it
            if (message.length > 0 && !confirm(message)) {
              MetaboxHelper.preventAdd = true;
              setTimeout(function() {
                MetaboxHelper.preventAdd = false;
              }, 200);
            }
            return true;
          });
          // Move the value keep option to the button
          button.after(jQuery(".container-history-keep-previous-value"));
          // Make sure the last (newest) history entry is not deletable
          setTimeout(function() {
            var versions = jQuery(".versions .search-choice-close");
            var total = versions.length;
            versions.each(function(id, element) {
              if (id+1 == total) {
                element.remove();
              }
            });
          }, 2000);
        });
      </script>
      <style type="text/css">
        .container-history-keep-previous-value {
          margin-left:7px !important;
        }
        .container-history-keep-previous-value input {
          vertical-align: top !important;
          float:none !important;
        }
      </style>
    ';
  }

  /**
   * @return string html tag with scripts
   */
  protected function getFieldUiScripts()
  {
    return '
      <script type="text/javascript">
        jQuery(function() {
          var select = jQuery("select[data-metakey=type]");
          // On Change of the type field
          select.on("change", function() {
            var fieldType = jQuery(this).val();
            var history = jQuery("#crm-custom-field__data-history");
            var multivalues = jQuery("#crm-custom-field__multi-values");
            var datevalues = jQuery("#crm-custom-field__date-values");
            // Basically allow history, but dont show multi values
            history.show();
            multivalues.hide();
            datevalues.hide();
            // If it is a table, show multival and disable history
            if (fieldType == "table") {
              history.hide();
              multivalues.show();
            }
            // If it is a dropdown or multicheckbox, show multival
            if (fieldType == "dropdown" || fieldType == "checkbox-multi") {
              multivalues.show();
            }
            // If it is a datefiled, show its settings
            if (fieldType == "datefield") {
              datevalues.show();
            }
          });
          
          // On load trigger a change to the type to show fields
          select.trigger("change");
        });
      </script>
    ';
  }

  /**
   * @return array custom field types
   */
  protected function getCustomFieldTypes()
  {
    return array(
      'textfield' => 'Einzeiliges Textfeld',
      'textarea' => 'Mehrzeiliges Textfeld',
      'datefield' => 'Datumsfeld',
      'checkbox' => 'Checkbox (Einzel)',
      'checkbox-multi' => 'Checkbox (Mehrfach-Auswahl)',
      'dropdown' => 'Dropdown',
      'table' => 'Tabelle',
      'file' => 'Datei-Upload'
    );
  }

  /**
   * @return array a selectable list of categories
   */
  protected function getSelectableProfileCategories()
  {
    $list = self::getProfileCategoryList();
    $categories = array();

    foreach ($list as $entry) {
      $categories[$entry->ID] = $entry->post_title;
    }

    return $categories;
  }

  /**
   * @return array the full user admin build data
   */
  protected function getUserAdminData()
  {
    $isMember = $this->currentIsMember();
    $field = $this->configuration['misc']['titleOverrideField'];
    $displayField = $this->editedUser->{$field};
    $titleOverrideValue = apply_filters('lbwp_crm_display_override_field', (strlen($displayField) > 0) ? $displayField : '', $this->editedUser);

    return array(
      'config' => $this->configuration,
      'editedIsMember' => $isMember || $this->isMember($_REQUEST['user_id']),
      'editedUserId' => $_REQUEST['user_id'],
      'editedUserRole' => $this->editedUser->roles[0],
      'userIsMember' => $isMember,
      'userIsAdmin' => current_user_can('administrator'),
      'neutralSalutations' => $this->getSalutationOptions(true, ''),
      'defaultSalutations' => $this->getSalutationOptions(false, ''),
      'titleOverrideField' => $this->configuration['misc']['titleOverrideField'],
      'titleOverrideValue' => $titleOverrideValue,
      'saveUserButton' => $this->configuration['misc']['saveUserButton'],
      'text' => array(
        'confirmUploadFileDelete' => __('Sind Sie sicher, dass Sie die Datei löschen möchten?', 'lbwp'),
        'requiredFieldsMessage' => __('Es wurden nicht alle Pflichtfelder ausgefüllt', 'lbwp'),
        'noContactsYet' => __('Es sind noch keine Kontakte in dieser Kategorie vorhanden.', 'lbwp'),
        'sureToDelete' => __('Möchten Sie den Kontakt wirklich löschen?', 'lbwp'),
        'deleteImpossible' => __('Löschen nicht möglich. Mindestens {number} Kontakt/e sind erforderlich.', 'lbwp'),
        'sureToDeleteSubAccount' => __('Wollen Sie das Login zur Löschung markieren? Es wird erst permanent gelöscht, wenn Sie danach das Profil speichern.')
      )
    );
  }

  /**
   * Invalidate performance caches
   */
  public function invalidateCaches()
  {
    wp_cache_delete('crmCustomFields', 'CrmCore');
    do_action('crm_on_cache_invalidation');
  }

  /**
   * @param $role
   * @param string $orderby
   * @param string $order
   * @param bool $inactives
   * @return array
   */
  public function getMembersByRole($role, $orderby = 'display_name', $order = 'ASC', $inactives = false)
  {
    $users = get_users(array(
      'role' => $role,
      'orderby' => $orderby,
      'order' => $order
    ));

    // Filter all inactive users out if needed
    if (!$inactives) {
      $userIds = $this->getInactiveUserIds();
      $users = array_filter($users, function ($user) use ($userIds) {
        return !in_array($user->ID, $userIds);
      });
    }

    return $users;
  }

  /**
   * Get member(s) specific by thy id
   * @param $ids int|array id(s) of the member(s)
   * @param $orderby string orderby value
   * @param $order string order direction
   * @param $inactives bool filter out the inactive users
   * @return array the searched user(s)
   */
  public function getMembersByIds($ids, $orderby = 'display_name', $order = 'ASC', $inactives = false){
    $users = get_users(array(
      'orderby' => $orderby,
      'order' => $order,
      'include' => is_array($ids) ? $ids : array($ids)
    ));

    // Filter all inactive users out if needed
    if (!$inactives) {
      $userIds = $this->getInactiveUserIds();
      $users = array_filter($users, function ($user) use ($userIds) {
        return !in_array($user->ID, $userIds);
      });
    }

    return $users;
  }

  /**
   * @param $role
   * @param string $orderby
   * @param string $order
   * @param bool $inactives
   * @return array
   */
  protected function getMemberIdsByRole($role, $inactives = false)
  {
    $db = WordPress::getDb();
    $sql = 'SELECT DISTINCT user_id FROM ' . $db->usermeta . ' WHERE meta_key = "' . $db->prefix . 'capabilities" AND meta_value LIKE "%' . $role . '%"';
    $userIds = array_map('intval', $db->get_col($sql));

    // Filter all inactive users out if needed
    if (!$inactives) {
      $inactiveIds = $this->getInactiveUserIds();
      return array_filter($userIds, function ($userId) use ($inactiveIds) {
        return !in_array($userId, $inactiveIds);
      });
    }

    return $userIds;
  }

  /**
   * @return array
   */
  public function getInactiveUserIds()
  {
    if (!is_array($this->inactiveUserIds)) {
      $this->inactiveUserIds = wp_cache_get('inactiveUserIds', 'CrmCore');
      if ($this->inactiveUserIds === false) {
        $sql = '
          SELECT user_id FROM {sql:userMetaTable}
          WHERE meta_key = "member-disabled" AND meta_value = 1
        ';

        $db = WordPress::getDb();
        $this->inactiveUserIds = $db->get_col(Strings::prepareSql($sql, array(
          'userMetaTable' => $db->usermeta
        )));
        wp_cache_set('inactiveUserIds', $this->inactiveUserIds, 'CrmCore', 3600);
      }
    }

    return $this->inactiveUserIds;
  }

  /**
   * @return array the profile category list
   */
  public static function getProfileCategoryList()
  {
    return get_posts(array(
      'post_type' => self::TYPE_PROFILE_CAT,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));
  }

  /**
   * @return array the contact category list
   */
  public static function getContactCategoryList()
  {
    $categories = get_posts(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));

    // Order by sort
    if (count($categories) > 1) {
      usort($categories, function ($a, $b) {
        $na = intval(get_post_meta($a->ID, 'sort', true));
        $nb = intval(get_post_meta($b->ID, 'sort', true));
        if ($na > $nb) {
          return 1;
        } else if ($na < $nb) {
          return -1;
        }
        return 0;
      });
    }

    return $categories;
  }

  /**
   * Get a comprehensible list of custom fields for the given role
   * @param array $categories list of profile categories
   * @return array a list of custom fields for that role
   */
  public function getCustomFields($categories, $ignorecaps = false)
  {
    $allFields = wp_cache_get('crmCustomFields', 'CrmCore');

    // Get the fields from db
    if (!is_array($allFields)) {
      $raw = get_posts(array(
        'post_type' => self::TYPE_FIELD,
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC'
      ));

      $allFields = array();
      foreach ($raw as $field) {


        $allFields[] = array(
          'id' => $field->ID,
          'title' => $field->post_title,
          'type' => get_post_meta($field->ID, 'type', true),
          'profiles' => get_post_meta($field->ID, 'profiles'),
          'tab' => get_post_meta($field->ID, 'tab', true),
          'history-active' => get_post_meta($field->ID, 'history-active', true) == 'on',
          'versions' => get_post_meta($field->ID, 'versions'),
          'field-values' => get_post_meta($field->ID, 'field-values'),
          'max-future-days' => intval(get_post_meta($field->ID, 'max-future-days', true)),
          'segmenting-active' => get_post_meta($field->ID, 'segmenting-active', true) == 'on',
          'segmenting-slug' => get_post_meta($field->ID, 'segmenting-slug', true),
          'description' => get_post_meta($field->ID, 'description', true),
          'track-changes' => get_post_meta($field->ID, 'track-changes', true) == 'on',
          'invisible' => get_post_meta($field->ID, 'cap-invisible', true) == 'on',
          'readonly' => get_post_meta($field->ID, 'cap-readonly', true) == 'on',
          'admin-readonly' => get_post_meta($field->ID, 'cap-admin-readonly', true) == 'on',
          'required' => get_post_meta($field->ID, 'cap-required', true) == 'on',
          'checkout-active' => get_post_meta($field->ID, 'checkout-active', true) == 'on',
          'checkout-required' => get_post_meta($field->ID, 'checkout-required', true) == 'on',
        );
      }

      wp_cache_set('crmCustomFields', $allFields, 'CrmCore');
    }

    // Remove invisible fields, if the user is not admin
    if (!$this->userAdminData['userIsAdmin'] && !$ignorecaps) {
      foreach ($allFields as $key => $field) {
        if ($field['invisible']) unset($allFields[$key]);
      }
    }

    // Unrequire fields if needed
    if ($this->userAdminData['userIsAdmin']) {
      foreach ($allFields as $key => $field) {
        if ($field['required'] && get_post_meta($field['id'], 'cap-not-required-admin', true) == 'on') {
          $allFields[$key]['required'] = false;
        }
      }
    }

    // Filter the fields by role if given
    if (is_array($categories)) {
      return array_filter($allFields, function ($item) use ($categories) {
        foreach ($categories as $categoryId) {
          if (in_array($categoryId, $item['profiles'])) {
            return true;
          }
        }
        return false;
      });
    }

    // Or return all fields if no role was given
    return $allFields;
  }

  /**
   * @param int $id the field id
   * @return bool|array the field or false
   */
  protected function getCustomFieldById($id)
  {
    $fields = $this->getCustomFields(false);
    foreach ($fields as $field) {
      if ($field['id'] == $id) {
        return $field;
      }
    }

    return false;
  }

  /**
   * Track lists (count) history
   * @return void
   */
  public function trackMailingLists(){
    // With some customers this could take longy mc longface
    set_time_limit(1200);
    ini_set('memory_limit', '2048M');

    $mailService = LocalMailService::getInstance();
    $lists = get_posts(array(
      'numberposts' => -1,
      'post_type' => $mailService::LIST_TYPE_NAME,
    ));

    foreach($lists as $list){
      $history = get_post_meta($list->ID, self::LIST_HISTORY_META, true);

      if(!is_array($history)){
        $history = array();
      }else if(count($history) > 750){
        $history = array_slice($history, 1, 750, true);
      }

      $listData = $mailService->getListData($list->ID);
      $history[time()] = count($listData);
      update_post_meta($list->ID, self::LIST_HISTORY_META, $history);

      $this->sendDifferenceNotification($history, $list->ID);
    }
  }

  /**
   * Enqueue the chart.js script
   * @return void
   */
  public function enqueueChartJS(){
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js');
  }

  /**
   * Add the metabox for the chart
   * @return void
   */
  public function addHistoryChart(){
    add_meta_box(
      'history-chart',
      'Verlauf der Datensätze',
      array($this, 'drawHistoryChart'),
      'lbwp-mailing-list'
    );
  }

  /**
   * Draw the chart with chart.js
   * @return void
   */
  public function drawHistoryChart(){
    global $post;
    $history = get_post_meta($post->ID, self::LIST_HISTORY_META, true);
    $dataSize = 30;
    $showControls = false;

    if(is_array($history) && count($history) > 0){
      $data = array(
        'labels' => array_map(function($item){
            return strval(date('d.m.Y', $item)); // Important: Needs to be the full year format
          }, array_keys($history)),
        'data' => array_values($history)
      );

      if(count($history) > $dataSize + 1){
        $showControls = true;
      }

      $completeData = $data;
      $data['labels'] = array_reverse(array_map('array_reverse', array_chunk(array_reverse($data['labels']), $dataSize)));
      $data['data'] = array_reverse(array_map('array_reverse', array_chunk(array_reverse($data['data']), $dataSize)));

      $dateLimit = array(
        'min' => $data['labels'][count($data['labels']) - 1][0],
        'max' => $completeData['labels'][count($completeData['labels']) - 1],
      );

      echo '
        <canvas id="historyChart"></canvas>
        <div class="chart-settings">
          <label>
            <input type="checkbox" id="beginAtZero"/>
            Bei 0 beginnen
          </label>
        </div> 
        <div class="chart-controls' . ($showControls ? '' : ' hide') . '">
          <button class="prev">Vorherige Daten</button>
          <label>
            Datum vom
            <input type="text" class="date-selection from" placeholder="' . $dateLimit['min'] . '">
          </label>
          <label>
            Datum bis
            <input type="text" class="date-selection to" placeholder="' . $dateLimit['max'] . '">
          </label>
          <button class="next disabled">Nächste Daten</button>
        </div>
        <br>
        <hr>
        <form method="POST">
          <input type="hidden" value="' . $_GET['post'] . '" name="postId" />
          <input type="hidden" value="' . get_the_title($_GET['post']) . '" name="postTitle" />
          <input type="submit" class="button button-primary" name="exportData" value="Datensätze exportieren">
        </form>
        <script>
          const completeData = JSON.parse(\'' . json_encode($completeData) . '\');
          const labels = JSON.parse(\'' . json_encode($data['labels']) . '\');
          const numbers = JSON.parse(\'' . json_encode($data['data']) . '\');
          var curData = ' . ($showControls ? 'labels.length - 1' : 0) . ';
                              
          const data = {
            labels: labels[curData],
            datasets: [{
              label: "Datensätze",
              backgroundColor: "#135e96",
              borderColor: "#135e96",
              data: numbers[curData],
            }],
          };
        
          const config = {
            type: "line",
            data: data,
            options: {
              scales: {
                y: {
                  offset: 10,
                  ticks: {
                    stepSize: 1,
                    callback: function(value, index, values) {
                      if(parseInt(value) >= 1000){
                        return value.toString().replace(",", "\'");
                      } else {
                        return value;
                      }
                    }
                  },
                  beginAtZero: false
                },
              },
              
              plugins: {
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      //let label = context.dataset.label;
                      return context.parsed.y;
                    }
                  }
                }
              }
            }
          };
          
          const dataChart = new Chart(
            document.getElementById("historyChart"),
            config
          );
          
          let startZero = document.getElementById("beginAtZero");
          startZero.addEventListener("change", ()=>{
            dataChart.options.scales.y.beginAtZero = startZero.checked;
            dataChart.update();
          });
          
          let btns = document.querySelectorAll("button.prev, button.next");
          let dateFields = document.querySelectorAll(".date-selection");

          btns[0].addEventListener("click", function(e){ // prev
            e.preventDefault();
            
            curData -= 1;
            curData = checkCurData(curData);
            
            var theData = dataChart.config.data;
            theData.labels = labels[curData];
            theData.datasets[0].data = numbers[curData];
            dataChart.update();
            
            dateFields[0].value = "";
            dateFields[1].value = "";
            dateFields[0].setAttribute("placeholder", labels[curData][0]);
            dateFields[1].setAttribute("placeholder", labels[curData][labels[curData].length - 1]);
            
            return false;
          });
          btns[1].addEventListener("click", function(e){ // next
            e.preventDefault();
            
            curData += 1;
            curData = checkCurData(curData);
            
            var theData = dataChart.config.data;
            theData.labels = labels[curData];
            theData.datasets[0].data = numbers[curData];
            dataChart.update();
            
            dateFields[0].value = "";
            dateFields[1].value = "";
            dateFields[0].setAttribute("placeholder", labels[curData][0]);
            dateFields[1].setAttribute("placeholder", labels[curData][labels[curData].length - 1]);
            
            return false;
          });
                    
          dateFields.forEach((field)=>{
            field.addEventListener("keyup", function(e){                    
              let fromDate = getDateFromString(dateFields[0].value);
              let toDate = getDateFromString(dateFields[1].value);

            
              fromDate = fromDate === false ? completeData.labels.indexOf(labels[curData][0]) : completeData.labels.indexOf(fromDate);
              toDate = toDate === false ? completeData.labels.indexOf(labels[curData][labels[curData].length - 1]) : completeData.labels.indexOf(toDate);
                              
              let range = [
                fromDate > 0 ? fromDate : 0,
                toDate
              ];
              
              let theData = dataChart.config.data;
              theData.labels = completeData.labels.slice(range[0], range[1]);
              theData.datasets[0].data = completeData.data.slice(range[0], range[1]);
              dataChart.update();
              
            });
          });
          
          function getDateFromString(dateStr){
            dateStr = dateStr.replace(" ", "");
            
            let splitChar = dateStr.indexOf(".") > 0 ? "." : "-"; 
            let date = dateStr.split(splitChar);
            
            if(date.length < 3){
              return false;
            }
            
            if(date[0].length < 2){
              date[0] = "0" + date[0];
            }
            
            if(date[1].length < 2){
              date[1] = "0" + date[1];
            }
            
            if(date[2].length < 4){
              date[2] = "20" + date[2].slice(-2);
            }
            
            return date[0] + "." + date[1] + "." + date[2];
          }
          
          function checkCurData(cData){
            if(cData >= labels.length - 1){
              cData = labels.length - 1;
              btns[1].classList.add("disabled");
            }else if(cData <= 0){
              cData = 0;
              btns[0].classList.add("disabled");
            }else{
              btns[0].classList.remove("disabled");              
              btns[1].classList.remove("disabled");       
            }
            
            return cData;
          }
        </script>
      ';
    }else{
      echo '<p>Für diese Liste sind noch keine Daten vorhanden.</p>';
    }
  }

  /**
   * Download the chart data as csv
   * @return void
   */
  private function exportChartData(){
    $data = get_post_meta($_POST['postId'], self::LIST_HISTORY_META, true);
    if(is_array($data)){
      $csvData = array();
      foreach($data as $date => $value){
        $csvData []= [strval(date('d.m.Y', $date)), $value];
      }

      Csv::downloadFile($csvData, $_POST['postTitle']);
    }
    exit;
  }

  /**
   * Add the notification settings
   * @return void
   */
  public function addNotificationSettings(){
    $helper = Metabox::get(LocalMailService::LIST_TYPE_NAME);
    $boxId = 'notification-config';
    $helper->addMetabox($boxId, 'Benachrichtigung aktivieren');
    $helper->addCheckbox('notification-active', $boxId, 'Benachrichtigen bei Datensatzveränderungen');
    $helper->addInputText('notification-email', $boxId, 'Senden an');
    $helper->addInputText('notification-difference', $boxId, 'Veränderung +/-', array(
      'description' => 'Benachrichtigung wird ausgelöst, wenn sich das Segment im Tagesverlauf um +/- diese Anzahl Datensätze verändert'
    ));
  }

  /**
   * Send a notification email if settings are set
   * @param $data array the segment data
   * @param $listId int the list id
   * @return void
   * @throws \PHPMailer\PHPMailer\Exception
   */
  public function sendDifferenceNotification($data, $listId){
    $notificate = get_post_meta($listId, 'notification-active', true);
    $email = get_post_meta($listId, 'notification-email', true);
    $minDiff = intval(get_post_meta($listId, 'notification-difference', true));

    if($notificate === 'on' && !Strings::isEmpty($email)){
      $lastData = array_slice($data, count($data) - 2, 2);
      $difference = abs($lastData[0] - $lastData[1]);

      if($difference >= $minDiff){
        $title = html_entity_decode(get_the_title($listId));
        $subject = 'Veränderung im Segment "' . $title . '"';
        $content = '
          Veränderung in der Anzahl Datensätze in den letzten 24h
          Segment: ' . $title . '
          Veränderung: ' . $lastData[0] . ' auf ' . $lastData[1] . ' Datensätze
        ';

        $mail = External::PhpMailer();
        $mail->Subject = $subject;
        $mail->Body = $content;
        $mail->AddAddress($email);
        $mail->isHTML(false);
        $mail->send();
      }
    }
  }
}