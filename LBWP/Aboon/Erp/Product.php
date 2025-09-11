<?php

namespace LBWP\Aboon\Erp;

use LBWP\Aboon\Erp\Entity\Product as ImportProduct;
use LBWP\Helper\Cronjob;
use LBWP\Helper\MasterApi;
use LBWP\Theme\Base\Component;
use LBWP\Util\LbwpData;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Base class to sync product data with an ERP system
 * @package LBWP\Aboon\Erp
 */
abstract class Product extends Component
{
  protected string $catTaxonomy = 'product_cat';
  protected string $propTaxonomy = 'product_prop';
  protected array $categories = array();
  protected array $properties = array();
  protected bool $convertImages = true;
  protected bool $updateProductLookupTables = true;
  protected string $treeSeparator = '>';

  /**
   * @var int number of datasets to be imported in full sync per minutely run
   */
  protected int $importsPerRun = 10;
  /**
   * Main starting point of the component
   */
  public function init()
  {
    add_action('rest_api_init', array($this, 'registerApiEndpoints'));
    add_action('cron_job_manual_aboon_erp_product_register_full_sync', array($this, 'registerFullSync'));
    add_action('cron_job_manual_aboon_erp_product_flush_terms', array($this, 'flushTerms'));
    add_action('cron_job_manual_aboon_erp_product_sync_terms_only', array($this, 'syncTermsOnly'));
    add_action('cron_job_manual_aboon_list_update_triggers', array($this, 'listUpdateTriggers'));
    add_action('cron_job_aboon_erp_product_sync_page', array($this, 'runBulkImportCron'));
    add_action('cron_job_aboon_product_sync_process_queue', array($this, 'processProductQueue'));
    if ($this->updateProductLookupTables) {
      add_action('cron_daily_21', array($this, 'updateProductLookupTables'));
    }
  }

  /**
   *
   */
  public function updateProductLookupTables()
  {
    // Update in CLI mode (directly write new info to DB)
    define('WP_CLI', true);
    wc_update_product_lookup_tables();
  }

  /**
   * @param ImportProduct $product a full product object that should be imported or updated
   * @return bool save status
   */
  protected function updateProduct(ImportProduct $product): bool
  {
    // Validate the product and save it
    if ($product->validate()) {
      return $product->save();
    }

    return false;
  }

  /**
   * Starts running a paged cron for bulk importing/syncing all products from remote system
   */
  public function registerFullSync()
  {
    wp_cache_delete('categoryTree', 'aboonErpProduct');
    wp_cache_delete('propertyTree', 'aboonErpProduct');
    // This basically creates a cron trigger with the first page of mass import (which will then contain itself until finished)
    Cronjob::register(array(
      current_time('timestamp') => 'aboon_erp_product_sync_page::1'
    ));
    // Print info that the import started
    echo 'full product data import has started.';
  }

  /**
   * Syncy terms only, can take very long in the first run
   */
  public function syncTermsOnly()
  {
    // No time limit here as this must run in one turn
    set_time_limit(0);
    // Make sure to not cache term objects as this causes overflows, also flush caches to force syncing
    wp_cache_delete('categoryTree', 'aboonErpProduct');
    wp_cache_delete('propertyTree', 'aboonErpProduct');
    wp_cache_add_never_persistent_groups(array('terms', 'term_meta'));
    // Update the property and the category tree
    $this->updateCategories($this->getFullCategoryList());
    $this->updateProperties($this->getFullPropertyList());
  }

  /**
   * @return void
   */
  public function listUpdateTriggers()
  {
    $db = WordPress::getDb();
    $raw = $db->get_results('SELECT post_id, meta_value FROM ' . $db->postmeta . ' WHERE meta_key = "_sku"');
    $sku = array();
    foreach ($raw as $row) {
      $sku[intval($row->post_id)] = $row->meta_value;
    }

    if (isset($_GET['missing-thumbnail'])) {
      $raw = $db->get_results('SELECT post_id FROM ' . $db->postmeta . ' WHERE meta_key = "_thumbnail_id"');
      foreach ($raw as $row) {
        unset($sku[$row->post_id]);
      }
    }

    $baseUrl = get_bloginfo('url');
    foreach ($sku as $id) {
      $url = $baseUrl . '/wp-json/aboon/erp/product/trigger/?product_id=' . $id;
      echo '<a href="' . $url . '">' . $url . '</a><br>';
    }
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

    foreach ($this->getPagedProductIds($page) as $remoteId) {
      if (strlen($remoteId) > 0 || $remoteId > 0) {
        $product = $this->convertProduct($remoteId);
        if ($this->convertImages) {
          $this->convertImage($product);
        }
        // And import or sync the provided product
        $syncResults[] = $this->updateProduct($product);
        usleep(500000);
      }
    }

    // Register another cron with next page if data was synced
    if (count($syncResults) > 0) {
      Cronjob::register(array(
        current_time('timestamp') => 'aboon_erp_product_sync_page::' . (++$page)
      ));
    }
  }

