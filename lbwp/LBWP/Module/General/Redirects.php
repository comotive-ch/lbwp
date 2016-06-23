<?php

namespace LBWP\Module\General;

use LBWP\Helper\PageSettings;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * This module provides simple redirect functionality
 * @author Michael Sebel <michael@comotive.ch>
 */
class Redirects extends \LBWP\Module\Base
{
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
    add_action('admin_menu', array($this, 'registerSettingsPage'));
    add_action('init', array($this, 'checkRedirects'));
  }

  /**
   * Check if a redirect matches, and do the actual redirect
   */
  public function checkRedirects()
  {
    $redirects = ArrayManipulation::forceArray(get_option('lbwpUrlRedirects'));
    $currentUri = trim($_SERVER['REQUEST_URI']);
    if (Strings::endsWith($currentUri, '/')) {
      $currentUri = rtrim($currentUri, '/');
    }

    foreach ($redirects as $redirect) {
      if ($redirect['validated'] && $redirect['source'] == $currentUri) {
        header('Location: ' . $redirect['destination'], NULL, intval($redirect['code']));
        exit;
      }
    }
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
            <th width="33%">Quell-Pfad</th>
            <th width="33%">Ziel-Pfad oder URL</th>
            <th width="24%">Typ</th>
            <th width="10%">&nbsp;</th>
          </tr>
        </thead>
        <tbody>
    ';

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
   * @param array $item
   */
  public function saveRedirectSettings($item)
  {
    $data = ArrayManipulation::forceArray($_POST['lbwpUrlRedirects']);
    $value = array();

    // Make a big cool array
    for ($i = 0; $i < count($data['source']); $i++) {
      $value[] = array(
        'source' => $this->validateSource($data['source'][$i]),
        'destination' => $this->validateDestination($data['destination'][$i]),
        'validated' => $this->validateRedirection($data['source'][$i], $data['destination'][$i]),
        'code' => $data['code'][$i]
      );
    }

    // Save that new array
    update_option('lbwpUrlRedirects', $value);
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