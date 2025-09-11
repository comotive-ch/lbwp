<?php

namespace LBWP\Aboon\Erp\SapByd;

use LBWP\Aboon\Base\Shop;
use LBWP\Aboon\Erp\Entity\Product as ImportProduct;
use LBWP\Aboon\Erp\Product as ProductBase;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * ERP implementation for remotely hosted SAGE 200 with REST APIs
 * @package LBWP\Aboon\Erp\Sage
 */
abstract class Product extends ProductBase
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
   * The standard assortment group for non-loggedin customers or customers with no group assigned
   */
  public static $STANDARD_ASSORTMENT_GROUP = '';
  /**
   * @var int number of imports per page
   */
  protected int $importsPerRun = 25;

  /**
   * @return void
   */
  public function init()
  {
    parent::init();

    add_action('cron_daily_20', array($this, 'updateAssortmentGroupMap'));
    add_action('cron_weekday_6', array($this, 'updateAllAssortments'));
    add_Action('cron_weekday_6', array($this, 'updateWebshopFlag'));
    add_Action('cron_job_manual_update_remaining_flag', array($this, 'updateRemainingFlag'));
    add_Action('cron_job_manual_update_webshop_flag', array($this, 'updateWebshopFlag'));
    add_action('cron_job_manual_update_assortment_by_sku', array($this, 'updateAssortmentGroupsBySku'));
    add_action('cron_job_manual_update_product_by_sku', array($this, 'updateProductBySku'));
  }

  /**
   * @return void
   */
  public function updateRemainingFlag()
  {
    // Products that don't have the webshop flag set
    $products = $this->getApi()->get('/sap/byd/odata/cust/v1/vmumaterial/MaterialCollection', array(
      '$format' => 'json',
      'sap-language' => 'DE',
      '$filter' => "Z_NichtmehrimSortiment_KUT eq true",
      '$select' => 'InternalID',
      '$top' => 100000
    ), 10);

    $productIds = array();
    foreach ($products['d']['results'] as $result) {
      $productIds[] = intval($result['InternalID']);
    }

    $skuMap = Shop::getSkuMap();
    foreach ($productIds as $sku) {
      if (isset($skuMap[$sku])) {
        $product = wc_get_product($skuMap[$sku]);
        $product->update_meta_data('is-remaining', 1);
        if ($product->get_stock_quantity() == 0) {
          $product->set_status('draft');
        }
        $product->save();
      }
    }
  }

  /**
   * @return void
   */
  public function updateWebshopFlag()
  {
    // Products that don't have the webshop flag set
    $products = $this->getApi()->get('/sap/byd/odata/cust/v1/vmumaterial/MaterialCollection', array(
      '$format' => 'json',
      'sap-language' => 'DE',
      '$filter' => "ZWebshopArtikel_KUT ne true",
      '$select' => 'InternalID',
      '$top' => 100000
    ), 10);

    $productIds = array();
    foreach ($products['d']['results'] as $result) {
      $productIds[] = intval($result['InternalID']);
    }

    $products = $this->getApi()->get('/sap/byd/odata/cust/v1/vmumaterial/MaterialCollection', array(
      '$format' => 'json',
      '$expand' => 'Sales',
      'sap-language' => 'DE',
      // Add these to check how many products are show active, but add en exit after this call
      '$filter' => "ZWebshopArtikel_KUT eq true",
      '$select' => 'InternalID,Sales/LifeCycleStatusCode',
      '$top' => 100000
    ), 90);


    foreach ($products['d']['results'] as $result) {
      if ($result['Sales'][0]['LifeCycleStatusCode'] == 3) {
        $productIds[] = intval($result['InternalID']);
      }
    }

    $skuMap = Shop::getSkuMap();
    $db = WordPress::getDb();
    foreach ($productIds as $sku) {
      if (isset($skuMap[$sku])) {
        $db->query('
          UPDATE ' . $db->posts . '
          SET post_status = "draft"
          WHERE ID = ' . intval($skuMap[$sku]) . '
        ');
      }
    }
  }

  /**
   * @return void
   */
  public function updateProductBySku()
  {
    if (!current_user_can('administrator')) {
      return;
    }

    set_time_limit(7200);
    ini_set('memory_limit', '2048M');

    $skus = array();
    list($start, $end) = explode('-', $_GET['data']);
    if (strlen($start) > 0 && strlen($end) > 0) {
      for ($i = $start; $i <= $end; $i++) {
        $skus[] = $i;
      }
    } else if (strlen($start) > 0) {
      $skus[] = intval($start);
    } else {
      // No range given, nothing to do
      exit;
    }

    foreach ($skus as $sku) {
      $this->getProductFromSapBy('InternalID', $sku);
      $importProduct = $this->convertProduct($sku);
      $importProduct->save();
    }
  }

  /**
   * @return void
   */
  public function updateAllAssortments()
  {
    // Allow large limit and don't cache here
    set_time_limit(3600*12);
    ini_set('memory_limit', '2048M');
    $skuMap = Shop::getSkuMap();
    $api = $this->getApi();
    global $wp_object_cache;
    $wp_object_cache->can_write = false;

    foreach ($skuMap as $sku => $productId) {
      $whitelist = array();
      usleep(250000);
      $raw = $api->get('/sap/byd/odata/cust/v1/kundensortiment/BO_CA_Helper_MaterialSalesOrderWhiteListCollection', array(
        '$format' => 'json',
        'materialID' => $sku
      ));

      if (isset($raw['d']['results']) && count($raw['d']['results']) > 0) {
        foreach ($raw['d']['results'] as $group) {
          $whitelist[] = str_replace('-', '', $group['UUID']);
        }
      }

      if (count($whitelist) > 0 && $productId > 0) {
        update_post_meta($productId, 'sap-whitelist', implode(',', $whitelist));
      }
    }
  }

  /**
   * @return void
   */
  public function updateAssortmentGroupsBySku()
  {
    set_time_limit(1200);
    $skuMap = Shop::getSkuMap();
    $skuList = array_map('intval', explode(',', $_GET['data']));
    $api = $this->getApi();

    if (!current_user_can('administrator')) {
      return;
    }

    foreach ($skuList as $sku) {
      $whitelist = array();
      $raw = $api->get('/sap/byd/odata/cust/v1/kundensortiment/BO_CA_Helper_MaterialSalesOrderWhiteListCollection', array(
        '$format' => 'json',
        'materialID' => $sku
      ));

      if (isset($raw['d']['results']) && count($raw['d']['results']) > 0) {
        foreach ($raw['d']['results'] as $group) {
          $whitelist[] = str_replace('-', '', $group['UUID']);
        }
      }

      if (count($whitelist) > 0 && $skuMap[$sku] > 0) {
        update_post_meta($skuMap[$sku], 'sap-whitelist', implode(',', $whitelist));
      }
    }
  }

  /**
   * Updates the map of known assortment groups
   * @return void
   */
  public function updateAssortmentGroupMap()
  {
    $raw = $this->getApi()->get('/sap/byd/odata/cust/v1/kundensortiment/BO_CA_CustomerAssortmentRootCollection', array(
      '$format' => 'json'
    ));

    $assortmentGroups = array();
    if (isset($raw['d']['results']) && count($raw['d']['results']) > 0) {
      foreach ($raw['d']['results'] as $group) {
        $id = str_replace('-', '', $group['UUID']);
        $assortmentGroups[$id] = trim($group['customerAssortment']);
      }
    }

    if (count($assortmentGroups) > 0) {
      update_option('sapbyd_assortment_groups', $assortmentGroups, false);
    }
  }

  /**
   * @param int $page the page to load
   * @return array a list of product ids on that page
   */
  protected function getPagedProductIds(int $page): array
  {
    $remoteIds = array();
    $skip = ($page * $this->importsPerRun) - $this->importsPerRun;
    // Call SAP to get the products by page (also set every product to cache, so we can access it eventually in convertProduct
    $products = $this->getApi()->get('/sap/byd/odata/cust/v1/vmumaterial/MaterialCollection', array(
      '$format' => 'json',
      '$expand' => 'Planning,ProductCategory,Sales/SalesText',
      'sap-language' => 'DE',
      // Add these to check how many products are show active, but add en exit after this call
      '$filter' => "ZWebshopArtikel_KUT eq false",
      //'$inlinecount' => 'allpages',
      '$skip' => $skip,
      '$top' => $this->importsPerRun
    ));

    if (isset($products['d']) && is_array($products['d']['results'])) {
      foreach ($products['d']['results'] as $product) {
        $remoteIds[] = intval($product['InternalID']);
        wp_cache_set('sap_product_raw_' . $product['InternalID'], $product, 'SapByd', 120);
      }
    }

    return $remoteIds;
  }

  /**
   * @param mixed $remoteId remote id given from external system
   * @return mixed the validated remote od
   */
  protected function validateRemoteId($remoteId)
  {
    return $remoteId;
  }

  /**
   * @param mixed $remoteId the id of the product in the remote system
   * @return ImportProduct predefined product object that can be imported or updated
   */
  protected function convertProduct($remoteId): ImportProduct
  {
    // Get raw product data from cache or SAP if not in cache anymore
    $raw = wp_cache_get('sap_product_raw_' . $remoteId, 'SapByd');

    // Get from SAP if invalid
    if (!is_array($raw) || !isset($raw['ObjectID'])) {
      $raw = $this->getProductFromSapBy('ObjectID', $remoteId);
    }

    // Translate ObjectID to Internal for $remoteId (SKU) if so given
    if ($remoteId == $raw['ObjectID']) {
      $remoteId = $raw['InternalID'];
    }

    // Get local ID by Sku
    $skuMap = Shop::getSkuMap();
    $localId = intval($skuMap[$remoteId]);
    $product = new ImportProduct($remoteId, $localId);
    $productSlug = Strings::forceSlugString($raw['Description']);
    $productCategory = $raw['ProductCategory']['ProductCategoryInternalID'];
    $api = $this->getApi();

    // Set VPE meta if greater than 1 (as 0 and 1 are both valid for normal purchase)
    $purchaseUnitCount = intval($raw['StandardMengeninhaltcontent_KUT']);
    if ($purchaseUnitCount > 1) {
      $product->setMeta('_vpe', $purchaseUnitCount);
    }

    // Get white and blacklist info and save them as meta on the product
    $whitelist = $this->simplifyAssortmentIdList($api->get('/sap/byd/odata/cust/v1/kundensortiment/BO_CA_Helper_MaterialSalesOrderWhiteListCollection', array(
      '$format' => 'json',
      'materialUUID' => $raw['ObjectID']
    )), $raw['UUID']);
    $blacklist = $this->simplifyAssortmentIdList($api->get('/sap/byd/odata/cust/v1/kundensortiment/BO_CA_Helper_MaterialSalesOrderBlackListCollection', array(
      '$format' => 'json',
      'materialUUID' => $raw['ObjectID']
    )), $raw['UUID']);

    // If there are no whitelist assortment groups or webshop disabled and the product is not imported, skip it
    if (!$product->isUpdating() && (count($whitelist) == 0 || $raw['ZWebshopArtikel_KUT'] !== true)) {
      return new ImportProduct('');
    } else if (count($whitelist) == 0 || $raw['ZWebshopArtikel_KUT'] !== true || $raw['Sales'][0]['LifeCycleStatusCode'] == 3) {
      // If imported already and no whitelist entries or not webshop, make the product invisible as no one would be able to purchase
      $product->setCoreField('post_status', 'draft');
    } else {
      $product->setCoreField('post_status', 'publish');
    }
    // Save the lists to the produt
    $product->setMeta('sap-whitelist', implode(',', $whitelist));
    $product->setMeta('sap-blacklist', implode(',', $blacklist));

    // Set prodcurement lead time if given
    if ($raw['Planning'][0]['ProcurementLeadDuration'] != NULL) {
      // Get last char as it inticates type (D=days, M=months, W=weeks, Y=years)
      $procurementDays = $raw['Planning'][0]['ProcurementLeadDuration'];
      $type = substr($procurementDays, -1);
      // And remove that character
      $procurementDays = substr($procurementDays, 1, strlen($procurementDays)-2);
      // Ignore D but multiply the other cases
      switch ($type) {
        case 'W': $procurementDays *= 7; break;
        case 'M': $procurementDays *= 30; break;
        case 'Y': $procurementDays *= 365; break;
      }
      // And save that in days for our display
      $product->setMeta('lead-time', $procurementDays);
    }

    // Override Description with SalesText if given
    $raw['Description_FR'] = $raw['Description'];
    if (isset($raw['Sales'][0]['SalesText']) && is_array($raw['Sales'][0]['SalesText'])) {
      foreach ($raw['Sales'][0]['SalesText'] as $text) {
        $field = 'Description';
        if ($text['LanguageCode'] != 'DE') {
          $field .= '_' . $text['LanguageCode'];
        }
        if (strlen($text['Text']) > 0) {
          $raw[$field] = str_replace(PHP_EOL, ', ', $text['Text']);
        }
      }
    }

    // Set post name from description
    if (isset($raw['Description']) && strlen($productSlug) > 0) {
      $product->setCoreField('post_name', $productSlug);
    } else {
      $product->setCoreField('post_name', 'product-id-' . $remoteId);
    }

    // Set restposten or not
    if ($raw['Z_NichtmehrimSortiment_KUT'] === true) {
      $product->setMeta('is-remaining', 1);
    } else {
      $product->setMeta('is-remaining', 0);
    }

    // Also set title and meta for french translation
    if (isset($raw['Description'])) {
      $product->setCoreField('post_title', $raw['Description']);
    }
    if (isset($raw['Description_FR'])) {
      $product->setMeta('title-fr', $raw['Description_FR']);
    }

    if (strlen($productCategory) > 0) {
      $product->setMeta('sap-product-category', $productCategory);
    }

    // Make sure to remember the SAP DB ID, also save the internalID (sku)
    $product->setMeta('sap-id', $raw['ObjectID']);
    $product->setMeta('_sku', $raw['InternalID']);
    $product->setMeta('_manage_stock', 'yes');
    $product->setMeta('_backorders', 'yes');

    return $product;
  }

  /**
   * @param string $field
   * @param string $value
   * @return array raw SAP data with no expands
   */
  public function getProductFromSapBy($field, $value) : array
  {
    $product = array($field => $value);
    $request = array(
      '$format' => 'json',
      '$filter' => "$field eq '$value'",
      '$expand' => 'Planning,ProductCategory,Sales/SalesText',
      'sap-language' => 'DE',
      '$top' => 1
    );

    $raw = $this->getApi()->get('/sap/byd/odata/cust/v1/vmumaterial/MaterialCollection', $request);
    if (isset($raw['d']) && is_array($raw['d']['results']) && count($raw['d']['results']) > 0 && strlen($value) >= 6) {
      $product = $raw['d']['results'][0];
      wp_cache_set('sap_product_raw_' . $product[$field], $product, 'SapByd', 120);
    }

    return $product;
  }

  /**
   * @param mixed $listRaw raw SAP result for white or blacklist (should be array)
   * @param mixed $uuid the product uuid (should be string)
   * @return array simplified uuid list
   */
  protected function simplifyAssortmentIdList($listRaw, $uuid) : array
  {
    $IdList = array();
    if (!is_array($listRaw) || $uuid == null) {
      return $IdList;
    }
    if (isset($listRaw['d']['results']) && count($listRaw['d']['results']) > 0) {
      foreach ($listRaw['d']['results'] as $group) {
        if ($group['materialUUID'] == $uuid) {
          if (strlen($group['UUID']) > 0) {
            $IdList[] = str_replace('-', '', $group['UUID']);
          } else if (strlen($group['id']) > 0) {
            $IdList[] = str_replace('-', '', $group['id']);
          }
        }
      }
    }

    return $IdList;
  }


  /**
   * @return array
   */
  public function getQueueTriggerObject() : array
  {
    // Get trigger info from request body json
    $object = json_decode(file_get_contents('php://input'), true);
    // generic product that has almost everything: 00163ED325BA1EDCA4996A1AFC78AAA4
    // generic product that has at least whitelist entry: FA163E1644311EDD8FCABF6B257E0214
    // generic product that has at least blacklist entry: FA163E1644311EED8FC7B5281DBDA435
    // generic product with both DE and FR salestext: FA163E1644311EDD8FCABF6B257E0214
    // generic product with two whitelists: FA163ED93AF31EDDA0DA6DF11BF32D8E
    // type can also be sap.byd.BO_CA_Helper_MaterialSalesOrder.WhiteList.Created.v1, when sortiment changes
    /*
    $object = json_decode('{
      "type": "sap.byd.Material.Root.Updated.v1",
      "data": { "entity-id": "FA163ED93AF31EEDA680AEB3262556A5" }
    }', true);
    */
    /* // This is for the article 225703 on prod
    $object = json_decode('{
      "type": "sap.byd.BO_CA_Helper_MaterialSalesOrder.WhiteList.Deleted.v1",
      "data": { "entity-id": "47138BB3F8FA1EDDBBAFC5EFF4335F5F","root-entity-id": "FA163ED93AF31EEDA3809B63E39362C4" }
    }', true);
    */

    // Leave early, request is invalid, don't bother SAP for that
    if (!isset($object['data']['entity-id'])) {
      SystemLog::mDebug('SapByd trigger for material error, invalid data', $object);
      return array('success' => false, 'message' => 'invalid request');
    }

    $object['id'] = $object['data']['entity-id'];
    // On White- oder BlackList trigger, use the root-entity-id
    if (isset($object['type']) && str_contains($object['type'], 'BO_CA_Helper_MaterialSalesOrder')) {
      $object['id'] = $object['data']['root-entity-id'];
    }

    return $object;
  }

  /** TODO
   * Provides rest api endpoint to change inventory of specific product
   */
  public function runInventoryTrigger() : array
  {
    $data = array_merge($_GET, $_POST);
    $data['inputBody'] = file_get_contents('php://input');
    // And import or sync the provided product
    return array('success' => 'not implemented yet');
  }

  /**
   * @return ApiHelper
   */
  protected function getApi() : ApiHelper
  {
    return new ApiHelper(static::$sapHostName, static::$sapUserName, static::$sapPassword);
  }

  /**
   * @return ApiHelper
   */
  protected static function getApiStatic() : ApiHelper
  {
    return new ApiHelper(static::$sapHostName, static::$sapUserName, static::$sapPassword);
  }


  /**
   * As of now, SAPByd cant manage categories for products, thus nothing is imported here
   * @return string[] list of all importable categories with subcategory syntax "category > subcategory > subsubcat"
   */
  protected function getFullCategoryList(): array
  {
    return array();
  }

  /**
   * As of now, SAPByd cant manage properties for products, thus nothing is imported here
   * @return string[] list of all main property names and their possible tags as key => array of properties
   */
  protected function getFullPropertyList(): array
  {
    return array();
  }

  public static function getRemainingProducts(){
    $getRemainingProducts = get_posts(array(
      'post_type' => 'product',
      'posts_per_page' => -1,
      'meta_query' => array(
        array(
          'key' => 'is-remaining',
          'value' => 1
        )
      )
    ));

    $remainingProducts = array();
    foreach ($getRemainingProducts as $remainingProduct) {
      $remainingProducts[] = $remainingProduct->ID;
    }

    return $remainingProducts;
  }
}