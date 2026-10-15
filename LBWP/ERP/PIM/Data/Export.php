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

  /**
   * @var ProductGroupExport
   */
  protected ProductGroupExport $productGroupExport;

  /**
   * Register the admin menu and the download handlers.
   * @return void
   */
  public function init(): void
  {
    $this->productGroupExport = new ProductGroupExport();
    $this->productGroupExport->registerHandlers();
    add_action('admin_menu', [$this, 'registerMenu']);
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
    echo '</div>';
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
