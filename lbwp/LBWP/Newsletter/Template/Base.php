<?php

namespace LBWP\Newsletter\Template;

use LBWP\Core;
use LBWP\Util\String;
use LBWP\Newsletter\Template\Item;

/**
 * This class is the basic for all templates
 * @package LBWP\Newsletter\Template
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
{
  /**
   * @var string the name of the template
   */
  protected $name = 'override this->name';
  /**
   * @var string default screenshot, can be overriden by each template (150x150 image)
   */
  protected $screenshot = '/wp-content/plugins/lbwp/resources/newsletter/screenshot-default.png';

  /**
   * Nothing to do yet
   */
  public function __construct()
  {

  }

  /**
   * @param \stdClass $newsletter the newsletter post class
   * @return string the newsletter html code
   */
  public function renderText($newsletter)
  {
    $html = $this->renderNewsletter($newsletter);

    // Cleanout whitespace from every line
    $lines = explode(PHP_EOL, $html);
    $text = '';
    foreach ($lines as $line) {
      $text .= trim($line);
    }

    // Make sure there are some CRLFs
    $text = preg_replace ('/<style(.*?)>(.*?)<\/style>/i', '', $text);
    $text = str_replace(PHP_EOL, '', $text);
    $text = str_replace(array('<br />', '<br>', '</td>'), PHP_EOL, $text);
    $text = strip_tags($text, '<a>');

    // Make sure no html entities are there
    $encoding = mb_detect_encoding($text);
    $text = html_entity_decode($text, ENT_QUOTES, $encoding);

    // Convert the service vars
    $text = $this->convertServiceVars($text);

    // And convert the link tags to normal text links
    $text = preg_replace_callback(
      '/<a(.*)href="(.*)"(.*)>(.*)<\/a>/',
      array($this, 'replacePlaintextLinks'),
      $text
    );

    return $text;
  }

  /**
   * @param string $html the newsletter html
   * @return string html replaced general with service variables
   */
  public function convertServiceVars($html)
  {
    // Get the current services variables to translate to standard vars
    $nlCore = Core::getModule('NewsletterBase');
    $variables = $nlCore->getService()->getVariables();
    foreach ($variables as $key => $serviceKey) {
      $generalKey = '{lbwp:' . $key . '}';
      $html = str_replace($generalKey, $serviceKey, $html);
    }

    return $html;
  }

  /**
   * @param array $match a preg_replace match
   * @return string the string the is replace with the match
   */
  public function replacePlaintextLinks($match)
  {
    $url = String::parseTagProperty($match[0], 'href');
    return $url . ' (' . $match[4] . ')';
  }

  /**
   * @param \stdClass $newsletter the newsletter post class
   * @return string the newsletter html code
   */
  abstract public function renderNewsletter($newsletter);

  /**
   * @param Item $item
   * @return string html this should render a specific item
   */
  abstract protected function renderItem(Item $item);

  /**
   * @param \stdClass $newsletter the newsletter post class
   * @return array the final items
   */
  protected function getItems($newsletter)
  {
    $items = array();
    if (is_array($newsletter->newsletterItems)) {
      foreach ($newsletter->newsletterItems as $postId) {
        $items[] = Item::createItem($postId);
      }
    }

    return $items;
  }

  /**
   * @return string template name
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * @return string screenshot
   */
  public function getScreenshot()
  {
    return $this->screenshot;
  }
} 