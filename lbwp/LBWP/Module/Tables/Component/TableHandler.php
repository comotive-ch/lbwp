<?php

namespace LBWP\Module\Tables\Component;
use LBWP\Util\ArrayManipulation;

/**
 * This class provides the shortcodes for the table frontend
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class TableHandler extends Base
{
  /**
   * @var array the basic table config, can be filtered
   */
  protected $tableConfig = array();
  /**
   * @var array the basic templates, can be filtered
   */
  protected $templates = array();

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Add the main shortcode, that doesn't yet do anything
    add_shortcode('lbwp:table', array($this, 'displayTable'));

    // Set the basic config, and allow filtering
    $this->tableConfig = apply_filters('LbwpTables_tableConfig', array(
      'tableSettings' => array(
        array(
          'key' => 'fixateFirstColumn',
          'label' => 'Erste Spalte fixieren?',
          'type' => 'dropdown',
          'default' => 0,
          'selection' => array(
            0 => 'Nein',
            1 => 'Ja'
          )
        ),
        array(
          'key' => 'fixateFirstRows',
          'label' => 'Zeilen fixieren?',
          'type' => 'dropdown',
          'default' => 0,
          'selection' => array(
            0 => 'Nein, keine Zeilen fixieren',
            1 => 'Ja, 1. Zeile fixieren',
            2 => 'Ja, 1. + 2. Zeile fixieren'
          )
        ),
        array(
          'key' => 'borderTypeLeft',
          'label' => 'Linker Rahmen',
          'type' => 'dropdown',
          'default' => 'bd-fixed',
          'selection' => array(
            'bd-fixed' => 'Linie',
            'bd-fixed-fat' => 'Linie (Fett)',
            'bd-dotted' => 'Gepunktete Linie',
            'bd-none' => 'Keine Linie'
          )
        ),
        array(
          'key' => 'borderTypeTop',
          'label' => 'Oberer Rahmen',
          'type' => 'dropdown',
          'default' => 'bd-fixed',
          'selection' => array(
            'bd-fixed' => 'Linie',
            'bd-fixed-fat' => 'Linie (Fett)',
            'bd-dotted' => 'Gepunktete Linie',
            'bd-none' => 'Keine Linie'
          )
        ),
        array(
          'key' => 'tableDesign',
          'label' => 'Tabellen-Design',
          'type' => 'dropdown',
          'default' => 'standard',
          'selection' => array(
            'standard' => 'Standard-Tabelle',
          )
        ),
      ),
      'cellSettings' => array(
        array(
          'key' => 'formatType',
          'label' => 'Zellen-Formatierung',
          'type' => 'dropdown',
          'default' => 'normal',
          'selection' => array(
            'normal' => 'Standard',
            'title' => 'Titel'
          )
        ),
        array(
          'key' => 'borderTypeRight',
          'label' => 'Rechter Rahmen',
          'type' => 'dropdown',
          'default' => 'bd-fixed',
          'selection' => array(
            'bd-fixed' => 'Linie',
            'bd-fixed-fat' => 'Linie (Fett)',
            'bd-dotted' => 'Gepunktete Linie',
            'bd-none' => 'Keine Linie'
          )
        ),
        array(
          'key' => 'borderTypeBottom',
          'label' => 'Unterer Rahmen',
          'type' => 'dropdown',
          'default' => 'bd-fixed',
          'selection' => array(
            'bd-fixed' => 'Linie',
            'bd-fixed-fat' => 'Linie (Fett)',
            'bd-dotted' => 'Gepunktete Linie',
            'bd-none' => 'Keine Linie'
          )
        ),
        array(
          'key' => 'backgroundColor',
          'label' => 'Hintergrundfarbe',
          'type' => 'dropdown',
          'default' => 'transparent',
          'selection' => array(
            'transparent' => 'Keine',
            'primary' => 'Primärfarbe',
          )
        ),
      )
    ));

    // Set the basic templates, and allow filtering
    $this->templates = apply_filters('LbwpTables_templates', array(
      'emptyTable' => array(
        'templateName' => 'Leere Tabelle mit Standard-Einstellung',
        'template' => array(
          'settings' => array(
            'fixateFirstColumn' => 0,
            'fixateFirstRows' => 0,
            'borderTypeLeft' => 'bd-fixed',
            'borderTypeTop' => 'bd-fixed',
            'tableDesign' => 'standard',
          ),
          'data' => array(
            0 => array(
              array(
                'content' => 'Beispiel-Inhalt',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'transparent'
                )
              )
            )
          )
        )
      ),
      'simpleGenericTable' => array(
        'templateName' => 'Tabelle, erste Spalte fixiert mit Hintergrund',
        'template' => array(
          'settings' => array(
            'fixateFirstColumn' => 1,
            'fixateFirstRows' => 0,
            'borderTypeLeft' => 'bd-fixed',
            'borderTypeTop' => 'bd-fixed',
            'tableDesign' => 'standard',
          ),
          'data' => array(
            0 => array(
              array(
                'content' => '',
                'settings' => array(
                  'formatType' => 'title',
                  'borderTypeRight' => 'bd-fixed-fat',
                  'borderTypeBottom' => 'bd-fixed-fat',
                  'backgroundColor' => 'primary'
                )
              ),
              array(
                'content' => 'Überschrift Zelle 1',
                'settings' => array(
                  'formatType' => 'title',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed-fat',
                  'backgroundColor' => 'primary'
                )
              ),
              array(
                'content' => 'Überschrift Zelle 2',
                'settings' => array(
                  'formatType' => 'title',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed-fat',
                  'backgroundColor' => 'primary'
                )
              ),
            ),
            1 => array(
              array(
                'content' => 'Zeile 1',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed-fat',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'primary'
                )
              ),
              array(
                'content' => 'Inhalt 1',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'transparent'
                )
              ),
              array(
                'content' => 'Inhalt 2',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'transparent'
                )
              ),
            ),
            2 => array(
              array(
                'content' => 'Zeile 2',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed-fat',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'primary'
                )
              ),
              array(
                'content' => 'Inhalt 3',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'transparent'
                )
              ),
              array(
                'content' => 'Inhalt 4',
                'settings' => array(
                  'formatType' => 'normal',
                  'borderTypeRight' => 'bd-fixed',
                  'borderTypeBottom' => 'bd-fixed',
                  'backgroundColor' => 'transparent'
                )
              ),
            )
          )
        )
      )
    ));
  }

  /**
   * @param array $data the data
   * @return array added new post content
   */
  public function handleHtmlConversion($data)
  {
    if ($data['post_type'] == Posttype::TABLE_SLUG) {
      $table = $this->getTable($_POST['post_ID']);
      $data['post_content'] = $this->getTableHtml($table);
    }

    return $data;
  }

  /** TODO actually generate tables
   * Create the actual table output for the frontend
   * @param array $table the table config and data
   * @return string the html representation of the table
   */
  protected function getTableHtml($table)
  {
    // Return empty string, if there is no data yet
    if (!is_array($table['data'])) {
      return '';
    }

    // Prepare the initial table
    $html = '<table class="' . self::getTableClasses($table['settings']) . '"><tbody>';

    // Go trough the whole table now to generate it
    foreach ($table['data'] as $rowIndex => $row) {
      $html .= '<tr>';
      // Look at all the cells we have
      foreach ($row as $cellIndex => $cell) {
        $html .= '
          <td class="' . self::getCellClasses($cell) . '">
            ' . $cell['content'] . '
          </td>
        ';
      }
      $html .= '</tr>';
    }

    // Close the table
    $html .= '</table>';

    return $html;
  }

  /**
   * Extract table classes from cell config
   * @param array $cell the cell
   * @return string the classes
   */
  public static function getCellClasses($cell)
  {
    $classes = array('responsive-cell');
    if (is_array($cell['settings'])) {
      foreach ($cell['settings'] as $key => $value) {
        $classes[] = $key . '--' . $value;
      }
    }

    return implode(' ', $classes);
  }

  /**
   * @param $settings
   * @return string the table classes
   */
  public static function getTableClasses($settings)
  {
    $classes = array('responsive-table');
    if (is_array($settings)) {
      foreach ($settings as $key => $value) {
        $classes[] = $key . '--' . $value;
      }
    }

    return implode(' ', $classes);
  }

  /**
   * @param int $tableId the table that has been saved
   */
  public function saveTableJson($filterData)
  {
    if ($filterData['post_type'] == Posttype::TABLE_SLUG) {
      $tableId = is_array($filterData) ? 0 : intval($filterData);
      if (isset($_POST['post_ID']) && $tableId == 0) {
        $tableId = intval($_POST['post_ID']);
      }

      // Create the table from a template, if it's a new one
      if ($_POST['isNewTable'] == 1) {
        // Override tableJson with a template
        $template = $this->getTemplateById($_POST['tableTemplate']);
        $data = $template['template'];
      } else {
        $data = json_decode($_POST['tableJson'], true);
      }

      // Save table json as meta info
      if ($tableId > 0) {
        update_post_meta($tableId, 'tableData', $data);
      }
    }

    return $filterData;
  }

  /**
   * @param $table
   */
  public function forceMissingSettings($table)
  {
    // Get trough each table row and cell
    foreach ($table['data'] as $x => $row) {
      foreach($row as $y => $cell) {
        // Now search every setting for its existance, and add if missing
        foreach ($this->tableConfig['cellSettings'] as $setting) {
          if (!isset($cell['settings'][$setting['key']])) {
            $cell['settings'][$setting['key']] = $setting['default'];
          }
        }
        $table['data'][$x][$y] = $cell;
      }
    }

    return $table;
  }

  /**
   * @param string $args the table
   * @return string the table
   */
  public function displayTable($args)
  {
    $table = $this->getTable($args['id']);
    // TODO enqueue scripts here for the footer (for frontend interactions)
    //return $this->getTableHtml($table);
    return '<!--not-yet-enabled-->';
  }

  /**
   * @param int $tableId the table id
   * @return array the table or empty array if not found
   */
  public function getTable($tableId)
  {
    return ArrayManipulation::forceArray(
      get_post_meta($tableId, 'tableData', true)
    );
  }

  /**
   * @return array all templates
   */
  public function getTemplates()
  {
    return $this->templates;
  }

  /**
   * @param string $id the table key
   * @return array a template by id or null if not existing
   */
  public function getTemplateById($id)
  {
    return $this->templates[$id];
  }

  /**
   * @return array configuration of the table
   */
  public function getConfig()
  {
    return $this->tableConfig;
  }
} 