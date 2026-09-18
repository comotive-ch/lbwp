<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Aboon\Component\PackagingUnit;
use LBWP\Aboon\Component\Preorder;
use LBWP\Util\Multilang;
use WC_Product;

/**
 * Resolves protocol item ids to products and answers eligibility, availability and quantity
 * questions. The feed emits exactly what resolveItem() accepts.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Catalog
{
  public const string IN_STOCK = 'in_stock';
  public const string LOW_STOCK = 'low_stock';
  public const string OUT_OF_STOCK = 'out_of_stock';
  public const string BACKORDER = 'backorder';
  public const string PRE_ORDER = 'pre_order';
  /**
   * @var int quantity cap when WooCommerce reports "unlimited"
   */
  public const int MAX_QUANTITY_CAP = 99;

  /**
   * Resolves an item id (product/variation id or SKU depending on settings) to a product.
   * @param string $itemId the id from the request
   * @param string $lang optional language slug for Multilang translations
   * @return WC_Product|null the product or null
   */
  public static function resolveItem(string $itemId, string $lang = ''): ?WC_Product
  {
    $itemId = trim($itemId);
    if ($itemId === '') {
      return null;
    }
    $productId = 0;
    if (Settings::itemIdSource() === 'sku') {
      $productId = (int) wc_get_product_id_by_sku($itemId);
    }
    if ($productId === 0 && ctype_digit($itemId)) {
      $productId = (int) $itemId;
    }
    if ($productId === 0) {
      return null;
    }
    $product = wc_get_product($productId);
    if (!$product instanceof WC_Product) {
      return null;
    }
    return $lang === '' ? $product : self::translatedProduct($product, $lang);
  }

  /**
   * Returns the translation of a product in a language, or the product itself.
   * @param WC_Product $product the product
   * @param string $lang language slug
   * @return WC_Product translated product when it exists
   */
  public static function translatedProduct(WC_Product $product, string $lang): WC_Product
  {
    if (!Multilang::isActive() || $lang === '' || Multilang::getPostLang($product->get_id()) === $lang) {
      return $product;
    }
    $translatedId = Multilang::getPostIdInLang($product->get_id(), $lang);
    if (!is_numeric($translatedId) || (int) $translatedId === $product->get_id()) {
      return $product;
    }
    $translated = wc_get_product((int) $translatedId);
    return $translated instanceof WC_Product ? $translated : $product;
  }

  /**
   * Returns the item id emitted for a product.
   * @param WC_Product $product the product
   * @return string id or sku
   */
  public static function itemId(WC_Product $product): string
  {
    if (Settings::itemIdSource() === 'sku' && $product->get_sku() !== '') {
      return $product->get_sku();
    }
    return (string) $product->get_id();
  }

  /**
   * Checks whether a product may be sold through an agent.
   * @param WC_Product $product the product
   * @return bool true when eligible
   */
  public static function isEligible(WC_Product $product): bool
  {
    $parentId = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
    $eligible = $product->get_status() === 'publish'
      && $product->is_purchasable()
      && !self::isExcluded($product->get_id())
      && !self::isExcluded($parentId)
      && self::inEligibleCategory($parentId);
    return (bool) apply_filters('aboon_agentic_product_eligible', $eligible, $product);
  }

  /**
   * Reads the product level exclusion checkbox.
   * @param int $productId product or variation id
   * @return bool true when excluded
   */
  protected static function isExcluded(int $productId): bool
  {
    $value = get_post_meta($productId, 'agentic-commerce-exclude', true);
    return is_array($value) && in_array(1, array_map('intval', $value), true);
  }

  /**
   * Checks the eligible category setting.
   * @param int $productId parent product id
   * @return bool true when no restriction or inside an allowed category
   */
  protected static function inEligibleCategory(int $productId): bool
  {
    $allowed = Settings::eligibleCategories();
    if (count($allowed) === 0) {
      return true;
    }
    $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'ids']);
    if (!is_array($terms)) {
      return false;
    }
    foreach ($terms as $termId) {
      $ancestors = get_ancestors((int) $termId, 'product_cat');
      if (count(array_intersect(array_merge([(int) $termId], $ancestors), $allowed)) > 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * Returns the optional agent note of a product (variation falls back to parent).
   * @param WC_Product $product the product
   * @return string the note or empty
   */
  public static function note(WC_Product $product): string
  {
    $note = (string) get_post_meta($product->get_id(), 'agentic-commerce-note', true);
    if ($note === '' && $product->get_parent_id() > 0) {
      $note = (string) get_post_meta($product->get_parent_id(), 'agentic-commerce-note', true);
    }
    return trim($note);
  }

  /**
   * Determines the availability status in protocol vocabulary.
   * @param WC_Product $product the product
   * @return string in_stock|low_stock|out_of_stock|backorder|pre_order
   */
  public static function availabilityStatus(WC_Product $product): string
  {
    if (class_exists(Preorder::class) && Preorder::isAvailable($product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id())) {
      return self::PRE_ORDER;
    }
    if (get_option('woocommerce_manage_stock') !== 'yes' || !$product->managing_stock()) {
      return match ($product->get_stock_status()) {
        'outofstock' => self::OUT_OF_STOCK,
        'onbackorder' => self::BACKORDER,
        default => self::IN_STOCK,
      };
    }
    $quantity = (int) $product->get_stock_quantity();
    if ($quantity <= 0) {
      return $product->backorders_allowed() ? self::BACKORDER : self::OUT_OF_STOCK;
    }
    $lowStock = (int) $product->get_low_stock_amount();
    if ($lowStock <= 0) {
      $lowStock = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    }
    return $quantity <= $lowStock ? self::LOW_STOCK : self::IN_STOCK;
  }

  /**
   * Returns the purchasable stock quantity or null when not tracked.
   * @param WC_Product $product the product
   * @return int|null quantity
   */
  public static function availableQuantity(WC_Product $product): ?int
  {
    if (!$product->managing_stock()) {
      return null;
    }
    return max(0, (int) $product->get_stock_quantity());
  }

  /**
   * Returns the maximum quantity per order.
   * @param WC_Product $product the product
   * @return int quantity (capped)
   */
  public static function maxQuantity(WC_Product $product): int
  {
    $max = (int) $product->get_max_purchase_quantity();
    if ($max < 0 || $max > self::MAX_QUANTITY_CAP) {
      $max = self::MAX_QUANTITY_CAP;
    }
    return max(1, $max);
  }

  /**
   * Returns the quantity step of a product (packaging unit).
   * @param WC_Product $product the product
   * @return int step, 1 when none
   */
  public static function quantityStep(WC_Product $product): int
  {
    if (!class_exists(PackagingUnit::class)) {
      return 1;
    }
    return max(1, (int) PackagingUnit::getPackagingUnit($product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id()));
  }

  /**
   * Returns the main image URL of a product (variation falls back to parent).
   * @param WC_Product $product the product
   * @param string $size image size
   * @return string url or empty
   */
  public static function imageUrl(WC_Product $product, string $size = 'large'): string
  {
    $imageId = (int) $product->get_image_id();
    if ($imageId === 0 && $product->get_parent_id() > 0) {
      $imageId = (int) get_post_thumbnail_id($product->get_parent_id());
    }
    if ($imageId === 0) {
      return '';
    }
    $url = wp_get_attachment_image_url($imageId, $size);
    return is_string($url) ? $url : '';
  }

  /**
   * Returns a plain text description (short description first, then description).
   * @param WC_Product $product the product
   * @param int $maxLength maximum characters
   * @return string description
   */
  public static function description(WC_Product $product, int $maxLength = 1000): string
  {
    $text = $product->get_short_description();
    if (trim($text) === '') {
      $text = $product->get_description();
    }
    if (trim($text) === '' && $product->get_parent_id() > 0) {
      $parent = wc_get_product($product->get_parent_id());
      $text = $parent instanceof WC_Product ? $parent->get_short_description() ?: $parent->get_description() : '';
    }
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text, true)));
    return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1) . '…' : $text;
  }
}
