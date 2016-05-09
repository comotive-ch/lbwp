<?php

namespace LBWP\Theme\Base;

/**
 * Base class for standard themes without the complex template engine
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class LightCore
{

  /**
   * @var array the constructor arguments
   */
  protected $arguments;
  /**
   * @var string the stylesheet uri
   */
  protected $uri;
  /**
   * @var int the version that can be used in enqueue functions
   */
  protected $version = 1;
  /**
   * @var string the theme slug (i.e. ensi2)
   */
  protected $slug;
  /**
   * @var string the theme base path
   */
  protected $path;
  /**
   * @var array the module instances
   */
  protected $modules = array();
  /**
   * @var LightCore
   */
  protected static $instance;

  /**
   * Adds some basic filters for our "own" template loader and makes
   * sure the setup/init/assets functions are called
   */
  public function __construct()
  {
    if (func_num_args() == 1) {
      $arguments = func_get_arg(0);
      $this->arguments = wp_parse_args($arguments, array());
    }
    $wpTheme = wp_get_theme();
    $this->slug = $wpTheme->get_stylesheet();
    $this->version = $wpTheme->get('Version');

    $this->uri = trailingslashit(get_stylesheet_directory_uri());
    $this->path = trailingslashit(get_stylesheet_directory());

    add_action('after_setup_theme', array($this, 'setup'), 1);
    add_action('init', array($this, 'init'), 10);
    add_action('init', array($this, 'assets'), 20);

    self::$instance = $this;
  }

  /**
   * Instantiates all modules and calls their load method
   */
  protected function loadModules()
  {
    foreach ($this->modules as $key => $null) {
      $this->modules[$key] = new $key($this);
      $this->modules[$key]->load();
    }
  }

  /**
   * @param string $slug the full namespaces class name of the module
   */
  protected function registerModule($slug)
  {
    $this->modules[$slug] = NULL;
  }

  /**
   * @param string $slug slug that identifies the module
   * @return Module
   */
  public function getModule($slug)
  {
    return $this->modules[$slug];
  }

  /**
   * Calls the abstract registerModules and loads them afterwards
   */
  final public function run()
  {
    $this->registerModules();
    $this->loadModules();
  }

  /**
   * @return array the arguments given to the theme
   */
  public function getArguments()
  {
    return $this->arguments;
  }

  /**
   * @return string theme path
   */
  public function getPath()
  {
    return $this->path;
  }

  /**
   * @return string theme slug
   */
  public function getSlug()
  {
    return $this->slug;
  }

  /**
   * @return string theme root uri
   */
  public function getUri()
  {
    return $this->uri;
  }

  /**
   * @return int theme version
   */
  public function getVersion()
  {
    return $this->version;
  }

  /**
   * @return LightCore the theme instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * Needs to be implemented. called on after_setup_theme(0) action.
   */
  public abstract function setup();

  /**
   * Needs to be implemented. called on init action.
   */
  public abstract function init();

  /**
   * Needs to be implemented. called on wp_enqueue action.
   */
  public abstract function assets();

  /**
   * Should be used to register the modules
   */
  protected abstract function registerModules();
}