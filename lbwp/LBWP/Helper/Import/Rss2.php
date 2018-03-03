<?php

namespace LBWP\Helper\Import;

use LBWP\Util\Strings;

/**
 * Simple Feedreader implementation to read RSS 2.0 feeds
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 */
class Rss2 extends Feedreader
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
    foreach ($this->xml->channel->item as $item) {
      // Special namespaced handling for content
      $content = $item->children('http://purl.org/rss/1.0/modules/content/');
      // paste it into the data container
      $this->data[] = array(
        'title' => (string)$item->title,
        'description' => trim((string)$item->description),
        'category' => $this->get_categories($item),
        'guid' => (string)$this->get_guid($item),
        'image' => $this->get_image($item),
        'link' => (string)$item->link,
        'date' => $this->get_date($item),
        'content' => trim((string)$content->encoded)
      );
    }
  }

  /**
   * Read the categories into a string
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  protected function get_categories(\SimpleXMLElement $item)
  {
    $cat = '';
    foreach ($item->category as $category) {
      $cat .= ((string)$category) . ';';
    }
    return $cat;
  }

  /**
   * Read the image from image tag or enclosure
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  protected function get_image(\SimpleXMLElement $item)
  {
    $image = '';
    // See if it's in an enclosure
    if (isset($item->enclosure)) {
      $image = (string)$item->enclosure->attributes()->url[0];
    }
    // See if it's in the standard image tag
    if (isset($item->image)) {
      $image = (string)$item->image;
    }
    // If nothing is found, parse the description for images
    if (strlen($image) == 0) {
      $regex = '/<(?:[img]+)[^>]*src[^>]*>/';
      preg_match_all($regex, $item->description, $result);
      if (is_array($result[0])) {
        $image = Strings::parseTagProperty($result[0][0], 'src');
      }
    }
    return $image;
  }

  /**
   * Read the data and add the timezone offset
   * @param \SimpleXMLElement $item the current item
   * @return string
   */
  protected function get_date(\SimpleXMLElement $item)
  {
    if (strlen((string)$item->pubDate) > 0) {
      // Create a timestamp and add our local offset, so it's displayed properly
      return strtotime((string)$item->pubDate);
    } else {
      // Try the dc: namespace
      $dc = $item->children('http://purl.org/dc/elements/1.1/');
      if (strlen((string)$dc->date) > 0)
        return strtotime((string)$dc->date);
    }
    // IF nothing worked, we have no date
    return 0;
  }

  /**
   * Well there are feeds with no guid. This method tries to use guid
   * but uses other values if it's not available
   * @param \SimpleXMLElement $item
   * @return string
   */
  protected function get_guid(\SimpleXMLElement $item)
  {
    // Just get the guid if it's there
    if (isset($item->guid))
      return (string)$item->guid;
    // If there is no guid... well use the link as guid
    if (isset($item->link))
      return (string)$item->link;
    // If there is _REALLY_ no link (happens), use title/date combination
    return ((string)$item->title) . $this->get_date($item);
  }
}