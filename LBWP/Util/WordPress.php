<?php

namespace LBWP\Util;

use LBWP\Module\Backend\S3Upload;
use LBWP\Module\Frontend\OutputFilter;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Feature\SortableTypes;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use \WP_Error;

/**
 * Utility functions for WordPress
 * @author Michael Sebel <michael@comotive.ch>
 */
class WordPress
{
  /**
   * Constants to remove core menus
   */
  const MENU_ID_POSTS = 'edit.php';
  const MENU_ID_PAGES = 'edit.php?post_type=page';
  const MENU_ID_MEDIA = 'upload.php';
  const MENU_ID_FORMS = 'forms';
  const MENU_ID_DESIGN = 'themes.php';
  const MENU_ID_COMMENTS = 'edit-comments.php';
  /**
   * @var array post types to be restricted with taxonomies
   */
  protected static $restrictPostTables = array();
  /**
   * @var array additional columns for post tables
   */
  protected static $postTableColumns = array();

  /**
   * Registers a taxonomy
   * @param string $slug the slug of the taxonomy
   * @param string $singular singular name
   * @param string $plural plural name
   * @param string $letter letter after "Übergeordnete" and "Neue" -> Could be "n" or "s"
   * @param array $config override the configuration with this array
   * @param string $types the types to be assigned (defaults to "post", can be an array)
   */
  public static function registerTaxonomy($slug, $singular, $plural, $letter = '', $config = array(), $types = 'post')
  {
    $defaults = array(
      'hierarchical' => true,
      'public' => true,
      'show_ui' => true,
      'show_tagcloud' => false,
      'labels' => array(
        'name' => $singular,
        'singular_name' => $singular,
        'search_items' => $plural . ' suchen',
        'popular_items' => '',
        'all_items' => 'Alle ' . $plural,
        'view_item' => $singular . ' ansehen',
        'parent_item' => 'Übergeordnete' . $letter . ' ' . $singular,
        'parent_item_colon' => 'Übergeordnete' . $letter . ' ' . $singular . ':',
        'edit_item' => $singular . ' bearbeiten',
        'update_item' => $singular . ' speichern',
        'add_new_item' => 'Neue' . $letter . ' ' . $singular . ' hinzufügen',
        'new_item_name' => 'Neue' . $letter . ' ' . $singular,
        'separate_items_with_commas' => $plural . ' durch Komma trennen',
        'add_or_remove_items' => $plural . ' hinzufügen oder entfernen',
        'menu_name' => $plural
      )
    );

    // Deep merge the defaults
    $mergedConfig = array();
    foreach ($defaults as $key => $value) {
      if (is_array($value) && isset($config[$key])) {
        $mergedConfig[$key] = array_merge($defaults[$key], $config[$key]);
      } else {
        $mergedConfig[$key] = isset($config[$key]) ? $config[$key] : $value;
      }
    }

    // Add configs that are not in defaults
    foreach ($config as $key => $value) {
      if (!isset($mergedConfig[$key])) {
        $mergedConfig[$key] = $value;
      }
    }

    register_taxonomy($slug, $types, $mergedConfig);
  }

  /**
   * Checks calls to a specified signature on an ip and blocks the ip if the thresholds are met
   * @param string $signature name of the signature
   * @param int $testtime seconds to count up until reset
   * @param int $calls max requests within test time to not get blocked
   * @param int $blocktime number of seconds the ip is blocked
   */
  public static function checkSignature($signature, $testtime, $calls, $blocktime)
  {
    if (class_exists('\Comotive\Firewall\Defender')) {
      $firewall = new \Comotive\Firewall\Defender();
      $firewall->checkIpSignature($signature, $testtime, $calls, $blocktime);
    }
  }

  /**
   * Removes a certain signature (resets its count to be blocked)
   * @param string $signature the signature id
   */
  public static function resetSignature($signature)
  {
    if (class_exists('\Comotive\Firewall\Defender')) {
      $firewall = new \Comotive\Firewall\Defender();
      $firewall->resetIpSignature($signature);
    }
  }

  /**
   * @param string $query
   * @return void
   */
  public static function getOptionsByQuery($query)
  {
    $db = self::getDb();
    return $db->get_col('SELECT option_name FROM ' . $db->options . ' WHERE option_name LIKE "%' . $query . '%"');
  }

  /**
   * @param string $plugin plugin file path
   * @return bool true if plugin is active
   */
  public static function isPluginActive($plugin)
  {
    return in_array($plugin, (array) get_option('active_plugins', array()));
  }

