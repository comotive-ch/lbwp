<?php

namespace LBWP\ERP\PIM\Data;

/**
 * Base for exports of PIM products matching an SQL condition, one row per product with a SKU.
 * Projects can restrict the data set via lbwp_pim_product_export_conditions (meta key => required value)
 * and add or change columns via lbwp_pim_product_export_columns, both receive the export instance.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class ProductExport extends CustomExport
{
  const string FILTER_CONDITIONS = 'lbwp_pim_product_export_conditions';
  const string FILTER_COLUMNS = 'lbwp_pim_product_export_columns';

  /**
   * SQL condition on the product post aliased as "p" that selects the exported products.
   * @return string
   */
  abstract protected function getProductCondition(): string;

  /**
   * Column definitions, each with a header and either a meta key or a callback receiving the product row.
   * @return array list of ['header' => string, 'meta' => string] or ['header' => string, 'value' => callable]
   */
  abstract protected function getColumns(): array;

  /**
   * Meta values a product must have to be exported, empty by default.
   * @return array meta key => required value
   */
  protected function getConditions(): array
  {
    return [];
  }

  /**
   * Column definitions after the project filter was applied.
   * @return array
   */
  protected function getFilteredColumns(): array
  {
    return apply_filters(self::FILTER_COLUMNS, $this->getColumns(), $this);
  }

  /**
   * Required meta values after the project filter was applied.
   * @return array meta key => required value
   */
  protected function getFilteredConditions(): array
  {
    return apply_filters(self::FILTER_CONDITIONS, $this->getConditions(), $this);
  }

  /**
   * @return array
   */
  protected function getHeaders(): array
  {
    return array_column($this->getFilteredColumns(), 'header');
  }

  /**
   * Build one row per matching product with the configured column values.
   * @return array
   */
  protected function getRows(): array
  {
    $columns = $this->getFilteredColumns();
    $rows = [];
    foreach ($this->getProducts($columns) as $product) {
      $rows[] = array_map(fn(array $column) => $this->getColumnValue($column, $product), $columns);
    }

    return $rows;
  }

  /**
   * Resolve a single column value of a product.
   * @param array $column column definition
   * @param array $product product row with ID, post_title and the pivoted meta keys
   * @return string
   */
  protected function getColumnValue(array $column, array $product): string
  {
    if (isset($column['value']) && is_callable($column['value'])) {
      return (string)call_user_func($column['value'], $product);
    }
    if (isset($column['meta'])) {
      return (string)($product[$column['meta']] ?? '');
    }
    return '';
  }

  /**
   * Title in the given language meta, falling back to the post title.
   * @param array $product product row
   * @param string $metaKey title meta key
   * @return string
   */
  protected function getProductTitle(array $product, string $metaKey): string
  {
    $title = trim((string)($product[$metaKey] ?? ''));
    return $title !== '' ? $title : html_entity_decode($product['post_title'], ENT_QUOTES, 'UTF-8');
  }

  /**
   * Load the matching published products with a SKU and all needed meta keys pivoted into columns.
   * @param array $columns column definitions
   * @return array product rows keyed by column name
   */
  protected function getProducts(array $columns): array
  {
    global $wpdb;

    $conditions = $this->getFilteredConditions();
    $metaKeys = $this->getMetaKeys($columns, array_keys($conditions));
    $having = ["meta_sku IS NOT NULL AND meta_sku != ''"];
    foreach ($conditions as $key => $value) {
      $having[] = $wpdb->prepare('`' . $this->getMetaAlias($key) . '` = %s', $value);
    }

    $metaKeyList = $wpdb->prepare(implode(',', array_fill(0, count($metaKeys), '%s')), ...$metaKeys);
    $postType = $wpdb->prepare('%s', Export::PIM_TYPE_SLUG);
    $sql = "SELECT p.ID, p.post_title, " . $this->getPivotSelect($metaKeys) . "
      FROM {$wpdb->posts} p
      INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ({$metaKeyList})
      WHERE p.post_type = {$postType} AND p.post_status = 'publish' AND " . $this->getProductCondition() . "
      GROUP BY p.ID
      HAVING " . implode(' AND ', $having) . "
      ORDER BY meta_sku";

    $products = [];
    foreach ($wpdb->get_results($sql, ARRAY_A) ?: [] as $row) {
      $products[] = $this->unaliasRow($row, $metaKeys);
    }

    return $products;
  }

  /**
   * All meta keys needed for the columns and conditions, the SKU is always included.
   * @param array $columns column definitions
   * @param array $conditionKeys meta keys used in conditions
   * @return array
   */
  protected function getMetaKeys(array $columns, array $conditionKeys): array
  {
    $keys = array_merge(['sku', 'title_de', 'title_fr'], array_column($columns, 'meta'), $conditionKeys);
    return array_values(array_unique(array_filter($keys, 'is_string')));
  }

  /**
   * Build the MAX(CASE …) select list pivoting each meta key into its own column.
   * @param array $metaKeys meta keys to pivot
   * @return string
   */
  protected function getPivotSelect(array $metaKeys): string
  {
    global $wpdb;

    $parts = [];
    foreach ($metaKeys as $key) {
      $parts[] = $wpdb->prepare('MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END)', $key)
        . ' AS `' . $this->getMetaAlias($key) . '`';
    }

    return implode(', ', $parts);
  }

  /**
   * SQL-safe column alias of a meta key, meta keys may contain characters not allowed in aliases.
   * @param string $key meta key
   * @return string
   */
  protected function getMetaAlias(string $key): string
  {
    return 'meta_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
  }

  /**
   * Map the aliased meta columns of a result row back to their meta keys.
   * @param array $row result row
   * @param array $metaKeys pivoted meta keys
   * @return array
   */
  protected function unaliasRow(array $row, array $metaKeys): array
  {
    $product = ['ID' => (int)$row['ID'], 'post_title' => (string)$row['post_title']];
    foreach ($metaKeys as $key) {
      $product[$key] = $row[$this->getMetaAlias($key)];
    }

    return $product;
  }
}
