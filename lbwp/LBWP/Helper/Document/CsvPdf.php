<?php

namespace LBWP\Helper\Document;


// Include the main tcpdf library used to generate the pdf
require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/tcpdf/tcpdf.php';

/**
 * This is a helper which accepts CSV data (multidimensional array)
 * and converts it to an HTML table which is then put into a pdf document
 */
class CsvPdf
{
  /**
   * @var \TCPDF the pdf object
   */
  protected $PDF = NULL;
  /**
   * @var \wpdb the wordpress db object
   */
  protected $wpdb = NULL;
  /**
   * @var bool defined if the first row is a single line header
   */
  protected $isHeaderFirstRow = false;

  /**
   * Initializes the class and the native PDF object
   */
  public function __construct()
  {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->PDF = new \TCPDF('L');
  }

  /**
   * @param bool $value true/false if the first row should be treated as the header
   */
  public function setHeaderFirstRow($value)
  {
    $this->isHeaderFirstRow = $value;
  }

  /**
   * @param string $data the multidimensional PDF data
   */
  public function generate($data)
  {
    $html = '<table cellpadding="3" cellspacing="0" border="1" bordercolor="#cccccc">';
    // How many columns do we have?
    $cols = count($data[0]);
    // Treat the first row as header if needed
    $keys = array_keys($data);
    if ($this->isHeaderFirstRow) {
      $innerKeys = array_keys($data[$keys[0]]);
      $html .= '
        <tr>
          <td colspan="' . $cols . '"><strong>' . $data[$keys[0]][$innerKeys[0]] . '</strong><br /></td>
        </tr>
      ';
      // Unset this row for the data traversal
      unset($data[0]);
    }

    // Traverse the actual CSV data
    foreach ($data as $row) {
      $html .= '<tr>';
      foreach ($row as $key => $cell) {
        $attr = '';
        $meta = explode('::', $key);
        if (isset($meta[1]) && strlen($meta[1]) > 0) {
          $attr = ' align="' . $meta[1] . '"';
        }
        if (isset($meta[2]) && strlen($meta[2]) > 0) {
          $attr = ' width="' . $meta[2] . '"';
        }
        $html .= '<td'.$attr.'>' . html_entity_decode($cell, ENT_QUOTES, 'UTF-8') . '</td>';
      }
      $html .= '</tr>';
    }


    $html .= '</table>';

    $this->PDF->SetFontSize(9);
    $this->PDF->AddPage();
    $this->PDF->writeHTML($html, true, false, false, false, '');
  }

  /**
   * Sets some basic pdf configuration
   * @param string $title the document meta data title
   */
  public function setConfiguration($title)
  {
    $this->PDF->SetTitle($title);
    $this->PDF->SetSubject($title);

    // Set some configurations
    $this->PDF->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $this->PDF->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $this->PDF->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
    $this->PDF->setImageScale(PDF_IMAGE_SCALE_RATIO);
  }

  /**
   * @return \TCPDF returns the pdf object for native actions
   */
  public function getPdf()
  {
    return $this->PDF;
  }

  /**
   * @param string $filename the filename you want to download
   */
  public function sendToBrowser($filename)
  {
    ob_clean();
    $this->PDF->Output($filename, 'I');
    exit;
  }

  /**
   * @param string $filename the filename (without path)
   * @return string the full filename of the generated pdf
   */
  public function saveToFile($filename)
  {
    // Add a new uploads directory item to the filename
    $path = ABSPATH.'wp-content/uploads/' . ASSET_KEY . '/' . time() . '/';
    if (!file_exists($path)) {
      mkdir($path, 0777, false);
    }
    $fullpath = $path . $filename;
    $this->PDF->Output($fullpath, 'F');

    return $fullpath;
  }
}