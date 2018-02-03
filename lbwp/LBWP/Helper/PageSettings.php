<?php

namespace LBWP\Helper;

/**
 * Provides functions to add page settings and alter settings. Also has methods to use
 * the settings and automatically caches them as long as possible (via options)
 * Use "admin_menu" to register the various pages
 * @author Michael Sebel <michael@comotive.ch>
 */
class PageSettings
{
  /**
   * @var array the whole pages->sections->settings array
   */
  protected static $configuration = array();
  /**
   * @var array contains all the settings, NULL means they're not loaded yet
   */
  protected static $settings = NULL;
  /**
   * @var bool tells if already initialized
   */
  protected static $initialized = false;

  protected static $backend = NULL;

  /**
   * Initialize the function for page settings
   */
  public static function initialize()
  {
    if (!self::$initialized) {
      self::$backend = new PageSettingsBackend();
      self::$backend->load();
      self::$initialized = true;
    }
  }

  /**
   * @return PageSettingsBackend the settings backend
   */
  public static function getBackend()
  {
    return self::$backend;
  }

  /**
   * @param string $slug the settings page slug used in addSection and addSetting
   * @param string $name the name to be display in the admin menu
   * @param string $parent the parent name (defaults to "Einstellungen")
   * @param string $capability the capability
   * @return string an error message if anything goes wrong
   */
  public static function addPage($slug, $name, $parent = 'options-general.php', $capability = 'administrator')
  {
    if (!isset(self::$configuration[$slug])) {
      self::$configuration[$slug] = array(
        'name' => $name,
        'parent' => $parent,
        'capability' => $capability,
        'sections' => array()
      );
    }
  }

  /**
   * @param string $slug the slug of the section (must be unique per page)
   * @param string $pageSlug the slug of the page to attach this section
   * @param string $title title of the section
   * @param string $description description of the section
   * @return string an error message if anything goes wrong
   */
  public static function addSection($slug, $pageSlug, $title, $description)
  {
    if (isset(self::$configuration[$pageSlug])) {
      if (!isset(self::$configuration[$pageSlug]['sections'][$slug])) {
        self::$configuration[$pageSlug]['sections'][$slug] = array(
          'title' => $title,
          'description' => $description,
          'items' => array()
        );
      }
    } else {
      return 'the settings page with the slug "'.$pageSlug.'" does not exist.';
    }
  }