  /**
   * Builds a id => value array of all categories. If given, it synchronizes wtih contents from $list
   * @param array $list full list of categories from remote system by name or syntaxed subcategories
   */
  protected function updateCategories(array $list)
  {
    // Get the latest tree from cache
    $tree = wp_cache_get('categoryTree', 'aboonErpProduct');
    if ($tree !== false) {
      $this->categories = $tree;
      return $tree;
    }

    // Load tree from DB if not in cache yet
    $tree = array();
    $raw = get_terms(array(
      'taxonomy' => $this->catTaxonomy,
      'orderby' => 'none',
      'hide_empty' => false
    ));

    foreach ($raw as $term) {
      if ($term->parent == 0) {
        $tree[html_entity_decode($term->name)] = $term->term_id;
      } else {
        $tree[html_entity_decode($this->getRecursiveTermName($term->name, $term->parent, $raw))] = $term->term_id;
      }
    }

    // Update the tree in DB and cache, if list is given and there are changes
    foreach ($list as $name) {
      // Check if not existing yet
      if (!isset($tree[$name])) {
        // Is is a main category without parents?
        if (!Strings::contains($name, $this->treeSeparator)) {
          // Just create a new term from that name and remember the id
          $term = wp_insert_term($name, $this->catTaxonomy);
        } else {
          // Split into their parts
          $parts = array_map('trim', explode($this->treeSeparator, $name));
          // Get the actual name of the term and the parents name, by poping from the end
          $actualName = array_pop($parts);
          // Import the parts if not yet
          foreach ($parts as $level => $part) {
            switch ($level) {
              case 0:
                $parentName = $part;
                if (isset($tree[$parentName])) break;
                $parent = wp_insert_term($part, $this->catTaxonomy);
                if ($parent instanceof \WP_Error) {
                  $tree[$parentName] = $parent->get_error_data('term_exists');
                } else {
                  $tree[$parentName] = $parent['term_id'];
                }
                break;
              case 1:
                $parentName = implode($this->treeSeparator, $parts);
                $parentId = $tree[$parts[0]];
                if (isset($tree[$parentName])) break;
                $parent = wp_insert_term($parentId . '.' . $part, $this->catTaxonomy, array(
                  'parent' => $parentId
                ));
                if ($parent instanceof \WP_Error) {
                  $tree[$parentName] = $parent->get_error_data('term_exists');
                } else {
                  $tree[$parentName] = $parent['term_id'];
                }
                break;
            }
          }

          // Get the parent id
          $parentName = implode($this->treeSeparator, $parts);
          $parentId = $tree[$parentName];
          // Insert with that parent
          $term = wp_insert_term($parentId . '.' . $actualName, $this->catTaxonomy, array(
            'parent' => $parentId
          ));
        }
        // Add term to tree if not error object
        if (!is_wp_error($term)) {
          $tree[$name] = $term['term_id'];
        }
      }
    }

    // Save tree into cache
    wp_cache_set('categoryTree', $tree, 'aboonErpProduct', 40000);

    // Map to the categories local var, to be accessed after updating
    $this->categories = $tree;
  }

