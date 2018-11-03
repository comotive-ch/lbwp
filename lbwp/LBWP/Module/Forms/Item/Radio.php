<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\Strings;
use LBWP\Util\ArrayManipulation;

/**
 * This will display a radio button list
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Radio extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Auswahl-Knopf',
    'help' => 'Eine Option per Knopfdruck wählen',
    'group' => 'Auswahl-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'compact' => array(
        'name' => 'Kompakte Anordnung der Auswahlfelder',
        'type' => 'radio',
        'values' => array(
          'ja' => 'Ja',
          'nein' => 'Nein'
        )
      ),
      'multicolumn' => array(
        'name' => 'Datenspeicher: Trennung in Spalten',
        'type' => 'radio',
        'help' => '
          Normalerweise wird für dieses Feld im Datenspeicher eine Spalte erstellt, in welchem die getroffene Auswahl mit Komma separiert wird.
          Sofern diese Einstellung aktiviert wird, erscheint im Datenspeicher und Export eine Spalte pro getroffener Auswahl.
        ',
        'values' => array(
          'ja' => 'Ja',
          'nein' => 'Nein'
        )
      ),
      'multicolumn_label_prefix' => array(
        'name' => 'Feld-Beschriftung pro Spalte voranstellen',
        'type' => 'radio',
        'help' => 'Damit die Spalten eindeutiger sind, kann der Feldname als Prefix vor jede generierte Spalte im Datenspeicher vorangestellt werden.',
        'values' => array(
          'ja' => 'Ja',
          'nein' => 'Nein'
        )
      ),
      'content' => array(
        'name' => 'Auswahlmöglichkeiten',
        'type' => 'textfieldArray',
        'help' => 'Bitte geben Sie eine oder mehrere Möglichkeiten an.',
        'separator' => Base::MULTI_ITEM_VALUES_SEPARATOR
      )
    ));

    // Set defaults for new fields
    $this->params['compact'] = 'nein';
    $this->params['multicolumn'] = 'nein';
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['description'] = 'Auswahlfelder mit einer Wahlmöglickeit';
    // Set this as a content item
    $this->shortCode = FormHandler::SHORTCODE_FORM_CONTENT_ITEM;
  }

  /**
   * @return string html code for multi select example
   */
  public function getExampleCode()
  {
    return $this->getMultiSelectExample();
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $this->addFormFieldConditions($args['conditions']);
    $attr = $this->getDefaultAttributes($args);
    $classes = $this->params['class'];
    $classes .= ($this->get('compact') == 'ja') ? ' field-compact' : '';

    // Make the field
    $field = '';
    $numberOfRadios = 0;
    $values = $this->prepareContentValues($content);

    foreach ($values as $key => $value) {
      $checked = '';
      $compare = strip_tags($key);

      // Handle returns of arrays and strings as well
      $selection = $this->getValue($args);
      if (is_array($selection)) {
        $checked = '';
        foreach ($selection as $id => $element) {
          if ($element['colname'] == $compare) {
            $checked = ' checked="checked"';
          }
        }
      } else {
        if (stristr($selection, $compare)) {
          $checked = ' checked="checked"';
        }
      }
      $radioAttr = str_replace(
        'id="' . $this->get('id') . '"',
        'id="' . $this->get('id') . '_' . (++$numberOfRadios) . '"',
        $attr
      );
      $field .= '
        <label class="label-checkbox">
          <input type="radio" value="' . strip_tags($key) . '"' . $radioAttr . $checked . ' />
          <div class="beside-checkbox">' . $value . '</div>
        </label>
      ';
    }

    // Wrapper element for the field list
    $field = '<div class="field-list">' . $field . '</div>';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('radio-field ' . trim($classes)), $html);
    $html = str_replace('{field}', $field, $html);

    return $html;
  }

  /**
   * @param array $args the shortcode params
   * @return string the user value, if the form has been sent
   */
  public function getValue($args = array())
  {
    // Get the value from post, if set
    if (isset($_POST[$this->get('id')]) && strlen($_POST[$this->get('id')]) > 0) {
      $value = $_POST[$this->get('id')];
      // Differ between multicol (which creates separate columns in datatable) and classic concat string
      if ($this->params['multicolumn'] == 'ja') {
        $return = array();
        // Get every selectable checkbox to prefill the array
        $unselected = $this->prepareContentValues($this->getContent());
        // First, add everything as not selected, thus maintaining the users order of the fields
        foreach ($unselected as $selection) {
          $key = Strings::forceSlugString($this->get('feldname') . '-' . html_entity_decode($selection, ENT_QUOTES));
          $return[$key] = array(
            'key' => $key,
            'name' => $this->get('feldname'),
            'colname' => $selection,
            'value' => ''
          );
        }

        // Now just override the value with a calculatable mark, if selected
        $key = Strings::forceSlugString($this->get('feldname') . '-' . $value);
        $return[$key]['value'] = '1';
        return $return;
      } else {
        return $value;
      }
    }

    // If there is no post value, but it is a form sending, handle everything as unselected if multicolumn
    // This is needed because if one doesn't make a selection at all, the whole _POST var is missing, thus data wouldn't be added
    if ($this->params['multicolumn'] == 'ja' && $_POST['lbwpFormSend'] == 1) {
      $return = array();
      $unselected = $this->prepareContentValues($this->getContent());
      // Now, add all selectables with empty value
      foreach ($unselected as $selection) {
        $key = Strings::forceSlugString($this->get('feldname') . '-' . $selection);
        $return[$key] = array(
          'key' => $key,
          'name' => $this->get('feldname'),
          'colname' => $selection,
          'value' => ''
        );
      }
      return $return;
    }

    return '';
  }

  /**
   * Escape attributes to allow HTML in options
   * @return string the content
   */
  public function getContent()
  {
    $content = parent::getContent();
    // Convert old comma value to new separator if none given
    if (stristr($content, self::MULTI_ITEM_VALUES_SEPARATOR) === false) {
      $content = str_replace(',', self::MULTI_ITEM_VALUES_SEPARATOR, $content);
    }

    return esc_attr($content);
  }
}