<?php

namespace LBWP\Aboon\AgenticCommerce\Feed;

use LBWP\Aboon\AgenticCommerce\Catalog;
use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Aboon\AgenticCommerce\Money;
use LBWP\Aboon\AgenticCommerce\Payment\Handlers;
use LBWP\Aboon\AgenticCommerce\Settings;
use LBWP\Core;
use WC_Product;

/**
 * Generates the OpenAI ACP product feed (UTF-8 TSV) nightly and uploads it to the CDN (S3) at
 * /shop/acp-feed.tsv. One row per simple product and per purchasable variation.
 * @package LBWP\Aboon\AgenticCommerce\Feed
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AcpProductFeed
{
  public const string REMOTE_PATH = '/shop/acp-feed.tsv';
  public const string CRON_JOB = 'agentic_acp_feed';
  public const int BATCH = 200;
  /**
   * @var array feed columns, required columns first
   */
  public const array COLUMNS = [
    'item_id', 'title', 'description', 'brand', 'url', 'image_url', 'price', 'availability',
    'is_eligible_search', 'is_eligible_checkout', 'seller_name', 'seller_url', 'seller_privacy_policy',
    'seller_tos', 'target_countries', 'store_country',
    'gtin', 'mpn', 'sale_price', 'availability_date', 'group_id', 'item_group_id', 'listing_has_variations',
    'variant_dict', 'product_category', 'inventory_quantity', 'condition', 'additional_image_urls',
  ];

  /**
   * Registers the nightly generation and the manual cron job.
   * @return void
   */
  public function register(): void
  {
    add_action('cron_daily_1', [$this, 'generateIfActive']);
    add_action('cron_job_' . self::CRON_JOB, [$this, 'generate']);
  }

  /**
   * Generates the feed when enabled in settings.
   * @return void
   */
  public function generateIfActive(): void
  {
    if (Settings::acpFeedActive()) {
      $this->generate();
    }
  }

  /**
   * Generates and uploads the feed.
   * @return string the CDN url or empty on failure
   */
  public function generate(): string
  {
    $file = tempnam(sys_get_temp_dir(), 'acpfeed');
    $handle = fopen($file, 'w');
    if ($handle === false) {
      Log::info('ACP feed: cannot open temp file');
      return '';
    }
    fwrite($handle, implode("\t", self::COLUMNS) . "\n");
    $count = 0;
    $page = 1;
    $checkoutPossible = count(Handlers::forProtocol('acp')) > 0;
    do {
      $products = wc_get_products(['status' => 'publish', 'limit' => self::BATCH, 'page' => $page, 'type' => ['simple', 'variable'], 'return' => 'objects', 'orderby' => 'ID', 'order' => 'ASC']);
      foreach ($products as $product) {
        if (!$product instanceof WC_Product) {
          continue;
        }
        foreach ($this->rowsFor($product, $checkoutPossible) as $row) {
          fwrite($handle, implode("\t", array_map([$this, 'cell'], $row)) . "\n");
          $count++;
        }
      }
      $page++;
    } while (count($products) === self::BATCH);
    fclose($handle);
    $s3 = Core::getModule('S3Upload');
    $url = $s3->uploadDiskFileFixedPath($file, self::REMOTE_PATH, 'text/tab-separated-values;charset=UTF-8', true);
    @unlink($file);
    Log::info('ACP feed generated', ['rows' => $count, 'url' => $url]);
    update_option('agentic_acp_feed_last_run', ['time' => time(), 'rows' => $count, 'url' => $url], false);
    return (string) $url;
  }

  /**
   * Builds the feed rows of a product (variations of a variable product).
   * @param WC_Product $product the product
   * @param bool $checkoutPossible whether a payment handler is configured
   * @return array list of rows (column => value)
   */
  protected function rowsFor(WC_Product $product, bool $checkoutPossible): array
  {
    if ($product->is_type('variable')) {
      $rows = [];
      foreach ($product->get_children() as $childId) {
        $variation = wc_get_product($childId);
        if ($variation instanceof WC_Product && $variation->get_status() === 'publish') {
          $rows[] = $this->row($variation, $product, $checkoutPossible);
        }
      }
      return array_values(array_filter($rows));
    }
    $row = $this->row($product, null, $checkoutPossible);
    return $row === null ? [] : [$row];
  }

  /**
   * Builds one feed row.
   * @param WC_Product $product simple product or variation
   * @param WC_Product|null $parent parent for variations
   * @param bool $checkoutPossible whether a payment handler is configured
   * @return array|null column => value, null to skip
   */
  protected function row(WC_Product $product, ?WC_Product $parent, bool $checkoutPossible): ?array
  {
    $eligible = Catalog::isEligible($product);
    $image = Catalog::imageUrl($product);
    $price = (float) $product->get_regular_price();
    if ($price <= 0 || $image === '') {
      return null;
    }
    $currency = Money::currency();
    $links = Settings::links();
    $availability = match (Catalog::availabilityStatus($product)) {
      Catalog::OUT_OF_STOCK => 'out_of_stock',
      Catalog::BACKORDER => 'backorder',
      Catalog::PRE_ORDER => 'pre_order',
      default => 'in_stock',
    };
    $categories = wp_get_post_terms($parent ? $parent->get_id() : $product->get_id(), 'product_cat', ['fields' => 'names']);
    $variantDict = [];
    if ($parent) {
      foreach ($product->get_attributes() as $name => $value) {
        $variantDict[wc_attribute_label(str_replace('attribute_', '', (string) $name))] = (string) $value;
      }
    }
    $gallery = array_values(array_filter(array_map(fn($id) => (string) wp_get_attachment_image_url((int) $id, 'large'), (array) $product->get_gallery_image_ids())));
    $row = [
      'item_id' => Catalog::itemId($product),
      'title' => $parent ? $parent->get_name() . ' – ' . implode(' / ', array_values($variantDict)) : $product->get_name(),
      'description' => Catalog::description($product, 5000),
      'brand' => (string) apply_filters('aboon_agentic_feed_brand', Settings::sellerName(), $product),
      'url' => (string) $product->get_permalink(),
      'image_url' => $image,
      'price' => number_format($price, 2, '.', '') . ' ' . $currency,
      'availability' => $availability,
      'is_eligible_search' => $eligible,
      'is_eligible_checkout' => $eligible && $checkoutPossible,
      'seller_name' => Settings::sellerName(),
      'seller_url' => get_bloginfo('url'),
      'seller_privacy_policy' => (string) ($links['privacy'] ?? ''),
      'seller_tos' => (string) ($links['terms'] ?? ''),
      'target_countries' => implode(',', Settings::acpFeedTargetCountries()),
      'store_country' => WC()->countries->get_base_country(),
      'gtin' => method_exists($product, 'get_global_unique_id') ? (string) $product->get_global_unique_id() : '',
      'mpn' => (string) $product->get_sku(),
      'sale_price' => $product->is_on_sale() && (float) $product->get_sale_price() > 0 ? number_format((float) $product->get_sale_price(), 2, '.', '') . ' ' . $currency : '',
      'availability_date' => $availability === 'in_stock' ? '' : gmdate('Y-m-d\TH:i:s\Z', time() + 14 * DAY_IN_SECONDS),
      'group_id' => $parent ? (string) $parent->get_id() : '',
      'item_group_id' => $parent ? (string) $parent->get_id() : '',
      'listing_has_variations' => $parent !== null,
      'variant_dict' => count($variantDict) > 0 ? wp_json_encode($variantDict, JSON_UNESCAPED_UNICODE) : '',
      'product_category' => is_array($categories) ? implode(' > ', $categories) : '',
      'inventory_quantity' => Catalog::availableQuantity($product) ?? '',
      'condition' => 'new',
      'additional_image_urls' => implode(',', $gallery),
    ];
    $row = apply_filters('aboon_agentic_acp_feed_item', $row, $product, $parent);
    return is_array($row) ? $row : null;
  }

  /**
   * Formats a cell for TSV output.
   * @param mixed $value the value
   * @return string cell
   */
  protected function cell(mixed $value): string
  {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    return str_replace(["\t", "\r", "\n"], ' ', (string) $value);
  }
}
