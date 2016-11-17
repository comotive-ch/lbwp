<?php

namespace LBWP\Helper\MetaItem;

use LBWP\Helper\MetaItem\Templates;
use LBWP\Util\Strings;

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

    $attr = '';
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

            $("#' . $key . '").on("chosen:ready change", function(evt, params) {
              var hasModals = false;
              $("#' . $chosenKey . ' .search-choice").each(function(){

                // compare label to option text
                var label = $(this).text().trim();
                var options = $("#' . $key . '").find("option").filter(function(){
                  return $(this).text().trim() == label;
                });
                if(options.length > 0){
                  var option = options[0];
                  if($(option).data("url")){
                    var className = "search-choice-link";
                    if ($(option).data("is-modal") == 1) {
                      className += " open-modal";
                      hasModals = true;
                    }
                    if(!$(this).hasClass("has-link-action")){
                      $(".search-choice-close", this).before("<a class=\"" + className + "\" href=\"" + $(option).data("url") + "\" ></a>");
                      $(this).addClass("has-link-action");
                    }
                  }
                  if($(option).data("image")){
                    if(!$(this).hasClass("has-image")){
                      $("span", this).before("<img class=\"search-choice-image\" src=\"" + $(option).data("image") + "\" />");
                      $(this).addClass("has-image");
                    }
                  }
                  if($(option).data("html") && $(option).data("html").length > 0){
                    $("span", this).after($(option).data("html"));
                    $("span", this).remove();
                  }
                }
              });

              if (!hasModals) {
                $("#' . $chosenKey . ' .search-choice-link").click(function(e){
                  // chosen is registered on parent, stop the propagation, but dont prevent the default action (i.e. browswer link)
                  e.stopPropagation();
                });
              }
            });

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
      $typeDropdown = '<select id="' . $id . '-metaDropdown" name="metaDropdown" class="mbh-type-dropdown-inline" data-key="' . $args['metaDropdown']['key'] . '">';
      foreach ($args['metaDropdown']['data'] as $key => $value) {
        $typeDropdown .= '<option value="' . $key . '">' . $value . '</option>';
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
   * helper function for converting the supplied dropdown items to option tags for the chosen plugin
   * @param $items
   * @param $value
   * @param bool $singleValue
   * @return array
   */
  public static function convertDropdownItemsToOptions($items, $value, $singleValue = false)
  {
    $options = array();

    if (!$singleValue) {
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
        $options[] = '<option value="' . $selectedValue . '" selected="selected" ' . $dataAttributes . '>' . $title . '</option>';
      }
    }

    foreach ($items as $itemValue => $item) {

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
        $options[] = '<option value="' . $itemValue . '" ' . $selected . ' ' . $dataAttributes . '>' . $title . '</option>';
      } else {
        if (in_array($itemValue, $value)) {
          continue;
        } else {
          $options[] = '<option value="' . $itemValue . '" ' . $dataAttributes . '>' . $title . '</option>';
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

    // Check if the field is required
    /* TODO solve this riddle
    if (isset($field['args']['required']) && $field['args']['required'] && empty($value)) {
      $this->addError($boxId, 'Bitte füllen Sie das Feld "' . $field['args']['title'] . '" aus.');
      return;
    }
    */

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
} 