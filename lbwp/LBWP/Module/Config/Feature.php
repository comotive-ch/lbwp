<?php

namespace LBWP\Module\Config;

use LBWP\Core;
use LBWP\Util\External;
use LBWP\Util\Strings;

/**
 * This Module is used at backend to provide user configurations
 * for the whole plugin, like en-/disabling features, whole modules
 * and tested plugins other than lbwp (wpSeo, XML sitemaps etc.).
 * The whole configuration is only on/off specific feature configurations.
 * features can provide a callback (must be part of this class) to execute
 * code on activation or deactivation, by adding activation/deactivation.
 * In the future, there will be a callback to even add additional configuration
 * for every feature, saving/destroying it with the above callbacks.
 * @author Michael Sebel <michael@comotive.ch>
 */
class Feature extends \LBWP\Module\Base
{

  /**
   * @var array Loaded if needed from includes/Module_FeatureConfig_featureData
   */
  protected $featureData = array();
  /**
   * @var array configure the columns
   */
  protected $columns = array(
    array('CorePackages', 'OutputFilterFeatures', 'BackendModules'),
    array('Plugins', 'Crons'),
    array('FrontendModules', 'PublicModules')
  );

	/**
	 * call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
	}

	/**
	 * Registers all the actions and filters and removes some.
	 */
	public function initialize()
  {
    // Add the menu to "Settings"
    if (Core::isSuperlogin()) {
      add_action('admin_menu',array($this,'addMenu'));
    }
	}

  /**
   * Adds the settings menu and its callback
   */
  public function addMenu()
  {
    add_submenu_page(
			'options-general.php',
			'LBWP Module',
			'LBWP Module',
			'administrator',
			'lbwp-modules',
			array($this,'configForm')
		);
  }

  /**
   * The actual form that displays the configurations
   */
  public function configForm()
  {
    // Load textual configurations from subfile
    require ABSPATH.PLUGINDIR.'/lbwp/views/includes/FeatureConfig_featureData.php';
    // Let the theme and plugins register own features
    $this->featureData = apply_filters('lbwp_feature_data', $this->featureData);
    // Controller, to save the features
    $html = '';
    $message = '';
    if (isset($_POST['saveLbwpSettings'])) {
      $message = $this->saveSettings();
    }

    // Enqueue the style
    wp_enqueue_style('lbwp-features','/wp-content/plugins/lbwp/resources/css/features.css',array(),'1.0.1');
    $totalCost = 0;
    // Create the html output from the features
    foreach ($this->columns as $keys) {
      // Begin a wrapper
      $html .= '<div class="lbwp-cfg-wrap">';
      foreach ($keys as $groupkey) {
        $group = $this->featureData[$groupkey];
        if (count($group['sub']) > 0) {
          $html .= '<div class="lbwp-cfg-column lbwp-cfg-arrow">';
          // Display Group data
          $html .= '
            <h3 class="lbwp-cfg-group">'.$group['name'].'
              <img src="'.$group['icon'].'">
            </h3>
            <h6>'.$group['description'].'</h6>
          ';
          // Display all the options
          $html .= '<ul>';
          foreach ($group['sub'] as $key => $config) {
            // Is this an invisible, always active module? if yes, don't display
            if ($config['state'] == 'invisible') {
              continue;
            }

            if ($config['adminonly'] && !Core::isSuperlogin() && $this->features[$groupkey][$key] !== 1) {
              continue;
            }

            $attr = '';
            // checked?
            if ($this->features[$groupkey][$key] == 1) {
              $attr .= ' checked="checked"';
            }
            // disabled?
            if (isset($config['state']) && $config['state'] == 'disabled') {
              $attr .= ' disabled="disabled"';
              $hidden = '<input type="hidden" name="setting['.$groupkey.']['.$key.']"  value="'.$this->features[$groupkey][$key].'" />';
            }
            // Not available due to configration
            if (isset($config['availabilityCallback'])) {
              if (!call_user_func($config['availabilityCallback'])) {
                $attr .= ' disabled="disabled"';
                $hidden = '<input type="hidden" name="setting['.$groupkey.']['.$key.']"  value="'.$this->features[$groupkey][$key].'" />';
              }
            }
            // Add costs, if available and checked
            if (isset($config['costs']) && $this->features[$groupkey][$key] == 1) {
              $totalCost += $config['costs'];
            }
            // the actual information row
            $html .= '
              <li>
                <label for="'.$groupkey.'_'.$key.'">
                  '.$config['name'].' <span>'.$config['description'].'</span>
                </label>
                <input type="checkbox" name="setting['.$groupkey.']['.$key.']" id="'.$groupkey.'_'.$key.'" value="1"'.$attr.' />
                '.$this->getCosts($config).' '.$hidden.'
              </li>
            ';
          }
          $html .= '</ul></div>';
        }
      }
      $html .= '</div>';
    }

    // Create the form around it and the submit buttin
    $html = '
      <div class="wrap">
        <h2>LBWP Module</h2>
        '.$message.'
        '.$this->getTotalCost($totalCost).'
        <form action="" method="post">
          '.$html.'
          <div class="lbwp-cfg-column lbwp-cfg-submit">
            <input type="submit" class="button-primary" name="saveLbwpSettings" value="Änderungen übernehmen">
          </div>
        </form>
      </div>
    ';
    echo $html;
  }

