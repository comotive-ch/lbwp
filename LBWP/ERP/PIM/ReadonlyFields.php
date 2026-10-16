<?php

namespace LBWP\ERP\PIM;

use LBWP\Core as LbwpCore;
use LBWP\ERP\PIM\Base as PimBase;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;

/**
 * Locks whitelisted product fields (ACF fields, thumbnail, taxonomies) in the backend UI.
 * This is a UI lock only: locked inputs are disabled, so they are not submitted and keep their stored value.
 * Programmatic writes (imports, update_field, wp_set_object_terms) are not affected.
 * @package LBWP\ERP\PIM
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class ReadonlyFields extends ACFBase
{
  /**
   * @var string slug of the settings options page
   */
  const OPTIONS_PAGE_SLUG = 'pim-readonly-fields';
  /**
   * @var string ACF field name of the whitelist setting
   */
  const SETTING_NAME = 'pim_readonly_fields';
  /**
   * @var string ACF field key of the whitelist setting
   */
  const SETTING_KEY = 'field_pim_readonly_fields';
  /**
   * @var string whitelist identifier of the featured image
   */
  const THUMBNAIL_ID = 'thumbnail';
  /**
   * @var string whitelist identifier prefix for taxonomies
   */
  const TAXONOMY_PREFIX = 'taxonomy:';
  /**
   * @var string separator between parent and child names in a field path
   */
  const PATH_SEPARATOR = '/';
  /**
   * @var string css class added to every locked field or meta box
   */
  const LOCKED_CLASS = 'lbwp-pim-readonly';
  /**
   * @var array ACF field types that natively support the readonly attribute
   */
  const NATIVE_READONLY_TYPES = ['text', 'textarea', 'number', 'email', 'url', 'range', 'date_picker'];
  /**
   * @var array ACF field types that only structure the form and can't be locked
   */
  const STRUCTURAL_TYPES = ['message', 'tab', 'accordion'];

  /**
   * @var array|null cached whitelist of locked identifiers
   */
  protected $whitelist = null;

  /**
   * Register the hooks for the settings choices and the field lock
   * @return void
   */
  public function setup()
  {
    parent::setup();

    add_filter('acf/load_field/key=' . self::SETTING_KEY, [$this, 'populateSettingChoices']);
    add_filter('acf/prepare_field', [$this, 'lockAcfField']);
    add_filter('quick_edit_show_taxonomy', [$this, 'hideLockedQuickEditTaxonomy'], 10, 3);
  }

  /**
   * Register the options page under the PIM menu
   * @return void
   */
  public function acfInit()
  {
    acf_add_options_sub_page([
      'page_title' => 'Felder Schreibschutz',
      'menu_title' => 'Felder Schreibschutz',
      'capability' => 'administrator',
      'menu_slug' => self::OPTIONS_PAGE_SLUG,
      'parent_slug' => 'options-general.php'
    ]);
  }

  /**
   * No blocks needed here
   * @return void
   */
  public function blocks()
  {
  }

  /**
   * Register the whitelist setting on the options page
   * @return void
   */
  public function fields()
  {
    acf_add_local_field_group([
      'key' => 'group_pim_readonly_fields_settings',
      'title' => 'Schreibgeschützte Felder',
      'fields' => [
        [
          'key' => self::SETTING_KEY,
          'label' => 'Felder',
          'name' => self::SETTING_NAME,
          'type' => 'checkbox',
          'instructions' => 'Ausgewählte Felder sind bei Produkten nur noch lesbar, auch für Administratoren. Importe sind davon nicht betroffen.',
          'choices' => [],
          'layout' => 'vertical',
          'toggle' => 1,
          'return_format' => 'value',
        ],
      ],
      'location' => [
        [
          [
            'param' => 'options_page',
            'operator' => '==',
            'value' => self::OPTIONS_PAGE_SLUG,
          ],
        ],
      ],
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'active' => true,
    ]);
  }

  /**
   * Enqueue the lock script and styles on the product edit screen, if anything is locked
   * @return void
   */
  public function adminAssets()
  {
    if (!$this->isProductEditScreen() || empty($this->getWhitelist())) {
      return;
    }

    $base = File::getResourceUri();
    wp_enqueue_style('lbwp-pim-readonly-fields', $base . '/css/lbwp-pim-readonly-fields.css', [], LbwpCore::REVISION);
    wp_enqueue_script('lbwp-pim-readonly-fields', $base . '/js/PIM/ReadonlyFields.js', ['jquery', 'acf-input'], LbwpCore::REVISION, true);
    wp_localize_script('lbwp-pim-readonly-fields', 'lbwpPimReadonly', [
      'lockedClass' => self::LOCKED_CLASS,
      'metaBoxes' => $this->getLockedMetaBoxIds(),
    ]);
  }

  /**
   * Fill the whitelist setting with all lockable product fields, the thumbnail and taxonomies
   * @param array $field the whitelist setting field
   * @return array the field with populated choices
   */
  public function populateSettingChoices($field)
  {
    $choices = [self::THUMBNAIL_ID => 'Beitragsbild'];
    $choices = array_merge($choices, $this->getTaxonomyChoices());

    foreach (acf_get_field_groups(['post_type' => PimBase::TYPE_SLUG]) as $group) {
      $choices = array_merge($choices, $this->getFieldChoices(acf_get_fields($group), $group['title']));
    }

    $field['choices'] = $choices;
    return $field;
  }

  /**
   * Mark a whitelisted ACF field as readonly on the product edit screen
   * @param array|false $field the field about to be rendered
   * @return array|false the (possibly locked) field
   */
  public function lockAcfField($field)
  {
    if (!is_array($field) || !$this->isProductEditScreen() || !$this->isLocked($this->getFieldPath($field))) {
      return $field;
    }

    $field['wrapper']['class'] = trim(($field['wrapper']['class'] ?? '') . ' ' . self::LOCKED_CLASS);
    if (in_array($field['type'], self::NATIVE_READONLY_TYPES, true)) {
      $field['readonly'] = 1;
    }

    return $field;
  }

  /**
   * Hide locked taxonomies in the quick and bulk edit forms of the product list
   * @param bool $show whether the taxonomy is shown
   * @param string $taxonomy the taxonomy slug
   * @param string $postType the post type of the list
   * @return bool false if the taxonomy is locked
   */
  public function hideLockedQuickEditTaxonomy($show, $taxonomy, $postType)
  {
    if ($postType === PimBase::TYPE_SLUG && $this->isLocked(self::TAXONOMY_PREFIX . $taxonomy)) {
      return false;
    }

    return $show;
  }

  /**
   * Get all locked identifiers from the setting, extendable by code
   * @return array list of field paths, the thumbnail id and taxonomy ids
   */
  public function getWhitelist()
  {
    if ($this->whitelist === null) {
      $saved = get_option('options_' . self::SETTING_NAME);
      $this->whitelist = apply_filters('lbwp_pim_readonly_fields', is_array($saved) ? $saved : []);
    }

    return $this->whitelist;
  }

  /**
   * Check if an identifier is on the whitelist
   * @param string $id field path, thumbnail id or taxonomy id
   * @return bool true if locked
   */
  protected function isLocked($id)
  {
    return in_array($id, $this->getWhitelist(), true);
  }

  /**
   * Check if the current admin screen is the product edit screen
   * @return bool true on the product add/edit screen
   */
  protected function isProductEditScreen()
  {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return $screen !== null && $screen->base === 'post' && $screen->post_type === PimBase::TYPE_SLUG;
  }

  /**
   * Build the stable path of a field from its own and its parents' names, e.g. "sync-settings/sku"
   * @param array $field the ACF field
   * @return string the field path
   */
  protected function getFieldPath($field)
  {
    $names = [!empty($field['_name']) ? $field['_name'] : $field['name']];
    $parentKey = $field['parent'] ?? '';

    while (is_string($parentKey) && strpos($parentKey, 'field_') === 0) {
      $parent = acf_get_field($parentKey);
      if (!$parent) {
        break;
      }
      array_unshift($names, $parent['name']);
      $parentKey = $parent['parent'] ?? '';
    }

    return implode(self::PATH_SEPARATOR, $names);
  }

  /**
   * Recursively build whitelist choices for a list of fields, descending into group fields
   * @param array $fields the ACF fields
   * @param string $labelPrefix label of the parent group(s)
   * @param string $pathPrefix path of the parent field(s)
   * @return array path => label choices
   */
  protected function getFieldChoices($fields, $labelPrefix, $pathPrefix = '')
  {
    $choices = [];

    foreach ($fields as $field) {
      if (empty($field['name']) || in_array($field['type'], self::STRUCTURAL_TYPES, true)) {
        continue;
      }

      $path = $pathPrefix . $field['name'];
      $label = $labelPrefix . ' › ' . $field['label'];

      if ($field['type'] === 'group' && !empty($field['sub_fields'])) {
        $choices = array_merge($choices, $this->getFieldChoices($field['sub_fields'], $label, $path . self::PATH_SEPARATOR));
        continue;
      }

      $choices[$path] = $label . ' (' . $path . ')';
    }

    return $choices;
  }

  /**
   * Build whitelist choices for all product taxonomies with a backend UI
   * @return array taxonomy id => label choices
   */
  protected function getTaxonomyChoices()
  {
    $choices = [];

    foreach (get_object_taxonomies(PimBase::TYPE_SLUG, 'objects') as $taxonomy) {
      if ($taxonomy->show_ui) {
        $choices[self::TAXONOMY_PREFIX . $taxonomy->name] = 'Taxonomie › ' . $taxonomy->labels->name;
      }
    }

    return $choices;
  }

  /**
   * Get the html ids of the native meta boxes (thumbnail, taxonomies) that are locked
   * @return array list of meta box ids
   */
  protected function getLockedMetaBoxIds()
  {
    $ids = [];

    if ($this->isLocked(self::THUMBNAIL_ID)) {
      $ids[] = 'postimagediv';
    }

    foreach (get_object_taxonomies(PimBase::TYPE_SLUG, 'objects') as $taxonomy) {
      if ($this->isLocked(self::TAXONOMY_PREFIX . $taxonomy->name)) {
        $ids[] = $taxonomy->hierarchical ? $taxonomy->name . 'div' : 'tagsdiv-' . $taxonomy->name;
      }
    }

    return $ids;
  }
}
