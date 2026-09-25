<?php

namespace LBWP\Helper\ACF;

/**
 * Makes an ACF true_false field editable via the WordPress bulk edit screen.
 * Adds a list column (required, as WP only renders bulk edit boxes for custom columns),
 * a tri-state select in the bulk edit box and saves the value for all edited posts.
 * @package LBWP\Helper\ACF
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class BulkEditField
{
  /**
   * @var string select value meaning "leave the field untouched"
   */
  const NO_CHANGE = '';
  /**
   * @var string the ACF field key (field_xxx)
   */
  protected $fieldKey = '';
  /**
   * @var array post types the bulk edit field is enabled for
   */
  protected $postTypes = [];
  /**
   * @var string label for the column and the bulk edit box, falls back to the field message/label
   */
  protected $label = '';

  /**
   * @param string $fieldKey the ACF field key (field_xxx)
   * @param string|array $postTypes one or more post types
   * @param string $label optional label, defaults to the field message or label
   */
  public function __construct($fieldKey, $postTypes, $label = '')
  {
    $this->fieldKey = $fieldKey;
    $this->postTypes = (array) $postTypes;
    $this->label = $label;
  }

  /**
   * Register all admin hooks needed for column, bulk edit box and saving
   * @return void
   */
  public function register()
  {
    if (!is_admin()) {
      return;
    }

    foreach ($this->postTypes as $postType) {
      add_filter('manage_' . $postType . '_posts_columns', [$this, 'addColumn']);
      add_action('manage_' . $postType . '_posts_custom_column', [$this, 'renderColumn'], 10, 2);
    }

    add_action('bulk_edit_custom_box', [$this, 'renderBulkEditBox'], 10, 2);
    add_action('bulk_edit_posts', [$this, 'saveBulkEdit'], 10, 2);
  }

  /**
   * Add the column of the field to the post list
   * @param array $columns the list columns
   * @return array the columns including the field column
   */
  public function addColumn($columns)
  {
    $columns[$this->getColumnName()] = esc_html($this->getLabel());
    return $columns;
  }

  /**
   * Render the current field value in the list column
   * @param string $column the current column name
   * @param int $postId the post id of the row
   * @return void
   */
  public function renderColumn($column, $postId)
  {
    if ($column !== $this->getColumnName()) {
      return;
    }

    echo $this->getValue($postId) ? __('Ja', 'lbwp') : '–';
  }

  /**
   * Render the tri-state select in the bulk edit box
   * @param string $column the current column name
   * @param string $postType the post type of the list
   * @return void
   */
  public function renderBulkEditBox($column, $postType)
  {
    if ($column !== $this->getColumnName() || !in_array($postType, $this->postTypes, true)) {
      return;
    }

    echo '
      <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
          <label class="alignleft">
            <span class="title">' . esc_html($this->getLabel()) . '</span>
            <select name="' . esc_attr($this->getInputName()) . '">
              <option value="' . self::NO_CHANGE . '">' . __('&mdash; Keine Änderung &mdash;', 'lbwp') . '</option>
              <option value="1">' . __('Ja', 'lbwp') . '</option>
              <option value="0">' . __('Nein', 'lbwp') . '</option>
            </select>
          </label>
        </div>
      </fieldset>
    ';
  }

  /**
   * Save the selected value on all bulk edited posts
   * @param int[] $updatedPostIds the post ids updated by bulk edit
   * @param array $sharedPostData the submitted bulk edit data
   * @return void
   */
  public function saveBulkEdit($updatedPostIds, $sharedPostData)
  {
    $value = $sharedPostData[$this->getInputName()] ?? self::NO_CHANGE;
    if ($value === self::NO_CHANGE) {
      return;
    }

    foreach ($updatedPostIds as $postId) {
      if ($this->canUpdate($postId)) {
        update_field($this->fieldKey, intval($value) === 1 ? 1 : 0, $postId);
      }
    }
  }

  /**
   * Check if a post may be updated by the current user and is of a supported type
   * @param int $postId the post id
   * @return bool true if the field may be updated
   */
  protected function canUpdate($postId)
  {
    return in_array(get_post_type($postId), $this->postTypes, true) && current_user_can('edit_post', $postId);
  }

  /**
   * Get the raw field value, read from meta directly for fast list rendering
   * @param int $postId the post id
   * @return bool true if the field is checked
   */
  protected function getValue($postId)
  {
    return intval(get_post_meta($postId, $this->getFieldName(), true)) === 1;
  }

  /**
   * Get the meta name of the field
   * @return string the field name
   */
  protected function getFieldName()
  {
    $field = acf_get_field($this->fieldKey);
    return is_array($field) ? $field['name'] : '';
  }

  /**
   * Get the label, fallback to the field message or label
   * @return string the label
   */
  protected function getLabel()
  {
    if (strlen($this->label) > 0) {
      return $this->label;
    }

    $field = acf_get_field($this->fieldKey);
    if (!is_array($field)) {
      return $this->fieldKey;
    }

    return strlen($field['message'] ?? '') > 0 ? $field['message'] : $field['label'];
  }

  /**
   * Get the unique list column name
   * @return string the column name
   */
  protected function getColumnName()
  {
    return 'acf-bulk-' . $this->fieldKey;
  }

  /**
   * Get the unique input name of the bulk edit select
   * @return string the input name
   */
  protected function getInputName()
  {
    return 'acf_bulk_' . $this->fieldKey;
  }
}
