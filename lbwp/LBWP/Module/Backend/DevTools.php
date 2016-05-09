<?php

namespace LBWP\Module\Backend;

use LBWP\Util\String;

/**
 * Developer Tools, only for local development purposes
 * @author Michael Sebel <michael@comotive.ch>
 */
class DevTools extends \LBWP\Module\Base
{
  /**
   * @var array the paths that can be searched for minimizeable files
   */
  protected $paths = array(
    'wp-content/plugins/',
    'wp-content/themes/'
  );

  protected $compressor = YUI_COMPRESSOR;

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_action('admin_menu', array($this, 'registerMenus'));
  }

  /**
   * Register the superlogin menu page
   */
  public function registerMenus()
  {
    add_submenu_page(
      'tools.php',
      'YUI Compressor',
      'YUI Compressor',
      'administrator',
      'yui-compressor',
      array($this, 'yuiCompressorView')
    );
  }

  /**
   * Provides a YUI compressor view and controller
   */
  public function yuiCompressorView()
  {
    if (isset($_POST['runCompressor'])) {
      $message = $this->runCompressor();
    }

    echo '
      <div class="wrap">
				<div id="icon-tools" class="icon32"><br></div>
				<h2>YUI Compressor</h2>
				' . $message . '
				' . $this->compressFiles() . '
				<p>
				  Hier können die CSS/JS Files von Plugins und Themes durch den YUI Compressor komprimiert werden.
				  Wahlweise kann auch nur eine Simulation ausgeführt werden. <br />Die Dateien müssen danach im Gebrauchsfall
				  von Hand durch die *.min.css/js Variante ersetzt werden. Der JS/CSS-Kompressor lädt die Files automatisch.
				</p>
				<p>
				  Sie können folgende Pfade prüfen und die gefundenen Dateien danach updaten:
				</p>
        ' . $this->getPathSelection() . '
        ' . $this->getQueryResults() . '
			</div>
    ';
  }

  /**
   * Lists all available paths and prints a returns a selection form
   */
  protected function getPathSelection()
  {
    $html = '<form action="' . get_admin_url() . 'tools.php?page=yui-compressor" method="post"><ul>';
    foreach ($this->paths as $key => $path) {
      $html .= '
        <li>
          <input type="checkbox" name="paths[]" id="path_' . $key . '" value="' . $key . '" />
          <label for="path_' . $key . '">' . $path . '</label>
        </li>
      ';
    }
    $html .= '<p><input type="submit" value="Pfade durchsuchen" class="button-primary" name="runPathQuery" /></p>';
    $html .= '</ul></form>';
    return $html;
  }

  /**
   * Shows a list of all found files and their current compression state
   */
  protected function getQueryResults()
  {
    $html = '';
    // Only do this, if a form is sent
    if (isset($_POST['runPathQuery']) && is_array($_POST['paths'])) {
      // Find the desire paths
      $files = $this->searchPaths();
      $html .= $this->getFileTable($files);
    }
    return $html;
  }

  /**
   * Compresses files and prints a message of success
   * Shows a list of all found files and their current compression state
   */
  protected function compressFiles()
  {
    $html = '';
    if (isset($_POST['compressFiles'])) {
      foreach ($_POST['compressedFiles'] as $file) {
        $extension = substr(String::getExtension($file), 1);
        $minVersion = str_replace('.' . $extension, '.min.' . $extension, $file);
        file_put_contents($minVersion, '', FILE_TEXT);
        exec('java -jar ' . $this->compressor . ' -o ' . $minVersion . ' ' . $file);
      }
      $html .= '<div class="updated"><p>Dateien wurden komprimiert.</p></div>';
    }
    return $html;
  }

  /**
   * @return array returns an array of css/js files found
   */
  protected function searchPaths()
  {
    $directories = array();
    foreach ($_POST['paths'] as $pathKey) {
      if (isset($this->paths[$pathKey])) {
        $directories[] = $this->paths[$pathKey];
      }
    }

    $files = array(
      'css' => array(),
      'js' => array()
    );

    // Search those paths recursively
    foreach ($directories as $directoy) {
      // Create a recursive iterator to easly search files
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(ABSPATH . $directoy),
        RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($iterator as $path) {
        if (!$path->isDir()) {
          $file = $path->__toString();
          // Gather css files
          if (String::endsWith($file, '.css') && !String::endsWith($file, '.min.css')) {
            $files['css'][] = $file;
          }
          // Gather js files
          if (String::endsWith($file, '.js') && !String::endsWith($file, '.min.js')) {
            $files['js'][] = $file;
          }
        }
      }
    }

    return $files;
  }

  /**
   * @param array $files multidimensional array of files
   * @return string html code to display the table
   */
  protected function getFileTable($files)
  {
    $html = '
      <form action="' . get_admin_url() . 'tools.php?page=yui-compressor" method="post">;
      <table class="widefat fixed" cellpadding="3">
      <thead>
        <tr>
          <th>Dateiname</th>
          <th width="100">Grösse</th>
          <th width="100">Min. Version</th>
          <th width="100">Min. Grösse</th>
          <th width="100">Komprimieren</th>
        </tr>
      </thead>
      <tbody>
    ';
    // Count total file sizes
    $fileSizeTotal = 0;
    $fileSizeMinTotal = 0;

    foreach ($files as $extension => $list) {
      foreach ($list as $file) {
        $displayFile = str_replace(ABSPATH, '', $file);
        $minVersion = str_replace('.' . $extension, '.min.' . $extension, $file);
        $fileSize = $this->getHumanReadableSize($file);
        $fileSizeTotal += $fileSize;
        // info for min file, if available
        $minFileState = 'N/A';
        $fileSizeMin = 'N/A';
        if (file_exists($minVersion)) {
          $minFileState = 'Ja';
          $fileSizeMin = $this->getHumanReadableSize($minVersion);
          $fileSizeMinTotal += $fileSizeMin;
          $fileSizeMin .= 'KB';
        }

        $html .= '
          <tr>
            <td>' . $displayFile . '</td>
            <td>' . $fileSize . ' KB</td>
            <td>' . $minFileState . '</td>
            <td>' . $fileSizeMin . '</td>
            <td>' . $this->getCheckbox($file) . '</td>
          </tr>
        ';
      }
    }

    // Print Totals
    $html .= '
      <tr>
        <td><strong>Total</strong></td>
        <td>' . $fileSizeTotal . ' KB</td>
        <td>&nbsp;</td>
        <td>' . $fileSizeMinTotal . ' KB</td>
        <td>&nbsp;</td>
      </tr>
    ';

    $html .= '
      </tbody></table>
      <p><input type="submit" name="compressFiles" class="button-primary" value="Dateien komprimieren" /></p>
      </form>
    ';
    return $html;
  }

  /**
   * @param $file the file name to be compressed
   * @return string the checkbox to compress the files
   */
  protected function getCheckbox($file)
  {
    return '
      <input type="checkbox" name="compressedFiles[]" value="' . $file . '" checked="checked" />
    ';
  }

  /**
   * @param $file the file whoose size is desired
   * @return string a human readable kilobyte string
   */
  protected function getHumanReadableSize($file)
  {
    $kbyte = round((filesize($file) / 1024), 2);
    return $kbyte;
  }
}