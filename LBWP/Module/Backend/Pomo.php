<?php

namespace LBWP\Module\Backend;

use LBWP\Core as LbwpCore;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * The Pomo Rewriter
 * Rewrite *.po files in the backend
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Pomo extends \LBWP\Module\Base
{
  /**
   * @var array the paths to look for po files
   */
  const PO_FILES_PATH = array(
    '/wp-content/languages',
    '/wp-content/languages/plugins',
    '/wp-content/plugins/lbwp/resources/languages',
    '/wp-content/plugins/affiliate-wp/languages', // hat ein .pot file?!
    '/wp-content/plugins/gravityforms/languages',
    '/wp-content/plugins/instagram-feed-pro/languages',
    '/wp-content/plugins/wcs-pre-renewal-notifications-premium/languages',
    '/wp-content/plugins/woo-payrexx-gateway/languages',
    '/wp-content/plugins/woocommerce-bookings/languages',
    '/wp-content/plugins/woocommerce-gateway-stripe/languages',
    '/wp-content/plugins/woocommerce-memberships/i18n/languages',
    '/wp-content/plugins/woocommerce-pdf-invoices-packing-slips/languages',
    '/wp-content/plugins/woocommerce-subscriptions/languages',
    '/wp-content/plugins/woocommerce/i18n/languages'
  );

  /**
   * @var string the JSON containing all the strings from the po files
   */
  const PO_STRINGS_FILE = 'https://sos-ch-dk-2.exo.io/lbwp-cdn/master/files/core/pomo-strings.json';

  /**
   * @var string the JSON containing all the strings from the po files (but this on is in the tmp folder)
   */
  const TMP_PO_STRINGS_FILE = '/tmp/pomo-strings.json';

  /**
   * @var int the maximal results to show in the search
   */
  const MAX_SEARCH_RESULTS = 30;
  /**
   * @var array|null list of overrides
   */
  protected $overrides = null;

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
    // Add if option count > 0 on filter
    $this->initialize();
    $this->overrides = $this->getOverrides();

    if ($this->overrides !== false && !empty($this->overrides)) {
      add_filter('gettext', array($this, 'overrideText'), 10, 3);
    }
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_action('init', array($this, 'initPomo'));
  }

  /**
   * Initialize the pomo editor
   */
  public function initPomo()
  {
    add_action('admin_menu', array($this, 'registerMenu'));
    add_action('admin_enqueue_scripts', array($this, 'registerAssets'));
    add_action('wp_ajax_searchTranslations', array($this, 'searchTranslations'));
  }

  /**
   * Return the overrides either from the option or from the cache (cahed for three days)
   * @return array with the overrides
   */
  protected function getOverrides()
  {
    return WordPress::getJsonOption('pomo-overrides');
  }

  /**
   * Set the overrides into the options and cache
   * @param $overrides array with the overrides
   */
  protected function setOverrides($overrides)
  {
    WordPress::updateJsonOption('pomo-overrides', $overrides);
  }

  /**
   * Get the pomo strings array
   * @param bool $filterByPlugin if only strings from the active plugins should be returned. Default: true
   * @return false|array
   */
  protected function getPomoStrings($filterByPlugin = false)
  {
    if (!file_exists(self::TMP_PO_STRINGS_FILE)) {
      $content = file_get_contents(self::PO_STRINGS_FILE);
      file_put_contents(self::TMP_PO_STRINGS_FILE, $content);
    }
    $strings = (array)json_decode(file_get_contents(self::TMP_PO_STRINGS_FILE));

    if ($filterByPlugin) {
      $strings = array_filter($strings, function ($obj) {
        if ($obj->plugin === 'admin' || $obj->plugin === '') {
          return true;
        }

        return is_plugin_active($obj->plugin . '/' . $obj->plugin . '.php');
      });
    }

    return $strings;
  }

  /**
   * Register the menu page
   */
  public function registerMenu()
  {
    add_submenu_page(
      'tools.php',
      'Texte anpassen',
      'Texte anpassen',
      'administrator',
      'pomo-rewriter',
      array($this, 'pomoEditor')
    );
  }

  /**
   * The backend page
   */
  public function pomoEditor()
  {
    if (isset($_POST['save-pomo-rewrites'])) {
      $this->setOverrides($_POST['pomo-override']);
    }

    $overrides = $this->getOverrides();
    $overrides = $overrides === false ? array() : $overrides;
    $overridesHtml = empty($overrides) ? '<p>Es wurden noch keine Texte überschrieben</p>' : '';
    $pomoStrings = $this->getPomoStrings();
    foreach ($overrides as $overrideKey => $override) {
			$inputName = 'pomo-override[' . htmlentities($overrideKey) . ']';
      $overridesHtml .= '<tr>
          <td>' . $pomoStrings[$override['key']]->plugin . '</td>
          <td>' . $pomoStrings[$override['key']]->lang . '</td>
          <td>' . $override['key'] . '</td>
          <td>
            <input type="text" name="' . $inputName . '[string]" value="' . $override['string'] . '"/>
            <input type="hidden" name="' . $inputName . '[key]" value="' . htmlentities($override['key']) . '"/>
          </td>
          <td><div class="delete-button"></div></td>
        </tr>';
    }

    echo '
      <div class="wrap">
        <h2>Systemtexte anpassen</h2>
        <p>
          Die meisten Texte von WordPress und Plugins können hier gesucht und mit eigenem Text überschrieben werden.
          Es ist zu beachten, dass bei vielen überschriebenen Texten leichte Performance einbussen entstehen können.
          Texte mit Syntax etwa "%s" oder "%1$s" sollten diese im überschriebenen Text übernehmen, damit dieser korrekt zusammengestellt wird.
        </p>
        <div id="pomo-rewriter-form">
          <form method="POST">
            <div id="pomo-search-container">
              <input type="text" id="pomo-search" placeholder="Text eintippen, suche startet automatisch und kann 3-4 Sekunden dauern"/>
              <div class="search-results"></div>
            </div>
            <table class="wp-list-table widefat striped">
              <thead>
                <tr>
                  <th>Quelle</th>
                  <th>Sprache</th>
                  <th>Originaler Text</th>
                  <th>Überschriebener Text</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                ' . $overridesHtml . '
              </tbody>
            </table>
            <input type="submit" name="save-pomo-rewrites" class="button-primary" value="Texte Speichern"/>
          </form>
        </div>
      </div>
    ';
  }

  /**
   * Add CSS and JS to the backendpage
   */
  public function registerAssets()
  {
    $base = File::getResourceUri();
    // Add if needed
    wp_enqueue_script('lbwp-pomo-js', $base . '/js/pomo-rewriter.js', array('jquery'), LbwpCore::REVISION, true);
  }

  /**
   * Overrides the translation if set
   */
  public function overrideText($text, $original, $domain)
  {
    if (isset($this->overrides[$text])) {
      return $this->overrides[$text]['string'];
    }

    return $text;
  }

  /**
   * Ajax request that searches for overrideable strings
   */
  public function searchTranslations()
  {
    $searched = $_POST['searched'];
    $strings = $this->getPomoStrings();
    $matches = array();

    foreach ($strings as $strKey => $strData) {
      if (count($matches) >= self::MAX_SEARCH_RESULTS) {
        break;
      }

      if (stripos($strData->key, $searched) !== false || stripos($strKey, $searched) !== false) {
        $matches[] = '<div class="search-result">' .
          '<p>' . Strings::wrap($strKey, $searched, '<span>', '</span>') . ' (' . $strData->plugin . ')' . '</p>' .
          '<div class="add-override button" data-override="' . htmlentities(json_encode(array($strKey, $strData->plugin, $strData->lang, $strData->key))) . '">Hinzufügen</div>' .
          '</div>';
      }
    }

    WordPress::sendJsonResponse($matches);
  }

  /**
   * http://master.lbwp.sdd1.local/wp-content/plugins/lbwp/views/cron/job.php?identifier=manual_scan_po_files
   * Scan for mo files and write it into the json file (the name of the method is fake news)
   * Translation object:
   *  [string_key]=> object(Translation_Entry)#ID (9){
   *     is_plural             => true|false
   *     context               => string|null
   *     singular              => string
   *     plural                => string|null
   *     translations          => array
   *     translator_comments   => string
   *     extracted_comments    => string
   *     references            => array
   *     flags                 => array
   *  }
   */
  public static function scanPoFiles()
  {
    $allStrings = array();

    foreach (self::PO_FILES_PATH as $path) {
      foreach (glob(ABSPATH . $path . '/*.mo') as $file) {
        // Cut the plugin name and language from the file path
        $filename = substr($file, strrpos($file, '/') + 1);
        $lastHyphen = strrpos($filename, '-') + 1;
        $info = array(
          'plugin' => substr($filename, 0, $lastHyphen - 1),
          'lang' => str_replace('.mo', '', substr($filename, $lastHyphen))
        );

        // Set array for the entries
        //$allStrings[$info['plugin']][''] = array();
        //$allStrings[$info['plugin']]['lang'] = $info['lang'];

        // Get the mo file entries and set them to the array
        $translations = new \MO();
        $translations->import_from_file($file);

        foreach ($translations->entries as $transKey => $entry) {
          foreach ($entry->translations as $translation) {
            $allStrings[$translation]['original'] = $transKey;
            $allStrings[$translation]['plugin'] = $info['plugin'];
            $allStrings[$translation]['lang'] = $info['lang'];
          }
        }
      }
    }

    $fileContent = json_encode($allStrings);
    $tmpFilePath = File::getNewUploadFolder() . '/pomo-strings.json';
    file_put_contents($tmpFilePath, $fileContent);
    $upload = LbwpCore::getModule('S3Upload');
    $upload->uploadDiskFileFixedPath($tmpFilePath, '/core/pomo-strings.json');
  }
}