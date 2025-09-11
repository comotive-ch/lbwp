<?php

namespace LBWP\Theme\Base;

use LBWP\Util\Strings;
use LBWP\Theme\Base\WpWrapper;
use LBWP\Util\File;
use Exception;

/**
 * Base class for the main theme class
 * @author Tom Forrer <tom.forrer@blogwerk.com>
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class CoreV2
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
   * @var string the version that can be used in enqueue functions
   */
  protected $parentVersion;
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
   * @var string text domain for multilanguage
   */
  protected $textDomain;
  /**
   * @var \WP_Theme $wordpressTheme
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
   * @var array list of all registered components
   */
  protected $components = array();
  /**
   * @var WpWrapper
   */
  protected $dependencyWrapper = null;

  /**
   * @var \WP_Query $backupQuery
   */
  protected $backupQuery = null;
  /**
   * @var array
   */
  protected $blockWhiteList = array();
  /**
   * @var array
   */
  protected $blockTypeWhiteList = array();
  /**
   * @var string[] the wp standard blocks
   */
  protected $wpStandardBlocks = array(
    'core/paragraph',
    'core/image',
    'core/heading',
    'core/gallery',
    'core/list',
    'core/quote',
    'core/shortcode',
    //'core/archives',
    'core/audio',
    //'core/button',
    //'core/buttons',
    //'core/calendar',
    'core/categories',
    'core/code',
    'core/columns',
    'core/column',
    //'core/cover',
    'core/embed',
    'core/file',
    'core/group',
    //'core/freeform',
    'core/html',
    //'core/media-text',
    //'core/latest-comments',
    //'core/latest-posts',
    //'core/missing',
    //'core/more',
    //'core/nextpage',
    //'core/preformatted',
    //'core/pullquote',
    //'core/rss',
    //'core/search',
    //'core/separator',
    'core/block',
    //'core/social-links',
    //'core/social-link',
    //'core/spacer',
    //'core/subhead',
    'core/table',
    //'core/tag-cloud',
    //'core/text-columns',
    //'core/verse',
    'core/video'
  );
  /**
   * @var Core
   */
  protected static $instance;

  /**
   * Adds some basic filters for our "own" template loader and makes
   * sure the setup/init/assets functions are called
   * @param WpWrapper $wrapper
   */
  public function __construct(WpWrapper $wrapper = null)
  {
    $this->dependencyWrapper = $wrapper;

    // Set the reference to get the teheme object from a static scope
    self::$instance = $this;

    // register setup callbacks
    add_action('after_setup_theme', array($this, 'internalSetup'), 0);
    add_action('after_setup_theme', array($this, 'setup'), 0);
    add_action('after_setup_theme', array($this, 'setupComponents'), 0);
    add_action('after_setup_theme', array($this, 'executeComponentsSetup'), 0);
  }

  /**
   * internal setup: fetch worpdress related values setup the text domain
   */
  public function internalSetup()
  {
    $this->wordpressTheme = wp_get_theme();
    $this->textDomain = $this->wordpressTheme->get('TextDomain');
    $this->slug = $this->wordpressTheme->get_stylesheet();
    $this->themeCacheHash = md5(get_stylesheet_directory());
    $this->version = $this->wordpressTheme->get('Version');

    $this->uri = trailingslashit(get_template_directory_uri());
    $this->path = trailingslashit(get_template_directory());
    $this->childUri = trailingslashit(get_stylesheet_directory_uri());
    $this->childPath = trailingslashit(get_stylesheet_directory());

    if (is_child_theme()) {
      $parent = wp_get_theme(get_template());
      load_theme_textdomain($this->textDomain, $this->childPath . 'assets/languages');
      load_theme_textdomain($parent->get('TextDomain'), $this->path . 'assets/languages');
      $this->parentVersion = $parent->get('Version');
    } else {
      load_theme_textdomain($this->textDomain, $this->path . 'assets/languages');
      $this->parentVersion = $this->version;
    }

    add_action('init', array($this, 'init'));
    add_action('widgets_init', array($this, 'widgets'), 0);

    // register the filter renderView a bit later: we want to override the template inclusion (say for plugin templates), but only if necessary
    add_filter('template_include', array($this, 'renderView'), 15, 3);

    // make the get_header function compatible for plugins with own templates
    add_filter('get_header', array($this, 'renderHeader'), 15, 2);
    // make the get_footer function compatible for plugins with own templates
    add_filter('get_footer', array($this, 'renderFooter'), 15, 2);

    add_action('wp_enqueue_scripts', array($this, 'assets'));
    add_action('wp_enqueue_scripts', array($this, 'lateAssets'), 50);
    add_action('enqueue_block_editor_assets', array($this, 'blockEditorAssets'), 100);
    add_filter('allowed_block_types_all', array($this, 'runBlockWhitelist'), 10, 2);

    // Only in admin
    if (is_admin()) {
      add_action('init', array($this, 'adminInit'));
      add_action('admin_enqueue_scripts', array($this, 'adminAssets'));
      add_action('admin_enqueue_scripts', array($this, 'lateAdminAssets'), 50);
      // Add the style version number of the theme to editor css
      $version = $this->version;
      add_filter('editor_stylesheets', function ($stylesheets) use ($version) {
        foreach ($stylesheets as $key => $url) {
          $stylesheets[$key] = Strings::attachParam('v', $version, $url);;
        }
        return $stylesheets;
      });
    }

    // Run update routine, if needed
    if ($this->version != get_option('lbwpCoreThemeVersion')) {
      $this->update();
      update_option('lbwpCoreThemeVersion', $this->version);
    }
  }

  /**
   * @param bool|array $allowed array with allowed block types (slugs)
   * @param WP_Block_Editor_Context $context the current block editor context 
   */
  public function runBlockWhitelist($allowed, $context)
  {
    if (count($this->blockWhiteList) == 0) {
      return $allowed;
    }

    // Different whitelist for widget areas
    /*if($context->name === 'core/edit-widgets' || $context->name === 'core/customize-widgets'){
      return $allowed;
    }*/

    $allowed = array();
    $notAllowed = array();
    $dynamicBlocks = get_dynamic_block_names();
    $dynamicBlocks = array_filter($dynamicBlocks, function($blockName){
      if(strpos($blockName, 'core/') === false && !Strings::startsWith($blockName, 'core/')){
        return $blockName;
      }
    });
    $dynamicBlocks = array_merge($dynamicBlocks, $this->wpStandardBlocks);
    /*
     * $dynamicBlocks -> gets all names of available blocks
     * If the name doesn't starts with and doesn't contain 'core/', then the name will be pushed in the array $allowed
     *
     */
    foreach ($dynamicBlocks as $dynamicBlock) {
      foreach ($this->blockTypeWhiteList as $blockType) {
        // check if the block contains or starts with the defined "not-allowed-type"
        if (strpos($dynamicBlock, $blockType . '/') !== false && Strings::startsWith($dynamicBlock, $blockType . '/')) {
          array_push($notAllowed, $dynamicBlock);
          continue;
        }
      }
      // if its not in the "forbidden-blocks" then allow it
      if (!in_array($dynamicBlock, $notAllowed)) {
        array_push($allowed, $dynamicBlock);
      }
    }

    if (in_array('lbwp/embeds-basic', $this->blockWhiteList)) {
      array_push($allowed,
        'core/embed',
        'core-embed/twitter',
        'core-embed/youtube',
        'core-embed/facebook',
        'core-embed/instagram',
        'core-embed/wordpress',
        'core-embed/vimeo'
      );
    }

    if (in_array('lbwp/embeds-extended', $this->blockWhiteList)) {
      array_push($allowed,
        'core-embed/soundcloud',
        'core-embed/spotify',
        'core-embed/flickr',
        'core-embed/vimeo',
        'core-embed/animoto',
        'core-embed/cloudup',
        'core-embed/collegehumor',
        'core-embed/dailymotion',
        'core-embed/funnyordie',
        'core-embed/hulu',
        'core-embed/imgur',
        'core-embed/issuu',
        'core-embed/kickstarter',
        'core-embed/meetup-com',
        'core-embed/mixcloud',
        'core-embed/photobucket',
        'core-embed/polldaddy',
        'core-embed/reddit',
        'core-embed/reverbnation',
        'core-embed/screencast',
        'core-embed/scribd',
        'core-embed/slideshare',
        'core-embed/smugmug',
        'core-embed/speaker',
        'core-embed/ted',
        'core-embed/tumblr',
        'core-embed/videopress',
        'core-embed/wordpress-tv'
      );
    }

    return array_merge($this->blockWhiteList, $allowed);
  }

  /**
   * @param $list
   * @param string[] $blockTypes
   */
  public function setBlockWhitelist($list, $blockTypes = array('core'))
  {
    $this->blockWhiteList = $list;
    $this->blockTypeWhiteList = $blockTypes;
  }

  /**
   * for child themes, merges a new list into the currently set blockWhiteList
   * @param array $list
   * @return void
   */
  public function addToBlockWhitelist($list)
  {
    $this->blockWhiteList = array_merge($this->blockWhiteList, $list);
  }

  /**
   * @param array $excludes standard wp blocks to excludes
   * @param array $includes blocks to additionally include
   * @param string $htmlBefore the html before the block. Use {classnames} in the "class" html-attribute to add the classes to the wrapper
   * @param string $htmlAfter the html after the block
   */
  protected function wrapBlocks($excludes = array(), $includes = array(), $htmlBefore = '', $htmlAfter = ''){
    $this->wrapExcludes = $excludes;
    $this->wrapIncludes = $includes;

    if(Strings::isEmpty($htmlBefore) && Strings::isEmpty($htmlAfter)){
      $htmlBefore = '
        <section class="wp-block-wrapper {classnames}">
          <div class="inner-r">
            <div class="inner-c">';
      $htmlAfter = '
            </div>
          </div>
        </section>';
    }

    $this->wrapHtml = array(
      $htmlBefore,
      $htmlAfter
    );

    add_filter('render_block', array($this, 'renderBlockWrap'), 20, 2);
  }

  /**
   * @param $html string the block html
   * @param $block array the data of the block
   * @return string the wrapped html
   */
  public function renderBlockWrap($html, $block){
    if (in_the_loop()) {
      // Wrap all configured blocks with additional html and that add class
      if (
        (!in_array($block['blockName'], $this->wrapExcludes) && strpos($block['blockName'], 'core/') !== false) ||
        (in_array($block['blockName'], $this->wrapIncludes))
      ) {
        // Set a classname from the part of the block after /
        $className = substr($block['blockName'], stripos($block['blockName'], '/') + 1);
        // Append block classes to wrapper
        if (strlen($block['attrs']['className']) > 0) {
          $className .= ' ' . $block['attrs']['className'];
        }
        $html = str_replace('{classnames}', $className, $this->wrapHtml[0]) . $html . $this->wrapHtml[1];
      }
    }

    return $html;
  }

  /**
   * Makes sure to remove eventual logged in cookies that are no longer valid
   * MUST be called in setup manually from the theme if needed
   * @return void
   */
  protected function runLoginCookieKill()
  {
    $potentialLoginCookies = array();
    foreach ($_COOKIE as $key => $value) {
      if (str_starts_with($key, 'wordpress_logged_in')) {
        $potentialLoginCookies[] = $key;
      }
    }

    if (count($potentialLoginCookies) > 0 && !is_user_logged_in()) {
      foreach ($potentialLoginCookies as $key) {
        setcookie($key, '', current_time('timestamp') - 3600, '/', '', true, true);
        unset($_COOKIE[$key]);
      }
    }
  }

  /**
   * Can be overridden to perform server side updates on new version of theme
   */
  protected function update()
  {
  }

  /**
   * Needs to be implemented. called on after_setup_theme(0) action.
   */
  public abstract function setup();

  /**
   * called at after_setup_theme(0), but after internalSetup and setup. If you plan to implement a child theme,
   * register the components here: that way you can inherit from the parent theme (with every view, theme support, etc),
   * but you can redefine the used components.
   */
  public function setupComponents()
  {

  }

  /**
   * called on init(10) action. can be overridden
   */
  public function init()
  {

  }

  /**
   * called on init(10) action. can be overridden
   */
  public function adminInit()
  {

  }

  /**
   * called at widgets_init(10). register widgets here.
   */
  public function widgets()
  {

  }

  /**
   * called at after_setup_theme(0), but after $this->setup(): let the components also set up early stuff.
   * This callback is not meant to be overriden (it is only public for the wordpress hook mechanism)
   */
  final public function executeComponentsSetup()
  {
    $this->instantiateComponents();
    foreach ($this->components as $component) {
      /**
       * @var Component $component
       */
      $component->setup();
    }
  }

  /**
   * Initialize components: instantiate each component class, if no other class is a child class of it
   */
  protected function instantiateComponents()
  {
    // get the namespaced class names
    $classes = array_keys($this->components);

    // filter all classes: only select classes that are ancestors of other components
    $ancestors = array_filter($classes, function ($class) use ($classes) {
      // compare $class to each other class and determine if $class is extended by any other $childClass
      return array_reduce($classes, function ($isAncestor, $childClass) use ($class) {
        return $isAncestor || ($childClass != $class && is_a($childClass, $class, true));
      }, false);
    });

    // get namespaced class names of classes that are not extended by other components
    $leafComponentClassNames = array_diff($classes, $ancestors);

    // kick out component classes that are being extended by other components
    $this->components = array_combine($leafComponentClassNames, array_fill(0, count($leafComponentClassNames), null));

    // instantiate all selected components
    foreach ($this->components as $class => $object) {
      $this->components[$class] = new $class($this);
    }
  }


  /**
   * Includes basic assets (well, not at the moment). can be overridden.
   */
  public function assets()
  {

  }

  /**
   * late assets callback, which will be called after component assets. can be overridden
   */
  public function lateAssets()
  {

  }

  /**
   * Block editor assets
   */
  public function blockEditorAssets()
  {

  }

  /**
   * Includes admin assets (well, not at the moment). can be overridden.
   */
  public function adminAssets()
  {

  }

  /**
   * late admin assets callback, which will be called after component admin assets. can be overridden
   */
  public function lateAdminAssets()
  {

  }
	
	/**
	 * Enables the critical css
	 */
	public function enableCriticalCss(){
		if(file_exists(get_stylesheet_directory() . '/assets/styles/critical-css/critical.css')){
			add_action('style_loader_tag', array($this, 'changeLinkTag'), 99, 4);
			add_action('wp_head', array($this, 'renderCriticalCss'));
		}
	}
	
	/**
	 * Alters the link tag for critical css
	 */
	public function changeLinkTag($tag, $handle, $href, $media){
		$tag = '
			<link rel="preload" href="' . $href . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">
			<noscript><link rel="stylesheet" href="' . $href . '"></noscript>
		';

		return $tag;
	}
	
	/**
	 * Render the critical css in to the head
	 */
	public static function renderCriticalCss(){
		$criticalCss = file_get_contents(get_stylesheet_directory() . '/assets/styles/critical-css/critical.css');

		echo '<style type="text/css" media="all">'
		. $criticalCss . 
		'</style>';
	}

  /**
   * register multiple components at once, see registerComponent
   *
   * @param array $namespacedClassNames
   */
  public function registerComponents($namespacedClassNames = array())
  {
    if (is_array($namespacedClassNames)) {
      foreach ($namespacedClassNames as $namespacedClassName) {
        $this->registerComponent($namespacedClassName);
      }
    }
  }

  /**
   * register component
   *
   * @param string $namespacedClassName
   */
  public function registerComponent($namespacedClassName)
  {
    $this->components[$namespacedClassName] = null;
  }

  /**
   * get a registered component, i.e. in view use an "@ var to" enable type-hinting
   *
   * @param string $namespacedClassName exactly as it was registered with registerComponent()
   *
   * @return Component the instantiated component
   * @throws \Exception if the namespaced class name was not found
   */
  public function getComponent($namespacedClassName)
  {
    // Try an explicit match
    if (!isset($this->components[$namespacedClassName])) {
      throw new Exception('Component not found', 255);
    }
    return $this->components[$namespacedClassName];
  }

  /**
   * Remove a component, mainly used in child themes
   * @param string $namespacedClassName to be removed component
   */
  public function removeComponent($namespacedClassName)
  {
    unset($this->components[$namespacedClassName]);
  }

  /**
   * search for components by partial namespaced class name
   *
   * @param string $partialNamespacedClassname
   * @return Component[] the instantiated component
   */
  public function searchComponents($partialNamespacedClassname)
  {
    // filter component class names by suffix partial comparision
    $foundComponentClassnames = array_filter(array_keys($this->components), function ($componentClassName) use ($partialNamespacedClassname) {
      return Strings::endsWith($componentClassName, $partialNamespacedClassname);
    });

    // return the instantiated objects of the found component class names
    return array_intersect_key($this->components, array_flip($foundComponentClassnames));
  }

  /**
   * search for a unique component by partial namespaced class name
   * @param $partialNamespacedClassname
   * @return Component
   * @throws Exception if the namespaced class name was not found or if more than one was found
   */
  public function searchUniqueComponent($partialNamespacedClassname, $forceLast = false)
  {
    // search components
    $components = $this->searchComponents($partialNamespacedClassname);
    // check for uniqueness
    if (count($components) < 1) {
      throw new Exception('no component found', 254);
    } else if (count($components) > 1 && !$forceLast) {
      throw new Exception('partial namespaced component classname not unique', 253);
    } else if (count($components) > 1 && $forceLast) {
      return array_pop($components);
    }

    // return the one component in the array
    return array_shift($components);
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
        $object = get_queried_object();

        // match if there was no object type in the $slug (already correct $templateType . '_template' hook)
        // or the $objectType matches the queried object type
        if (
          $objectType == null || // no special type specified in slug
          (
            get_query_var('post_type') == $objectType || // check query var (doesn't work with 'post')
            (isset($object->post_type) && $object->post_type == $objectType) || // check type of queried object
            (is_tax() && get_query_var($objectType) != '') // check taxonomy
          )
        ) {
          $result = $slug;
        }

        return $result;
      }
    );
    add_action('get_template_part_' . $slug, array($this, 'renderView'), 10, 3);
  }

  /**
   * @param $block
   * @param bool $serverRender
   * @param bool $editorCss
   * @param bool $frontCss
   */
  protected function registerBlock($block, $serverRender = false, $editorCss = false, $frontCss = false)
  {
    add_action('init', function () use ($block, $serverRender, $editorCss, $frontCss) {
      $path = get_stylesheet_directory() . '/assets/blocks/' . $block . '/';
      $url = get_stylesheet_directory_uri() . '/assets/blocks/' . $block . '/';

      // automatically load dependencies and version
      $assets = require_once $path . 'build/index.asset.php';
      // Make sure to add wp editor dependency if not given
      $assets['dependencies'][] = 'wp-editor';
      wp_register_script('block-' . $block, $url . 'build/index.js', $assets['dependencies'], $assets['version']);

      $config = array(
        'style' => $block,
        'editor_script' => 'block-' . $block,
      );

      if ($editorCss) {
        wp_register_style('block-' . $block . '-css-editor', $url . 'editor.css', array('wp-edit-blocks'), $this->getVersion());
        $config['editor_style'] = 'block-' . $block . '-css-editor';
      }

      if ($frontCss) {
        wp_enqueue_style('block-' . $block . '-css', $url . 'frontend.css', array(), $this->getVersion());
      }

      // If there is server rendering include our callback function that needs to have specific syntax
      if ($serverRender) {
        require_once $path . 'src/render.php';
        $config['render_callback'] = str_replace('-', '_', $this->getSlug()) . '_render_block_' . str_replace('-', '_', $block);
      }

      register_block_type($this->getSlug() . '/' . $block, $config);
    });
  }

  /**
   * @param string $block the block name
   */
  protected function registerCoreBlock($block)
  {
    add_action('init', function () use ($block) {
      $path = File::getResourcePath() . '/blocks/' . $block . '/';
      $url = File::getResourceUri() . '/blocks/' . $block . '/';
      // automatically load dependencies and version
      $assets = require_once $path . 'build/index.asset.php';
      // Make sure to add wp editor dependency if not given
      $assets['dependencies'][] = 'wp-editor';
      wp_register_script('block-' . $block, $url . 'build/index.js', $assets['dependencies'], $assets['version']);

      $config = array(
        'style' => $block,
        'editor_script' => 'block-' . $block,
      );
      // Let the block extend the config and even add a render callback if needed
      require_once $path . 'block.php';

      register_block_type('lbwp/' . $block, $config);
    });
  }

  /**
   * get the view file by slug
   *
   * @param string $slug the view slug
   * @param bool $resolvePath
   * @return bool|string view file path if it exists, false otherwise
   */
  public function getViewFileBySlug($slug, $resolvePath = true)
  {
    $viewFile = false;
    $viewsBySlug = $this->getViewsBySlug();
    if (isset($viewsBySlug[$slug])) {
      $viewFile = $viewsBySlug[$slug];
      if ($resolvePath) {
        $viewFile = $this->resolvePath($viewFile);
      }
    }
    return $viewFile;
  }

  /**
   * Resolve the path: if the relative $file path exist in the child theme, the child theme absolute path + file is returned,
   * otherwise from the parent (or normal) theme path.
   *
   * @param string $file
   * @return string
   */
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

  /**
   * resolve the uri: if the relative $file path exists in the child theme, the child theme absolute uri + file is returned,
   * otherwise from the parent (or normal) theme path.
   *
   * @param $file
   * @return string
   */
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
   * Includes the actual configured file for a view or template part, if it is registered.
   * This function is used in multiple ways: generally it behaves like an filter (it returns something),
   * but for the template_include filter it can return false (when this function actually includes a template in object
   * context) to prevent template inclusion from wordpress and behave more like an action callback.
   *
   * @param string $slug the view slug
   * @param mixed $name additional argument from get_template_part ($name)
   * @param array $args additional argument from get_template_part ($args)
   * @return bool always false, to preview the wordpress loader include
   */
  public function renderView($slug, $name = null, $args = array())
  {
    $viewFile = $this->getViewFileBySlug($slug);
    if ($viewFile) {
      if (is_array($name)) {
        extract($name, EXTR_SKIP);
      }
      include($viewFile);

      // override WPINC/template-loader.php to not additionally include something
      return false;
    }

    // let plugin templates without work normally (without object context)
    return $slug;
  }

  /**
   * Callback for the get_header action: make plugins compatible
   *
   * @param mixed $headerArgument get_header name argument
   */
  public function renderHeader($headerArgument)
  {
    $this->renderView('header', array('headerArgument' => $headerArgument));
  }

  /**
   * Callback for the get_footer action: make plugins compatible
   *
   * @param mixed $footerArgument get_footer name argument
   */
  public function renderFooter($footerArgument)
  {
    $this->renderView('footer', array('footerArgument' => $footerArgument));
  }

  /**
   * register a page template, which allows it to be placed anywhere in the theme directory:
   * it has not to be in the theme root.
   *
   * @param string $slug an identifier (to be stored in the _wp_page_template meta field
   * @param string $view relative file path (to the theme root)
   * @param string $name page template name shown in dropdown
   * @param string $types the post types which this template is for
   */
  public function registerPageTemplate($slug, $view, $name, $types = array('page'))
  {
    // fetch the currently registered page templates
    foreach ($types as $postType) {
      $this->pageTemplateViewsBySlug[$postType][$slug] = $name;
    }

    // override the page template loader from wordpress by just storing the page template configuration in cache
    wp_cache_set('post_templates-' . $this->getThemeCacheHash(), $this->getPageTemplateViewsBySlug(), 'themes', 3);

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
   * Helper function to determine if the current or a specific post is a page template
   * @param string $slug the slug under which the page template was registered
   * @param int|null $pageId optional page id
   * @return bool true if a page template (identfied by slug)
   */
  public function isPageTemplate($slug, $postId = null)
  {
    $result = false;
    // get the page id somehow, if not specified
    if ($postId === null) {
      $post = get_post();
      $postId = $post->ID;
    }

    // if passed directly as a post parameter
    if ($postId == null && isset($_POST['post_ID'])) {
      $postId = absint($_POST['post_ID']);
    }
    // if passed directly as get parameter
    if ($postId == null && isset($_GET['post'])) {
      $postId = absint($_GET['post']);
    }

    // check the template slug if the post id is found
    if (($postId != null && is_singular()) || is_admin()) {
      // check the slug
      if (get_post_meta($postId, '_wp_page_template', true) === $slug) {
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
   * @param array $config optional config array for the theme support: if the config is an array, it will be merged with previous configs, otherwise if it is not null it will add the theme support "normally", without merging
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
   * Query helper function to save the query object for later use
   */
  public function backupQuery()
  {
    $this->backupQuery = clone $this->getQuery();
  }

  /**
   * Query helper function to restore the query object from the backupQuery field
   *
   * @param bool $rewind wether to rewind the query after restoring it.
   */
  public function restoreQuery($rewind = false)
  {
    if (is_object($this->backupQuery)) {
      $query = clone $this->backupQuery;
      $this->setQuery($query);
      if ($rewind) {
        $query->rewind_posts();
        $query->the_post();
      }
      $this->backupQuery = null;
    }
  }

  /**
   * Set theme supports
   * @param array|string $selection assotiative array to only activate the selected supports or string to activate only one support.
   * @param mixed $singleArg if $selection is a string then this can be used to pass the args value for add_theme_support.
   * Array formats: "support-name" => "support-value". Set "support-value" as null for not passing any args to the add_theme_support function.
   * Or pass an array only with "support-names" to pass them without args to the add_theme_support funciton.
   */
  protected function gutenbergThemeSupports($selection = false, $singleArg = null, $removeDefPatterns = true, $removeFlags = true){
    // setup the supports
    if($selection !== false){
      if(is_array($selection)) {
        foreach ($selection as $supportName => $supportValue) {
          if(is_numeric($supportName)){
            add_theme_support($supportValue);
            continue;
          }

          if ($supportValue === null) {
            add_theme_support($supportName);
          } else {
            add_theme_support($supportName, $supportValue);
          }
        }
      }else{
        if ($singleArg === null) {
          add_theme_support($selection);
        } else {
          add_theme_support($selection, $singleArg);
        }
      }
    }else {
      add_theme_support('post-thumbnails');
      add_theme_support('editor-color-palette');
      add_theme_support('disable-custom-colors');
      add_theme_support('editor-font-sizes', array());
      add_theme_support('disable-custom-font-sizes');
      add_theme_support('responsive-embeds');
      add_theme_support('editor-styles');
    }

    if ($removeFlags) {
      remove_filter( 'render_block', 'wp_render_layout_support_flag', 10, 2 );
      remove_filter( 'render_block', 'gutenberg_render_layout_support_flag', 10, 2 );
    }

    // Remove the default block patterns
    if($removeDefPatterns) {
      add_action('init', function () {
        $bpClass = \WP_Block_Patterns_Registry::get_instance();
        $defaults = $bpClass->get_all_registered();

        foreach ($defaults as $default) {
          unregister_block_pattern($default['name']);
        }
      }, 99);
    }

  }

  /**
   * Helper function do check wether query vars have been backed up
   *
   * @return bool
   */
  public function isMainQuery()
  {
    $result = true;
    if (is_object($this->backupQuery)) {
      $result = false;
    }
    return $result;
  }

  /**
   * @return CoreV2
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
   * Getter
   *
   * @return string
   */
  public function getParentVersion()
  {
    return $this->parentVersion;
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
   * Getter
   *
   * @return \WP_Query
   */
  public function getQuery()
  {
    return $this->getDependencyWrapper()->getQuery();
  }

  /**
   * Internal setter
   *
   * @param \WP_Query $query
   */
  public function setQuery($query)
  {
    $this->getDependencyWrapper()->setQuery($query);
  }

  /**
   * Getter
   *
   * @return \wpdb
   */
  public function getDb()
  {
    return $this->getDependencyWrapper()->getDb();
  }

  /**
   * @return WpWrapper
   */
  public function getDependencyWrapper()
  {
    return $this->dependencyWrapper;
  }

}