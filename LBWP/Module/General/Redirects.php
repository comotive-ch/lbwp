<?php

namespace LBWP\Module\General;

use LBWP\Helper\Location;
use LBWP\Helper\PageSettings;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * This module provides simple redirect functionality
 * @author Michael Sebel <michael@comotive.ch>
 */
class Redirects extends \LBWP\Module\Base
{
  /**
   * @var array
   */
  protected static $settings = array(
    'check_leave_params' => false,
    'attach_get_params' => false
  );

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * @param $settings
   * @return void
   */
  public static function configure($settings)
  {
    self::$settings = $settings;
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_action('admin_menu', array($this, 'registerSettingsPage'));
    add_action('init', array($this, 'checkRedirects'));
  }

  /**
   * Check if a redirect matches, and do the actual redirect
   */
  public function checkRedirects()
  {
    $redirects = ArrayManipulation::forceArray(get_option('lbwpUrlRedirects'));
    // Remove eventual parameters
    $currentUri = trim($_SERVER['REQUEST_URI']);
    if (Strings::contains($currentUri, '?') && !self::$settings['check_leave_params']) {
      $currentUri = substr($currentUri, 0, strpos($currentUri, '?'));
    }
    // Make sure to handle with and without slash
    if (Strings::endsWith($currentUri, '/')) {
      $currentUri = rtrim($currentUri, '/');
    }

    // Get browser language and add lang to every redirect that hasn't got it
    $lang = Location::getLangFromBrowser();

    // Go trough redirects and eventually have a match
    foreach ($redirects as $redirect) {
      $langmatch = ($lang == $redirect['lang'] || !isset($redirect['lang']) || strlen($redirect['lang']) == 0);
      if (
        ($redirect['validated'] && $redirect['source'] == $currentUri && $langmatch) ||
        ($redirect['validated'] && stristr($redirect['source'], '*') !== false && fnmatch($redirect['source'], $currentUri) && $langmatch)
      ) {
        HTMLCache::avoidCache();
        header('Location: ' . $this->prepareUrl($redirect['destination']), null, intval($redirect['code']));
        exit;
      }
    }
  }

  /**
   * @param $url
   * @return mixed
   */
  public function prepareUrl($url)
  {
    if (self::$settings['attach_get_params']) {
      foreach ($_GET as $key => $value) {
        if (strlen($value) > 0) {
          $url = Strings::attachParam($key, $value, $url);
        }
      }
    }

    return $url;
  }

