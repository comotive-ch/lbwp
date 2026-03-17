<?php

namespace LBWP\ERP;

use LBWP\Theme\Base\CoreV2;

/**
 * The main Core ERP class handling and loading all the sub components (Which are normal theme components)
 * The class should be instantiated in the themes core on setup, and automatically loads components to the theme
 * @package LBWP\ERP
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core
{
  /**
  * @var CoreV2 Theme instance that is loading the ERP or PIM
  */
  protected $theme = null;
  /**
   * @var array configuration for ERP/PIM, also deciding which components to load
   */
  protected $config = [
    'components' => [
      'pim_base' => true,
      'custom_fields' => true,
      'inventory_base' => false,
      'addons' => []
    ],
    // Replacements are loaded instead of the default component, but must inherit them for compatibility
    'replacements' => [
      //'LBWP\ERP\Inventory\Base' => 'ErneuerbarGo\Component\Inventory'
    ]
  ];
  /**
   * @var LBWP\ERP\Core singleton instance of the ERP core handler
   */
  protected static $instance = null;

  /**
   * @param CoreV2 $theme Theme instance that is loading the ERP or PIM
   */
  public function __construct(CoreV2 $theme)
  {
    $this->theme = $theme;
    self::$instance = $this;
  }

  /**
   * @return LBWP\ERP\Core erp core instance, callable after construction
   */
  public static function get()
  {
    return self::$instance;
  }

  /**
   * @param $config
   * @return void
   */
  public function setConfig($config)
  {
    $this->config = $config;
  }

  /**
   * @param $config
   * @return void
   */
  public function mergeConfig($config)
  {
    $this->config = array_merge($this->config, $config);
  }

  /**
   * Must be called to load components, after setting config, if needed
   * @return void
   */
  public function loadComponents()
  {
    $components = array();
    if ($this->config['components']['pim_base']) {
      $components['LBWP\ERP\PIM\Base'] = true;
      $components['LBWP\ERP\PIM\Data\Import'] = true;
      $components['LBWP\ERP\PIM\Data\ImageImport'] = true;
      $components['LBWP\ERP\PIM\Data\Export'] = true;
    }
    if ($this->config['components']['inventory_base']) {
      $components['LBWP\ERP\Inventory\Base'] = true;
    }
    if ($this->config['components']['custom_fields']) {
      $components['LBWP\ERP\PIM\CustomFields'] = true;
    }
    foreach ($this->config['components']['addons'] as $addon) {
      $components[$addon] = true;
    }

    // Do replacements if configured
    if (!empty($this->config['replacements'])) {
      foreach ($this->config['replacements'] as $default => $replacement) {
        unset($components[$default]);
        $components[$replacement] = true;
      }
    }

    // Create array from keys and set the components (register instantiates and loads automatically)
    $components = array_keys($components);
    $this->theme->registerComponents($components);
  }
}