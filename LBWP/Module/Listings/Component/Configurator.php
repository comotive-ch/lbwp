<?php

namespace LBWP\Module\Listings\Component;

/**
 * This class handles template configuraton
 * @package LBWP\Module\Listings\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Configurator extends Base
{
  /**
   * @var array the templates registered by the developer
   */
  protected $templates = array();
  /**
   * @var string the metabox id prefix
   */
  const BOX_PREFIX = 'mbh-listing-';
  /**
   * Called after component construction
   */
  public function load() { }

  /**
   * Called at init(50)
   */
  public function initialize() { }

  /**
   * @param string $key the internal key of the template
   * @param string $name the name of the template visible to the user
   * @param string $container must contain {listing} where the actual listing html should land
   * @return string the box id to use on metabox helper
   */
  public function addTemplate($key, $name, $container = '{listing}')
  {
    // Add the yet empty template config
    $this->templates[$key] = array(
      'key' => $key,
      'name' => $name,
      'container' => $container,
      'path' => '',
      'html' => ''
    );

    return $this->getTemplateKey($key);
  }

  /**
   * @param string $inheritedKey template to inherit from
   * @param string $key the new key for the new template
   * @param string $name the new name for the template
   * @return string the template key for the metabox helper
   */
  public function inheritTemplate($inheritedKey, $key, $name)
  {
    // Copy the template first
    $this->templates[$key] = $this->templates[$inheritedKey];
    $this->templates[$key]['key'] = $key;
    $this->templates[$key]['name'] = $name;

    // Set variables for admin init inline function
    $config = $this;
    $helper = $this->core->getHelper();

    // Also, make a copy of the whole metabox by first adding a new metabox
    add_action('admin_init', function() use($key, $inheritedKey, $name, $config, $helper) {
      $inheritedBoxId = $config->getTemplateKey($inheritedKey);
      $boxId = $config->getTemplateKey($key);
      $helper->addMetabox($boxId, __(sprintf('Einstellungen "%s"', $name), 'lbwp'));

      // Now get all fields from the inhertited metabox
      $fields = $helper->getBoxFields($inheritedBoxId);

      // And add them as new items to the new metabox
      if (is_array($fields)) {
        foreach ($fields as $field) {
          $helper->addFieldObject($field, $boxId);
        }
      }
    });

    return $this->getTemplateKey($key);
  }

  /**
   * @param string $key the internal key of the template
   * @return string the box id to use on metabox helper
   */
  public function getTemplateKey($key)
  {
    return self::BOX_PREFIX . $key;
  }

  /**
   * @param string $key the template id
   * @return array the template config or NULL if not found
   */
  public function getTemplate($key)
  {
    return $this->templates[$key];
  }

  /**
   * @param string $key the template key
   * @param string $container html container for the list
   */
  public function setContainer($key, $container)
  {
    $this->templates[$key]['container'] = $container;
  }

  /**
   * @param string $key template key
   * @param string $path relative to theme directory without leading slash
   */
  public function setTemplatePath($key, $path)
  {
    $this->templates[$key]['html'] = '';
    if (stristr($path, 'wp-content/themes') === false) {
      $full = get_stylesheet_directory() . '/' . $path;
      if (!file_exists($full)) {
        $full = get_template_directory() . '/' . $path;
      }
    } else {
      $full = $path;
    }

    $this->templates[$key]['path'] = $full;
  }

  /**
   * @param string $key template key
   * @param string $path relative relative to wordpress root with leading slash
   */
  public function setTemplateRootPath($key, $path)
  {
    $this->templates[$key]['html'] = '';
    $this->templates[$key]['path'] = ABSPATH . $path;
  }

  /**
   * @param string $key template key
   * @param string $html html template to adapt to items
   */
  public function setTemplateHtml($key, $html)
  {
    $this->templates[$key]['path'] = '';
    $this->templates[$key]['html'] = $html;
  }

  /**
   * @return array of registered
   */
  public function getTemplates()
  {
    return $this->templates;
  }
} 