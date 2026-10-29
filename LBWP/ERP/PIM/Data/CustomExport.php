<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Helper\XlsxHelper;

/**
 * Base for project specific XLSX exports shown on the PIMport export page below the general exports.
 * Subclasses only define the texts, headers and rows, the download handling is done here.
 * Register instances by overriding Export::getCustomExports() or via the lbwp_pim_custom_exports filter.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class CustomExport
{
  const string CAPABILITY = 'edit_pages';

  /**
   * Unique admin-post action name, also used as nonce action.
   * @return string
   */
  abstract public function getAction(): string;

  /**
   * Section heading on the export page.
   * @return string
   */
  abstract public function getTitle(): string;

  /**
   * Explanation shown below the heading.
   * @return string
   */
  abstract public function getDescription(): string;

  /**
   * Filename without date and extension.
   * @return string
   */
  abstract protected function getFilenameBase(): string;

  /**
   * Column headers of the export.
   * @return array
   */
  abstract protected function getHeaders(): array;

  /**
   * Data rows, each a list of values in header order.
   * @return array
   */
  abstract protected function getRows(): array;

  /**
   * Submit button label, override for a more specific text.
   * @return string
   */
  public function getButtonLabel(): string
  {
    return __('Herunterladen', 'lbwp');
  }

  /**
   * Additional form fields (e.g. filters), override to add some. Values are available in $_POST within getRows().
   * @return void
   */
  protected function renderOptions(): void
  {
  }

  /**
   * Register the admin-post handler that streams the download.
   * @return void
   */
  public function registerHandler(): void
  {
    add_action('admin_post_' . $this->getAction(), [$this, 'download']);
  }

  /**
   * Render the download form posting to admin-post.php.
   * @return void
   */
  public function renderForm(): void
  {
    $action = $this->getAction();
    echo '<h2>' . esc_html($this->getTitle()) . '</h2>';
    echo '<p>' . esc_html($this->getDescription()) . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
    wp_nonce_field($action);
    $this->renderOptions();
    echo '<p><input type="submit" class="button-primary" value="' . esc_attr($this->getButtonLabel()) . '" /></p>';
    echo '</form>';
  }

  /**
   * Verify the request and stream the XLSX download.
   * @return void
   */
  public function download(): void
  {
    if (!current_user_can(static::CAPABILITY)) {
      wp_die(__('Keine Berechtigung.', 'lbwp'), 403);
    }
    check_admin_referer($this->getAction());

    ini_set('memory_limit', '2G');
    set_time_limit(0);
    $filename = $this->getFilenameBase() . '-' . current_time('Y-m-d') . '.xlsx';
    XlsxHelper::downloadFromArray($this->getHeaders(), $this->getRows(), $filename, true);
  }
}