  /**
   * Builds slug => array of id => name array of properties like
   * array('marke' => array(1 => 'sony', 33 => 'panasonic'))
   * @param array $list
   */
  protected function updateProperties(array $list)
  {
    // Get the latest tree from cache
    $tree = wp_cache_get('propertyTree', 'aboonErpProduct');
    if ($tree !==  false) {
      $this->properties = $tree;
      return $tree;
    }

    // No caching when importing, at least for db queryies and inserts
    global $wp_object_cache;
    $wp_object_cache->can_write = false;
    // Load tree from DB if not in cache yet and maybe update
    $tree = array();
    $raw = get_terms(array(
      'taxonomy' => $this->propTaxonomy,
      'orderby' => 'none',
      'hide_empty' => false
    ));

    // First build the property base terms
    foreach ($raw as $term) {
      if ($term->parent == 0) {
        $tree[html_entity_decode($term->name)] = array(
          'id' => $term->term_id,
          'terms' => array()
        );
      }
    }

    // Now build the sub terms, actual property names
    foreach ($raw as $term) {
      if ($term->parent > 0) {
        // Get the parent of the given property
        foreach ($tree as $name => $branch) {
          if ($branch['id'] == $term->parent) {
            break;
          }
        }
        // Add the term to the terms of that branch
        $tree[$name]['terms'][html_entity_decode(Strings::removeUntil($term->name, '.'))] = $term->term_id;
      }
    }

    // In a first loop create main terms thate are not yet existing
    foreach ($list as $name => $terms) {
      if (!isset($tree[$name])) {
        $term = wp_insert_term($name, $this->propTaxonomy);
        if (!is_wp_error($term)) {
          $tree[$name] = array(
            'id' => $term['term_id'],
            'terms' => array()
          );
        }
      }
    }

    // In the second loop, add all sub terms to the properties that not yet exist
    foreach ($list as $name => $terms) {
      foreach ($terms as $term) {
        if (!isset($tree[$name]['terms'][$term])) {
          // Before we insert, see if it's an existing term with different upper/lowercase
          $isNumeric = $this->isFullyNumericTerm($terms);
          if ($isNumeric) {
            if (isset($tree[$name]['terms'][$term])) {
              $key = $term;
            } else if (!str_contains($term, '.') && isset($tree[$name]['terms'][intval($term)])) {
              $key = intval($term);
            } else {
              $key = false;
            }
          } else {
            $key = array_search(strtolower($term), array_map('strtolower', array_keys($tree[$name]['terms'])));
          }
          // Try insert only if key is not existing
          if ($key === false || $key === NULL) {
            $inserted = wp_insert_term($tree[$name]['id'] . '.' . $term, $this->propTaxonomy, array(
              'parent' => $tree[$name]['id']
            ));
            if (!is_wp_error($inserted)) {
              $tree[$name]['terms'][$term] = $inserted['term_id'];
            }
          } else {
            // Map the key with the same existing id
            if (isset($tree[$name]['terms'][$key])) {
              $tree[$name]['terms'][$term] = $tree[$name]['terms'][$key];
            }
          }
        }
      }
    }

    // Allow writing again
    $wp_object_cache->can_write = true;
    // Save tree into cache
    wp_cache_set('propertyTree', $tree, 'aboonErpProduct', 40000);

    // Map to the properties local var, to be accessed after updating
    $this->properties = $tree;
  }

  /**
   * @param string $terms
   * @return bool
   */
  protected function isFullyNumericTerm(&$terms)
  {
    foreach ($terms as $term) {
      if (!is_numeric($term)) {
        return false;
      }
    }

    return true;
  }

  /**
   * @param string $name
   * @param int $parent
   * @param array $terms
   * @return string the recursive full category name
   */
  protected function getRecursiveTermName($name, $parent, &$terms): string
  {
    // Find the parent if given
    if ($parent > 0) {
      foreach ($terms as $term) {
        if ($parent == $term->term_id) {
          return $this->getRecursiveTermName($term->name . $this->treeSeparator . Strings::removeUntil($name, '.'), $term->parent, $terms);
        }
      }
    }

    return $name;
  }

