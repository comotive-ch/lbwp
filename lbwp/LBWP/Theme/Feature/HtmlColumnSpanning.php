<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;

/**
 * Allow to make multi column layouts with specified break html tags
 * Defaults to use a <hr> Tag for spanning
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class HtmlColumnSpanning
{
  /**
   * @var array Contains all options for the breadcrumb
   */
  protected $options = array();
  /**
   * @var LineColumnSpanning the instance
   */
  protected static $instance = NULL;
  /**
   * @var bool allows a developer to force spanning, even if not in loop
   */
  public static $forceSpanning = false;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = $options;
    // Register the main filter to make the layouting (very late)
    add_filter('the_content', array($this, 'applyColumnSpanning'), 5000, 1);
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    $defaults = array(
      'container_begin' => '<div class="lbwp-container lbwp-columns-{total}">',
      'container_end' => '</div>',
      'element_begin' => '<div class="lbwp-col-{number}">',
      'element_end' => '</div>',
      'column_numbering' => 'ascending',
      'spanning_tags' => array('<hr />', '<hr>')
    );

    $settings = ArrayManipulation::deepMerge($defaults, $options);

    self::$instance = new HtmlColumnSpanning($settings);
  }

  /**
   * Spanning function
   * @param string $html the html
   * @return string the changed html
   */
  public function applyColumnSpanning($html)
  {
    if (in_the_loop() || self::$forceSpanning) {
      $contentParts = array();
      // Try to split by one of the spanning tags
      foreach ($this->options['spanning_tags'] as $tag) {
        if (stristr($html, $tag) !== false) {
          $contentParts = explode($tag, $html);
        }
      }

      // If a spanning was possible, continue
      if (count($contentParts) > 0) {
        $html = str_replace('{total}', count($contentParts), $this->options['container_begin']);
        foreach ($contentParts as $id => $part) {
          $html .= str_replace('{number}', $this->getColumnNumber($id, $contentParts), $this->options['element_begin']);
          $html .= $part;
          $html .= $this->options['element_end'];
        }
        // Close the cointainer
        $html .= $this->options['container_end'];
      }
    }

    return $html;
  }

  /**
   * Returns the correct number for the column depening on numbering type
   * @param int $id current array index (zero based)
   * @param array $parts all parts
   * @return int the column id
   */
  protected function getColumnNumber($id, $parts)
  {
    switch ($this->options['column_numbering']) {
      case 'ascending':
        return ++$id;
      case '12grid':
        return 6;
      case 'bootstrap':
      case 'bootstrap-large':
        $sizes = array('sm', 'md');
        if ($this->options['column_numbering'] == 'bootstrap-large') {
          $sizes[1] = 'lg';
        }
        $total = count($parts);
        if ($total % 4 == 0) {
          return 'col-' . $sizes[0] . '-6 col-' . $sizes[1] . '-3';
        } else if ($total % 3 == 0) {
          return 'col-' . $sizes[0] . '-6 col-' . $sizes[1] . '-4';
        } else {
          // If not 3 or 4 columns, eveythign else is two cols
          return 'col-' . $sizes[1] . '-6';
        }
      case 'leftright':
        return (++$id % 2 == 0) ? 'right' : 'left';
    }
  }
}
