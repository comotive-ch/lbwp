<?php

namespace LBWP\Aboon\Erp\Entity;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\WordPress;

/**
 * Importable product data object
 * @package LBWP\Aboon\Erp\Entity
 */
class Product
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
   * @var int the thumbnail id, if set
   */
  protected $thumbnailId = 0;
  /**
   * @var bool tells if the current objects is updating or new
   */
  protected bool $updating = false;
  /**
   * @var bool tells if the current objects is new (even after saving)
   */
  protected bool $new = false;
  /**
   * @var array
   */
  protected array $categories = array();
  /**
   * @var array
   */
  protected array $properties = array();
  /**
   * @var array
   */
  protected array $meta = array();
  /**
   * @var array
   */
  protected array $fields = array();
  /**
   * @var string
   */
  protected string $categoryTaxonomy = '';
  /**
   * @var string
   */
  protected string $propertyTaxonomy = '';
  /**
   * @var bool
   */
  protected bool $appendMode = false;
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
        $this->thumbnailId = get_post_thumbnail_id($id);
        $this->updating = true;
      }
    } else {
      $this->thumbnailId = get_post_thumbnail_id($this->id);
      $this->updating = true;
    }
  }

  /**
   * @return bool
   */
  public function validate(): bool
  {
    return apply_filters('aboon_erp_product_validate', isset($this->fields['post_name']) && isset($this->fields['post_title']), $this);
  }

  /**
   * Hash that tells if the object changed
   * @return string
   */
  protected function generateHash() : string
  {
    $this->hash = md5(
      'v2.1' . $this->thumbnailId .
      json_encode($this->meta) .
      json_encode($this->categories) .
      json_encode($this->properties) .
      json_encode($this->fields)
    );

    return $this->hash;
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
      $lastHash = get_post_meta($this->id, 'last-sync-hash', true);
      if (strlen($lastHash) > 0 && $lastHash === $this->hash) {
        SystemLog::add('AboonErpProduct', 'debug', 'No changes detected for remote id ' . $this->remoteId . ' (local id ' . $this->id . ')');
        return true;
      }
    }

    if (!$this->updating) {
      // Create the user first to get the local id
      $this->id = wp_insert_post(array_merge($this->fields, array(
        'post_type' => 'product',
        'post_status' => 'publish'
      )));

      // See if its a WP error
      if (is_wp_error($this->id)) {
        SystemLog::add('AboonErpProduct', 'debug', 'WP Error on importing remote id ' . $this->remoteId, array(
          'message' => $this->id->get_error_message(),
          'error_id' => $this->id->get_error_code(),
          'error_data' => $this->id->get_all_error_data()
        ));
        return false;
      }

      // Also, connect this user to his remote erp id
      update_post_meta($this->id, 'erp-remote-product-id', $this->remoteId);
      update_user_meta($this->id, 'last-sync-hash', $this->hash);
      // If called again, the product is only updating
      $this->updating = true;
      $this->new = true;
      // Let developers add their own logic after every product import took place
      do_action('aboon_erp_after_product_new_import', $this);
    } else {
      if (count($this->fields) > 0) {
        wp_update_post(array_merge($this->fields, array(
          'ID' => $this->id,
        )));
      }
    }

    // Let developers add their own logic after every product import took place
    do_action('aboon_before_erp_product_import', $this);

    // Set thumbnail (also deletes it if thumbnailId is zero
    $this->savePostThumbnail();

    // Update categories and properties
    if (count($this->properties) > 0)
      wp_set_post_terms($this->id, $this->properties, $this->propertyTaxonomy, $this->appendMode);
    if (count($this->categories) > 0)
      wp_set_post_terms($this->id, $this->categories, $this->categoryTaxonomy, $this->appendMode);

    // Update other meta data
    foreach ($this->meta as $key => $value) {
      update_post_meta($this->id, $key, $value);
    }

    // Print imported data, when on local development
    if (defined('LOCAL_DEVELOPMENT') || isset($_GET['verbose'])) {
      var_dump(array(
        'remoteId' => $this->remoteId,
        'localId' => $this->id,
        'categoryIds' => $this->categories,
        'propertyIds' => $this->properties,
        'meta' => $this->meta,
        'core' => $this->fields
      ));
    }

    // Let developers add their own logic after every product import took place
    do_action('aboon_after_erp_product_import', $this);
    update_post_meta($this->id, 'last-sync-hash', $this->hash);
    clean_post_cache($this->id);
    clean_object_term_cache($this->id, $this->propertyTaxonomy);
    clean_object_term_cache($this->id, $this->categoryTaxonomy);
    wp_cache_delete($this->id, $this->propertyTaxonomy . '_relationships');
    wp_cache_delete($this->id, $this->categoryTaxonomy . '_relationships');
    wc_delete_product_transients($this->id);
    // Load product newly to refresh cache
    wc_get_product($this->id);

    return true;
  }

  /**
   * Save the post thubmnail
   */
  public function savePostThumbnail()
  {
    set_post_thumbnail($this->id, $this->thumbnailId);
  }

  /**
   * @param mixed $remoteId the remote system id
   * @return int the id or 0
   */
  protected function searchLocalIdConnection($remoteId): int
  {
    return intval($this->db->get_var('
      SELECT post_id FROM ' . $this->db->postmeta . ' 
      WHERE meta_key = "erp-remote-product-id" AND meta_value = "' . $remoteId . '"
    '));
  }

  /**
   * @return array
   */
  public function getCategories(): array
  {
    return $this->categories;
  }

  /**
   * @param int $category
   */
  public function setCategory(int $categoryId)
  {
    if (!in_array($categoryId, $this->categories) && $categoryId > 0) {
      $this->categories[] = $categoryId;
    }
  }

  /**
   * @return array
   */
  public function getProperties(): array
  {
    return $this->properties;
  }

  /**
   * @param array $properties
   */
  public function setProperty(int $propertyId)
  {
    if (!in_array($propertyId, $this->properties) && $propertyId > 0) {
      $this->properties[] = $propertyId;
    }
  }

  /**
   * @return array
   */
  public function getMetaList(): array
  {
    return $this->meta;
  }

  /**
   * @param string $key
   * @param mixed $value
   */
  public function setMeta(string $key, $value)
  {
    $this->meta[$key] = $value;
  }

  /**
   * @return array
   */
  public function getFields(): array
  {
    return $this->fields;
  }

  /**
   * @param string $field
   * @param string $value
   */
  public function setCoreField(string $field, string $value)
  {
    $this->fields[$field] = $value;
  }

  /**
   * @return string
   */
  public function getCategoryTaxonomy(): string
  {
    return $this->categoryTaxonomy;
  }

  /**
   * @param string $taxonomy
   */
  public function setCategoryTaxonomy(string $taxonomy): void
  {
    $this->categoryTaxonomy = $taxonomy;
  }

  /**
   * @return string
   */
  public function getPropertyTaxonomy(): string
  {
    return $this->propertyTaxonomy;
  }

  /**
   * @param string $taxonomy
   */
  public function setPropertyTaxonomy(string $taxonomy): void
  {
    $this->propertyTaxonomy = $taxonomy;
  }

  /**
   * @param int $id
   */
  public function setThumbnailId(int $id)
  {
    // Gracefully, completely remove previous image
    if ($this->thumbnailId > 0 && $this->thumbnailId != $id) {
      wp_delete_attachment($this->thumbnailId);
    }

    $this->thumbnailId = $id;
  }

  /**
   * @return int
   */
  public function getThumbnailId(): int
  {
    return $this->thumbnailId;
  }

  /**
   * @return bool
   */
  public function isUpdating(): bool
  {
    return $this->updating;
  }

  /**
   * @return bool
   */
  public function isNew(): bool
  {
    return $this->new;
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
   * Enable append mode for taxonomy functions
   */
  public function enableAppendMode()
  {
    $this->appendMode = true;
  }

  /**
   * Disable append mode for taxonomy functions
   */
  public function disableAppendMode()
  {
    $this->appendMode = false;
  }
}