  /**
   * @return void
   */
  public function processProductQueue()
  {
    $table = new LbwpData('aboon_product_sync');
    foreach ($table->getRows('pid', 'DESC', $this->importsPerRun) as $data) {
      $product = $this->convertProduct($data['id']);
      if ($this->convertImages) {
        $this->convertImage($product);
      }
      $this->updateProduct($product);
      $table->deleteRowByPid($data['pid']);
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
    register_rest_route('aboon/erp/product', 'trigger', array(
      'methods' => \WP_REST_Server::ALLMETHODS,
      'callback' => array($this, 'queueExternalTrigger')
    ));
    register_rest_route('aboon/erp/product', 'inventory', array(
      'methods' => \WP_REST_Server::ALLMETHODS,
      'callback' => array($this, 'runInventoryTrigger')
    ));
    register_rest_route('aboon/erp/product', 'pricelist', array(
      'methods' => \WP_REST_Server::ALLMETHODS,
      'callback' => array($this, 'runPricelistTrigger')
    ));
  }

  /**
   * @return void
   */
  public function queueExternalTrigger()
  {
    $table = new LbwpData('aboon_product_sync');
    $object = $this->getQueueTriggerObject();
    $validId = isset($object['id']) && strlen($object['id']) > 0;
    if ($validId) {
      $table->updateRow($object['id'], $object);
      $this->registerProcessQueueJob();
    }
    return array('success' => $validId);
  }

  /**
   * @return void
   */
  protected function registerProcessQueueJob()
  {
    Cronjob::register(array(
      current_time('timestamp') => 'aboon_product_sync_process_queue'
    ), 1);
  }

  /** TODO
   * Provides rest api endpoint to change inventory of specific product
   */
  public function runInventoryTrigger()
  {
    $remoteId = $this->validateRemoteId($_GET['product_id']);
    // And import or sync the provided product
    return array('success' => 'not implemented yet');
  }

  /** TODO
   * Provides rest api endpoint to change inventory of specific product
   */
  public function runPricelistTrigger()
  {
    $remoteId = $this->validateRemoteId($_GET['product_id']);
    // And import or sync the provided product
    return array('success' => 'not implemented yet');
  }

  /**
   * Basically remove all terms and relationsshops, flush the cache completely
   */
  public function flushTerms()
  {
    if (!current_user_can('administrator')) {
      return;
    }

    $db = WordPress::getDb();
    $taxIn = '"product_cat", "product_prop"';
    // Delete relationships and terms by join on our to types
    $db->query('
      DELETE ' . $db->term_relationships . ' FROM ' . $db->term_relationships . ' 
      INNER JOIN ' . $db->term_taxonomy . ' ON ' . $db->term_taxonomy . '.term_taxonomy = ' . $db->term_relationships . '.term_taxonomy_id
      WHERE taxonomy IN(' . $taxIn . ')
    ');
    $db->query('
      DELETE ' . $db->terms . ' FROM ' . $db->terms . ' 
      INNER JOIN ' . $db->term_taxonomy . ' ON ' . $db->term_taxonomy . '.term_id = ' . $db->terms . '.term_id
      WHERE taxonomy IN(' . $taxIn . ')
    ');
    // Delete taxonomy table and the product to taxonomy caching map
    $db->query('DELETE FROM ' . $db->term_taxonomy . ' WHERE taxonomy IN(' . $taxIn . ')');
    // This table may not exist on every instance, but $db handles it gracefully
    $db->query('DELETE FROM ' . $db->prefix . 'lbwp_prod_map');
    // Remove dead terms and relationships
    $db->query('
      DELETE ' . $db->terms . ' FROM ' . $db->terms . ' 
      LEFT JOIN ' . $db->term_taxonomy . ' ON ' . $db->term_taxonomy . '.term_id = ' . $db->terms . '.term_id
      WHERE ' . $db->term_taxonomy . '.term_taxonomy_id IS NULL
    ');
    $db->query('
      DELETE ' . $db->term_relationships . ' FROM ' . $db->term_relationships . ' 
      LEFT JOIN ' . $db->term_taxonomy . ' ON ' . $db->term_taxonomy . '.term_id = ' . $db->terms . '.term_id
      WHERE ' . $db->term_taxonomy . '.term_taxonomy_id IS NULL
    ');

    echo 'Done. Please flush cache manually.';
  }

  /**
   * Retrieves data sent by a webhook/rigger and converts it
   * @return array with at least an id and additional data
   */
  abstract protected function getQueueTriggerObject(): array;

  /**
   * @param int $page the page to load
   * @return array a list of product ids on that page
   */
  abstract protected function getPagedProductIds(int $page): array;

  /**
   * @param mixed $remoteId remote id given from external system
   * @return mixed the validated remote od
   */
  abstract protected function validateRemoteId($remoteId);

  /**
   * Actual function to be implemented to convert remote product to local product to be able to import
   * @param mixed $remoteId the id of the product in the remote system
   * @return ImportProduct predefined product object that can be imported or updated
   */
  abstract protected function convertProduct($remoteId): ImportProduct;

  /**
   * Function to implement the image converter
   * @param ImportProduct $product the id of the product in the remote system
   */
  abstract protected function convertImage(ImportProduct $product);

  /**
   * @return string[] list of all importable categories with subcategory syntax "category > subcategory > subsubcat"
   */
  abstract protected function getFullCategoryList(): array;

  /**
   * @return string[] list of all main property names and their possible tags as key => array of properties
   */
  abstract protected function getFullPropertyList(): array;
}