<?php
namespace LBWP\Theme\Feature\BetterTables;

use LBWP\Core;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Theme\Component\Crm\Core as CrmCore;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Make WordPress Tables better
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class BetterUserTables extends BetterTables{
  public function __construct($settings = array()){
    parent::__construct($settings);
    $this->getUsers();
  }

  public function replaceWPPage(){
    add_action('load-users.php', array($this, 'replaceUserPage'));
  }
  public function replaceUserPage(){
    if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'delete'){
      return;
    }

    if(isset($_GET['clear_usermeta'])){
      delete_user_meta(get_current_user_id(), 'bettertables-users-settings');
    }

    require_once ABSPATH . '/wp-admin/admin.php';
    require_once ABSPATH . 'wp-admin/admin-header.php';

    echo '<div class="wrap">
      <h1 class="wp-heading-inline">
        ' . __('Users') . '
      </h1>
      <div>
        <div id="js-root"></div>
      </div>
    </div>';

    require_once ABSPATH . 'wp-admin/admin-footer.php';
    die();
  }

  public function registerApiEndpoints(){
    register_rest_route('lbwp/bettertables', 'users', array(
      'methods' => 'GET',
      'callback' => array($this, 'getUsers'),
    ));

    register_rest_route('lbwp/bettertables', 'get_users_settings', array(
      'methods' => 'POST',
      'callback' => array($this, 'getUsersSettings'),
    ));

    register_rest_route('lbwp/bettertables', 'save_users_settings', array(
      'methods' => 'POST',
      'callback' => array($this, 'saveUsersSettings'),
    ));
  }

  public function getUsers(){
    HTMLCache::avoidCache();

    if($this->settings['useFlatTable']){
      return $this->searchFlatTable();
    }else{
      return $this->searchCachedArray();
    }
  }

  private function searchFlatTable(){
    // Currently no flat table implementation available - delegate to cached array search
    return $this->searchCachedArray();
  }

  private function searchCachedArray(){
    // Normalize and validate request parameters with sensible defaults
    $params = array_replace(
      array(
        'per_page' => 10,
        'page' => 1,
        'search' => '',
        'search_column' => '',
        'user_id' => 0
      ),
      $_GET
    );

    $perPage = max(1, intval($params['per_page']));
    $pageNr = max(1, intval($params['page']));
    $userId = intval($params['user_id']);
    $searchParam = trim((string)$params['search']);
    $searchColumnParam = trim((string)$params['search_column']);

    // Obtain CRM contacts; guard if the component is missing or returns no data
    $crm = $this->settings['crmComponent'] ?? null;
    if (!$crm || !method_exists($crm, 'getContactsByCategory')) {
      return array('columns' => array(), 'rows' => array(), 'total' => 0);
    }

    $raw = $crm->getContactsByCategory(-1, true);
    if (empty($raw) || !is_array($raw)) {
      return array('columns' => array(), 'rows' => array(), 'total' => 0);
    }

    // Create basic columns from the first row safely
    $firstRow = reset($raw);
    $columns = $this->getColumns($userId, $firstRow);

    // Load user settings once and normalize
    $userSettingsMeta = get_user_meta($userId, 'bettertables-users-settings');
    $userSettings = is_array($userSettingsMeta) ? ($userSettingsMeta[0] ?? array()) : array();

    // Apply search filters if provided. Support multiple searches separated by commas.
    if ($searchParam !== '' && $searchColumnParam !== '') {
      $searchFor = array_map('trim', explode(',', $searchParam));
      $inColumn = array_map('trim', explode(',', $searchColumnParam));

      // Only consider pairs up to the shortest array to avoid undefined indexes
      $pairs = min(count($searchFor), count($inColumn));

      if ($pairs > 0) {
        $filtered = array();
        foreach ($raw as $data) {
          $match = true;

          for ($i = 0; $i < $pairs; $i++) {
            $col = $inColumn[$i];
            $needle = $searchFor[$i];

            // Normalize the haystack and do a case-insensitive substring check using native functions
            $hay = isset($data[$col]) ? (string)$data[$col] : '';
            if ($needle === '') {
              // empty needle -> skip this pair (treat as match)
              continue;
            }

            if (stripos($hay, $needle) === false) {
              $match = false;
              break;
            }
          }

          if ($match) {
            $filtered[] = $data;
          }
        }

        // Replace raw with filtered results (reindexed array)
        $raw = $filtered;
      }
    }

    // Order by column (safe access with fallback to empty string)
    $order = $userSettings['order'] ?? 'asc';
    $orderby = $userSettings['orderby'] ?? 'user_id';

    usort($raw, function($a, $b) use ($order, $orderby) {
      $va = isset($a[$orderby]) ? $a[$orderby] : '';
      $vb = isset($b[$orderby]) ? $b[$orderby] : '';

      if ($va === $vb) return 0;

      return ($order === 'asc') ? strnatcmp($va, $vb) : strnatcmp($vb, $va);
    });

    $total = count($raw);

    // Efficient paging: compute offset and slice once
    $offset = max(0, ($pageNr - 1) * $perPage);
    $pageRows = array_slice($raw, $offset, $perPage);

    $rows = array();

    // Build rows for response. Keep transformations minimal and safe.
    foreach ($pageRows as $data) {
      // Apply per-user column visibility if present
      if (is_array($userSettings) && isset($userSettings['columns']) && is_array($userSettings['columns'])) {
        $visible = array();
        foreach ($data as $k => $v) {
          if (isset($userSettings['columns'][$k]) && !empty($userSettings['columns'][$k][1])) {
            $visible[$k] = $v;
          }
        }
        $row = $visible;
      } else {
        $row = $data;
      }

      // Normalize user id field (support common variants)
      $userid = $row['userid'] ?? ($row['user_id'] ?? '');

      $deleteUrl = wp_nonce_url(
        admin_url('users.php?action=delete&user=' . $userid),
        'delete-user_' . $userid
      );

      $orderedRow = array(
        '<a href="/wp-admin/user-edit.php?user_id=' . $userid . '" class="dashicons-before dashicons-edit"></a>',
        '<a href="' . $deleteUrl . '" class="dashicons-before dashicons-trash"></a>'
      );

      // Use array_values to avoid sending associative keys to the client
      $rows[] = array_merge($orderedRow, array_values($row));
    }

    return array(
      'columns' => $columns,
      'rows' => $rows,
      'total' => $total
    );
  }

  public function getUsersSettings($data){
    $postData = $data->get_params();
    $settingsFromMetas = get_user_meta($postData['user_id'], 'bettertables-users-settings');

    if(!is_array($settingsFromMetas)){
      $settingsFromMetas = array();
    }else{
      $settingsFromMetas = $settingsFromMetas[0];
    }

    $fieldsTitles = $this->getColumnLabels();
    $cols = $this->getColumns($postData['user_id'], $this->settings['crmComponent']->getContactsByCategory(-1, true)[0], true);
    foreach($cols as $colname => $value){
      $label = $fieldsTitles[$colname] ?? strtoupper($colname);
      $cols[$colname] = [$label, true];
    }

    return array(
      'per_page' => !empty($settingsFromMetas['per_page']) ? $settingsFromMetas['per_page'] : 10,
      'orderby' => !empty($settingsFromMetas['orderby']) ? $settingsFromMetas['orderby'] : 'id',
      'order' => !empty($settingsFromMetas['order']) ? $settingsFromMetas['order'] : 'asc',
      'columns' => !empty($settingsFromMetas['columns']) ? $settingsFromMetas['columns'] : $cols
    );
  }

  public function saveUsersSettings($data){
    $settings = $data->get_params();
    $saved = false;

    if($settings !== null){
      $userId = $settings['user_id'];
      unset($settings['user_id']);
      $saved = update_user_meta($userId, 'bettertables-users-settings', $settings);
    }

    return $saved;
  }

  public function getColumns($userId, $rawData, $omittActionColumns = false){
    $columns = $omittActionColumns ? array() : array(
      'Edit' => 'Edit',
      'Delete' => 'Delete'
    );
    $fieldsTitles = $this->getColumnLabels();

    $userSettings = get_user_meta($userId, 'bettertables-users-settings');
    $userSettings = is_array($userSettings) ? $userSettings[0] : [];

    foreach ($rawData as $colname => $value){
      if(!empty($userSettings) && is_array($userSettings['columns']) && $userSettings['columns'][$colname][1] !== true){
        continue;
      }

      // Find the custom field title in the CRM custom fields array
      $columns[$colname] = $fieldsTitles[$colname] ?? ucfirst($colname);
    }

    return $columns;
  }

  public function getColumnLabels(){
    $customFields = CrmCore::getInstance()->getCustomFields(false);
    return array_combine(array_column($customFields, 'segmenting-slug'), array_column($customFields, 'title'));
  }
}
