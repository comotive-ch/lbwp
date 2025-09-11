<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Base\Component;
use LBWP\Theme\Base\CoreV2;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * ACF Base component
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class ACFBase extends Component
{
  /**
   * @var array
   */
  protected $bidirectionalRelations = array();

  /**
   * Make sure to early call the acf/init features
   */
  public function setup()
  {
    add_action('acf/init', array($this, 'acfInit'), 10);
    add_action('acf/init', array($this, 'fields'), 10);
    add_action('acf/init', array($this, 'blocks'), 10);
  }

  /**
   * Init the ACFBase component
   */
  public function init() {}

  /**
   * Not used right now
   */
  public function acfInit() {}

  /**
   * @param $name
   * @param int $postId
   * @param bool $single
   * @return bool|mixed
   */
  public static function meta($name, $postId = 0, $single = true)
  {
    if ($postId == 0) {
      $postId = intval(WordPress::getPostId());
      if ($postId == 0) {
        return false;
      }
    }

    $meta = get_post_meta($postId, $name, true);
    if ($single && is_array($meta)) {
      $meta = $meta[0];
    }

    return $meta;
  }

  /**
   * @param $name
   * @param $value
   * @param int $postId
   * @return bool
   */
  public static function isSelected($name, $value, $postId = 0)
  {
    $values = self::meta($name, $postId, false);
    if (!is_array($values)) return false;
    return in_array($value, $values);
  }

  /**
   * @param $name
   * @return mixed|void
   */
  public static function option($name)
  {
    return get_option('options_' . $name);
  }

  /**
   * @param string $option
   * @return bool
   */
  public static function isOptionActive($option)
  {
    $tmp = get_option('options_' . $option);
    return (is_array($tmp) && $tmp[0] == 1);
  }

  /**
   * @param $config
   */
  protected function registerBlock($config)
  {
    $base = array(
      'post_types' => array('post', 'page'),
      'supports' => array(
        'align' => false,
        'anchor' => true,
        'mode' => $config['preview']
      ),
      'mode' => $config['preview'] ? 'auto' : 'edit',
      'category' => 'layout',
      'render_callback' => array($this, 'emptyBlockFallback')
    );

    // Add from base what is not already in config
    foreach ($base as $key => $value) {
      if (!isset($config[$key])) {
        $config[$key] = $value;
      }
    }

    // When it should be shown on widget screen, unset the post_types array so ACF shows it
    if (in_array('widgets', $config['post_types']) && str_ends_with($_SERVER['REQUEST_URI'], 'widgets.php')) {
      unset($config['post_types']);
    }

    // then, let devs expand it if needed
    $config = apply_filters('lbwp_custom_block_args', $config);
    acf_register_block_type($config);
  }

  /**
   * @param $title
   * @param $slug
   * @param $parent
   * @param array $config
   */
  protected function addOptionsPage($title, $slug, $parent = 'options-general.php', $config = array())
  {
    $page = array(
      'page_title' => $title,
      'menu_title' => $title,
      'menu_slug' => $slug,
      'parent_slug' => $parent
    );
    foreach ($config as $key => $value) {
      $page[$key] = $value;
    }
    acf_add_options_page($page);
  }

  /**
   * Save bidirectional relationship fields
   */
  protected function saveBidirectionalRelations()
  {
    if (count($this->bidirectionalRelations) > 0) {
      foreach ($this->bidirectionalRelations as $relationship) {
        add_filter('acf/update_value', function ($value, $postId, $field) {
          foreach ($this->bidirectionalRelations as $relationship) {
            list($a, $b) = $relationship;
            if ($field['name'] === $a) {
              return $this->syncBidirectionalField($a, $b, $value, $postId);
            } else if ($field['name'] === $b) {
              return $this->syncBidirectionalField($b, $a, $value, $postId);
            }
          }
          return $value;
        }, 10, 3);
      }
    }
  }

  /**
   * @param $key_a
   * @param $key_b
   * @param $value
   * @param $postId
   * @return mixed
   */
  protected function syncBidirectionalField($name_a, $name_b, $value, $postId)
  {
    $field_a = acf_get_field($name_a);
    $field_b = acf_get_field($name_b);

    // set the field names to check
    // for each post
    $key_a = $field_a['key'];
    $key_b = $field_b['key'];

    // get the old value from the current post
    // compare it to the new value to see
    // if anything needs to be updated
    // use get_post_meta() to a avoid conflicts
    $old_values = get_post_meta($postId, $name_a, true);
    // make sure that the value is an array
    if (!is_array($old_values)) {
      if (empty($old_values)) {
        $old_values = array();
      } else {
        $old_values = array($old_values);
      }
    }
    // set new values to $value
    // we don't want to mess with $value
    $new_values = $value;
    // make sure that the value is an array
    if (!is_array($new_values)) {
      if (empty($new_values)) {
        $new_values = array();
      } else {
        $new_values = array($new_values);
      }
    }

    // get the differences
    $add = $new_values;
    $delete = array_diff($old_values, $new_values);

    // reorder the arrays to prevent possible invalid index errors
    $add = array_values($add);
    $delete = array_values($delete);

    if (!count($add) && !count($delete)) {
      // there are no changes,  so there's nothing to do
      return $value;
    }

    // do deletes first, loop through all of the posts that need to have the recipricol relationship removed
    for ($i = 0; $i < count($delete); $i++) {
      $related_values = get_post_meta($delete[$i], $name_b, true);
      if (!is_array($related_values)) {
        if (empty($related_values)) {
          $related_values = array();
        } else {
          $related_values = array($related_values);
        }
      }
      // we use array_diff again, this will remove the value without needing to loop
      $related_values = array_diff($related_values, array($postId));
      // insert the new value, insert the acf key reference, just in case
      update_post_meta($delete[$i], $name_b, $related_values);
      update_post_meta($delete[$i], '_' . $name_b, $key_b);
    }

    // do additions, to add $post_id
    for ($i = 0; $i < count($add); $i++) {
      $related_values = get_post_meta($add[$i], $name_b, true);
      if (!is_array($related_values)) {
        if (empty($related_values)) {
          $related_values = array();
        } else {
          $related_values = array($related_values);
        }
      }
      if (!in_array($postId, $related_values)) {
        // add new relationship if it does not exist
        $related_values[] = $postId;
      }
      // update value
      update_post_meta($add[$i], $name_b, $related_values);
      // insert the acf key reference, just in case
      update_post_meta($add[$i], '_' . $name_b, $key_b);
    }

    return $value;
  }

  /**
   * @param string $left name of the left field of the relation
   * @param string $right name of the left field of the relation
   */
  protected function addBidirectionalRelation($left, $right)
  {
    $this->bidirectionalRelations[] = array($left, $right);
  }

  /**
   * @return mixed
   */
  abstract public function fields();

  abstract public function blocks();

  /**
   * Check if is gutenberg and if block is empty then show fallback
   * @param array $block the full block object
   * @return string eventually changed/wrapped html
   */
  public function emptyBlockFallback($block, $content, $is_preview, $post_id, $wp_block, $context){
    $renderBlock = true;
    $forcePreview = false;

    if(is_admin() && is_array($block)){
      $emptyBlock = true;
      $renderBlock = false;
      $forcePreview = apply_filters('lbwp_force_block_preview', false, $block);

      if(!empty($block['data']) && !$forcePreview){
        foreach ($block['data'] as $fieldKey => $field){
          $theField = $field;
          $theFieldKey = $fieldKey;
          $fieldData = get_field_object($theFieldKey);
          $isRepeater = $fieldData['type'] === 'repeater';

          if($fieldData['type'] === 'radio'){
            continue;
          }

          if($isRepeater && is_array($theField)){
            $repeaterContent = array_filter($theField, function($fVals){
              foreach($fVals as $fVal){
                if(!empty($fVal)){
                  return $fVals;
                }
              }
            });

            // Ignoring counting (empty) rows
            if(empty($repeaterContent) || $fieldData['name'] === $theField || $fieldData['name'] === $theFieldKey){
              continue;
            }
          }

          if(Strings::startsWith($fieldKey, '_') && !$isRepeater){
            $theFieldKey = $field;
            $theField = $block['data'][substr($fieldKey, 1)] === null ? '' : $block['data'][substr($fieldKey, 1)];
            $fieldData = get_field_object($theFieldKey);

						if(Strings::isEmpty($theField)){
							$theField = $fieldData['value'];
						}
          }

          if($fieldData['type'] === 'radio'){
            continue;
          }

          if(is_array($theField)){
            $arrayContent = array_filter($theField, function($fVal, $fKey){
              $isRadio = get_field_object($fKey)['type'] === 'radio';

              if(!empty($fVal) && !$isRadio){
                return $fVal;
              }
            }, ARRAY_FILTER_USE_BOTH);

            if(empty($arrayContent)){
              continue;
            }
          }

          // check if is row counter
          if(is_array($fieldData['value'])){
            if(count($fieldData['value']) === $theField){
              continue;
            }
          }

          if(!Strings::isEmpty($theField)){
            $emptyBlock = false;
            $renderBlock = true;
          }
        }
      }

      if($emptyBlock && !$forcePreview){
        $msg = isset($block['missing_settings_message']) ? $block['missing_settings_message'] : 'Der Block &laquo;' . $block['title'] . '&raquo; benötigt noch Einstellungen.';
        echo '
          <div class="lbwp-empty-block-fallback">
            <div class="no-content-text">
              <p>' . $msg . '</p>
            </div>
          </div>';
      }
    }

    if($renderBlock || $forcePreview) {
      if (file_exists($block['render_template'])) {
        $path = $block['render_template'];
      } else {
        $path = locate_template($block['render_template']);
      }

      if (file_exists($path)) {
        include($path);
      }
    }
  }
	
	/**
	 * Render the acf inner block
	 *
	 * @param  array $allowedBlocks the alloweblocks, format:
	 * 	array('core/heading', 'core/paragraph, [...])),
	 * @param  array $template a predefined template, format:
	 * 	array(
	 * 		array('core/heading', array(
	 * 			'level' => 2,
	 * 			'content' => 'Title Goes Here',
	 * 		)),
	 * 		[...]
	 * 	)
	 * @param  bool $templateLock if the template should be locked (default false)
	 * @return string the inner block html
	 */
	public static function renderInnerBlock($allowedBlocks = false, $template = false, $templateLock = false){
		$innerBlocks = '<InnerBlocks {aBlocks} {template} {tLock} />';

		$innerBlocks = str_replace('{aBlocks}', ($allowedBlocks === false ? '' : 
			'allowedBlocks="' . esc_attr(wp_json_encode($allowedBlocks)) . '"'), 
			$innerBlocks);

		$innerBlocks = str_replace('{template}', ($template === false ? '' : 
			'template="' .	esc_attr(wp_json_encode($template)) . '"'), 
			$innerBlocks);

		$innerBlocks = str_replace('{tLock}', ($templateLock === false ? '' : 
			'templateLock="all"'), 
			$innerBlocks);

		echo $innerBlocks;
	}

  /**
   * @param int $postId
   * @param string $mainKey key of the repeater
   * @param string $subKeys name of the sub fields
   * @return void
   */
  protected function recountRepeaterEntries($postId, $mainKey, $subKeys)
  {
    $keys = $keys2 = array();
    foreach ($subKeys as $key) {
      $keys[] = 'meta_key LIKE "' . $mainKey . '_%_' . $key . '"';
      $keys2[] = 'meta_key LIKE "_' . $mainKey . '_%_' . $key . '"';
    }
    // Get all according keys of the repeater
    $db = WordPress::getDb();
    $raw = $db->get_results('
      SELECT meta_id, meta_key FROM ' . $db->postmeta . '
      WHERE post_id = ' . $postId . ' AND (' . implode(' OR ', $keys) . ')
      ORDER BY meta_key ASC
    ');
    $raw2 = $db->get_results('
      SELECT meta_id, meta_key FROM ' . $db->postmeta . '
      WHERE post_id = ' . $postId . ' AND (' . implode(' OR ', $keys2) . ')
      ORDER BY meta_key ASC
    ');

    $fieldCount = count($subKeys);
    $rowsTotal = count($raw) / $fieldCount;
    for ($i = 0; $i < $rowsTotal; $i++) {
      // Get the next items (number of subkeys) from the array
      $slice = array_splice($raw,0, $fieldCount);
      $slice2 = array_splice($raw2,0, $fieldCount);
      // Update the old keys from that slice to the new onces
      foreach ($slice as $meta) {
        list($type, $id, $field) = explode('_', $meta->meta_key);
        $newKey = str_replace($id, $i, $meta->meta_key);
        $db->query('
          UPDATE ' . $db->postmeta . ' SET meta_key = "' . $newKey . '"
          WHERE meta_id = ' . $meta->meta_id . ' 
        ');
      }
      foreach ($slice2 as $meta) {
        list($bogus, $type, $id, $field) = explode('_', $meta->meta_key);
        $newKey = str_replace($id, $i, $meta->meta_key);
        $db->query('
          UPDATE ' . $db->postmeta . ' SET meta_key = "' . $newKey . '"
          WHERE meta_id = ' . $meta->meta_id . ' 
        ');
      }
    }

    // Finally update the count
    $db->query('
      UPDATE ' . $db->postmeta . ' SET meta_value = "' . $rowsTotal . '"
      WHERE meta_key = "' . $mainKey . '" AND post_id = ' . $postId . '
    ');
  }

  /**
   * Faster save function for repeaters, but adds data at the end
   * @param int $postId
   * @param string $mainKey
   * @param array $keyValues
   * @return void
   */
  public static function addRepeaterEntry($postId, $mainKey, $keyValues)
  {
    $field = acf_get_field($mainKey);
    $rows = intval(get_post_meta($postId, $field['name'], true));
    $mainName = $field['name'];

    // Add very basics, if now rows yet
    if ($rows == 0) {
      update_post_meta($postId, '_' . $mainName, $mainKey);
    }

    // Add a new row
    foreach ($keyValues as $subKey => $value) {
      $subName = '';
      foreach ($field['sub_fields'] as $subfield) {
        if ($subfield['key'] == $subKey) {
          $subName = $subfield['name'];
        }
      }

      if (strlen($subName) > 0) {
        $rowKey = $mainName . '_' . $rows . '_' . $subName;
        update_post_meta($postId, '_' . $rowKey, $subKey);
        update_post_meta($postId, $rowKey, $value);
      }
    }

    // Update to inform it has one more row
    update_post_meta($postId, $field['name'], ++$rows);
  }

  /**
   * Faster save function for repeaters, but adds data at the end
   * @param int $postId
   * @param string $mainKey
   * @param array $keyValues
   * @return void
   */
  public static function addOptionRepeaterEntry($mainKey, $keyValues)
  {
    $field = acf_get_field($mainKey);
    $rows = intval(get_option('options_' . $field['name']));
    $mainName = 'options_' . $field['name'];

    // Add very basics, if now rows yet
    if ($rows == 0) {
      update_option( '_' . $mainName, $mainKey);
    }

    // Add a new row
    foreach ($keyValues as $subKey => $value) {
      $subName = '';
      foreach ($field['sub_fields'] as $subfield) {
        if ($subfield['key'] == $subKey) {
          $subName = $subfield['name'];
        }
      }

      if (strlen($subName) > 0) {
        $rowKey = $mainName . '_' . $rows . '_' . $subName;
        update_option('_' . $rowKey, $subKey);
        update_option($rowKey, $value);
      }
    }

    // Update to inform it has one more row
    update_option($mainName, ++$rows);
  }
}
