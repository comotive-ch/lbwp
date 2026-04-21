<?php

namespace LBWP\Helper\Import;

/**
 * Basic Feedreader implementation
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 */
abstract class Feedreader
{

  /**
   * The loaded XML Element
   * @var \SimpleXMLElement
   */
  public $xml = NULL;
  /**
   * The read data, having a specific design, at the moment only:
   * array of items like this: array(
   *   'category' => 'cat1;cat2;cat3',
   *   'guid' => 'unique_id',
   *   'title' => 'title of the item',
   *   'content' => 'content of the item',
   *   'image' => 'the image url',
   *   'link' => 'http://whatever.org/?p=1234',
   *   'date' => 'timestamp' // server time
   * );
   * @var array
   */
  public $data = array();
  /**
   * Tells if the feed is readable (a valid xml file)
   * @var bool
   */
  private $readable = false;

  /**
   * Tries to load the url into an xml object
   */
  public function __construct($url)
  {
    // Suggest a user agent, so we don't get eventually blocked with reading the feed
    $context = stream_context_create(['http' => [
      'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ]]);
    $data = file_get_contents($url, false, $context);
    $this->xml = @simplexml_load_string($data, NULL, LIBXML_NOCDATA);
    // Set the readable flag
    $this->readable = ($this->xml !== false);
  }

  /**
   * Tells if the feed is readable
   */
  public function is_readable()
  {
    return $this->readable;
  }

  /**
   * The read function which should load the data
   */
  abstract public function read();
}