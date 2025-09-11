<?php

namespace LBWP\Helper;

/**
 * Class HashFilter for initially done to filter by hashes in url
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class HashFilter
{
  /**
   * @var string filter base link
   */
  protected $base = '';
  /**
   * @var array the filters
   */
  protected $filters = array();

  /**
   * @param string $base the link to be the base for the filter
   */
  public function setBase($base)
  {
    $this->base = $base;
  }

  /**
   * @param string $key the key of the filter
   * @param string|array $values the filtered values
   */
  public function addFilter($key, $values)
  {
    if (!is_array($values)) {
      $values = array($values);
    }

    $this->filters[$key] = $values;
  }

  /**
   * Creates the link with the given filters and base
   */
  public function getFilterLink()
  {
    $link = $this->base . '#';

    foreach ($this->filters as $key => $values) {
      $link .= $key . ':' . implode(',', $values) . '/';
    }

    return $link;
  }
} 