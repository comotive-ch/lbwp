<?php

namespace LBWP\Newsletter\Component;

use LBWP\Newsletter\Component\Base;
use LBWP\Newsletter\Template\Base as BaseTemplate;

/**
 * This class handles the newsletter post type
 * @package LBWP\Newsletter\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Templating extends Base
{
  /**
   * @var array the templates
   */
  protected $templates = array(
    //'testing' => '\\LBWP\\Newsletter\\Template\\Standard\\Testing',
    //'htmltest' => '\\LBWP\\Newsletter\\Template\\Standard\\HtmlTest',
    'standard-single' => '\\LBWP\\Newsletter\\Template\\Standard\\StandardSingle'
  );

  /**
   * Called after component construction
   */
  public function load() { }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Let developers add their own templates
    $this->templates = apply_filters('lbwpNewsletterTemplates', $this->templates);
  }

  /**
   * @param string $id the internal template id
   * @return BaseTemplate a template implementation
   */
  public function getTemplate($id)
  {
    if (isset($this->templates[$id])) {
      return new $this->templates[$id];
    }

    return false;
  }

  /**
   * @return array all template implementations
   */
  public function getTemplates()
  {
    $result = array();

    foreach ($this->templates as $id => $class) {
      $result[$id] = $this->getTemplate($id);
    }

    return $result;
  }

  /**
   * @param string $id a template id
   * @return bool true/false if existing or not
   */
  public function templateExists($id)
  {
    return isset($this->templates[$id]);
  }
} 