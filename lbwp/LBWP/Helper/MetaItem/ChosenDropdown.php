<?php

namespace LBWP\Helper\MetaItem;

use LBWP\Helper\MetaItem\Templates;
use LBWP\Module\General\AuthorHelper;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Helper object to provide chosen dropdowns
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class ChosenDropdown
{
  /**
   * Display Dropdown callback: displays a harvesthq.github.io/chosen/ dropdown.
   * special arguments:
   * - bool required
   * - bool multiple
   * - array | string value
   * - array items | callable itemsCallback
   * @param $args
   * @return mixed|string
   */
  public static function displayDropdown($args)
  {
    $key = $args['post']->ID . '_' . $args['key'];
    if (!isset($args['containerClasses'])) {
      $args['containerClasses'] = 'chosen-dropdown-item';
    }
    $html = Templates::get($args, $key);
    $isCrossReference = Strings::startsWith($key, $args['post']->ID . '_' . CrossReference::PREFIX);

    if (isset($args['name'])) {
      $name = $args['name'];
    } else {
      // Get the current value
      $name = $key;
    }

    $attr = ' data-metakey="' . $args['key'] . '"';
    if (isset($args['required'])) {
      $attr .= __checked_selected_helper($args['required'], true, false, 'required');
    }

    $singleValue = true;
    if (isset($args['multiple']) && $args['multiple']) {
      $attr .= ' multiple';

      $arrayNotation = '[]';
      if (substr($name, -strlen($arrayNotation)) !== $arrayNotation) {
        $name .= $arrayNotation;
      }
      $singleValue = false;
    }

    // Set default texts and settings for chosen, if none are given
    if (!isset($args['placeholder_text_multiple']))
      $args['placeholder_text_multiple'] = __('Bitte wählen', 'lbwp');
    if (!isset($args['placeholder_text_single']))
      $args['placeholder_text_single'] = __('Bitte wählen', 'lbwp');
    if (!isset($args['no_results_text']))
      $args['no_results_text'] = __('Keine Suchergebnisse für', 'lbwp');
    if (!isset($args['disable_search_threshold']))
      $args['disable_search_threshold'] = 9;

    if (isset($args['value']) && $singleValue && !is_array($args['value']) && !is_object($args['value'])) {
      $value = $args['value'];
    } else if (isset($args['value']) && (!$singleValue || is_string($args['value']))) {
      $value = $args['value'];
    } else if (isset($args['value'][0]) && $singleValue) {
      $value = $args['value'][0];
    } else {
      // Get the current value
      $value = get_post_meta($args['post']->ID, $args['key'], $singleValue);
      if (!$singleValue) {
        $value = array_filter($value);
      }
    }

    // Reset single value on cross references after loading
    if ($isCrossReference) {
      $singleValue = false;
      $value = $value[0];
    }

    $items = $args['items'];
    if (isset($args['itemsCallback']) && is_callable($args['itemsCallback'])) {
      $items = callUserFunctionWithSafeArguments($args['itemsCallback'], array($args));
    }

    if (isset($args['allow_single_deselect']) && $args['allow_single_deselect'] == true) {
      $items = array(0 => array('title' => '')) + $items;
    }

    // Only the current full selection is selectable values
    if ($items == 'self') {
      $value = ArrayManipulation::forceArrayAndInclude($value);
      $items = array();
      foreach ($value as $selection) {
        $items[$selection] = $selection;
      }
    }

    $options = self::convertDropdownItemsToOptions($items, $value, $singleValue);

    $select = '
      <select name="' . $name . '" id="' . $key . '" ' . $attr . '>
        ' . implode('', $options) . '
      </select>
    ';

    // Add chosen javascripts
    $select .= self::addChosenScripts($key, $args);

    // Add html to add a new item, if config is given
    if (isset($args['newItem'])) {
      $select .= self::addNewItemHtml($key, $args);
    }

    $html = str_replace('{input}', $select, $html);
    return $html;
  }

  /**
   * Adds javascript for chosen dropdown to work
   * @param string $key the option key
   * @param array $args the arguments
   * @return string html/js
   */
  protected static function addChosenScripts($key, $args)
  {
    $html = '';
    $chosenArguments = array_merge(array(
      'width' => '100%', // sets the width of the chosen container
      'search_contains' => true,
    ), $args);

    $chosenKey = str_replace('-', '_', $key) . '_chosen';

    // convert array to object recursively
    $chosenArgumentsObject = json_decode(json_encode($chosenArguments), FALSE);
    $chosenArgumentsJson = json_encode($chosenArgumentsObject);

    $html .= '
      <script>
        (function ($) {
          $(document).ready(function(){
            // Register an event on change of the chosen
            if (typeof(MetaboxHelper) != "undefined") {
              $("#' . $key . '").on("chosen:ready change", function() {
                MetaboxHelper.handleChosenEventsOnChange("' . $chosenKey . '","' . $key . '");
              });
            }
            // Actually create the chosen with arguments
            jQuery("#' . $key . '").chosen(' . $chosenArgumentsJson . ');
          });
        }(jQuery));
      </script>
    ';

    // only call the sortable method if it was requested
    if ($chosenArguments['sortable'] != false) {
      $html .= '
        <script type="text/javascript">
          jQuery(function() {
            jQuery("#' . $key . '").chosenSortable();
          });
        </script>
      ';
    }

    // Let the user add new values to this dropdown
    if (isset($chosenArguments['add_new_values'])) {
      $html .= '
        <script type="text/javascript">
          jQuery(function() {
            jQuery("#' . $key . '_add_item").on("click", function() {
              var element = jQuery("#' . $key . '_added_text");
              dropdown = jQuery("#' . $key . '");
              dropdown.append(\'<option value="\' + element.val() + \'" selected="selected">\' + element.val() + \'</option>\');
              dropdown.trigger("chosen:updated");
              element.val("");
            });
          });
        </script>
        <div class="mbh-add-dropdown-value">
          <input type="text" id="' . $key . '_added_text" />
          <input type="button" class="button" id="' . $key . '_add_item" data-key="' . $key . '" value="Hinzufügen" />
        </div>
      ';
    }

    return $html;
  }

  /**
   * Adds an html block to add new items to the dropdown
   * @param string $key the option key
   * @param array $args the arguments
   * @return string html/js
   */
  public static function addNewItemHtml($key, $args)
  {
    // Prepare some data for the html block
    $id = $key . '-newitem';
    $typeDropdown = '';
    $attr = '
      data-post-id="' . $args['post']->ID . '"
      data-post-type="' . esc_attr($args['newItem']['postType']) . '"
      data-original-select="' . $key . '"
      data-ajax-action="' . $args['newItem']['ajaxAction'] . '"
      data-option-key="' . esc_attr($args['key']) . '"
    ';

    // Add a dropdown for a type, if given
    if (isset($args['metaDropdown'])) {
      // Sort the data array, if needed (
      if (isset($args['metaDropdown']['sortBy'])) {
        switch ($args['metaDropdown']['sortBy']) {
          case 'sort':
            uasort($args['metaDropdown']['data'], array('\LBWP\Helper\MetaItem\ChosenDropdown', 'sortByNumber'));
            break;
        }
      }

      // Create the dropdown
      $typeDropdown = '<select id="' . $id . '-metaDropdown" name="metaDropdown" class="mbh-type-dropdown-inline" data-key="' . $args['metaDropdown']['key'] . '">';
      foreach ($args['metaDropdown']['data'] as $key => $item) {
        $typeDropdown .= '<option value="' . $item['key'] . '">' . $item['name'] . '</option>';
      }
      $typeDropdown .= '</select>';
    }

    // Directly return the html block
    return '
      <div class="add-new-dropdown-item ' . $args['newItem']['containerClass'] . '">
        <label for="' . $id . '">' . $args['newItem']['title'] . ':</label>
        <input type="text" id="' . $id . '" value="" placeholder="Titel eingeben">
        ' . $typeDropdown . '
        <a href="javascript:void(0);" class="button"' . $attr . '>Hinzufügen</a>
      </div>
    ';
  }

  /**
   * Update the sort order of post ids
   */
  public static function updateSortOrder()
  {
    // Validate input data
    $pos = stripos($_POST['flag'], '_');
    $postId = intval(substr($_POST['flag'], 0, $pos));
    $key = substr($_POST['flag'], $pos+1);
    $list = array_map('intval', $_POST['ids']);

    // Override the meta list, if all is good
    if ($postId > 0 && strlen($key) > 0) {
      self::saveToMeta($postId, $key, $list);
    }

    WordPress::sendJsonResponse(array(
      'success' => true
    ));
  }

  /**
   * @param $a
   * @param $b
   */
  public static function sortByNumber($a, $b)
  {
    if ($a['sort'] > $b['sort']) {
      return 1;
    } else if ($a['sort'] < $b['sort']) {
      return -1;
    } else {
      return 0;
    }
  }

  /**
   * helper function for converting the supplied dropdown items to option tags for the chosen plugin
   * @param $items
   * @param $value
   * @param bool $singleValue
   * @return array
   */
  public static function convertDropdownItemsToOptions($items, $value, $singleValue = false)
  {
    $options = array();

    if (!$singleValue && is_array($value)) {
      foreach ($value as $selectedValue) {
        $title = $items[$selectedValue];
        if (isset($items[$selectedValue]['title'])) {
          $title = $items[$selectedValue]['title'];
        }
        $dataAttributes = '';
        if (isset($items[$selectedValue]['data'])) {
          $data = $items[$selectedValue]['data'];
          foreach ($data as $dataKey => $dataValue) {
            $dataAttributes .= ' data-' . $dataKey . '="' . $dataValue . '"';
          }
        }
        // Only add if the selected value still exists in items
        if (isset($items[$selectedValue])) {
          $options[$selectedValue] = '<option value="' . $selectedValue . '" selected="selected" ' . $dataAttributes . '>' . $title . '</option>';
        }
      }
    }

    foreach ($items as $itemValue => $item) {
      // Skip if already added above
      if (isset($options[$itemValue])) {
        continue;
      }

      $dataAttributes = '';
      if (isset($item['data'])) {
        $data = $item['data'];
        foreach ($data as $dataKey => $dataValue) {
          $dataAttributes .= ' data-' . $dataKey . '="' . $dataValue . '"';
        }
      }

      $title = $item;
      if (isset($item['title'])) {
        $title = $item['title'];
      }
      if ($singleValue) {
        $selected = selected($itemValue, $value, false);
        $options[$itemValue] = '<option value="' . $itemValue . '" ' . $selected . ' ' . $dataAttributes . '>' . $title . '</option>';
      } else {
        if (is_array($itemValue) && in_array($itemValue, $value)) {
          continue;
        } else {
          $options[$itemValue] = '<option value="' . $itemValue . '" ' . $dataAttributes . '>' . $title . '</option>';
        }
      }
    }
    return $options;
  }

  /**
   * Save the dropdown values, accepts arrays (if multiple was specified) and
   * stores them in order with add_post_meta $unique=false.
   * @param $postId
   * @param $field
   * @param $boxId
   * @return array|string
   */
  public static function saveDropdown($postId, $field, $boxId)
  {
    // Validate and save the field
    $value = $_POST[$postId . '_' . $field['key']];

    if (isset($field['args']['multiple']) && $field['args']['multiple'] && is_array($value)) {
      $value = array_map('stripslashes', $value);
    } else {
      $value = stripslashes(trim($value));
    }

    // Safely handle the post meta
    self::saveToMeta($postId, $field['key'], $value);

    return $value;
  }

  /**
   * @param $postId
   * @param $key
   * @param $value
   */
  public static function saveToMeta($postId, $key, $value)
  {
    if (is_array($value)) {
      delete_post_meta($postId, $key);
      foreach ($value as $item) {
        add_post_meta($postId, $key, $item, false);
      }
    } else {
      // Save the meta data to the database
      update_post_meta($postId, $key, $value);
    }
  }

  /**
   * @param \WP_User $user
   * @return string the html representation in a chosen for the user
   */
  public static function getUserHtmlCallback($user)
  {
    // Edit link for modals
    $editLink = admin_url('user-edit.php?user_id=' . $user->ID . '&ui=show-as-modal&parent=' . $_GET['post']);

    // Get a list of roles of the user
    global $wp_roles;
    $roles = array();
    foreach ($user->roles as $role) {
      $roles[] = translate_user_role($wp_roles->roles[$role]['name']);
    }

    return '
      <div class="mbh-chosen-inline-element">
        <h2><a href="' . $editLink . '" class="open-modal">' . $user->display_name . '</a></h2>
        <ul class="mbh-item-actions">
          <li><a href="' . $editLink . '" class="open-modal">' . __('Bearbeiten', 'lbwp') . '</a></li>
        </ul>
        <p class="mbh-post-info">E-Mail: ' . $user->user_email . '</p>
        <p class="mbh-post-info">Rolle(n): ' . implode(', ', $roles) . '</p>
      </div>
    ';
  }
} 