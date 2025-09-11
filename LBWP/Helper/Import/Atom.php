<?php

namespace LBWP\Helper\Import;

use LBWP\Util\Strings;

/**
 * Simple Feedreader implementation to read atom feeds
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 */
class Atom extends Feedreader
{

  /**
   * Create the object, that loads the xml file. Call read to execute
   * and access data to get the output array
   */
  public function __construct($url)
  {
    parent::__construct($url);
  }

  /**
   * Converts the XML file to the needed compatible format:
   * array of items like this: array(
   *   'category' => 'cat1;cat2;cat3',
   *   'guid' => 'unique_id',
   *   'title' => 'title of the item',
   *   'description' => 'short description',
   *   'content' => 'content of the item',
   *   'image' => 'the image url',
   *   'link' => 'http://whatever.org/?p=1234',
   *   'date' => 'timestamp' // server time
   * );
   */
  public function read()
  {
    // traverse through all items
    foreach ($this->xml->entry as $item) {
      $this->data[] = array(
        'title' => (string)$item->title,
        'description' => (string)$item->summary,
        'category' => $this->get_categories($item),
        'guid' => $this->get_guid($item),
        // No images in atom feeds, but search in content
        'image' => $this->get_image($item),
        'link' => $this->get_link($item),
        'date' => $this->get_date($item),
        'content' => (string)$item->content
      );
    }
  }

  /**
   * Read the categories into a string
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  private function get_categories(\SimpleXMLElement $item)
  {
    $cat = '';
    foreach ($item->category as $category) {
      $cat .= ((string)$category->attributes()->term) . ';';
    }
    return $cat;
  }

  /**
   * Read the image from image tag or enclosure
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  private function get_image(\SimpleXMLElement $item)
  {
    $image = '';
    $regex = '/<(?:[img]+)[^>]*src[^>]*>/';
    preg_match_all($regex, $item->content, $result);
    if (is_array($result[0])) {
      $image = Strings::parseTagProperty($result[0][0], 'src');
    }
    return $image;
  }

  /**
   * Read the correct article Link
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  private function get_link(\SimpleXMLElement $item)
  {
    $link = '';
    foreach ($item->link as $link) {
      $attr = $link->attributes();
      if ($attr->rel == 'alternate' && $attr->type == 'text/html')
        return (string)$attr->href;
    }
    return $link;
  }

  /**
   * Read the data and add the timezone offset
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  private function get_date(\SimpleXMLElement $item)
  {
    // Create a timestamp and add our local offset, so it's displayed properly
    return strtotime((string)$item->published) + (get_option('gmt_offset') * 3600);
  }

  /**
   * Well there are feeds with no guid. This method tries to use guid
   * but uses other values if it's not available
   * @param \SimpleXMLElement $item
   * @return string
   */
  private function get_guid(\SimpleXMLElement $item)
  {
    // Just get the guid if it's there
    if (isset($item->id))
      return (string)$item->guid;
    // If there is no guid... well use the link as guid
    if (strlen($this->get_link($item)) > 0)
      return $this->get_link($item);
    // If there is _REALLY_ no link (happens), use title/date combination
    return ((string)$item->title) . $this->get_date($item);
  }
}