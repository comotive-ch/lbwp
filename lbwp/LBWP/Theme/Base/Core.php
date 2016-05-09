<?php

namespace LBWP\Theme\Base;

use LBWP\Util\String;

/**
 * Base class for the main theme class
 * @author Tom Forrer <tom.forrer@blogwerk.com>
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Core
{
  /**
   * @var array list af all registered views
   */
  protected $viewsBySlug = array();
  /**
   * @var string the template uri
   */
  protected $uri;
  /**
   * @var string the stylesheet uri
   */
  protected $childUri;
  /**
   * @var string the version that can be used in enqueue functions
   */
  protected $version;
  /**
   * @var string the theme slug
   */
  protected $slug;
  /**
   * @var string the theme base path
   */
  protected $path;
  /**
   * @var string the child theme base path
   */
  protected $childPath;
  /**
   * @var array the module instances
   */
  protected $modules = array();
  /**
   * @var string text domain for multilanguage
   */
  protected $textDomain;
  /**
   * @var \WP_Theme $wordpressTheme ;
   */
  protected $wordpressTheme;

  /**
   * @var string theme cache hash
   */
  protected $themeCacheHash;

  /**
   * @var array list of all registered page templates
   */
  protected $pageTemplateViewsBySlug = array();

  /**
   * @var array $backupQueryVars
   */
  protected $backupQueryVars = array();
  /**
   * @var Core
   */
  protected static $instance;

  /**
   * Adds some basic filters for our "own" template loader and makes
   * sure the setup/init/assets functions are called
   */
  public function __construct()
  {
    // Set the reference to get the teheme object from a static scope
    self::$instance = $this;

    // register setup callbacks
    add_action('after_setup_theme', array($this, 'internalSetup'), 0);
    add_action('after_setup_theme', array($this, 'setup'), 1);
  }

  /**
   * internal setup: fetch worpdress related values setup the text domain
   */
  public function internalSetup()
  {
    $this->wordpressTheme = wp_get_theme();
    $this->textDomain = $this->wordpressTheme->get('TextDomain');
    $this->slug = $this->wordpressTheme->get_stylesheet();
    $this->version = $this->wordpressTheme->get('Version');
    $this->themeCacheHash = md5(get_stylesheet_directory());

    $this->uri = trailingslashit(get_template_directory_uri());
    $this->path = trailingslashit(get_template_directory());
    $this->childUri = trailingslashit(get_stylesheet_directory_uri());
    $this->childPath = trailingslashit(get_stylesheet_directory());

    load_theme_textdomain($this->textDomain, $this->path . 'resources/languages');

    add_action('init', array($this, 'init'));
    add_action('wp_enqueue_scripts', array($this, 'assets'));
    add_filter('template_include', array($this, 'renderView'), 10, 2);
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
   * Used to register templates and template parts
   *
   * @param array $viewsBySlug key= template part / view, value = file to include. view key can contain an object type indicated after the colon, i.e. single:post
   */
  public function registerViews(array $viewsBySlug)
  {
    foreach ($viewsBySlug as $slug => $view) {
      $this->registerView($slug, $view);
    }
  }

  /**
   * This adds filters so our defined files can be included at
   * specified template parts or wordpress predefined views.
   *
   * @param string $slug the view slug (home, archive etc.). can contain an object type indicated after the colon, i.e. single:post
   * @param string $view the view file to be used included
   */
  public function registerView($slug, $view)
  {
    $this->viewsBySlug[$slug] = $view;
    list($templateType, $objectType) = explode(':', $slug);

    // lolwhut? wordpress filter mangling
    $filterTypeName = preg_replace('|[^a-z0-9-]+|', '', $templateType);

    add_filter(
      $filterTypeName . '_template',
      function ($file) use ($slug, $objectType) {
        // default: false, template_loader will use the index template
        $result = $file;

        // match if there was no object type in the $slug (already correct $templateType . '_template' hook)
        // or the $objectType matches the queried object type
        if ($objectType == null || (get_query_var('post_type') == $objectType || (is_tax() && get_query_var($objectType) != ''))) {
          $result = $slug;
        }

        return $result;
      }
    );
    add_action('get_template_part_' . $slug, array($this, 'renderView'), 10, 2);
  }

  /**
   * get the view file by slug
   *
   * @param string $slug the view slug
   * @return bool|string view file path if it exists, false otherwise
   */
  public function getViewFileBySlug($slug)
  {
    $viewFile = false;
    $viewsBySlug = $this->getViewsBySlug();
    if (isset($viewsBySlug[$slug])) {
      $viewFile = $this->resolvePath($viewsBySlug[$slug]);
    }
    return $viewFile;
  }

  public function resolvePath($file)
  {
    $filePath = '';
    if (file_exists($this->getPath() . $file)) {
      $filePath = $this->getPath() . $file;
    }
    if (file_exists($this->getChildPath() . $file)) {
      $filePath = $this->getChildPath() . $file;
    }
    return $filePath;
  }

  public function resolveUri($file)
  {
    $filePath = '';
    if (file_exists($this->getPath() . $file)) {
      $filePath = $this->getUri() . $file;
    }
    if (file_exists($this->getChildPath() . $file)) {
      $filePath = $this->getChildUri() . $file;
    }
    return $filePath;
  }

  /**
   * Includes the actual configured file for a view or template part
   *
   * @param string $slug the view slug
   * @param mixed $arguments additional argument from get_template_part ($name)
   * @return bool always false, to preview the wordpress loader include
   */
  public function renderView($slug, $arguments = null)
  {
    // Fallback for a special case where slug is a file name
    if (String::endsWith($slug, '.php')) {
      $slug = 'index';
    }

    // Get the view file by config
    $viewFile = $this->getViewFileBySlug($slug);

    if ($viewFile) {
      if (is_array($arguments)) {
        extract($arguments, EXTR_SKIP);
      }
      include($viewFile);
    }

    // override WPINC/template-loader.php to not additionally include something
    return false;
  }

  /**
   * register a page template, which allows it to be placed anywhere in the theme directory:
   * it has not to be in the theme root.
   *
   * @param string $slug an identifier (to be stored in the _wp_page_template meta field
   * @param string $view relative file path (to the theme root)
   * @param string $name page template name shown in dropdown
   */
  public function registerPageTemplate($slug, $view, $name)
  {
    // fetch the currently registered page templates
    $this->pageTemplateViewsBySlug = $this->getWordpressTheme()->get_page_templates();
    $this->pageTemplateViewsBySlug[$slug] = $name;

    // override the page template loader from wordpress by just storing the page template configuration in cache
    wp_cache_set('page_templates-' . $this->getThemeCacheHash(), $this->getPageTemplateViewsBySlug(), 'themes', 3);

    // register the view: will be rendered by renderView
    $this->registerView($slug, $view);

    // $this can not be used in lambda function
    $theme = $this;

    // trick the template loader from wordpress: return false if we can load the page template
    add_filter('template_include', function ($file) use ($slug, $view, $theme) {
      // if we don't know the current page as a page template, pass the $file parameter through
      $result = $file;

      // check if we know this template
      if ($theme->isPageTemplate($slug)) {

        // render view, but provide private object context (this works because the page template was also registered as normal view)
        $theme->renderView($slug);

        //override template loader
        $result = false;
      }
      return $result;
    }, 5);
  }

  /**
   * Helper function to determine if the current or a specific page is a page template
   *
   * @param string $slug the slug under which the page template was registered
   * @param int|null $pageId optional page id
   * @return bool true if a page template (identfied by slug)
   */
  public function isPageTemplate($slug, $pageId = null)
  {
    $result = false;
    // get the page id somehow, if not specified
    if ($pageId === null) {
      $post = get_post();
      $pageId = $post->ID;
    }

    if ($pageId == null && isset($_POST['post_ID'])) {
      $pageId = absint($_POST['post_ID']);
    }
    // if passed directly as get parameter
    if ($pageId == null && isset($_GET['post'])) {
      $pageId = absint($_GET['post']);
    }

    if ($pageId != null || (is_admin())) {
      // check the slug
      if (get_post_meta($pageId, '_wp_page_template', true) === $slug) {
        $result = true;
      }
    }
    return $result;
  }

  /**
   * Register theme support helper:
   * register an array of theme supports at once.
   * this function will merge existing theme features (with mergeThemeSupport in registerThemeSupport)
   *
   * @param array $themeSupports key-value array, where the key is the theme support name and the value the (optional) config. if there is no config, the theme support can be given without a key (meaning a numeric key)
   */
  public function registerThemeSupports($themeSupports = array())
  {
    if (is_array($themeSupports)) {
      foreach ($themeSupports as $feature => $config) {
        if (is_numeric($feature) && is_string($config)) {
          // if the theme support was not registered in a key => value fashion
          $this->registerThemeSupport($config, null);
        } else {
          // normal key-value theme support registration
          $this->registerThemeSupport($feature, $config);
        }
      }
    }
  }

  /**
   * Helper function for registering a single theme support, merging the config if necessary
   *
   * @param string $feature theme support name
   * @param array $config optional config array for the theme support: if the config is an array, it will be merged
   * with previous configs, otherwise if it is not null it will add the theme support "normally", without merging
   */
  public function registerThemeSupport($feature, $config = array())
  {
    if (is_array($config)) {
      $this->mergeThemeSupport($feature, $config);
    } elseif (!is_null($config)) {
      add_theme_support($feature, $config);
    } else {
      add_theme_support($feature);
    }
  }

  /**
   * Helper function for merging theme support configurations
   *
   * @param string $feature
   * @param array $config
   */
  public function mergeThemeSupport($feature, $config = array())
  {
    $themeSupport = array();
    // only attempt to merge if something is defined
    if (count($config) > 0) {

      // fetch existing theme support
      $existingThemeSupport = get_theme_support($feature);
      if (is_array($existingThemeSupport) && count($existingThemeSupport) > 0) {
        // multiple add_theme_support builds an array, we want to merge the first item with $config
        $existingThemeSupport = $existingThemeSupport[0];
      } else {
        $existingThemeSupport = array();
      }
      // recursive distinct merging
      $themeSupport = array_merge_recursive($existingThemeSupport, $config);
    }

    add_theme_support($feature, $themeSupport);
  }

  /**
   * Query helper function to save the query variables
   */
  public function backupQuery()
  {
    $this->backupQueryVars = $this->getQuery()->query_vars;
  }

  /**
   * Query helper function to restore the query variable (and query the posts again), from the backupQueryVars field
   */
  public function restoreQuery()
  {
    if (is_array($this->backupQueryVars) && count($this->backupQueryVars) > 0) {
      $this->getQuery()->query($this->backupQueryVars);
    }
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

  /**
   * @return Core
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * Getter
   *
   * @return string
   */
  public function getPath()
  {
    return $this->path;
  }

  /**
   * Getter
   *
   * @return string
   */
  public function getSlug()
  {
    return $this->slug;
  }

  /**
   * Getter
   *
   * @return string
   */
  public function getTextDomain()
  {
    return $this->textDomain;
  }

  /**
   * Getter
   *
   * @return string
   */
  public function getUri()
  {
    return $this->uri;
  }

  /**
   * @return string
   */
  public function getChildPath()
  {
    return $this->childPath;
  }

  /**
   * @return string
   */
  public function getChildUri()
  {
    return $this->childUri;
  }

  /**
   * Getter
   *
   * @return string
   */
  public function getVersion()
  {
    return $this->version;
  }

  /**
   * @return array
   */
  public function getViewsBySlug()
  {
    return $this->viewsBySlug;
  }


  /**
   * Getter
   *
   * @return \WP_Theme
   */
  public function getWordpressTheme()
  {
    return $this->wordpressTheme;
  }

  /**
   * Getter
   *
   * @return string
   */
  public function getThemeCacheHash()
  {
    return $this->themeCacheHash;
  }

  /**
   * Getter
   *
   * @return array
   */
  public function getPageTemplateViewsBySlug()
  {
    return $this->pageTemplateViewsBySlug;
  }

  /**
   * @return array the arguments given to the theme
   */
  public function getArguments()
  {
    return $this->arguments;
  }
}