  /**
   * @param $type
   * @param $status
   * @return array|object|\stdClass[]|null
   */
  public static function getPostNameListByType($type, $status = 'publish')
  {
    $db = self::getDb();
    return $db->get_results('
      SELECT ID, post_name FROM ' . $db->posts . '
      WHERE post_type = "' . $type . '" AND post_status = "' . $status . '"
    ');
  }

  /**
   * Run a callback when something of a type is changed (saved, deleted, trashed, transitioned)
   * @param $type
   * @param $callback
   */
  public static function onTypeChange($type, $callback)
  {
    add_action('save_post_' . $type, function() use($callback) {
      call_user_func($callback);
    });
    add_action('delete_post', function($postId) use ($callback, $type) {
      if (get_post($postId)->post_type == $type) {
        call_user_func($callback);
      }
    });
    add_action('transition_post_status', function($old, $new, $post) use ($callback, $type) {
      if ($post->post_type == $type) {
        call_user_func($callback);
      }
    }, 10, 3);
    add_action('LBWP_SortableTypes_after_saving', function () use($callback) {
      call_user_func($callback);
    });
  }

  /**
   * @param $postId
   * @param $taxonomy
   * @param $field
   * @return array
   */
  public static function getTermFieldList($postId, $taxonomy, $field)
  {
    $fields = array();
    foreach (wp_get_post_terms($postId, $taxonomy) as $term) {
      $fields[] = $term->{$field};
    }
    return $fields;
  }

  /**
   * Registering a post type
   * @param string $type slug of the type
   * @param string $singular singular name
   * @param string $plural plural name
   * @param string $letter to be added to "neue"
   * @param array $config can override the defaults of this function (array_merge)
   */
  public static function registerType($type, $singular, $plural, $config = array(), $letter = 's')
  {
    $defaults = array(
      'label' => $plural,
      'labels' => array(
        'name' => $plural,
        'singular_name' => $singular,
        'add_new' =>  __('Erstellen', 'lbwp'),
        'add_new_item' =>  'Neue' . $letter . ' ' . $singular . ' erfassen',
        'edit_item' =>  'Bearbeite ' . $singular,
        'new_item' =>  'Neue' . $letter . ' ' . $singular,
        'view_item' =>  $singular . ' ansehen',
        'search_items' =>  $singular . ' suchen',
        'not_found' =>  'Keine ' . $plural . ' gefunden',
        'not_found_in_trash' =>  'Keine ' . $plural . ' im Papierkorb gefunden',
        'parent_item_colon' => '',
        'featured_image' => _x('Featured image', 'page'),
        'set_featured_image' => _x('Set featured image', 'page'),
        'remove_featured_image' => _x('Remove featured image', 'page'),
        'use_featured_image' => _x('Use as featured image', 'page'),
      ),
      'public' => true,
      'has_archive' => true
    );

    // Deep merge the defaults
    $mergedConfig = array();
    foreach ($defaults as $key => $value) {
      if (is_array($value) && isset($config[$key])) {
        $mergedConfig[$key] = array_merge($defaults[$key], $config[$key]);
      } else {
        $mergedConfig[$key] = $value;
      }
    }

    // Add configs that are not in defaults
    foreach ($config as $key => $value) {
      $mergedConfig[$key] = $value;
    }

    register_post_type($type, $mergedConfig);
  }

  /**
   * @param $url
   * @param $parentId
   * @return int|WP_Error
   */
  public static function createAttachmentImageFromUrl($url, $parentId)
  {
    $fileName = File::getFileOnly($url);
    Strings::alphaNumFiles($fileName);
    $tempFile = File::getNewUploadFolder() . $fileName;
    // Get binary
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $binaryData = curl_exec($ch);
    curl_close($ch);
    // Get binary of image with curl
    file_put_contents($tempFile, $binaryData);
    return WordPress::createAttachmentImageFromFile($tempFile, $parentId);
  }

  /**
   * Sideloads a non image file from url
   * @param $url
   * @param $parentId
   * @return int|WP_Error
   */
  public static function createAttachmentFromUrl($url, $parentId)
  {
    $fileName = File::getFileOnly($url);
    Strings::alphaNumFiles($fileName);
    $tempFile = File::getNewUploadFolder() . $fileName;
    // Get binary
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $binaryData = curl_exec($ch);
    // Get binary of file with curl
    file_put_contents($tempFile, $binaryData);
    curl_close($ch);

    if (!function_exists('wp_generate_attachment_metadata')){
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      require_once(ABSPATH . 'wp-admin/includes/media.php');
    }

    $wp_upload_dir = wp_upload_dir();
    $filetype = wp_check_filetype(basename($tempFile), null);
    // Prepare an array of post data for the attachment.
    $attachment = array(
      'guid'           => $wp_upload_dir['url'] . '/' . basename( $tempFile ),
      'post_mime_type' => $filetype['type'],
      'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $tempFile ) ),
      'post_content'   => '',
      'post_status'    => 'inherit'
    );

    // Insert the attachment, this starts the upload
    $attachmentId = wp_insert_attachment($attachment, $tempFile, $parentId);
    $localPathPart = substr($tempFile, stripos($tempFile, ASSET_KEY) + strlen(ASSET_KEY) + 1);
    update_post_meta($attachmentId, '_wp_attached_file', $localPathPart);
    update_post_meta($attachmentId, '_wp_attachment_metadata', array(
      'filesize' => filesize($tempFile),
    ));
    /** @var S3Upload $s3 Upload manually as it doesn't work with the filters */
    $s3 = LbwpCore::getModule('S3Upload');
    $remoteName = ASSET_KEY . '/files/' . $localPathPart;
    $s3->uploadDiskFileFixedPath($tempFile, $remoteName, $filetype['type'], true);

    return $attachmentId;
  }

  /**
   * Beware: This function *deletes* the folder containing the files, use new folder everytime it is called
   * @param string $file local path to an image file
   * @param int $parentId the parent to attach the image to
   */
  public static function createAttachmentImageFromFile($file, $parentId)
  {
    if (!function_exists('wp_generate_attachment_metadata')){
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      require_once(ABSPATH . 'wp-admin/includes/media.php');
    }

    // Check the type of file
    $filetype = wp_check_filetype(basename($file), null);
    $wp_upload_dir = wp_upload_dir();

    // Prepare an array of post data for the attachment.
    $attachment = array(
      'guid'           => $wp_upload_dir['url'] . '/' . basename( $file ),
      'post_mime_type' => $filetype['type'],
      'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file ) ),
      'post_content'   => '',
      'post_status'    => 'inherit'
    );

    try {
      // Insert the attachment, this starts the upload
      $attachmentId = wp_insert_attachment($attachment, $file, $parentId);
      // Generate the metadata for the attachment, and update the database record.
      $attachmentData = wp_generate_attachment_metadata($attachmentId, $file);
      wp_update_attachment_metadata($attachmentId, $attachmentData);
    } catch (\Exception $e) {
      $attachmentId = 0;
      SystemLog::mDebug('error importing file ' . $file . ' as attachment: ' . $e->getMessage());
    }

    return $attachmentId;
  }

  /**
   * @param string $fileId id from $_FILES
   * @param bool $validateImage makes sure, it's an image
   * @param int $validateHeight if set, makes sure, that the image is at least the given height
   * @param int $validateWidth if set, makes sure, that the image is at least the given height
   * @return int attachment id of the uploaded item
   */
  public static function uploadAttachment($fileId, $validateImage, $validateHeight = 0, $validateWidth = 0)
  {
    if (!function_exists('wp_generate_attachment_metadata')){
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      require_once(ABSPATH . 'wp-admin/includes/media.php');
    }

    // Check for images, if needed, and return 0 if no image
    if ($validateImage) {
      $file = $_FILES[$fileId];
      if (!File::isImage($file['name']) || !File::isImageMime($file['type'])) {
        return 0;
      }
    }

    // Get image dimensions, if needed and validate them
    if ($validateHeight > 0 || $validateWidth > 0) {
      list($imageWidth, $imageHeight) = getimagesize($file['tmp_name']);
      if ($validateHeight > 0 && $imageHeight < $validateHeight) {
        return 0;
      }
      if ($validateWidth > 0 && $imageWidth < $validateWidth) {
        return 0;
      }
    }

    // Run the update
    try {
      $result = media_handle_upload($fileId, 0);
    } catch (\Exception $e) {
      $result = false;
      SystemLog::mDebug('error importing file ' . $file . ' as attachment: ' . $e->getMessage());
    }

    // Check for errors
    if ($result instanceof WP_Error || $result === false) {
      return 0;
    }

    return intval($result);
  }

  /**
   * Can be used to get all meta, but single values stay single
   * @param int $postId the post to get all meta data
   * @return array
   */
  public static function getAccessiblePostMeta($postId, $skipInternal = false)
  {
    $meta = get_post_meta($postId);
    $newMeta = array();
    foreach ($meta as $key => $list) {
      if ($skipInternal && $key[0] == '_') {
        continue;
      }
      if (count($list) == 1) {
        $newMeta[$key] = maybe_unserialize($list[0]);
      } else {
        $newMeta[$key] = $list;
      }
    }

    return $newMeta;
  }

  /**
   * Can be used to get all meta, but single values stay single
   * @param int $userId the user to get all meta data
   * @return array
   */
  public static function getAccessibleUserMeta($userId)
  {
    $meta = get_user_meta($userId);
    $newMeta = array();
    foreach ($meta as $key => $list) {
      if (count($list) == 1) {
        $newMeta[$key] = $list[0];
      } else {
        $newMeta[$key] = $list;
      }
    }

    return $newMeta;
  }

  /**
   * @param \WP_Post $post the post to be checked
   * @return bool true if displayable
   */
  public static function isDisplayable($post)
  {
    if (current_user_can('edit_posts')) {
      return isset($post->post_status) && $post->post_status != 'trash';
    }

    return $post->post_status == 'publish';
  }


  /**
   * PLase do not use this unless it is ultimetely needed. This function might be shaky.
   * @param int $id id of the post
   * @param int $length count of words
   * @param string $more full more link
   * @param bool $force_more show always the more link
   * @param bool $addDots add dots if text ist cutted
   * @return mixed|void html
   */
  public static function getConfigurableExcerpt($id, $length, $more, $force_more = false, $addDots = false, $dots = '... ')
  {
    $done_more = false;
    $post = get_post($id);
    $text = $post->post_excerpt;

    if ($text == '') {
      if (strlen($post->post_content) > 0) {
        $text = wpautop(preg_replace('#\[(.+?)\]#', '', $post->post_content));
        // Shorten the text if it has a more tag
        $morePos = strpos($text, '<!--more-->');
        if ($morePos !== false) {
          $text = substr($text, 0, $morePos);
        }

        // Clean up from html
        $text = str_replace(']]>', ']]&gt;', $text);
        $text = strip_tags($text);

        $words = explode(' ', $text, $length + 1);
        if (count($words) > $length) {
          array_pop($words);
          $text = implode(' ', $words);
          if ($addDots) {
            $text .= $dots;
          }
          $text .= $more;
          $done_more = true;
        }
      }
    }

    if ($done_more == false && $force_more == true) {
      $text = $text . $more;
    }

    return apply_filters('get_configurable_excerpt', $text);
  }

  /**
   * @param string $html
   * @return string html with ssl links
   */
  public static function handleSslLinks($html)
  {
    // This is mainly for CDNs that have HTTP/HTTPS handly seperately
    if (defined('WP_FORCE_SSL') && WP_FORCE_SSL) {
      $replacements = array();

      // Replace the host name for sure (just to be sure)
      $replacements[] = array('http://' . LBWP_HOST, 'https://' . LBWP_HOST);

      // Replace http name with https name (again, just to be sure
      if (defined('CDN_HTTP_NAME')) {
        $replacements[] = array('http://' . CDN_HTTP_NAME, 'https://' . CDN_NAME);
        $replacements[] = array('https://' . CDN_HTTP_NAME, 'https://' . CDN_NAME);
      }

      foreach ($replacements as $replacement) {
        $html = str_replace($replacement[0], $replacement[1], $html);
        $replacement[0] = str_replace('/', '\/', $replacement[0]);
        $replacement[1] = str_replace('/', '\/', $replacement[1]);
        $html = str_replace($replacement[0], $replacement[1], $html);
      }
    }

    return $html;
  }

  /**
   * @param string $name post_name
   * @param string|array $posttype post_type or array of
   * @return int the found id
   */
  public static function getPostIdByName($name, $posttype)
  {
    $wpdb = self::getDb();
    $posttype = ArrayManipulation::forceArrayAndInclude($posttype);
    // Get id by simple query
    $sql = 'SELECT ID FROM {sql:postTable} WHERE post_type IN("{raw:postTypes}") AND post_name = {postName}';
    $postId = $wpdb->get_var(Strings::prepareSql($sql, array(
      'postTable' => $wpdb->posts,
      'postTypes' => implode('","', $posttype),
      'postName' => $name
    )));

    return intval($postId);
  }

  /**
   * @param string $guid the guid
   * @param string|array $posttype post_type or array of
   * @return int the found id
   */
  public static function getPostIdByGuid($guid, $posttype)
  {
    $wpdb = self::getDb();
    $posttype = ArrayManipulation::forceArrayAndInclude($posttype);
    // Get id by simple query
    $sql = 'SELECT ID FROM {sql:postTable} WHERE post_type IN("{raw:postTypes}") AND guid = {postGuid}';
    $postId = $wpdb->get_var(Strings::prepareSql($sql, array(
      'postTable' => $wpdb->posts,
      'postTypes' => implode('","', $posttype),
      'postGuid' => $guid
    )));

    return intval($postId);
  }

  /**
   * @param array $list a list of integer ids
   * @return \WP_Post[] object list, can be empty if validation removes some items
   */
  public static function getValidatedPostObjects($list)
  {
    $objects = array();
    // Only get published objects
    foreach ($list as $id) {
      $object = get_post(intval($id));
      if ($object instanceof \WP_Post && $object->post_status != 'trashed') {
        $objects[] = $object;
      }
    }

    return $objects;
  }

  /**
   * @param array $list a list of integer ids
   * @return \WP_Post[] object list, can be empty if validation removes some items
   */
  public static function getPublishedPostObjects($list)
  {
    $objects = array();
    // Only get published objects
    foreach ($list as $id) {
      $object = get_post(intval($id));
      if ($object instanceof \WP_Post && $object->post_status == 'publish') {
        $objects[] = $object;
      }
    }

    return $objects;
  }

  /**
   * Allows to remove core columns from post lists tables
   * @param $args
   * @return void
   */
  public static function removePostTableColumns($args)
  {
    add_filter('manage_posts_columns' , function($columns) use ($args) {
      if ($args['post_type'] == $_GET['post_type']) {
        foreach ($args['column_keys'] as $key) {
          unset($columns[$key]);
        }
      }
      return $columns;
    });
  }

  /**
   * @param array $args contains post_type, meta_key, multiple, heading, callback (optional)
   */
  public static function addPostTableColumn($args)
  {
    // Register the filters, if first call
    if (count(self::$postTableColumns) == 0) {
      add_filter('manage_posts_columns', array('\LBWP\Util\WordPress', 'addPostTableColumnHeader'));
      add_action('manage_posts_custom_column', array('\LBWP\Util\WordPress', 'addPostTableColumnCell'), 10, 2);
    }

    // Also, make that sortable if needed
    if (isset($args['sortable'])) {
      add_filter('manage_edit-' . $args['post_type'] . '_sortable_columns', function($columns) use ($args) {
        $columns[$args['column_key']] = $args['sortable'];
        return $columns;
      });
      if (isset($_GET['orderby']) && $_GET['orderby'] == $args['sortable'] && str_starts_with($args['sortable'], 'meta:')) {
        list($null, $metaField) = explode(':', $args['sortable']);
        add_action('pre_get_posts', function($query) use ($metaField) {
          $query->set('orderby', 'meta_value');
          $query->set('meta_query', array(
            'relation' => 'OR',
            array('key' => $metaField, 'compare' => 'NOT EXISTS'),
            array('key' => $metaField, 'compare' => 'EXISTS')
          ));
        });
      }
    }

    self::$postTableColumns[$args['column_key']] = $args;
  }

  /**
   * @param $columns
   * @return mixed
   */
  public static function addPostTableColumnHeader($columns)
  {
    foreach (self::$postTableColumns as $config) {
      if ($config['post_type'] == $_GET['post_type']) {
        $columns[$config['column_key']] = $config['heading'];
      }
    }

    return $columns;
  }

  /**
   * @param $key
   * @param $postId
   */
  public static function addPostTableColumnCell($key, $postId)
  {
    if (isset(self::$postTableColumns[$key])) {
      $config = self::$postTableColumns[$key];
      // Get the meta value of the post
      $value = '';
      if (isset($config['meta_key'])) {
        $value = get_post_meta($postId, $config['meta_key'], $config['single']);
      }
      // See if there is a callback
      if (isset($config['callback']) && is_callable($config['callback'])) {
        call_user_func($config['callback'], $value, $postId);
      } else {
        // If there is no callback, simple print the value of the meta field
        echo $value;
      }
    }
  }

  /**
   * Allows to restrict post tables with taxonomy dropdowns
   */
  public static function restrictPostTable($args)
  {
    // Register the filters, if first call
    if (count(self::$restrictPostTables) == 0) {
      add_action('restrict_manage_posts', array('\LBWP\Util\WordPress', 'restrictPostTableFilter'));
      add_filter('parse_query', array('\LBWP\Util\WordPress', 'restrictPostTableQuery'));
    }

    self::$restrictPostTables[] = $args;
  }

  /**
   * Restricts to all registered type/tax combinations
   */
  public static function restrictPostTableFilter()
  {
    global $typenow;
    foreach (self::$restrictPostTables as $item) {
      $type = $item['type'];
      if ($typenow == $type) {
        // Handle taxonomy restrictors
        if (isset($item['taxonomy'])) {
          $taxonomy = $item['taxonomy'];
          $selected = isset($_GET[$taxonomy]) ? $_GET[$taxonomy] : '';
          $html = wp_dropdown_categories(array(
            'show_option_all' => $item['all_label'],
            'taxonomy' => $taxonomy,
            'name' => $taxonomy,
            'orderby' => $item['orderby'],
            'selected' => $selected,
            'echo' => false,
            'show_count' => $item['show_count'],
            'hide_empty' => $item['hide_empty'],
          ));
          // Only display if there is at least one option
          if (Strings::contains($html, '<option')) {
            echo $html;
          }
        }

        // Handle meta restrictors
        if (isset($item['meta'])) {
          $html = '
            <select name="lbwp_meta[' . $item['meta'] . ']">
              <option value="">' . $item['all_label'] . '</option>
          ';
          foreach ($item['options'] as $key => $value) {
            $selected = selected($key, $_GET['lbwp_meta'][$item['meta']], false);
            $html .= '<option value="' . $key . '"' . $selected . '>' . $value . '</option>';
          }
          echo $html;
        }
      };
    }
  }

  /**
   * Restricts to all registered type/tax combinations
   * @param \WP_Query $query
   */
  public static function restrictPostTableQuery($query)
  {
    global $pagenow;
    foreach (self::$restrictPostTables as $item) {
      $type = $item['type'];
      $vars = &$query->query_vars;

      // Handle taxonomy filters
      if (isset($item['taxonomy'])) {
        $taxonomy = $item['taxonomy'];
        if ($pagenow == 'edit.php' && isset($vars['post_type']) && $vars['post_type'] == $type && isset($vars[$taxonomy]) && is_numeric($vars[$taxonomy]) && $vars[$taxonomy] != 0) {
          $term = get_term_by('id', $vars[$taxonomy], $taxonomy);
          $vars[$taxonomy] = $term->slug;
        }
      }

      // Handle at least one meta filter
      if (isset($item['meta'])) {
        if ($pagenow == 'edit.php' && isset($vars['post_type']) && $vars['post_type'] == $type && isset($_GET['lbwp_meta'][$item['meta']]) && strlen($_GET['lbwp_meta'][$item['meta']]) > 0) {
          $vars['meta_key'] = $item['meta'];
          $vars['meta_value'] = $_GET['lbwp_meta'][$item['meta']];
        }
      }
    }
  }

  /**
   * @param string $key the searching key
   * @return array list of found options keys
   */
  public static function searchOptionKeys($key)
  {
    $db = self::getDb();
    $sql = 'SELECT option_name FROM {sql:optionTable} WHERE option_name LIKE "{raw:keySearch}"';
    return $db->get_col(Strings::prepareSql($sql, array(
      'optionTable' => $db->prefix . 'options',
      'keySearch' => '%' . $key . '%'
    )));
  }

  /**
   * Updates a post natively in the database without firing all actions
   * @param array $post a post array like for wp_update_post
   */
  public static function updatePostNative($post)
  {
    $db = self::getDb();
    $postId = intval($post['ID']);
    unset($post['ID']);
    // Update in database directly
    $db->update(
      $db->posts,
      $post,
      array('ID' => $postId)
    );
    // Flush cache for that post
    clean_post_cache($postId);
  }

  /**
   * @param string $key the option key
   * @return array the encoded option or false
   */
  public static function getJsonOption($key)
  {
    $result = json_decode(get_option($key, false), true);
    return ($result == NULL) ? false : $result;
  }

  /**
   * @param string $key the option key
   * @param array $value the jsonizable object
   */
  public static function updateJsonOption($key, $value)
  {
    update_option($key, json_encode($value), false);
  }

  /**
   * Sets correct heads for json output
   * @param array $result the array that should be send via json
   * @param int $options json options
   */
  public static function sendJsonResponse($result, $options = 0)
  {
    header('Content-Type: application/json');
    echo json_encode($result, $options);
    exit;
  }

  /**
   * Sets correct heads for json output
   * @param array $result the array that should be send via json
   */
  public static function flushAndSendJsonResponse($result)
  {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
  }

  /**
   * @param int $postId the id that is currently shown
   * @param string $postType the post type of the id
   * @param string $orderby field to order by (only wp_posts fields)
   * @param string $order ASC/DESC
   * @return array key/value of "next", "prev" with respective urls
   */
  public static function getPrevNextLinks($postId, $postType, $orderby, $order)
  {
    // Few validations
    $postType = Strings::forceSlugString($postType);
    $orderby = Strings::validateField($orderby);
    $order = strtoupper(Strings::forceSlugString($order));

    // First, get all post ids in the correct order
    $db = self::getDb();
    $postIds = $db->get_col('
      SELECT ID FROM ' . $db->posts . '
      WHERE post_type = "' . $postType . '"
      AND post_status = "publish"
      ORDER BY ' . $orderby . ' ' . $order
    );

    // Sort out postids of other languages
    if (Multilang::isActive() && Multilang::isPostTypeTranslated($postType)) {
      $lang = Multilang::getCurrentLang();
      foreach ($postIds as $key => $id) {
        if (Multilang::getPostLang($id) != $lang) {
          unset($postIds[$key]);
        }
      }
      // Revert keys of the array to navigate
      $postIds = array_values($postIds);
    }

    // Set the array to our current post id
    $max = (count($postIds) - 1);
    $current = array_search($postId, $postIds);
    // Prev or the last if current is the first
    $prev = ($current != 0) ? ($current - 1) : $max;
    // Next or the first if the current is already the last
    $next = ($current != $max) ? ($current + 1) : 0;

    return array(
      'prev' => get_permalink($postIds[$prev]),
      'next' => get_permalink($postIds[$next])
    );
  }

  /**
   * @param string $pageId backend page parameter
   * @return string the name or an error message
   */
  public static function getBackendPageName($pageId)
  {
    global $submenu;

    foreach ($submenu as $items) {
      foreach ($items as $item) {
        if ($item[2] == $pageId) {
          return $item[0];
        }
      }
    }

    return 'could not resolve page name';
  }

  /**
   * @param int $id attachment id
   * @param string $size image size
   * @return string the image url
   */
  public static function getImageUrl($id, $size)
  {
    list($imageUrl) = wp_get_attachment_image_src($id, $size);
    return $imageUrl;
  }

  /**
   * @param int $id attachment id
   * @return string the image alternative text
   */
  public static function getImageAltText($id)
  {
    return get_post_meta($id, '_wp_attachment_image_alt', true);
  }

  /**
   * @param int $id attachment id
   * @param string $size image size
   * @return array image data
   */
  public static function getAttachmentData($id, $size)
  {
    $attachment = get_post($id);
    return array(
      'alt' => self::getImageAltText($attachment->ID),
      'caption' => $attachment->post_excerpt,
      'description' => $attachment->post_content,
      'href' => get_permalink($attachment->ID),
      'src' => self::getImageUrl($attachment->ID, $size),
      'title' => $attachment->post_title
    );
  }

  /**
   * @param string $url the attachments full export url
   * @return int the attachment id (or 0 if not found)
   */
  public static function getAttachmentIdFromUrl($url)
  {
    // Check if it could be an attachment url
    if (!Strings::contains($url, CDN_NAME)) {
      return 0;
    }

    // Search for that one as part of an attachment guid
    $sql = 'SELECT ID FROM {sql:postTable} WHERE post_type="attachment" AND guid = {filePath}';

    $db = WordPress::getDb();
    return intval($db->get_var(Strings::prepareSql($sql, array(
      'postTable' => $db->posts,
      'filePath' => $url
    ))));
  }

  /**
   * @param string $url the attachments full export url
   * @return int the attachment id (or 0 if not found)
   */
  public static function guessAttachmentIdFromUrl($url)
  {
    // Check if it could be an attachment url
    $key = '/lbwp-cdn/' . ASSET_KEY . '/files/';
    if (!Strings::contains($url, $key)) {
      return 0;
    }

    $url = substr($url, stripos($url, $key) + 1);
    // Search for that one as part of an attachment guid
    $sql = 'SELECT ID FROM {sql:postTable} WHERE post_type="attachment" AND guid LIKE {filePath}';
    $db = WordPress::getDb();
    return intval($db->get_var(Strings::prepareSql($sql, array(
      'postTable' => $db->posts,
      'filePath' => '%' . $url
    ))));
  }

  /**
   * Caching wrapper for wpNavMenu
   * @param array $config the menu config
   * @param int $cacheTime the cache time
   * @return string html code of the menu
   */
  public static function wpNavMenu($config, $cacheTime = 300)
  {
    // Try to get the menu from cache
    $key = $config['theme_location'] . '_' . md5(json_encode($config) . '_' . md5($_SERVER['REQUEST_URI']));
    $html = wp_cache_get($key, 'wpNavMenu');

    if ($html !== false) {
      return $html;
    }

    // Not from cache, generate it
    $config['echo'] = 0;
    $html = wp_nav_menu($config);
    wp_cache_set($key, $html, 'wpNavMenu', $cacheTime);
    return $html;
  }

  /**
   * @param int $termId the term id
   * @param string $taxonomy the taxonomy of the term
   * @return \stdClass highest term
   */
  public static function getHighestParent($termId, $taxonomy)
  {
    // start from the current term
    $parent = get_term_by('id', $termId, $taxonomy);

    // climb up the hierarchy until we reach a term with parent = '0'
    while ($parent !== false && $parent->parent != '0') {
      $termId = $parent->parent;
      $parent = get_term_by('id', $termId, $taxonomy);
    }

    return $parent;
  }

  /**
   * @param int $postId the term id
   * @return \stdClass highest post object
   */
  public static function getHighestParentPost($postId)
  {
    // start from the current term
    $parent = get_post($postId);

    // climb up the hierarchy until we reach a term with parent = '0'
    while ($parent !== false && $parent->post_parent != '0') {
      $postId = $parent->post_parent;
      $parent = get_post($postId);
    }

    return $parent;
  }

  /**
   * @param string $template the template
   * @param mixed $part the part
   * @return string html code returned from get_template_part()
   */
  public static function returnTemplatePart($template, $part = null)
  {
    ob_start();
    get_template_part($template, $part);
    $content = ob_get_contents();
    ob_end_clean();
    return $content;
  }

  /**
   * is_edit_page
   * function to check if the current page is a post edit page
   *
   * @param null|string|array $type what page to check for: null, 'post', 'post-new', array('post', 'post-new', 'edit')
   * @return boolean
   */
  public static function isEditPage($type = null)
  {
    global $pagenow;
    //make sure we are on the backend
    if (!is_admin()) {
      return false;
    }

    if (is_null($type)) {
      return in_array($pagenow, array('post.php', 'post-new.php'));
    } elseif (is_array($type)) {
      return in_array($pagenow, array_map(function ($item) {
        return $item . '.php';
      }, $type));
    } else {
      return in_array($pagenow, array($type . '.php'));
    }
  }

  /**
   * Gather all configured image sizes (taken from wp_generate_attachment_metadata)
   * @global array $_wp_additional_image_sizes
   * @return array $sizes all configured image sizes (i.e. array('thumbnail'=>array('width'=>100, 'height'=>100, 'crop'=>1)) )
   */
  public static function getAllImageSizes()
  {
    $sizes = array();
    // make thumbnails and other intermediate sizes
    global $_wp_additional_image_sizes;

    foreach (get_intermediate_image_sizes() as $s) {
      $sizes[$s] = array('width' => '', 'height' => '', 'crop' => false);
      if (isset($_wp_additional_image_sizes[$s]['width']))
        $sizes[$s]['width'] = intval($_wp_additional_image_sizes[$s]['width']); // For theme-added sizes
      else
        $sizes[$s]['width'] = get_option("{$s}_size_w"); // For default sizes set in options
      if (isset($_wp_additional_image_sizes[$s]['height']))
        $sizes[$s]['height'] = intval($_wp_additional_image_sizes[$s]['height']); // For theme-added sizes
      else
        $sizes[$s]['height'] = get_option("{$s}_size_h"); // For default sizes set in options
      if (isset($_wp_additional_image_sizes[$s]['crop']))
        $sizes[$s]['crop'] = intval($_wp_additional_image_sizes[$s]['crop']); // For theme-added sizes
      else
        $sizes[$s]['crop'] = get_option("{$s}_crop"); // For default sizes set in options
    }

    $sizes = apply_filters('intermediate_image_sizes_advanced', $sizes);
    return $sizes;
  }

  /**
   * @param array $config of config variables
   * @return string login customization css and js
   */
  public static function getCustomizedLoginScreenHtml($config)
  {
    return '
      <style type="text/css">
        body.login {
          background-color:' . $config['background'] . ';
        }
        body.login h1 a {
          background-image: url("' . $config['logo'] . '") !important;
          width: ' . $config['logoWidth'] . ';
          background-size: ' . $config['logoBackgroundWidth'] . ';
          height: ' . $config['logoHeight'] . ';
        }
        body.login .message {
          border-left-color:' . $config['messageBorderColor'] . ';
        }
        .button-primary, .button-primary:hover {
            background: ' . $config['buttonColor'] . ' !important;
            border-color:  ' . $config['buttonColor'] . ' !important;
            color:  ' . $config['buttonFontColor'] . ' !important;
            text-shadow: none !important;
            box-shadow:none !important;
            -webkit-box-shadow:none !important;
        }
        input[type=text]:focus, input[type=password]:focus {
          border-color: #ccc !important;
          -webkit-box-shadow: none !important;
          box-shadow: none !important;
        }
      </style>
      <script type="text/javascript">
        jQuery(function() {
          jQuery("h1 a").attr("href", "' . $config['url']  . '");
        });
      </script>
    ';
  }

  /**
   * Checks if a post has a term/tax assigned or a subterm of the given one
   * @param int $postId the post it to check
   * @param int $termId the term id to check
   * @param string $taxonomy the taxonomy to look for
   * @return bool true/false if assigned or not
   */
  public static function hasTermOrSub($postId, $termId, $taxonomy)
  {
    // Check for if the term is directly assigned
    if (has_term($termId, $taxonomy, $postId)) {
      return true;
    }

    // If not, check if a subterm is assigned (child_of also looks at grand children)
    $subs = get_categories(array(
      'child_of' => $termId,
      'hide_empty' => false,
      'taxonomy' => $taxonomy
    ));

    foreach ($subs as $subterm) {
      if (has_term($subterm->term_id, $taxonomy, $postId)) {
        return true;
      }
    }

    // If not found, it isn't assigned
    return false;
  }

  /**
   * Returns an array of term ids containing the given term id and
   * all it's assigned sub terms. This can be used with tax_query
   * @param int $termId the term to look for
   * @param string $taxonomy the taxonomy where it's assigned
   * @return array term ids
   */
  public static function getTermAndSubIds($termId, $taxonomy)
  {
    $termIds = array($termId);
    $subs = get_categories(array(
      'child_of' => $termId,
      'hide_empty' => false,
      'taxonomy' => $taxonomy
    ));
    foreach ($subs as $sub) {
      $termIds[] = $sub->term_id;
    }

    return $termIds;
  }

  /**
   * @param array $config the configuration for the backlink
   * @return array of url/text to use (maybe additional params, depending on config
   */
  public static function getDynamicBackLink($config = array())
  {
    // Pre-generate the fallback link
    $fallback = get_bloginfo('url');
    // If multilang, correctly get the home url
    if (Multilang::isActive()) {
      $fallback = Multilang::getHomeUrl();
    }
    // If there is a blog page defined, use (override) it
    if (intval(get_option('page_for_posts')) > 0) {
      $fallback = get_permalink(get_option('page_for_posts'));
    }

    // Merge the config
    $config = array_merge(array(
      'fallback_link' => $fallback,
      'goto_previous_page_text' => __('Zurück', 'lbwp'),
      'goto_posts_page_text' => __('Zur Übersicht', 'lbwp'),
    ), $config);

    // Prepare the link data initially
    $link = array(
      'url' => $config['fallback_link'],
      'text' => $config['goto_posts_page_text']
    );

    // Check if the referer is internal, then switch
    if (strlen($_SERVER['HTTP_REFERER']) > 0 && Strings::startsWith($_SERVER['HTTP_REFERER'], get_bloginfo('url'))) {
      $link = array(
        'url' => $_SERVER['HTTP_REFERER'],
        'text' => $config['goto_previous_page_text']
      );
    }

    return $link;
  }

  /**
   * @param int $postId the post id
   * @param string $taxonomy the taxonomy
   * @return string name of the first found assigned term in that taxonomy, or empty string, if none
   */
  public static function getFirstTerm($postId, $taxonomy = 'category')
  {
    $terms = wp_get_post_terms($postId, $taxonomy);
    if (is_array($terms) && count($terms) > 0) {
      return $terms[0];
    }

    return false;
  }

  /**
   * @param int $postId the post id
   * @param string $taxonomy the taxonomy
   * @return string name of the first found assigned term in that taxonomy, or empty string, if none
   */
  public static function getFirstTermName($postId, $taxonomy = 'category')
  {
    $term = self::getFirstTerm($postId, $taxonomy);
    return ($term != false) ? $term->name : '';
  }

  /**
   * @param int $postId the post id
   * @param string $taxonomy the taxonomy
   * @return string name of the first found assigned term in that taxonomy, or empty string, if none
   */
  public static function getFirstTermSlug($postId, $taxonomy = 'category')
  {
    $term = self::getFirstTerm($postId, $taxonomy);
    return ($term != false) ? $term->slug : '';
  }

  /**
   * This just "removes" a menu. Only use, if security is not the most imporant thing here.
   * @param string $menuItemId one of the MENU_ID_* constants
   * @param array $menu the global $menu
   * @return array the same $menu, with $menuItem removed
   */
  public static function removeCoreMenu($menuItemId, $menu)
  {
    foreach ($menu as $menuId => $item) {
      if ($item[2] == $menuItemId) {
        unset($menu[$menuId]);
      }
    }

    return $menu;
  }

  /**
   * @return \WP_Query
   */
  public static function getQuery()
  {
    global $wp_query;
    return $wp_query;
  }

  /**
   * @return \WP_Admin_Bar
   */
  public static function getAdminBar()
  {
    global $wp_admin_bar;
    return $wp_admin_bar;
  }

  /**
   * @return \WP_Object_Cache
   */
  public static function getObjectCache()
  {
    global $wp_object_cache;
    return $wp_object_cache;
  }

  /**
   * @return string
   */
  public static function getPageNow()
  {
    global $pagenow;
    return $pagenow;
  }

  /**
   * @return \wpdb
   */
  public static function getDb()
  {
    global $wpdb;
    return $wpdb;
  }

  /**
   * @return \WP_Rewrite
   */
  public static function getRewrite()
  {
    global $wp_rewrite;
    return $wp_rewrite;
  }

  /**
   * @return \WP_Roles
   */
  public static function getRoles()
  {
    global $wp_roles;
    return $wp_roles;
  }

  /**
   * @return mixed
   */
  public static function getUserRoles()
  {
    global $wp_user_roles;
    return $wp_user_roles;
  }

  /**
   * @return string
   */
  public static function getVersion()
  {
    global $wp_version;
    return $wp_version;
  }

  /**
   * @return \WP_Post
   */
  public static function getPost()
  {
    global $post;
    return $post;
  }

  /**
   * @return int
   */
  public static function getPostId()
  {
    global $post;
    return $post->ID;
  }

  /**
   * Guess the current post from global or request data
   * @return \WP_Post a post object or NULL if nothing found
   */
  public static function guessCurrentPost()
  {
    $post = self::getPost();

    if (intval($post->ID) == 0) {
      foreach (array('post', 'post_id', 'post_ID', 'p', 'id') as $possibleId) {
        if (isset($_REQUEST[$possibleId]) && intval($_REQUEST[$possibleId])) {
          return get_post($_REQUEST[$possibleId]);
        }
      }
    }

    // If given or nothing found return null
    return $post;
  }

  /**
   * @param $userId
   * @param $postId
   * @return bool true if the user owns, false if not
   */
  public static function userOwnsPost($userId, $postId)
  {
    $db = WordPress::getDb();
    $owning = $db->get_var('SELECT COUNT(ID) FROM ' . $db->posts . ' WHERE post_author = ' . intval($userId) . ' AND ID = ' . intval($postId));
    return $owning == 1;
  }

  /**
   * @return array|bool|null|object
   */
  public static function getComment()
  {
    global $comment;
    return $comment;
  }

  /**
   * @return array
   */
  public static function getComments()
  {
    global $comments;
    return $comments;
  }

  /**
   * @return mixed
   */
  public static function getCustomImageHeader()
  {
    global $custom_image_header;
    return $custom_image_header;
  }

  /**
   * @return array
   */
  public static function getShortcodeTags()
  {
    global $shortcode_tags;
    return $shortcode_tags;
  }

  /**
   * @return mixed
   */
  public static function getThemeDirectories()
  {
    global $wp_theme_directories;
    return $wp_theme_directories;
  }

  public static function getThemes()
  {
    global $wp_themes;
    return $wp_themes;
  }

  /**
   * @return mixed
   */
  public static function getLocale()
  {
    global $wp_locale;
    return $wp_locale;
  }

  /**
   * @return array|bool|mixed|string|void
   */
  public static function getMenu()
  {
    global $menu;
    return $menu;
  }

  public static function setMenu($newMenu)
  {
    global $menu;
    $menu = $newMenu;
  }

  /**
   * @return string
   */
  public static function getSubMenuFile()
  {
    global $submenu_file;
    return $submenu_file;
  }

  /**
   * @return array
   */
  public static function getSubMenu()
  {
    global $submenu;
    return $submenu;
  }

  /**
   * @param array $newSubbMenu
   */
  public static function setSubMenu($newSubbMenu = array())
  {
    global $submenu;
    $submenu = $newSubbMenu;
  }

  /**
   * @param string $file
   */
  public static function setSubMenuFile($file)
  {
    global $submenu_file;
    $submenu_file = $file;
  }

  /**
   * @return \WP_Scripts
   */
  public static function getScripts()
  {
    global $wp_scripts;
    return $wp_scripts;
  }

  /**
   * @return \WP_Styles
   */
  public static function getStyles()
  {
    global $wp_styles;
    return $wp_styles;
  }

  /**
   * @return mixed
   */
  public static function getl10n()
  {
    global $l10n;
    return $l10n;
  }

  /**
   * @return array
   */
  public static function getWidgets()
  {
    global $wp_registered_widgets;
    return $wp_registered_widgets;
  }

  /**
   * @return array
   */
  public static function getSidebars()
  {
    global $wp_registered_sidebars;
    return $wp_registered_sidebars;
  }
}