  /**
   * Adds an item to a page
   * array(
   *   'page'         => 'the page slug',                                 // needed
   *   'section'      => 'the section slug',                              // needed
   *   'id'           => 'unique id to access the setting',               // must be unique over all pages/sections
   *   'type'         => 'text|textarea|editor|number',                   // type of the field
   *   'config'       => array()                                          // to configure the setting field
   *   'title'        => 'left hand title of the setting',                // optional (but important though)
   *   'description'  => 'helper text printed below the setting field'    // optional
   * );
   * @param array $item a settings item, see above documentation
   * @return string error message, if there is something wrong with the configuration
   */
  public static function addItem($item)
  {
    // Check if the page/section exists
    if (!isset(self::$configuration[$item['page']]['sections'][$item['section']]['items'])) {
      echo 'the page and/or section does not exist.';
    }

    // Check if the id doesn't exist yet
    foreach (self::$configuration[$item['page']]['sections'] as $section) {
      foreach ($section['items'] as $checkitem) {
        if ($checkitem['id'] == $item['id']) {
          return 'an item with the id "'.$item['id'].'" already exists.';
        }
      }
    }

    // Add the setting, if everything is ok (not returned yet)
    self::$configuration[$item['page']]['sections'][$item['section']]['items'][] = $item;
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title
   * @param callable $diplayCallback the callback to display the field
   * @param callable $saveCallback the callback to save the field
   * @param bool $isMultilang determines if the field is configurable in every language
   */
  public static function addCallback($page, $section, $key, $title, $diplayCallback, $saveCallback, $isMultilang = false)
  {
    $config = array(
      'multilang' => $isMultilang,
      'displayCallback' => $diplayCallback,
      'saveCallback' => $saveCallback,
      'saveAlways' => true
    );

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'title' => $title,
      'type' => 'callback',
      'config' => $config
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title
   * @param array $config must contain a saveCallback and an infoCallback
   * @param bool $isMultilang
   */
  public static function addFileUpload($page, $section, $key, $title, $config = array(), $isMultilang = false)
  {
    $config['multilang'] = $isMultilang;
    $config['saveAlways'] = true;
    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'title' => $title,
      'type' => 'upload',
      'config' => $config
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title, left displayed (optional)
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the setting (optional)
   * @param array $config additional configuration: optional=true, afterHtml=''
   */
  public static function addTextInput($page, $section, $key, $title = '', $isMultilang = false, $description = '', $config = array())
  {
    if (!isset($config['multilang'])) {
      $config['multilang'] = $isMultilang;
    }

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'text',
      'config' => $config,
      'title' => $title,
      'description' => $description
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $titleRight the title, right after the checkbox
   * @param string $titleLeft the title, left before the checkbox
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the setting (optional)
   */
  public static function addCheckbox($page, $section, $key, $titleRight = '', $titleLeft = '', $isMultilang = false, $description = '', $config = array())
  {
    $config = array_merge($config, array(
      'rightHtml' => $titleRight,
      'saveAlways' => true,
      'multilang' => $isMultilang
    ));

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'checkbox',
      'config' => $config,
      'title' => $titleLeft,
      'description' => $description
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title, right after the checkbox
   * @param array $values key/Value array for options
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the dropdown
   * @param array $config configuration: afterHtml = ''
   */
  public static function addDropdown($page, $section, $key, $title, $values = array(), $isMultilang = false, $description = '', $config = array())
  {
    if (!isset($config['multilang'])) {
      $config['multilang'] = $isMultilang;
    }
    if (!isset($config['values'])) {
      $config['values'] = $values;
    }

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'dropdown',
      'config' => $config,
      'title' => $title,
      'description' => $description
    ));
  }
  
  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title, right after the checkbox
   * @param array $values Data structure: $values[] = array('name' => 'Groupname', 'values' => array('key' => 'value'))
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the dropdown
   * @param array $config configuration: afterHtml = ''
   */
  public static function addGroupDropdown($page, $section, $key, $title, $values = array(), $isMultilang = false, $description = '', $config = array())
  {
    if (!isset($config['multilang'])) {
      $config['multilang'] = $isMultilang;
    }
    if (!isset($config['values'])) {
      $config['values'] = $values;
    }

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'groupdropdown',
      'config' => $config,
      'title' => $title,
      'description' => $description
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title, left displayed (optional)
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the setting (optional)
   * @param array $config additional configuration: rangeFrom=0, rangeTo=10000, afterHtml=''
   */
  public static function addNumber($page, $section, $key, $title = '', $isMultilang = false, $description = '', $config = array())
  {
    // Add defaults
    if (!isset($config['rangeFrom'])) {
      $config['rangeFrom'] = 0;
    }
    if (!isset($config['rangeTo'])) {
      $config['rangeTo'] = 10000;
    }
    if (!isset($config['multilang'])) {
      $config['multilang'] = $isMultilang;
    }

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'number',
      'config' => $config,
      'title' => $title,
      'description' => $description
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title, left displayed (optional)
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the setting (optional)
   * @param array $config additional configuration: optional=true, afterHtml='', height=150
   */
  public static function addTextarea($page, $section, $key, $title = '', $isMultilang = false, $description = '', $config = array())
  {
    if (!isset($config['multilang'])) {
      $config['multilang'] = $isMultilang;
    }

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'textarea',
      'config' => $config,
      'title' => $title,
      'description' => $description
    ));
  }

  /**
   * @param string $page the page to use
   * @param string $section the section to use
   * @param string $key the name of the option to save to
   * @param string $title the title, left displayed (optional)
   * @param bool $isMultilang determines if the field is configurable in every language
   * @param string $description the description below the setting (optional)
   * @param array $config additional configuration: afterHtml='', rows=5
   */
  public static function addEditor($page, $section, $key, $title = '', $isMultilang = false, $description = '', $config = array())
  {
    if (!isset($config['multilang'])) {
      $config['multilang'] = $isMultilang;
    }

    self::addItem(array(
      'page' => $page,
      'section' => $section,
      'id' => $key,
      'type' => 'editor',
      'config' => $config,
      'title' => $title,
      'description' => $description
    ));
  }

  /**
   * @param string $id the id given in addItem => id
   * @return mixed value that is stored in the item
   */
  public static function get($id)
  {
    return get_option($id);
  }

  /**
   * Echoes the setting, practical for frontend use
   * @param string $id the id given in addItem => id
   */
  public static function _e($id)
  {
    echo get_option($id);
  }

  /**
   * @return array the whole $configuration array
   */
  public static function getConfiguration()
  {
    return self::$configuration;
  }
}