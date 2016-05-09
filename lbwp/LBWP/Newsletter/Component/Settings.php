<?php

namespace LBWP\Newsletter\Component;

use LBWP\Core as LbwpCore;
use LBWP\Module\Config\Settings as LbwpSettings;
use LBWP\Newsletter\Core;
use LBWP\Newsletter\Component\Base;
use LBWP\Newsletter\Service\Definition;

/**
 * This class handles settings (with getters and setters) and the settings backend
 * @package LBWP\Newsletter\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Settings extends Base
{
  /**
   * @var array the settings
   */
  protected $settings = array();
  /**
   * @var LbwpSettings instance of the feautre module
   */
  protected $feature = NULL;

  /**
   * Called after component construction
   */
  public function load()
  {
    $this->settings = get_option('LbwpNewsletter');
    // Make sure it is an array
    if (!is_array($this->settings)) {
      $this->settings = array();
    }
  }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Make a reference to the feature backend
    if (is_admin()) {
      $this->feature = LbwpCore::getModule('LbwpConfig');
    }
  }

  /**
   * Displays the settings Backend
   */
  public function displayBackend()
  {
    $html = '<div class="wrap lbwp-config">';

    // Add the settings CSS
    wp_enqueue_style('lbwp-config', '/wp-content/plugins/lbwp/resources/css/config.css', array(), '1.1');

    // Show the service page or the main settings
    if (isset($_GET['servicePage'])) {
      $html .= $this->getServiceSettings();
    } else {
      $html .= $this->getMainSettings();
    }

    // Close the wrapper and return
    echo $html . '</div>';
  }

  /**
   * @return string html code to display the main settings
   */
  public function getMainSettings()
  {
    // Save the general settings?
    if (isset($_POST['saveMainSettings'])) {
      $this->saveMainSettings();
    }

    // Display a header
    $html = '
      <h2>Newsletter &raquo; Einstellungen</h2>
      <p>Hier können Sie alle Einstellungen für den Newsletter-Versand vornehmen.</p>
    ';

    $html .= $this->getMessage();

    // Display the service configuration
    $html .= '
      <form action="?page=' . $_GET['page'] . '" method="post">
        ' . $this->getServiceSetting() . '
        ' . $this->getGeneralSettings() . '
        <input type="submit" value="Speichern" name="saveMainSettings" class="button-primary" />
      </form>
    ';

    return $html;
  }

  /**
   * Display a message from msg param
   * @return string the message
   */
  protected function getMessage()
  {
    switch ($_GET['msg']) {
      case 'success':
        return '<div class="updated"><p>' . __('Einstellungen wurden gespeichert.', 'lbwp') . '</p></div>';
    }
  }

  /**
   * Save the main settings and redirect back to main setting with a message
   */
  protected function saveMainSettings()
  {
    $message = 'success';

    // Save configuration of unpublished posts
    update_option('newsletterUnpublishedPosts', intval($_POST['newsletterUnpublishedPosts']));

    // Use internal mechanic to save
    //$this->set('xxxx', $settingXXXX);
    //$this->set('yyyy', $settingYYYY);
    $this->saveSettings();

    // Redirect with a message
    header('Location: ' . get_admin_url() . 'admin.php?page=' . $_GET['page'] . '&msg=' . $message);
    exit;
  }

  /**
   * This doesn't return any settings at the moment
   * @return string HTML code to represent the general settings
   */
  protected function getGeneralSettings()
  {
    // Get the template from lbwp configuration
    $template = $this->feature->getTplDesc();
    $templateNoDesc = $this->feature->getTplNodesc();
    $html = '';

    // Checkbox option to use future and draft posts
    $input = '
      <label for="newsletterUnpublishedPosts" class="cfg-field-check">
        <input type="checkbox" name="newsletterUnpublishedPosts" value="1" id="newsletterUnpublishedPosts"' . checked(1, get_option('newsletterUnpublishedPosts')) . ' />
        <div class="cfg-field-check-text">Unveröffentlichte Beiträge im Editor anzeigen</div>
      </label>
    ';

    $html .= str_replace('{title}', 'Verschiedene Optionen', $templateNoDesc);
    $html = str_replace('{input}', $input, $html);
    $html = str_replace('{fieldId}', 'newsletterUnpublishedPosts', $html);

    return $html;
  }

  /**
   * @return string html code to display the service settings
   */
  protected function getServiceSettings()
  {
    $message = '';
    $serviceId = $_GET['servicePage'];
    $service = $this->core->getServiceById($serviceId);
    $signature = $service->getSignature();

    // Run the saving, if needed
    if (isset($_POST['saveServiceSettings'])) {
      $message = $service->saveSettings();
    }

    // Display the form
    $html = '
      <h2>Newsletter &raquo; Einstellungen &raquo; ' . $signature['name'] . '</h2>
      ' . $message . '
      <form action="?page=' . $_GET['page'] . '&servicePage=' . $serviceId . '" method="post">
        ' . $service->displaySettings() . '
        <input type="submit" value="Speichern" name="saveServiceSettings" class="button-primary" />&nbsp;
        <a href="?page=' . $_GET['page'] . '" class="button">Zurück zur Übersicht</a>
      </form>
    ';

    return $html;
  }

  /**
   * @param \LBWP\Newsletter\Service\Definition $service
   */
  public function saveServiceClass($service)
  {
    $signature = $service->getSignature();
    // Get the class from ID
    $this->set('serviceClass', $signature['class']);
    $this->saveSettings();
  }

  /**
   * This uses the service instance, to display the service setting
   */
  protected function getServiceSetting()
  {
    // Get the template from lbwp configuration
    $template = $this->feature->getTplDesc();

    // Get the available services
    $services = $this->core->getAvailableServices();
    $currentService = $this->core->getService();

    // If there is no service configured
    if ($currentService === false) {
      $description = '
        Sie haben noch keinen Dienst konfiguriert. Sie müssen einen Versand-Dienst konfigurieren,<br />
        um das Newsletter Tool vollständig nutzen zu können.
      ';
      $buttonText = 'Dienst einrichten';
    } else {
      // There is already a service configured
      $description = '
        Der Versand-Dienst ist bereits konfiguriert. Sie können die Konfiguration jederzeit ändern<br />
        oder einen anderen Versand-Dienst einstellen.
        ' . $this->getServiceConfigurationHtml($currentService) . '
      ';
      $buttonText = 'Dienst-Einstellungen ändern';
    }

    // And the value. which is a dropdown and a button to configure the service
    $input = '<select id="serviceSelection">';
    foreach ($services as $key => $service) {
      $signature = $service->getSignature();
      // Make the button with the first service
      if ($key == 0) {
        $button = '
          <a href="?page=' . $_GET['page'] . '&servicePage=' . $signature['id'] . '" class="button">' . $buttonText . '</a>
        ';
      }

      $selected = '';
      if ($service !== false) {
        $currentSig['id'] = 0;
        if ($currentService instanceof Definition) {
          $currentSig = $currentService->getSignature();
        }
        if ($currentSig['id'] == $signature['id']) {
          $selected = ' selected="selected"';
          // Override button
          $button = '
            <a href="?page=' . $_GET['page'] . '&servicePage=' . $signature['id'] . '" class="button">' . $buttonText . '</a>
          ';
        }
      }

      // Now add the option for the selection
      $input .= '<option value="' . $signature['id'] . '"' . $selected . '>' . $signature['name'] . '</option>';
    }
    // Close the select and add the button
    $input .= '</select> ' . $button;

    // Create the actual html from template
    $template = str_replace('{title}', 'Versand-Dienst', $template);
    $template = str_replace('{input}', $input, $template);
    $template = str_replace('{description}', $description, $template);
    // Add JS to switch between services
    $template.= $this->getServiceChangeJS();

    return $template;
  }

  /**
   * @return string JS code to react on select changes of the service
   */
  protected function getServiceChangeJS()
  {
    return '
      <script type="text/javascript">
        jQuery(function() {
          jQuery("#serviceSelection").change(function() {
            var serviceId = jQuery(this).val();
            var link = jQuery(this).next();
            var url = link.prop("href");
            // Replace everything after servicePage= with the new id
            url = url.substring(0, url.indexOf("servicePage=") + 12);
            link.prop("href", url + serviceId);
          });
        });
      </script>
    ';
  }

  /**
   * @param Definition $service the currently installed newsletter server
   * @return string the current configuration of a service
   */
  protected function getServiceConfigurationHtml(Definition $service)
  {
    $info = $service->getConfigurationInfo();

    return '
      <strong>Aktuelle Einstellungen:</strong>
      <ul class="service-config">
        <li>' . implode('</li><li>', $info) . '</li>
      </ul>
    ';
  }

  /**
   * @param string $key the option you need
   * @return mixed the option value or NULL if not set
   */
  public function get($key)
  {
    return $this->settings[$key];
  }

  /**
   * @param string $key option name
   * @param mixed $value the value (primitives only)
   */
  public function set($key, $value)
  {
    $this->settings[$key] = $value;
  }

  /**
   * This will save settings that have previously been edited wit "set()"
   */
  public function saveSettings()
  {
    update_option('LbwpNewsletter', $this->settings);
  }
} 