<?php
namespace LBWP\Theme\Feature\BetterTables;

use LBWP\Module\Frontend\HTMLCache;

/**
 * Make WordPress Post Tables better
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class BetterPostTables extends BetterTables{
  /**
   * The post type to display in the table
   * @var string
   */
  protected $postType = 'post';

  /**
   * Custom columns configuration
   * @var array
   */
  protected $customColumns = array();

  /**
   * Default fields override, if provided via constructor
   * @var array|null
   */
  protected $defaultFields = null;

  /**
   * Entity type for this table
   * @var string
   */
  protected $entityType = 'posts';

  public function __construct($settings = array()){
    if (isset($settings['postType'])) {
      $this->postType = $settings['postType'];
    }
    if (isset($settings['customColumns'])) {
      $this->customColumns = $settings['customColumns'];
    }
    if (isset($settings['defaultFields'])) {
      $this->defaultFields = $settings['defaultFields'];
    }
    parent::__construct($settings);
  }

  public function replaceWPPage(){
    add_action('load-edit.php', array($this, 'replacePostPage'));
  }

  public function replacePostPage(){
    // Only replace for our configured post type
    $screen = get_current_screen();
    if ($screen && $screen->post_type !== $this->postType) {
      return;
    }

    // Don't replace if we're doing a bulk action
    if (isset($_REQUEST['action']) && $_REQUEST['action'] !== '-1') {
      return;
    }

    if (isset($_GET['clear_postmeta'])) {
      delete_user_meta(get_current_user_id(), 'bettertables-posts-settings-' . $this->postType);
    }

    require_once ABSPATH . '/wp-admin/admin.php';
    require_once ABSPATH . 'wp-admin/admin-header.php';

    $postTypeObject = get_post_type_object($this->postType);
    $title = $postTypeObject ? $postTypeObject->labels->name : __('Posts');

    echo '<div class="wrap">
      <h1 class="wp-heading-inline">
        ' . esc_html($title) . '
      </h1>
      <a href="' . admin_url('post-new.php?post_type=' . $this->postType) . '" class="page-title-action">' . __('Add New') . '</a>
      <div>
        <div id="js-root"></div>
      </div>
    </div>';

    require_once ABSPATH . 'wp-admin/admin-footer.php';
    die();
  }

  /**
   * Check if we're on the correct admin page for posts
   * @return bool
   */
  protected function isCorrectPage(){
    global $pagenow, $typenow;
    return $pagenow === 'edit.php' && $typenow === $this->postType;
  }

  public function enqueueScripts(){
    parent::enqueueScripts();

    // Add post-specific configuration
    wp_localize_script('lbwp-better-tables', 'lbwpBetterTablesPosts', array(
      'post_type' => $this->postType
    ));
  }

  public function registerApiEndpoints(){
    register_rest_route('lbwp/bettertables', 'posts', array(
      'methods' => 'GET',
      'callback' => array($this, 'getPosts'),
      'permission_callback' => function () {
        return current_user_can('edit_posts');
      }
    ));

    register_rest_route('lbwp/bettertables', 'get_posts_settings', array(
      'methods' => 'POST',
      'callback' => array($this, 'getPostsSettings'),
      'permission_callback' => function () {
        return current_user_can('edit_posts');
      }
    ));

    register_rest_route('lbwp/bettertables', 'save_posts_settings', array(
      'methods' => 'POST',
      'callback' => array($this, 'savePostsSettings'),
      'permission_callback' => function () {
        return current_user_can('edit_posts');
      }
    ));
  }

  public function getPosts(){
    HTMLCache::avoidCache();

    // Normalize and validate request parameters with sensible defaults
    $params = array_replace(
      array(
        'per_page' => 10,
        'page' => 1,
        'search' => '',
        'search_column' => '',
        'user_id' => 0,
        'post_type' => $this->postType
      ),
      $_GET
    );

    $perPage = max(1, intval($params['per_page']));
    $pageNr = max(1, intval($params['page']));
    $userId = intval($params['user_id']);
    $searchParam = trim((string)$params['search']);
    $searchColumnParam = trim((string)$params['search_column']);
    $postType = sanitize_text_field($params['post_type']);

    // Load user settings
    $userSettingsMeta = get_user_meta($userId, 'bettertables-posts-settings-' . $postType);
    $userSettings = is_array($userSettingsMeta) && !empty($userSettingsMeta[0]) ? $userSettingsMeta[0] : array();

    // Build WP_Query arguments
    $queryArgs = array(
      'post_type' => $postType,
      'posts_per_page' => $perPage,
      'paged' => $pageNr,
      'post_status' => array('publish', 'pending', 'draft', 'private'),
      'orderby' => isset($userSettings['orderby']) ? $userSettings['orderby'] : 'date',
      'order' => isset($userSettings['order']) ? strtoupper($userSettings['order']) : 'DESC'
    );

    // Handle search filters
    if ($searchParam !== '' && $searchColumnParam !== '') {
      $searchFor = array_map('trim', explode(',', $searchParam));
      $inColumn = array_map('trim', explode(',', $searchColumnParam));
      $pairs = min(count($searchFor), count($inColumn));

      $metaQuery = array('relation' => 'AND');

      for ($i = 0; $i < $pairs; $i++) {
        $col = $inColumn[$i];
        $needle = $searchFor[$i];

        if ($needle === '') {
          continue;
        }

        // Handle built-in columns
        if ($col === 'post_title') {
          $queryArgs['s'] = $needle;
        } elseif ($col === 'post_author') {
          // Search by author name
          $users = get_users(array(
            'search' => '*' . $needle . '*',
            'search_columns' => array('display_name', 'user_login', 'user_nicename'),
            'fields' => 'ID'
          ));
          if (!empty($users)) {
            $queryArgs['author__in'] = $users;
          } else {
            // No matching authors, return empty
            $queryArgs['author__in'] = array(0);
          }
        } elseif ($col === 'post_date') {
          // Date search - filter by specific date
          if (!empty($needle)) {
            $queryArgs['date_query'] = array(
              array(
                'year' => date('Y', strtotime($needle)),
                'month' => date('m', strtotime($needle)),
                'day' => date('d', strtotime($needle))
              )
            );
          }
        } elseif ($col === 'categories') {
          // Filter by category slug
          $queryArgs['category_name'] = $needle;
        } elseif ($col === 'tags') {
          // Filter by tag slug
          $queryArgs['tag'] = $needle;
        } elseif ($col === 'post_status') {
          $queryArgs['post_status'] = $needle;
        } else {
          // Assume it's a meta field
          $metaQuery[] = array(
            'key' => $col,
            'value' => $needle,
            'compare' => 'LIKE'
          );
        }
      }

      if (count($metaQuery) > 1) {
        $queryArgs['meta_query'] = $metaQuery;
      }
    }

    $query = new \WP_Query($queryArgs);
    $posts = $query->posts;
    $total = $query->found_posts;

    // Get columns configuration
    $columns = $this->getColumns($userId, $postType);

    // Build rows
    $rows = array();
    $columnKeys = array_keys($columns);

    // Remove action columns from keys for data mapping
    $dataKeys = array_filter($columnKeys, function($k) {
      return $k !== 'Edit' && $k !== 'Delete';
    });

    foreach ($posts as $post) {
      $row = array();

      foreach ($dataKeys as $key) {
        $row[] = $this->getColumnValue($post, $key);
      }

      // Build action links
      $editUrl = get_edit_post_link($post->ID, 'raw');
      $nonce = wp_create_nonce('delete-post_' . $post->ID);
      $deleteUrl = admin_url('post.php?action=trash&post=' . $post->ID . '&_wpnonce=' . $nonce);

      $orderedRow = array(
        '<a href="' . esc_url($editUrl) . '" class="dashicons-before dashicons-edit"></a>',
        '<a href="' . esc_url($deleteUrl) . '" class="dashicons-before dashicons-trash"></a>'
      );

      $rows[] = array_merge($orderedRow, $row);
    }

    return array(
      'columns' => $columns,
      'rows' => $rows,
      'total' => $total,
      'filters' => $this->getFilterOptions($postType)
    );
  }

  /**
   * Get filter options for select fields
   * @param string $postType
   * @return array
   */
  protected function getFilterOptions($postType){
    $filters = array();

    // Post statuses
    $statuses = get_post_statuses();
    $filters['post_status'] = array();
    foreach ($statuses as $slug => $label) {
      $filters['post_status'][] = array('value' => $slug, 'label' => $label);
    }

    // Categories
    $categories = get_categories(array(
      'hide_empty' => true,
      'orderby' => 'name',
      'order' => 'ASC'
    ));
    $filters['categories'] = array();
    foreach ($categories as $cat) {
      $filters['categories'][] = array('value' => $cat->slug, 'label' => $cat->name);
    }

    // Tags
    $tags = get_tags(array(
      'hide_empty' => true,
      'orderby' => 'name',
      'order' => 'ASC'
    ));
    $filters['tags'] = array();
    foreach ($tags as $tag) {
      $filters['tags'][] = array('value' => $tag->slug, 'label' => $tag->name);
    }

    return $filters;
  }

  /**
   * Get the value of a column for a post
   * @param \WP_Post $post
   * @param string $column
   * @return string
   */
  private function getColumnValue($post, $column) {
    switch ($column) {
      case 'ID':
      case 'post_id':
        return $post->ID;

      case 'post_title':
        return $post->post_title;

      case 'post_author':
        $author = get_user_by('id', $post->post_author);
        return $author ? $author->display_name : '';

      case 'post_date':
        return get_the_date('d.m.Y H:i', $post);

      case 'post_modified':
        return get_the_modified_date('d.m.Y H:i', $post);

      case 'post_status':
        $statuses = get_post_statuses();
        return isset($statuses[$post->post_status]) ? $statuses[$post->post_status] : $post->post_status;

      case 'post_type':
        $typeObj = get_post_type_object($post->post_type);
        return $typeObj ? $typeObj->labels->singular_name : $post->post_type;

      case 'categories':
        $terms = get_the_terms($post->ID, 'category');
        if ($terms && !is_wp_error($terms)) {
          return implode(', ', wp_list_pluck($terms, 'name'));
        }
        return '';

      case 'tags':
        $terms = get_the_terms($post->ID, 'post_tag');
        if ($terms && !is_wp_error($terms)) {
          return implode(', ', wp_list_pluck($terms, 'name'));
        }
        return '';

      case 'featured_image':
        $thumbId = get_post_thumbnail_id($post->ID);
        if ($thumbId) {
          $thumb = wp_get_attachment_image_src($thumbId, 'thumbnail');
          if ($thumb) {
            return '<img src="' . esc_url($thumb[0]) . '" style="max-width:50px;max-height:50px;" />';
          }
        }
        return '';

      case 'comment_count':
        return $post->comment_count;

      default:
        // Check for custom columns with callable renderers
        if (isset($this->customColumns[$column]) && is_callable($this->customColumns[$column])) {
          return call_user_func($this->customColumns[$column], $post);
        }
        // Try post meta (also covers custom columns passed as simple list)
        return (string) get_post_meta($post->ID, $column, true);
    }
  }

  public function getPostsSettings($data){
    $postData = $data->get_params();
    $userId = isset($postData['user_id']) ? intval($postData['user_id']) : get_current_user_id();
    $postType = isset($postData['post_type']) ? sanitize_text_field($postData['post_type']) : $this->postType;

    $settingsFromMetas = get_user_meta($userId, 'bettertables-posts-settings-' . $postType);

    if (!is_array($settingsFromMetas) || empty($settingsFromMetas[0])) {
      $settingsFromMetas = array();
    } else {
      $settingsFromMetas = $settingsFromMetas[0];
    }

    $cols = $this->getColumns($userId, $postType, true);
    $fieldsTitles = $this->getColumnLabels($postType);

    foreach ($cols as $colname => $value) {
      $label = isset($fieldsTitles[$colname]) ? $fieldsTitles[$colname] : ucfirst(str_replace('_', ' ', $colname));
      $cols[$colname] = array($label, true);
    }

    return array(
      'per_page' => !empty($settingsFromMetas['per_page']) ? $settingsFromMetas['per_page'] : 10,
      'orderby' => !empty($settingsFromMetas['orderby']) ? $settingsFromMetas['orderby'] : 'date',
      'order' => !empty($settingsFromMetas['order']) ? $settingsFromMetas['order'] : 'desc',
      'columns' => !empty($settingsFromMetas['columns']) ? $settingsFromMetas['columns'] : $cols
    );
  }

  public function savePostsSettings($data){
    $settings = $data->get_params();
    $saved = false;

    if ($settings !== null) {
      $userId = isset($settings['user_id']) ? intval($settings['user_id']) : get_current_user_id();
      $postType = isset($settings['post_type']) ? sanitize_text_field($settings['post_type']) : $this->postType;
      unset($settings['user_id']);
      unset($settings['post_type']);
      $saved = update_user_meta($userId, 'bettertables-posts-settings-' . $postType, $settings);
    }

    return $saved;
  }

  public function getColumns($userId, $postType = null, $omitActionColumns = false){
    if ($postType === null) {
      $postType = $this->postType;
    }

    $columns = $omitActionColumns ? array() : array(
      'Edit' => 'Edit',
      'Delete' => 'Delete'
    );

    $fieldsTitles = $this->getColumnLabels($postType);
    $userSettings = get_user_meta($userId, 'bettertables-posts-settings-' . $postType);
    $userSettings = is_array($userSettings) && !empty($userSettings[0]) ? $userSettings[0] : array();

    // Prioritize user settings order
    if (!empty($userSettings) && is_array($userSettings['columns'] ?? null)) {
      foreach ($userSettings['columns'] as $colname => $config) {
        if ($config[1] === true) {
          if (!$omitActionColumns && ($colname === 'Edit' || $colname === 'Delete')) {
            continue;
          }
          $columns[$colname] = isset($fieldsTitles[$colname]) ? $fieldsTitles[$colname] : ucfirst(str_replace('_', ' ', $colname));
        }
      }
    }

    // Add default columns if no settings exist
    if (empty($userSettings['columns'])) {
      $defaultColumns = $this->getDefaultColumns($postType);
      foreach ($defaultColumns as $colname) {
        $columns[$colname] = isset($fieldsTitles[$colname]) ? $fieldsTitles[$colname] : ucfirst(str_replace('_', ' ', $colname));
      }
    }

    return $columns;
  }

  /**
   * Get default columns for a post type
   * @param string $postType
   * @return array
   */
  protected function getDefaultColumns($postType){
    if ($this->defaultFields !== null) {
      $defaults = $this->defaultFields;
    } else {
      $defaults = array(
        'ID',
        'post_title',
        'post_author',
        'post_date',
        'post_status'
      );
    }

    // Add categories and tags for 'post' type
    if ($postType === 'post') {
      $defaults[] = 'categories';
      $defaults[] = 'tags';
    }

    // Add any custom columns from settings
    if (!empty($this->customColumns)) {
      // Support both simple list ['key1', 'key2'] and associative ['key1' => callable]
      $customKeys = array_values($this->customColumns) === $this->customColumns
        ? $this->customColumns
        : array_keys($this->customColumns);
      $defaults = array_merge($defaults, $customKeys);
    }

    return $defaults;
  }

  /**
   * Get column labels
   * @param string $postType
   * @return array
   */
  public function getColumnLabels($postType = null){
    $labels = array(
      'ID' => 'ID',
      'post_id' => 'ID',
      'post_title' => __('Title'),
      'post_author' => __('Author'),
      'post_date' => __('Date'),
      'post_modified' => __('Modified'),
      'post_status' => __('Status'),
      'post_type' => __('Type'),
      'categories' => __('Categories'),
      'tags' => __('Tags'),
      'featured_image' => __('Featured Image'),
      'comment_count' => __('Comments')
    );

    // Add custom column labels
    if (!empty($this->settings['customLabels'])) {
      $labels = array_merge($labels, $this->settings['customLabels']);
    }

    return $labels;
  }
}