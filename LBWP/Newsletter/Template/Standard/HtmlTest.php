<?php

namespace LBWP\Newsletter\Template\Standard;

use LBWP\Util\File;
use LBWP\Newsletter\Template\Base;
use LBWP\Newsletter\Template\Item;

/**
 * This class implements a full html test
 * @package LBWP\Newsletter\Template
 * @author Michael Sebel <michael@comotive.ch>
 */
class HtmlTest extends Base
{
  /**
   * @var string the name of the template
   */
  protected $name = 'HTML Test';
  /**
   * @var string the screenshot
   */
  protected $screenshot = '/wp-content/plugins/lbwp/resources/newsletter/testing/screenshot.png';

  /**
   * @param \stdClass $newsletter the newsletter post class
   * @return string the newsletter html code
   */
  public function renderNewsletter($newsletter)
  {
    // Change the html/ink file here
    $base = File::getResourcePath();
    $html = file_get_contents($base . '/newsletter/standard-single/html/newsletter.html');

    return $html;
  }

  /**
   * @param Item $item
   * @return string html this should render a specific item
   */
  protected function renderItem(Item $item)
  {
    return '';
  }
} 