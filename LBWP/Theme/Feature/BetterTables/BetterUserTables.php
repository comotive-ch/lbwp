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

    require_once ABSPATH . '/wp-admin/admin.php';
    require_once ABSPATH . 'wp-admin/admin-header.php';

    echo '<div class="wrap">
      <h1 class="wp-heading-inline">
        ' . __('Users') . '
      </h1>
      <div>
        <div id="react-root"></div>
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

  private function searchFlatTable(){}

  private function searchCachedArray(){
    // Request validation
    $perPage = intval($_GET['per_page']);
    $pageNr = intval($_GET['page']);

    // TODO: add other order and orderby filters

    /** @var CrmCore $crm cached data from CRM */
    $crm = $this->settings['crmComponent'];
    $raw = $crm->getContactsByCategory(-1, true);
    // Create basic columns (no name yet, TODO)
    $columns = $this->getColumns($_GET['user_id'], $raw[0]);
    $userSettings = get_user_meta($_GET['user_id'], 'bettertables-users-settings')[0];

    // Actually search
    if(!empty($_GET['search']) && !empty($_GET['search_column'])){
      $search = array();
      $searchFor = explode(',', $_GET['search']);
      $inColumn = explode(',', $_GET['search_column']);

      foreach($raw as $index => $data){
        $found = [];

        // Search multiple values ind multiple columns
        for($i = 0; $i < count($searchFor); $i++){
          $found[] = isset($data[$inColumn[$i]]) && Strings::contains($data[$inColumn[$i]], $searchFor[$i]);
        }

        if(!in_array(false, $found)){
          $search[$index] = $data;
        }
      }

      // Override raw with search results
      $raw = $search;
    }

    // Order by column
    usort($raw, function($a, $b) use ($userSettings){
      $order = $userSettings['order'] ?? 'asc';
      $orderby = $userSettings['orderby'] ?? 'user_id';

      if($order == 'asc'){
        return strnatcmp($a[$orderby], $b[$orderby]);
      }else{
        return strnatcmp($b[$orderby], $a[$orderby]);
      }
    });

    $total = count($raw);

    // Basic slice for paging as example
    $rows = array_slice($raw, max($pageNr-1, 0) * $perPage, $perPage);
    // Rework to zero based array for less data transfer after searchghing/paging
    foreach ($rows as $key => $row){
      if(is_array($userSettings) && isset($userSettings['columns'])){
        $row = array_filter($row, function ($value, $key) use ($userSettings) {
          return isset($userSettings['columns'][$key]) && $userSettings['columns'][$key][1];
        }, ARRAY_FILTER_USE_BOTH);
      }

      $deleteUrl = wp_nonce_url(
        admin_url('users.php?action=delete&user=' . $row['userid']),
        'delete-user_' . $row['userid']
      );
      $orderedRow = array(
        '<a href="/wp-admin/user-edit.php?user_id=' . $row['userid'] . '" class="dashicons-before dashicons-edit"></a>',
        '<a href="' . $deleteUrl . '" class="dashicons-before dashicons-trash"></a>'
      );
      $rows[$key] = array_merge($orderedRow, array_values($row));
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

    $cols = $this->getColumns($postData['user_id'], $this->settings['crmComponent']->getContactsByCategory(-1, true)[0], true);
    foreach($cols as $colname => $value){
      $cols[$colname] = [strtoupper($colname), true];
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

    $userSettings = get_user_meta($userId, 'bettertables-users-settings');
    $userSettings = is_array($userSettings) ? $userSettings[0] : [];

    foreach ($rawData as $colname => $value){
      if(!empty($userSettings) && is_array($userSettings['columns']) && $userSettings['columns'][$colname][1] !== true){
        continue;
      }

      $columns[$colname] = $colname;
    }

    return $columns;
  }
}