  /**
   * Settings page to define forwarders
   */
  public function registerSettingsPage()
  {
    // Page and settings configuration
    PageSettings::initialize();
    PageSettings::addPage('redirect-settings', 'Weiterleitungen');
    PageSettings::addSection('redirect-list', 'redirect-settings', 'Liste der Weiterleitungen', '
      Als Quelle kann nur ein interner Pfad angegeben werden, welches mit "/" starten muss.<br />
      Das Ziel kann sowohl ein interner Pfad aber auch eine externe URL sein.
    ');

    // Add a field with a table callback
    PageSettings::addCallback(
      'redirect-settings',
      'redirect-list',
      'lbwpUrlRedirects',
      'Liste der Weiterleitungen',
      array($this, 'displayRedirectSettings'),
      array($this, 'saveRedirectSettings')
    );
  }

  /**
   * @param array $config
   * @param array $value
   * @param string $html output
   * @return string html code
   */
  public function displayRedirectSettings($config, $value, $html)
  {
    // Validate the table value
    $value = ArrayManipulation::forceArray($value);

    // if a new item is requests, add an empty value to be displayed
    if (isset($_GET['new-item']) || count($value) == 0) {
      $value[] = array('source' => '', 'destination' => '', 'code' => '');
    }

    // Get the redirect code options
    $codeOptions = array(
      301 => '301: Moved Permanently',
      302 => '302: Found',
      307 => '307: Temporary Redirect'
    );

    // Table header
    $html = '
      <table class="widefat fixed">
        <thead>
          <tr>
            <th width="25%">Quell-Pfad</th>
            <th width="25%">Ziel-Pfad oder URL</th>
            <th width="15%">Browsersprache</th>
            <th width="25%">Typ</th>
            <th width="10%">&nbsp;</th>
          </tr>
        </thead>
        <tbody>
    ';

    // Sort the values by source field
    if (is_array($value) && count($value) > 0) {
      ArrayManipulation::sortByStringField($value, 'source');
    }

    // Display table contents
    foreach ($value as $item) {
      $html .= '
        <tr>
          <td>
            <input type="text" class="text-field" style="width:100%;" value="' . esc_attr($item['source']) . '" name="lbwpUrlRedirects[source][]" />
          </td>
          <td>
            <input type="text" class="text-field" style="width:100%;" value="' . esc_attr($item['destination']) . '" name="lbwpUrlRedirects[destination][]" />
          </td>
          <td>
            ' . $this->getLangOptions($item['lang']) . '
          </td>
          <td>
            ' . $this->getCodeOptions($item['code'], $codeOptions) . '
          </td>
          <td>
            <a class="button delete-redirect" href="#">Löschen</a>
          </td>
        </tr>
      ';
    }


    // Close table tag and return
    $html .= '</tbody></table>';
    // Add some JS code
    $html .= '
      <script type="text/javascript">
        var LbwpRedirects = {
          hasChanges : false,

          initialize : function() {
            // Delete button
            jQuery(".delete-redirect").click(function() {
              jQuery(this).parent().parent().remove();
            });
            // Monitor changes
            jQuery(".text-field").change(function() {
              LbwpRedirects.hasChanges = true;
            });
            // Reset if saved is clicked
            jQuery(".button-primary").click(function() {
              LbwpRedirects.hasChanges = false;
            });
            // Add a checker function
            window.onbeforeunload = function() {
              if (LbwpRedirects.hasChanges) {
                return "Möchten Sie diese Website verlassen?";
              }
            };
          }
        };

        jQuery(function() {
          LbwpRedirects.initialize();
        });
      </script>
    ';
    $html .= '<p><a href="?page=redirect-settings&new-item">Neue Weiterleitung hinzufügen</a></p>';
    return $html;
  }

  /**
   * @param string $slug
   * @param array $items
   * @return string
   */
  protected function getCodeOptions($slug, $items)
  {
    $html = '<select name="lbwpUrlRedirects[code][]" style="width:100%">';
    // Add the options and preselect
    foreach ($items as $key => $value) {
      $selected = selected($key, $slug, false);
      $html .= '<option value="' . $key . '"' . $selected . '>' . $value . '</option>';
    }

    // Close tag and return
    $html .= '</select>';
    return $html;
  }

  /**
   * @param string $lang
   * @return string
   */
  protected function getLangOptions($lang)
  {
    return '
      <select name="lbwpUrlRedirects[lang][]" style="width:100%">
        <option value="">Alle</option>
        <option value="de" ' . selected('de', $lang, false) . '>DE</option>
        <option value="fr" ' . selected('fr', $lang, false) . '>FR</option>
        <option value="it" ' . selected('it', $lang, false) . '>IT</option>
        <option value="en" ' . selected('en', $lang, false) . '>EN</option>
      </select>
    ';
  }

  /**
   * @param array $item
   */
  public function saveRedirectSettings($item)
  {
    $data = ArrayManipulation::forceArray($_POST['lbwpUrlRedirects']);
    $value = array();

    // Make a big cool array
    for ($i = 0; $i < count($data['source']); $i++) {
      if (strlen($data['source'][$i]) > 0 && strlen($data['destination'][$i]) > 0 && $data['code'][$i] > 200) {
        $value[] = array(
          'source' => $this->validateSource($data['source'][$i]),
          'destination' => $this->validateDestination($data['destination'][$i]),
          'validated' => $this->validateRedirection($data['source'][$i], $data['destination'][$i]),
          'lang' => strtolower(substr($data['lang'][$i], 0, 2)),
          'code' => $data['code'][$i]
        );
      }
    }

    // Sort so redirects with a specific language are first
    usort($value, function($a, $b) {
      $la = strlen($a['lang']);
      $lb = strlen($b['lang']);
      if (($la === 0 && $lb === 0) || ($la === 0 && $lb > 0)) {
        return 1;
      } else if (($la > 0 && $lb === 0) || ($la > 0 && $lb > 0)) {
        return -1;
      }
      return 0;
    });

    // Save that new array
    update_option('lbwpUrlRedirects', $value, false);
    // Also make sure to flush frontend cache
    MemcachedAdmin::flushFrontendCacheHelper();
  }

  /**
   * @param string $source the source path
   * @return string a maybe changed version of $source
   */
  protected function validateSource($source)
  {
    // If the last character is a slash, remove it
    return rtrim($source, '/ ');
  }

  /**
   * For now, this doesn't validate at all
   * @param string $destination the destination path or url
   * @return string a maybe changed version of $destination
   */
  protected function validateDestination($destination)
  {
    return $destination;
  }

  /**
   * For now, this always validates. More complexe validation soon.
   * @param string $source the source path
   * @param string $destination the destination path or url
   * @return bool true, if the redirect is going to work
   */
  protected function validateRedirection($source, $destination)
  {
    return true;
  }
}