  /**
   * Displays and calculates the total cost
   * @param $costs
   * @return string
   */
  protected function getTotalCost($costs)
  {
    return '
      <div class="total-costs">
        <span class="total-text">Totale Kosten:</span>
        '.$costs.' CHF pro Jahr /
        ~'.round(($costs / 12), 1).'0 CHF pro Monat
      </div>
    ';
  }

  /**
   * @param array $config the config for the current field
   * @return string HTML code or null
   */
  protected function getCosts($config)
  {
    if (isset($config['costs'])) {
      return '
        <div class="feature-costs">
          ' . $config['costs'] . '.00 CHF pro Jahr / ~' . round($config['costs'] / 12, 2) . ' CHF pro Monat.
        </div>
      ' ;
    }
  }

  /**
   * Saves the settings and returns a message of success.
   * @return string message string with error or info
   */
  protected function saveSettings()
  {
    // Go trough all active settings and see what the new value is
    foreach ($this->featureData as $groupkey => $group) {
      if (count($group['sub']) > 0) {
        foreach ($group['sub'] as $key => $data) {
          // Only even try if the feature is editable by the customer
          if ($data['state'] == 'editable') {
            $newValue = intval($_POST['setting'][$groupkey][$key]);
            $oldValue = intval($this->features[$groupkey][$key]);
            // if change from 0 to 1, call installation, if possible
            if ($oldValue == 0 && $newValue == 1) {
              if (isset($data['install'])) {
                $this->featureCallback($data['install']['callback'],$data['install']['params']);
              }
            }
            // if change from 1 to 0, call uninstallation, if possible
            if ($oldValue == 1 && $newValue == 0) {
              if (isset($data['uninstall'])) {
                $this->featureCallback($data['uninstall']['callback'],$data['uninstall']['params']);
              }
            }
            // only save it to the feature array if 0 or 1
            if ($newValue == 0 || $newValue == 1) {
              $this->features[$groupkey][$key] = $newValue;
            }
          }
        }
      }
    }

    // Change the features if something has actually changed
    update_option('LbwpFeatures',$this->features);
    // As this went pretty well, return a wp message
    return $this->getMessage('updated','Einstellungen wurden gespeichert.');
  }

  /**
   * Runs a callback with params for feature installation or uninstallation
   * @param mixed $callback the callback (must be a valid callback of any kind)
   * @param array $params the params to give the callback (must be an array, or single value)
   */
  protected function featureCallback($callback,$params)
  {
    if (is_callable($callback)) {
      call_user_func($callback,$params);
    }
  }

  /**
   * This checks, if quForm can be activated or not
   */
  public function checkQuForm()
  {
    // Doesn't work with compressed css/js, so we don't make it available
    if ($this->features['OutputFilterFeatures']['CompressCssJs'] == 1) {
      return false;
    }

    return true;
  }

  /**
   * Callback to only make a feature selectable for super users
   */
  public function isSuperuser()
  {
    if (Core::isSuperlogin()) {
      return true;
    }

    return false;
  }

  /**
   * @return bool tells if the newsletter base is activated
   */
  public function hasNewsletterBase()
  {
    return ($this->features['PublicModules']['NewsletterBase'] == 1);
  }

  /**
   * Uninstalls a plugin silently (doesn't actually tell if it worked..)
   * @param array $params must contain the key "file" for the plugin to be installed
   */
  public function uninstallPlugin($params)
  {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    if (!is_array($params['files'])) {
      $params['files'] = array($params['files']);
    }
    foreach ($params['files'] as $plugin) {
      if (is_plugin_active($plugin)) {
        deactivate_plugins($plugin,false,false);
      }
    }
  }

  /**
   * Installs a plugin silently (doesn't actually tell if it worked..)
   * @param array $params must contain the key "file" for the plugin to be installed
   * @return WP_Error|bool Error or a bool that says "true"
   */
  public function installPlugin($params)
  {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    if (!is_array($params['files'])) {
      $params['files'] = array($params['files']);
    }
    foreach ($params['files'] as $plugin) {
      if (!is_plugin_active($plugin)) {
        activate_plugin($plugin,'',false,false);
      }
    }
  }

  /**
   * @param string $type type: error or updated
   * @param string $text the text to contain in the message
   * @return string html code to display the message
   */
  protected function getMessage($type,$text)
  {
    return '<div class="'.$type.'"><p><strong>'.$text.'</strong></p></div>';
  }
}