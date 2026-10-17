<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Theme\Component\ACFBase;

/**
 * PIM Export: admin page to download the current data in the formats the PIM import expects.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class Export extends ACFBase
{
  const string PIM_TYPE_SLUG = 'lbwp-pid';
  const string FILTER_CUSTOM_EXPORTS = 'lbwp_pim_custom_exports';

  /**
   * @var ProductGroupExport
   */
  protected ProductGroupExport $productGroupExport;
  /**
   * @var CustomExport[]
   */
  protected array $defaultExports = [];
  /**
   * @var CustomExport[]
   */
  protected array $customExports = [];

  /**
   * Register the admin menu and the download handlers.
   * @return void
   */
  public function init(): void
  {
    $this->productGroupExport = new ProductGroupExport();
    $this->productGroupExport->registerHandlers();
    $this->defaultExports = $this->getDefaultExports();
    foreach ($this->defaultExports as $export) {
      $export->registerHandler();
    }
    add_action('wp_loaded', [$this, 'registerCustomExports']);
    add_action('admin_menu', [$this, 'registerMenu']);
  }

  /**
   * Collect the project specific exports and register their download handlers.
   * Runs on wp_loaded so filters added in any component's init() are already in place.
   * @return void
   */
  public function registerCustomExports(): void
  {
    $exports = apply_filters(self::FILTER_CUSTOM_EXPORTS, $this->getCustomExports());
    $this->customExports = array_filter($exports, fn($export) => $export instanceof CustomExport);
    foreach ($this->customExports as $export) {
      $export->registerHandler();
    }
  }

  /**
   * General exports available in every PIM, shown after the product group exports.
   * @return CustomExport[]
   */
  protected function getDefaultExports(): array
  {
    return [
      new ProductsWithoutImageExport(),
      new ProductsWithoutCategoryExport(),
    ];
  }

  /**
   * Project specific exports shown after the general ones, override in a subclass to add some.
   * @return CustomExport[]
   */
  protected function getCustomExports(): array
  {
    return [];
  }

  /**
   * Register the export submenu below PIMport.
   * @return void
   */
  public function registerMenu(): void
  {
    add_submenu_page(
      'pimport',
      'Export',
      'Export',
      ProductGroupExport::CAPABILITY,
      'pexport',
      [$this, 'renderExportPage']
    );
  }

  /**
   * Render the export admin page.
   * @return void
   */
  public function renderExportPage(): void
  {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('PIMport Export', 'lbwp') . '</h1>';
    echo '<p>' . esc_html__('Die exportierten Dateien können bearbeitet und unter PIMport > Import wieder importiert werden.', 'lbwp') . '</p>';
    $this->productGroupExport->renderForms();
    foreach ($this->defaultExports as $export) {
      echo '<hr>';
      $export->renderForm();
    }
    $this->renderCustomExports();
    echo '</div>';
  }

  /**
   * Render the project specific exports in their own section, if there are any.
   * @return void
   */
  protected function renderCustomExports(): void
  {
    if (empty($this->customExports)) {
      return;
    }

    echo '<hr>';
    echo '<h3 style="font-size:22px;">' . esc_html__('Kundenspezifische Exporte', 'lbwp') . '</h3>';
    foreach (array_values($this->customExports) as $index => $export) {
      if ($index > 0) {
        echo '<hr>';
      }
      $export->renderForm();
    }
  }

  /**
   * No blocks needed here.
   * @return void
   */
  public function blocks(): void
  {
  }

  /**
   * No field settings needed here.
   * @return void
   */
  public function fields(): void
  {
  }
}
