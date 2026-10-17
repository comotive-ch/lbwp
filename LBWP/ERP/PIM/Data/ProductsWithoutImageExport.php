<?php

namespace LBWP\ERP\PIM\Data;

/**
 * Exports all PIM products without a featured image.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class ProductsWithoutImageExport extends ProductExport
{
  const string ACTION = 'pexport_products_without_image';

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
    return __('Export von Artikeln ohne Bildzuweisung', 'lbwp');
  }

  /**
   * @return string
   */
  public function getDescription(): string
  {
    return __('Exportiert alle Artikel, denen kein Beitragsbild zugewiesen ist.', 'lbwp');
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
    return 'artikel-ohne-bild';
  }

  /**
   * Products without a _thumbnail_id meta or with an empty one.
   * @return string
   */
  protected function getProductCondition(): string
  {
    global $wpdb;

    return "NOT EXISTS (
      SELECT 1 FROM {$wpdb->postmeta} thumb
      WHERE thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id' AND thumb.meta_value NOT IN ('', '0')
    )";
  }

  /**
   * @return array
   */
  protected function getColumns(): array
  {
    return [
      ['header' => __('Artikelnummer', 'lbwp'), 'meta' => 'sku'],
      ['header' => __('Artikelbezeichnung DE', 'lbwp'), 'value' => fn(array $product) => $this->getProductTitle($product, 'title_de')],
      ['header' => __('Artikelbezeichnung FR', 'lbwp'), 'meta' => 'title_fr'],
    ];
  }
}
