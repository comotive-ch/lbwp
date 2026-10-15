<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Helper\XlsxHelper;
use WP_Term;

/**
 * Exports the current product group tree and product-group assignments as XLSX files
 * in exactly the format ProductGroupImport expects, so they can be edited and re-imported.
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class ProductGroupExport
{
  const string ACTION_TREE = 'pexport_pg_tree';
  const string ACTION_ASSIGNMENTS = 'pexport_pg_assignments';
  const string CAPABILITY = 'edit_pages';
  const array ASSIGNMENT_HEADERS = ['Slug', 'SKU'];

  /**
   * Register the admin-post handlers that stream the downloads.
   * @return void
   */
  public function registerHandlers(): void
  {
    add_action('admin_post_' . self::ACTION_TREE, [$this, 'downloadCategoryTree']);
    add_action('admin_post_' . self::ACTION_ASSIGNMENTS, [$this, 'downloadAssignments']);
  }

  /**
   * Render the two product group export forms.
   * @return void
   */
  public function renderForms(): void
  {
    $this->renderForm(
      self::ACTION_TREE,
      __('Produktgruppen-Baum exportieren', 'lbwp'),
      __('Exportiert alle Produktgruppen in der aktuellen Reihenfolge im Format von "Produktgruppen-Baum importieren".', 'lbwp'),
      __('Kategoriebaum herunterladen', 'lbwp')
    );
    echo '<hr>';
    $this->renderForm(
      self::ACTION_ASSIGNMENTS,
      __('Produktgruppen-Zuweisung exportieren', 'lbwp'),
      __('Exportiert alle Zuweisungen von Produkten (SKU) zu Produktgruppen im Format von "Produktgruppen-Zuweisung importieren".', 'lbwp'),
      __('Zuweisungen herunterladen', 'lbwp')
    );
  }

  /**
   * Render a single download form posting to admin-post.php.
   * @param string $action admin-post action name, also used as nonce action
   * @param string $title section heading
   * @param string $description explanation shown below the heading
   * @param string $buttonLabel submit button label
   * @return void
   */
  protected function renderForm(string $action, string $title, string $description, string $buttonLabel): void
  {
    echo '<h2>' . esc_html($title) . '</h2>';
    echo '<p>' . esc_html($description) . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
    wp_nonce_field($action);
    echo '<p><input type="submit" class="button-primary" value="' . esc_attr($buttonLabel) . '" /></p>';
    echo '</form>';
  }

  /**
   * Stream the category tree as XLSX download.
   * @return void
   */
  public function downloadCategoryTree(): void
  {
    $this->verifyRequest(self::ACTION_TREE);
    $headers = array_merge(['Slug'], ProductGroupImport::LEVEL_COLS_DE, ProductGroupImport::LEVEL_COLS_FR);
    XlsxHelper::downloadFromArray($headers, $this->getCategoryTreeRows(), $this->getFilename('produktgruppen-baum'), true);
  }

  /**
   * Stream the product-group assignments as XLSX download.
   * @return void
   */
  public function downloadAssignments(): void
  {
    $this->verifyRequest(self::ACTION_ASSIGNMENTS);
    ini_set('memory_limit', '2G');
    set_time_limit(0);
    XlsxHelper::downloadFromArray(self::ASSIGNMENT_HEADERS, $this->getAssignmentRows(), $this->getFilename('produktgruppen-zuweisung'), true);
  }

  /**
   * Abort unless the current user may export and the nonce is valid.
   * @param string $action nonce action
   * @return void
   */
  protected function verifyRequest(string $action): void
  {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die(__('Keine Berechtigung.', 'lbwp'), 403);
    }
    check_admin_referer($action);
  }

  /**
   * Build a dated download filename.
   * @param string $base filename without date and extension
   * @return string
   */
  protected function getFilename(string $base): string
  {
    return $base . '-' . current_time('Y-m-d') . '.xlsx';
  }

  /**
   * Build the tree rows in depth-first order, one term per row with only its own level filled,
   * which is how the import derives parents and the order meta.
   * @return array rows of [slug, level1De … level5De, level1Fr … level5Fr]
   */
  protected function getCategoryTreeRows(): array
  {
    $terms = get_terms([
      'taxonomy' => ProductGroupImport::TAXONOMY_SLUG,
      'hide_empty' => false,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
      return [];
    }

    $children = $this->groupByParent($terms);
    $rows = [];
    $this->appendSubtree($children, 0, 0, $rows);
    return $rows;
  }

  /**
   * Group terms by parent ID, each group sorted by the order meta, then by name.
   * @param WP_Term[] $terms all product group terms
   * @return array parent term ID => sorted list of child terms
   */
  protected function groupByParent(array $terms): array
  {
    $knownIds = array_flip(array_map(fn(WP_Term $term) => $term->term_id, $terms));
    $children = [];
    foreach ($terms as $term) {
      $parent = isset($knownIds[$term->parent]) ? $term->parent : 0;
      $children[$parent][] = $term;
    }

    foreach ($children as &$siblings) {
      usort($siblings, [$this, 'compareTerms']);
    }
    unset($siblings);

    return $children;
  }

  /**
   * Sort terms by order meta ascending, terms without order last, then by name.
   * @param WP_Term $a first term
   * @param WP_Term $b second term
   * @return int
   */
  protected function compareTerms(WP_Term $a, WP_Term $b): int
  {
    $orderA = $this->getTermOrder($a);
    $orderB = $this->getTermOrder($b);
    if ($orderA !== $orderB) {
      return $orderA <=> $orderB;
    }
    return strnatcasecmp($a->name, $b->name);
  }

  /**
   * Get the numeric order meta of a term.
   * @param WP_Term $term the term
   * @return int order, PHP_INT_MAX if not set
   */
  protected function getTermOrder(WP_Term $term): int
  {
    $order = get_term_meta($term->term_id, 'order', true);
    return is_numeric($order) ? (int)$order : PHP_INT_MAX;
  }

  /**
   * Recursively append the rows of all children of a parent.
   * Terms deeper than the supported levels are skipped, the import could not place them anyway.
   * @param array $children parent term ID => sorted child terms
   * @param int $parentId parent whose children are appended
   * @param int $level zero-based level of the children
   * @param array $rows rows collected so far
   * @return void
   */
  protected function appendSubtree(array $children, int $parentId, int $level, array &$rows): void
  {
    if ($level >= count(ProductGroupImport::LEVEL_COLS_DE)) {
      return;
    }

    foreach ($children[$parentId] ?? [] as $term) {
      $rows[] = $this->buildTreeRow($term, $level);
      $this->appendSubtree($children, $term->term_id, $level + 1, $rows);
    }
  }

  /**
   * Build a single tree row with the names in the given level columns.
   * @param WP_Term $term the term
   * @param int $level zero-based level
   * @return array
   */
  protected function buildTreeRow(WP_Term $term, int $level): array
  {
    $levels = count(ProductGroupImport::LEVEL_COLS_DE);
    $namesDe = array_fill(0, $levels, '');
    $namesFr = array_fill(0, $levels, '');
    $namesDe[$level] = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
    $namesFr[$level] = (string)get_term_meta($term->term_id, 'name-fr', true);

    return array_merge([$term->slug], $namesDe, $namesFr);
  }

  /**
   * Load all product-group assignments of PIM products that have a SKU.
   * @return array rows of [slug, sku]
   */
  protected function getAssignmentRows(): array
  {
    global $wpdb;

    $sql = $wpdb->prepare(
      "SELECT t.slug, pm.meta_value AS sku
       FROM {$wpdb->term_relationships} tr
       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
       INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
       INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
       INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'sku'
       WHERE tt.taxonomy = %s AND p.post_type = %s AND pm.meta_value != ''
       ORDER BY t.slug, pm.meta_value",
      ProductGroupImport::TAXONOMY_SLUG,
      ProductGroupImport::POST_TYPE_SLUG
    );

    return $wpdb->get_results($sql, ARRAY_N) ?: [];
  }
}
