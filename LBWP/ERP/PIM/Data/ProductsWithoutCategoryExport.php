<?php

namespace LBWP\ERP\PIM\Data;

/**
 * Exports all PIM products without a product group, with an empty first column so the file
 * can be completed and imported via "Produktgruppen-Zuweisung importieren" (slug, SKU by position).
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class ProductsWithoutCategoryExport extends ProductExport
{
  const string ACTION = 'pexport_products_without_category';

  /**
   * @return string
   */
  public function getAction(): string
  {
    return self::ACTION;
  }

  /**
   * @return string
   */
  public function getTitle(): string
  {
    return __('Export von Artikeln ohne Kategorien-Zuweisung', 'lbwp');
  }

  /**
   * @return string
   */
  public function getDescription(): string
  {
    return __('Exportiert alle Artikel ohne Produktgruppe. Die Spalte "Slug" kann ergänzt und die Datei danach als Produktgruppen-Zuweisung importiert werden.', 'lbwp');
  }

  /**
   * @return string
   */
  public function getButtonLabel(): string
  {
    return __('Artikel herunterladen', 'lbwp');
  }

  /**
   * @return string
   */
  protected function getFilenameBase(): string
  {
    return 'artikel-ohne-kategorie';
  }

  /**
   * Products without any product group term.
   * @return string
   */
  protected function getProductCondition(): string
  {
    global $wpdb;

    return $wpdb->prepare(
      "NOT EXISTS (
        SELECT 1 FROM {$wpdb->term_relationships} tr
        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE tr.object_id = p.ID AND tt.taxonomy = %s
      )",
      ProductGroupImport::TAXONOMY_SLUG
    );
  }

  /**
   * Slug and SKU must stay the first two columns, the assignment import reads them by position.
   * @return array
   */
  protected function getColumns(): array
  {
    return [
      ['header' => 'Slug', 'value' => fn(array $_product) => ''],
      ['header' => __('Artikelnummer', 'lbwp'), 'meta' => 'sku'],
      ['header' => __('Artikelbezeichnung DE', 'lbwp'), 'value' => fn(array $product) => $this->getProductTitle($product, 'title_de')],
      ['header' => __('Artikelbezeichnung FR', 'lbwp'), 'meta' => 'title_fr'],
    ];
  }
}
